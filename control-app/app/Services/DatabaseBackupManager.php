<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class DatabaseBackupManager
{
    public function backup(Site $site): string
    {
        $driver = $site->databaseType() ?? 'sqlite';
        $dir = $this->siteDir($site);
        File::ensureDirectoryExists($dir);

        $extension = $driver === 'sqlite' ? 'sqlite' : 'sql';
        $path = $dir . '/' . Carbon::now()->format('Y-m-d_His') . "-{$driver}.{$extension}";

        match ($driver) {
            'sqlite' => File::copy($this->sqliteDbPath($site), $path),
            'mysql' => $this->backupMysql($site, $path),
            'pgsql' => $this->backupPgsql($site, $path),
            default => throw new \InvalidArgumentException("Unsupported database driver: {$driver}"),
        };

        return $path;
    }

    public function restore(Site $site, string $filename): void
    {
        $path = $this->resolvePath($site, $filename);
        $backupDriver = $this->driverFromFilename($filename);
        $currentDriver = $site->databaseType() ?? 'sqlite';

        if ($backupDriver !== $currentDriver) {
            throw new \RuntimeException(
                "This backup is from a {$backupDriver} database, but the site is currently on {$currentDriver} — switch the site back to {$backupDriver} first."
            );
        }

        match ($backupDriver) {
            'sqlite' => File::copy($path, $this->sqliteDbPath($site)),
            'mysql' => $this->restoreMysql($site, $path),
            'pgsql' => $this->restorePgsql($site, $path),
        };
    }

    public function list(Site $site): array
    {
        $dir = $this->siteDir($site);
        if (!File::isDirectory($dir)) {
            return [];
        }

        return collect(File::files($dir))
            ->map(fn ($file) => [
                'name' => $file->getFilename(),
                'size' => $file->getSize(),
                'created_at' => $file->getMTime(),
            ])
            ->sortByDesc('created_at')
            ->values()
            ->all();
    }

    public function delete(Site $site, string $filename): void
    {
        File::delete($this->resolvePath($site, $filename));
    }

    public function prune(Site $site, int $keep): void
    {
        foreach (array_slice($this->list($site), $keep) as $old) {
            $this->delete($site, $old['name']);
        }
    }

    public function importedPath(Site $site, string $driver, string $extension): string
    {
        $dir = $this->siteDir($site);
        File::ensureDirectoryExists($dir);

        return $dir . '/' . Carbon::now()->format('Y-m-d_His') . "-{$driver}-imported.{$extension}";
    }

    protected function resolvePath(Site $site, string $filename): string
    {
        $path = $this->siteDir($site) . '/' . basename($filename);

        if (!File::exists($path)) {
            throw new \InvalidArgumentException("Backup not found: {$filename}");
        }

        return $path;
    }

    protected function driverFromFilename(string $filename): string
    {
        if (preg_match('/-(sqlite|mysql|pgsql)(?:-imported)?\.(sqlite|sql)$/', basename($filename), $m)) {
            return $m[1];
        }

        throw new \InvalidArgumentException("Cannot determine database driver from backup filename: {$filename}");
    }

    protected function siteDir(Site $site): string
    {
        return config('ldev.backups_dir') . '/' . $site->name;
    }

    protected function sqliteDbPath(Site $site): string
    {
        return $site->projectRoot() . '/database/database.sqlite';
    }

    protected function backupMysql(Site $site, string $path): void
    {

        Process::run('mariadb-dump -h 127.0.0.1 -u root ' . escapeshellarg($this->databaseName($site)) . ' > ' . escapeshellarg($path))->throw();
    }

    protected function restoreMysql(Site $site, string $path): void
    {
        Process::run('mariadb -h 127.0.0.1 -u root ' . escapeshellarg($this->databaseName($site)) . ' < ' . escapeshellarg($path))->throw();
    }

    protected function backupPgsql(Site $site, string $path): void
    {
        Process::run('pg_dump -h 127.0.0.1 -U postgres ' . escapeshellarg($this->databaseName($site)) . ' -f ' . escapeshellarg($path))->throw();
    }

    protected function restorePgsql(Site $site, string $path): void
    {
        Process::run('psql -h 127.0.0.1 -U postgres ' . escapeshellarg($this->databaseName($site)) . ' -f ' . escapeshellarg($path))->throw();
    }

    protected function databaseName(Site $site): string
    {
        return str_replace('-', '_', $site->name);
    }
}
