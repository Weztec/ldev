<?php

namespace App\Services;

class MeilisearchProvisioner
{
    public const ENV_KEYS = ['SCOUT_DRIVER', 'MEILISEARCH_HOST', 'MEILISEARCH_KEY'];

    public function enable(string $projectPath): void
    {
        (new EnvFileWriter)->update($projectPath, [
            'SCOUT_DRIVER' => 'meilisearch',
            'MEILISEARCH_HOST' => config('ldev.meilisearch_url'),
            'MEILISEARCH_KEY' => '',
        ]);
    }

    public function disable(string $projectPath): void
    {
        (new EnvFileWriter)->remove($projectPath, self::ENV_KEYS);
    }
}
