<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class NodeVersionManager
{

    public const KNOWN_VERSIONS = ['22', '24', '26'];

    public function availableVersions(): array
    {
        $installedMajors = collect($this->installedVersions())
            ->map(fn ($v) => explode('.', $v)[0]);

        return collect(self::KNOWN_VERSIONS)
            ->merge($installedMajors)
            ->unique()
            ->sortBy(fn ($v) => (int) $v)
            ->values()
            ->all();
    }

    public function installedVersions(): array
    {
        $dir = $this->nvmDir() . '/versions/node';
        if (!File::isDirectory($dir)) {
            return [];
        }

        $versions = array_map(
            fn ($path) => ltrim(basename($path), 'v'),
            File::directories($dir)
        );
        natsort($versions);

        return array_values($versions);
    }

    public function resolveInstalledDir(string $version): ?string
    {
        $installed = $this->installedVersions();

        if (in_array($version, $installed, true)) {
            return $version;
        }

        $matches = array_filter(
            $installed,
            fn ($v) => $v === $version || str_starts_with($v, $version . '.')
        );

        if (empty($matches)) {
            return null;
        }

        usort($matches, 'version_compare');

        return end($matches);
    }

    public function ensureInstalled(string $version): void
    {
        if ($this->resolveInstalledDir($version) !== null) {
            return;
        }

        Process::env(['HOME' => config('ldev.home')])
            ->timeout(300)
            ->run('bash -lc ' . escapeshellarg(
                'export NVM_DIR="$HOME/.nvm"; . "$NVM_DIR/nvm.sh"; nvm install ' . escapeshellarg($version)
            ))
            ->throw();
    }

    public function npmInstallAndBuild(string $projectPath, string $version): void
    {
        $env = $this->npmEnv($version);

        Process::path($projectPath)->env($env)->timeout(300)->run('npm install')->throw();

        $packageJson = json_decode(File::get($projectPath . '/package.json') ?: '{}', true);
        if (isset($packageJson['scripts']['build'])) {
            Process::path($projectPath)->env($env)->timeout(300)->run('npm run build')->throw();
        }
    }

    public function writeLockfile(string $projectPath, string $version): void
    {
        Process::path($projectPath)->env($this->npmEnv($version))->timeout(300)->run('npm install --package-lock-only')->throw();
    }

    protected function npmEnv(string $version): array
    {
        $this->ensureInstalled($version);
        $resolved = $this->resolveInstalledDir($version);

        return [
            'HOME' => config('ldev.home'),
            'PATH' => $this->binPath($resolved) . ':' . (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
        ];
    }

    public function npmVersion(string $installedVersion): ?string
    {
        $result = Process::env([
            'HOME' => config('ldev.home'),
            'PATH' => $this->binPath($installedVersion) . ':' . (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
        ])->run('npm -v');

        return $result->successful() && preg_match('/^\d+\.\d+\.\d+$/', trim($result->output())) ? trim($result->output()) : null;
    }

    public function upgradeNpm(string $installedVersion, string $npmVersion): void
    {
        if (!in_array($installedVersion, $this->installedVersions(), true) || !preg_match('/^\d+\.\d+\.\d+$/', $npmVersion)) {
            throw new \InvalidArgumentException('Unknown Node or npm version.');
        }

        Process::env([
            'HOME' => config('ldev.home'),
            'PATH' => $this->binPath($installedVersion) . ':' . (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
        ])->timeout(300)->run('npm install -g ' . escapeshellarg('npm@' . $npmVersion))->throw();
    }

    public function activeVersion(?string $preferredVersion): ?string
    {
        $binary = 'node';
        if ($preferredVersion) {
            $resolved = $this->resolveInstalledDir($preferredVersion);
            if ($resolved === null) {
                return null;
            }
            $binary = $this->binPath($resolved) . '/node';
        }

        $result = Process::env([
            'HOME' => config('ldev.home'),
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
        ])->run($binary . ' -v');

        return $result->successful() ? trim($result->output()) : null;
    }

    public function binPath(string $resolvedVersion): string
    {
        return $this->nvmDir() . "/versions/node/v{$resolvedVersion}/bin";
    }

    protected function nvmDir(): string
    {
        return config('ldev.home') . '/.nvm';
    }
}
