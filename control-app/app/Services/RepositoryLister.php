<?php

namespace App\Services;

use App\Models\RepositoryToken;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class RepositoryLister
{

    public function forToken(RepositoryToken $token): array
    {
        $repos = match ($token->provider) {
            'github' => $this->github($token),
            'bitbucket' => $this->bitbucket($token),
            default => throw new \InvalidArgumentException("Unsupported repository provider: {$token->provider}"),
        };

        return $this->withLastCommits($token, array_values($repos));
    }

    protected function withLastCommits(RepositoryToken $token, array $repos): array
    {
        if (!$repos) {
            return $repos;
        }

        $responses = Http::pool(function (Pool $pool) use ($token, $repos) {
            foreach ($repos as $i => $repo) {
                $path = implode('/', array_map('rawurlencode', explode('/', $repo['name'])));
                $request = $pool->as((string) $i)->timeout(10)->withToken($token->token);
                $token->provider === 'github'
                    ? $request->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
                        ->get("https://api.github.com/repos/{$path}/commits", ['per_page' => 1])
                    : $request->get("https://api.bitbucket.org/2.0/repositories/{$path}/commits", ['pagelen' => 1]);
            }
        }, 10);

        foreach ($repos as $i => &$repo) {
            $response = $responses[(string) $i] ?? null;
            if (!$response instanceof Response || !$response->successful()) {
                continue;
            }

            if ($token->provider === 'github') {
                $commit = $response->json('0');
                $repo['updated_at'] = $commit['commit']['committer']['date'] ?? $repo['updated_at'];
                $repo['updated_by'] = $commit['author']['login'] ?? $commit['commit']['author']['name'] ?? null;
            } else {
                $commit = $response->json('values.0');
                $repo['updated_at'] = $commit['date'] ?? $repo['updated_at'];
                $repo['updated_by'] = $commit['author']['user']['display_name']
                    ?? (isset($commit['author']['raw']) ? trim(preg_replace('/<[^>]*>/', '', $commit['author']['raw'])) : null);
            }
        }
        unset($repo);

        return $repos;
    }

    protected function github(RepositoryToken $token): array
    {
        $response = Http::withToken($token->token)
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
            ->get('https://api.github.com/user/repos', ['per_page' => 100, 'sort' => 'updated']);

        if ($response->failed()) {
            throw new \RuntimeException("GitHub repository list failed: " . $response->body());
        }

        return collect($response->json())
            ->map(fn ($repo) => [
                'name' => $repo['full_name'],
                'clone_url' => $repo['clone_url'],
                'clone_url_ssh' => $repo['ssh_url'],
                'private' => $repo['private'],
                'updated_at' => $repo['pushed_at'] ?? $repo['updated_at'] ?? null,
                'updated_by' => null,
            ])
            ->all();
    }

    protected function bitbucket(RepositoryToken $token): array
    {
        $response = Http::withToken($token->token)
            ->get("https://api.bitbucket.org/2.0/repositories/{$token->username}", [
                'pagelen' => 100,
                'sort' => '-updated_on',
            ]);

        if ($response->failed()) {
            throw new \RuntimeException("Bitbucket repository list failed: " . $response->body());
        }

        return collect($response->json('values'))
            ->map(function ($repo) {
                $links = collect($repo['links']['clone'] ?? []);

                return [
                    'name' => $repo['full_name'],
                    'clone_url' => $links->firstWhere('name', 'https')['href'] ?? null,
                    'clone_url_ssh' => $links->firstWhere('name', 'ssh')['href'] ?? null,
                    'private' => $repo['is_private'],
                    'updated_at' => $repo['updated_on'] ?? null,
                    'updated_by' => null,
                ];
            })
            ->filter(fn ($repo) => $repo['clone_url'])
            ->all();
    }
}
