<?php

namespace App\Services;

use App\Models\RepositoryToken;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

class RemoteRepositoryProvisioner
{
    public function createAndPush(string $path, string $projectName, RepositoryToken $token, bool $private = true): string
    {
        [$cloneUrl, $existed] = match ($token->provider) {
            'github' => $this->createGithubRepo($projectName, $token, $private),
            'bitbucket' => $this->createBitbucketRepo($projectName, $token, $private),
            default => throw new \InvalidArgumentException("Unsupported repository provider: {$token->provider}"),
        };

        if ($existed && !$this->remoteIsEmpty($path, $cloneUrl, $token)) {
            throw new \RuntimeException("A repository named \"{$projectName}\" already exists on " . ucfirst($token->provider) . " and already has commits. Choose another name, or delete that repository first.");
        }

        $this->initAndPush($path, $cloneUrl, $token);

        return $cloneUrl;
    }

    protected function createGithubRepo(string $projectName, RepositoryToken $token, bool $private): array
    {
        $response = $this->github($token)->post('https://api.github.com/user/repos', [
            'name' => $projectName,
            'private' => $private,
        ]);

        if ($response->status() === 403) {
            throw new \RuntimeException('GitHub refused to create the repository: the saved token needs "Administration: Read and write" (fine-grained) or the public_repo/repo scope (classic). Update the token on the Repositories page.');
        }

        if ($this->alreadyExists($response)) {
            $owner = $this->github($token)->get('https://api.github.com/user')->throw()->json('login');
            $existing = $this->github($token)->get("https://api.github.com/repos/{$owner}/{$projectName}");

            if ($existing->failed() || !$existing->json('clone_url')) {
                throw new \RuntimeException("GitHub says \"{$projectName}\" already exists, but it could not be looked up: " . $existing->body());
            }

            return [$existing->json('clone_url'), true];
        }

        if ($response->failed()) {
            throw new \RuntimeException("GitHub repository creation failed: " . $response->body());
        }

        return [$response->json('clone_url'), false];
    }

    protected function createBitbucketRepo(string $projectName, RepositoryToken $token, bool $private): array
    {
        $workspace = $token->username;
        $repoUrl = "https://api.bitbucket.org/2.0/repositories/{$workspace}/{$projectName}";

        $response = Http::withToken($token->token)->post($repoUrl, [
            'scm' => 'git',
            'is_private' => $private,
        ]);

        $existed = $this->alreadyExists($response);

        if ($existed) {
            $response = Http::withToken($token->token)->get($repoUrl);
        }

        if ($response->failed()) {
            throw new \RuntimeException("Bitbucket repository creation failed: " . $response->body());
        }

        $https = collect($response->json('links.clone'))->firstWhere('name', 'https');

        if (!$https) {
            throw new \RuntimeException("Bitbucket repository created but no https clone URL was returned.");
        }

        return [$https['href'], $existed];
    }

    protected function github(RepositoryToken $token)
    {
        return Http::withToken($token->token)
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28']);
    }

    protected function alreadyExists(Response $response): bool
    {
        return in_array($response->status(), [400, 422], true)
            && str_contains(strtolower($response->body()), 'already exist');
    }

    protected function remoteIsEmpty(string $path, string $cloneUrl, RepositoryToken $token): bool
    {
        $authenticatedUrl = (new RepositoryCloneProvisioner)->authenticatedUrl($cloneUrl, $token);

        $result = Process::inProject($path)
            ->run('GIT_TERMINAL_PROMPT=0 git ls-remote --heads ' . escapeshellarg($authenticatedUrl))
            ->throw();

        return trim($result->output()) === '';
    }

    protected function initAndPush(string $path, string $cloneUrl, RepositoryToken $token): void
    {
        $authenticatedUrl = (new RepositoryCloneProvisioner)->authenticatedUrl($cloneUrl, $token);

        (new GitInitializer)->initIfNeeded($path);

        $branch = trim(Process::inProject($path)->run('git rev-parse --abbrev-ref HEAD')->output());

        $remotes = preg_split('/\s+/', trim(Process::inProject($path)->run('git remote')->output()));
        $verb = in_array('origin', $remotes, true) ? 'set-url' : 'add';

        Process::inProject($path)->run("git remote {$verb} origin " . escapeshellarg($authenticatedUrl))->throw();

        try {
            Process::inProject($path)->run('GIT_TERMINAL_PROMPT=0 git push -u origin ' . escapeshellarg($branch))->throw();
        } finally {
            Process::inProject($path)->run('git remote set-url origin ' . escapeshellarg($cloneUrl));
        }
    }
}
