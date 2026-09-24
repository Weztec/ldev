<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

class DashboardBackupManager
{
    const SNAPSHOT_PATTERN = '/^\d{8}-\d{6}(-\d+)?$/';
    const SAFETY_PATTERN = '/^pre-restore-\d{8}-\d{6}(-\d+)?$/';

    public function __construct(
        protected ?string $dbPath = null,
        protected ?string $envPath = null,
        protected ?string $dir = null,
    ) {
        $this->dbPath ??= config('database.connections.sqlite.database');
        $this->envPath ??= base_path('.env');
        $this->dir ??= config('ldev.backups_dir') . '/_dashboard';
    }

    public function directory(): string
    {
        return $this->dir;
    }

    public function backup(): array
    {
        if (!is_file($this->dbPath)) {
            throw new \RuntimeException("Dashboard database not found at {$this->dbPath}.");
        }

        File::ensureDirectoryExists($this->dir, 0700);

        $name = date('Ymd-His');
        $suffix = 0;
        while (is_dir("{$this->dir}/{$name}" . ($suffix ? "-{$suffix}" : ''))) {
            $suffix++;
        }
        $name .= $suffix ? "-{$suffix}" : '';
        $snapshot = "{$this->dir}/{$name}";
        mkdir($snapshot, 0700);

        try {

            $pdo = new \PDO('sqlite:' . $this->dbPath);
            $pdo->exec('VACUUM INTO ' . $pdo->quote("{$snapshot}/database.sqlite"));
            $pdo = null;

            $check = new \PDO('sqlite:' . "{$snapshot}/database.sqlite");
            if ($check->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
                throw new \RuntimeException('Snapshot failed its integrity check.');
            }
            $check = null;

            if (is_file($this->envPath)) {
                copy($this->envPath, "{$snapshot}/.env");
            }
            @chmod("{$snapshot}/database.sqlite", 0600);
            @chmod("{$snapshot}/.env", 0600);
        } catch (\Throwable $e) {
            File::deleteDirectory($snapshot);
            throw $e;
        }

        return $this->describe($name);
    }

    public function list(): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }

        $names = array_filter(scandir($this->dir), fn ($n) => preg_match(self::SNAPSHOT_PATTERN, $n));
        rsort($names);

        return array_map(fn ($n) => $this->describe($n), array_values($names));
    }

    public function safetyCopies(): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }

        $names = array_filter(scandir($this->dir), fn ($n) => preg_match(self::SAFETY_PATTERN, $n) && is_file("{$this->dir}/{$n}/database.sqlite"));
        rsort($names);

        return array_map(fn ($n) => $this->describe($n), array_values($names));
    }

    public function prune(int $keep): int
    {
        $removed = 0;
        foreach (array_slice($this->list(), max(1, $keep)) as $snap) {
            File::deleteDirectory($snap['path']);
            $removed++;
        }

        return $removed;
    }

    public function restore(string $name): array
    {
        $known = preg_match(self::SNAPSHOT_PATTERN, $name) || preg_match(self::SAFETY_PATTERN, $name);
        if (!$known || !is_file("{$this->dir}/{$name}/database.sqlite")) {
            throw new \InvalidArgumentException("No dashboard backup named \"{$name}\".");
        }

        $source = "{$this->dir}/{$name}";
        $check = new \PDO('sqlite:' . "{$source}/database.sqlite");
        if ($check->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            throw new \RuntimeException("Backup {$name} failed its integrity check, so it was not restored.");
        }
        $check = null;

        $lock = null;
        if (is_file($this->dbPath)) {
            $lock = new \PDO('sqlite:' . $this->dbPath, null, null, [\PDO::ATTR_TIMEOUT => 15]);
            $lock->exec('BEGIN EXCLUSIVE');
        }

        $safety = "{$this->dir}/pre-restore-" . date('Ymd-His');
        $suffix = 0;
        while (is_dir($safety . ($suffix ? "-{$suffix}" : ''))) {
            $suffix++;
        }
        $safety .= $suffix ? "-{$suffix}" : '';
        mkdir($safety, 0700, true);
        if (is_file($this->dbPath)) {
            copy($this->dbPath, "{$safety}/database.sqlite");
        }
        if (is_file($this->envPath)) {
            copy($this->envPath, "{$safety}/.env");
            @chmod("{$safety}/.env", 0600);
        }

        try {
            $this->replace("{$source}/database.sqlite", $this->dbPath);
            if (is_file("{$source}/.env")) {
                $this->replace("{$source}/.env", $this->envPath, 0600);
            }
        } finally {
            if ($lock) {
                $lock->exec('ROLLBACK');
                $lock = null;
            }
        }

        return ['restored' => $name, 'safetyCopy' => $safety];
    }

    protected function replace(string $from, string $to, int $mode = 0644): void
    {
        $tmp = $to . '.restoring';
        copy($from, $tmp);
        @chmod($tmp, $mode);
        rename($tmp, $to);
    }

    protected function describe(string $name): array
    {
        $path = "{$this->dir}/{$name}";

        return [
            'name' => $name,
            'path' => $path,
            'time' => (int) filemtime($path),
            'dbSize' => (int) @filesize("{$path}/database.sqlite"),
            'hasEnv' => is_file("{$path}/.env"),
        ];
    }
}
