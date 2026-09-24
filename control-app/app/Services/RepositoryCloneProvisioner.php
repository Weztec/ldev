<?php

namespace App\Services;

use App\Models\RepositoryToken;
use Illuminate\Support\Facades\Process;

class RepositoryCloneProvisioner
{
    public function clone(string $repoUrl, string $path, ?RepositoryToken $token): void
    {
        $url = $token ? $this->authenticatedUrl($repoUrl, $token) : $repoUrl;

        Process::env(['HOME' => config('ldev.home')])
            ->run('git clone ' . escapeshellarg($url) . ' ' . escapeshellarg($path))->throw();

        if ($token) {
            Process::inProject($path)->run('git remote set-url origin ' . escapeshellarg($repoUrl));
        }
    }

    protected function authenticatedUrl(string $repoUrl, RepositoryToken $token): string
    {
        if (!str_starts_with($repoUrl, 'https://')) {
            return $repoUrl;
        }

        $parts = parse_url($repoUrl);
        $hostAndPath = ($parts['host'] ?? '')
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . ($parts['path'] ?? '')
            . (isset($parts['query']) ? '?' . $parts['query'] : '');

        return match ($token->provider) {
            'github' => 'https://' . rawurlencode($token->token) . '@' . $hostAndPath,
            'bitbucket' => 'https://x-token-auth:' . rawurlencode($token->token) . '@' . $hostAndPath,
            default => $repoUrl,
        };
    }
}
