<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ProjectFeatureDetector
{
    public function detectAndApply(Site $site, string $projectPath): array
    {
        $applied = [];

        $composer = $this->readComposerJson($projectPath);
        $requires = array_merge($composer['require'] ?? [], $composer['require-dev'] ?? []);

        if (isset($requires['laravel/reverb']) && !$site->reverb_enabled) {
            $this->ensureReverbEnv($projectPath, $site);
            $site->update(['reverb_enabled' => true]);
            $applied[] = 'reverb';
        }

        $queueConnection = $this->envValue($projectPath, 'QUEUE_CONNECTION');
        if ($queueConnection && !in_array($queueConnection, ['sync', 'null', ''], true) && !$site->queue_worker_enabled) {
            $site->update(['queue_worker_enabled' => true]);
            $applied[] = 'queue';
        }

        if (isset($requires['meilisearch/meilisearch-php']) && $this->envValue($projectPath, 'SCOUT_DRIVER') !== 'meilisearch') {
            (new MeilisearchProvisioner)->enable($projectPath);
            $applied[] = 'meilisearch';
        }

        if (isset($requires['league/flysystem-aws-s3-v3']) && $this->envValue($projectPath, 'FILESYSTEM_DISK') !== 's3') {
            (new S3Provisioner)->provision($projectPath, $site->name);
            $applied[] = 's3';
        }

        return $applied;
    }

    protected function readComposerJson(string $projectPath): array
    {
        $path = $projectPath . '/composer.json';
        if (!File::exists($path)) {
            return [];
        }

        return json_decode(File::get($path), true) ?? [];
    }

    protected function envValue(string $projectPath, string $key): ?string
    {
        return (new EnvFileWriter)->get($projectPath, $key);
    }

    protected function ensureReverbEnv(string $projectPath, Site $site): void
    {
        $values = [
            'REVERB_HOST' => '127.0.0.1',
            'REVERB_PORT' => $site->reverb_port,
            'REVERB_SCHEME' => 'http',
            'VITE_REVERB_HOST' => "{$site->name}-reverb.test",
            'VITE_REVERB_PORT' => 443,
            'VITE_REVERB_SCHEME' => 'https',
        ];

        foreach (['REVERB_APP_ID', 'REVERB_APP_KEY', 'REVERB_APP_SECRET'] as $key) {
            if (!$this->envValue($projectPath, $key)) {
                $values[$key] = Str::random(20);
            }
        }

        (new EnvFileWriter)->update($projectPath, $values);
    }
}
