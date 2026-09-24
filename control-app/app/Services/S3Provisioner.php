<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

class S3Provisioner
{
    public function provision(string $projectPath, string $name): void
    {

        $bucket = $name;

        Process::run('mc mb --ignore-existing ' . escapeshellarg("ldevlocal/{$bucket}"))->throw();

        (new EnvFileWriter)->update($projectPath, [
            'FILESYSTEM_DISK' => 's3',
            'AWS_ACCESS_KEY_ID' => config('ldev.minio.access_key'),
            'AWS_SECRET_ACCESS_KEY' => config('ldev.minio.secret_key'),
            'AWS_DEFAULT_REGION' => 'us-east-1',
            'AWS_BUCKET' => $bucket,
            'AWS_ENDPOINT' => config('ldev.minio.endpoint'),
            'AWS_USE_PATH_STYLE_ENDPOINT' => 'true',
        ]);
    }
}
