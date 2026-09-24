<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class ProjectSetupPipeline
{
    public function run(string $projectPath, array $steps = []): void
    {

        (new EnvFileWriter)->ensureExists($projectPath);

        $steps = $steps ?: $this->detectSteps($projectPath);

        foreach ($steps as $step) {
            $this->executeStep($projectPath, $step);
        }
    }

    protected function hasAppKey(string $path): bool
    {
        $env = $path . '/.env';

        return File::exists($env) && (bool) preg_match('/^APP_KEY=\S+/m', File::get($env));
    }

    protected function detectSteps(string $path): array
    {
        $steps = [];
        if (File::exists($path . '/composer.json')) {
            $steps[] = 'composer install';
        }
        if (File::exists($path . '/package.json')) {
            $steps[] = 'npm install';
        }
        if (File::exists($path . '/artisan')) {

            if (!$this->hasAppKey($path)) {
                $steps[] = 'php artisan key:generate';
            }
            $steps[] = 'php artisan migrate';

            $steps[] = 'php artisan storage:link --force';
        }
        return $steps;
    }

    protected function executeStep(string $path, string $command): void
    {

        $result = Process::inProject($path)->run($command);

        Log::info("[ldev] $command", [
            'path' => $path,
            'exit_code' => $result->exitCode(),
            'output' => $result->output(),
            'error_output' => $result->errorOutput(),
        ]);

        if ($result->failed()) {
            $host = str_starts_with($command, 'composer')
                ? (new ComposerAuthWriter)->extractAuthFailureHost($result->errorOutput())
                : null;

            if ($host) {
                throw new \RuntimeException(
                    "Step failed ($command) in $path: $host requires Composer credentials Linux Dev doesn't have. "
                    . "Add them on the Settings page under \"Composer credentials\", then retry."
                );
            }

            throw new \RuntimeException("Step failed ($command) in $path: " . $result->errorOutput());
        }
    }
}
