<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

class EnvFileWriter
{
    public function update(string $projectPath, array $values): void
    {
        $envPath = $projectPath . '/.env';
        if (!File::exists($envPath)) {
            return;
        }

        $env = File::get($envPath);
        foreach ($values as $key => $value) {
            $pattern = '/^' . preg_quote($key, '/') . '=.*/m';
            $line = $key . '=' . $value;
            $env = preg_match($pattern, $env)
                ? preg_replace($pattern, $line, $env)
                : $env . PHP_EOL . $line;
        }
        File::put($envPath, $env);
    }

    public function ensureExists(string $projectPath): void
    {
        $envPath = $projectPath . '/.env';
        $examplePath = $projectPath . '/.env.example';
        if (!File::exists($envPath) && File::exists($examplePath)) {
            File::copy($examplePath, $envPath);
        }
    }

    public function get(string $projectPath, string $key): ?string
    {
        $envPath = $projectPath . '/.env';
        if (!File::exists($envPath)) {
            return null;
        }

        if (preg_match('/^' . preg_quote($key, '/') . '=(.*)$/m', File::get($envPath), $m)) {
            return trim($m[1]);
        }

        return null;
    }
}
