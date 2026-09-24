<?php

namespace App\Services;

use App\Models\RepositoryToken;
use Illuminate\Support\Facades\Http;

class RepositoryLister
{

    public function forToken(RepositoryToken $token): array
    {
        return match ($token->provider) {
            'github' => $this->github($token),
            'bitbucket' => $this->bitbucket($token),
            default => throw new \InvalidArgumentException("Unsupported repository provider: {$token->provider}"),
        };
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
                ];
            })
            ->filter(fn ($repo) => $repo['clone_url'])
            ->all();
    }
}
