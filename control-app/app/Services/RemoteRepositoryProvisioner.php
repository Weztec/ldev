<?php

namespace App\Services;

use App\Models\RepositoryToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

class RemoteRepositoryProvisioner
{
    public function createAndPush(string $path, string $projectName, RepositoryToken $token, bool $private = true): string
    {
        $cloneUrl = match ($token->provider) {
            'github' => $this->createGithubRepo($projectName, $token, $private),
            'bitbucket' => $this->createBitbucketRepo($projectName, $token, $private),
            default => throw new \InvalidArgumentException("Unsupported repository provider: {$token->provider}"),
        };

        $this->initAndPush($path, $cloneUrl, $token);

        return $cloneUrl;
    }

    protected function createGithubRepo(string $projectName, RepositoryToken $token, bool $private): string
    {
        $response = Http::withToken($token->token)
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
            ->post('https://api.github.com/user/repos', [
                'name' => $projectName,
                'private' => $private,
            ]);

        if ($response->status() === 403) {
            throw new \RuntimeException('GitHub refused to create the repository: the saved token needs "Administration: Read and write" (fine-grained) or the public_repo/repo scope (classic). Update the token on the Repositories page.');
        }

        if ($response->failed()) {
            throw new \RuntimeException("GitHub repository creation failed: " . $response->body());
        }

        return $response->json('clone_url');
    }

    protected function createBitbucketRepo(string $projectName, RepositoryToken $token, bool $private): string
    {
        $workspace = $token->username;

        $response = Http::withToken($token->token)
            ->post("https://api.bitbucket.org/2.0/repositories/{$workspace}/{$projectName}", [
                'scm' => 'git',
                'is_private' => $private,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException("Bitbucket repository creation failed: " . $response->body());
        }

        $cloneLinks = collect($response->json('links.clone'));
        $https = $cloneLinks->firstWhere('name', 'https');

        if (!$https) {
            throw new \RuntimeException("Bitbucket repository created but no https clone URL was returned.");
        }

        return $https['href'];
    }

    protected function initAndPush(string $path, string $cloneUrl, RepositoryToken $token): void
    {

        $authenticatedUrl = match ($token->provider) {
            'github' => str_replace('https://', 'https://' . rawurlencode($token->token) . '@', $cloneUrl),
            'bitbucket' => str_replace('https://', 'https://x-token-auth:' . rawurlencode($token->token) . '@', $cloneUrl),
            default => $cloneUrl,
        };

        (new GitInitializer)->initIfNeeded($path);

        $branch = trim(Process::inProject($path)->run('git rev-parse --abbrev-ref HEAD')->output());

        Process::inProject($path)->run('git remote add origin ' . escapeshellarg($authenticatedUrl))->throw();
        Process::inProject($path)->run('git push -u origin ' . escapeshellarg($branch))->throw();
        Process::inProject($path)->run('git remote set-url origin ' . escapeshellarg($cloneUrl));
    }
}
