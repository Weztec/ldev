<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class TestSchemaCache
{
    public const CONNECTIONS = ['mysql', 'mariadb'];
    public const PATH_PATTERN = '#^[A-Za-z0-9_./-]+$#';

    public function prepare(string $root, string $php, ?string $config, array $overrides = []): ?string
    {
        $base = config('ldev.test_schemas_dir');
        if (!File::isDirectory($root . '/database/migrations') || File::exists($root . '/database/schema') || !File::exists($root . '/artisan')
            || !preg_match(self::PATH_PATTERN, $root) || !preg_match(self::PATH_PATTERN, $base) || !preg_match(self::PATH_PATTERN, config('ldev.test_runs_dir'))) {
            return null;
        }

        [$env, $dotenv] = TestRunner::databaseEnv($root, $config, $overrides);
        $connection = $env['DB_CONNECTION'] ?? $dotenv['DB_CONNECTION'] ?? 'sqlite';
        $database = $env['DB_DATABASE'] ?? null;
        $host = $env['DB_HOST'] ?? $dotenv['DB_HOST'] ?? '127.0.0.1';
        if (!in_array($connection, self::CONNECTIONS, true) || !$database || !preg_match(TestEnvironment::DATABASE_PATTERN, $database) || !in_array($host, ['127.0.0.1', 'localhost'], true)) {
            return null;
        }

        $parts = [$connection, $php, File::exists($root . '/composer.lock') ? md5_file($root . '/composer.lock') : ''];
        foreach (File::glob($root . '/database/migrations/*.php') as $migration) {
            $parts[] = basename($migration) . ':' . md5_file($migration);
        }
        $name = basename($root);
        $dir = $base . '/' . $name . '-' . substr(sha1(implode("\n", $parts)), 0, 16);
        $file = $dir . '/schema/' . $connection . '-schema.sql';

        if (!File::exists($file) && !$this->build($root, $php, $env + $overrides, $connection, $database, $file)) {
            return null;
        }
        foreach (File::directories($base) as $old) {
            if ($old !== $dir && preg_match('/^' . preg_quote($name, '/') . '-[0-9a-f]{16}$/', basename($old))) {
                File::deleteDirectory($old);
            }
        }

        $probe = config('ldev.test_runs_dir') . '/overlay-probe-' . getmypid();
        $works = Process::path($root)->timeout(30)->run(self::isolate('test -f ' . escapeshellarg('database/schema/' . $connection . '-schema.sql'), $root, $dir, $probe))->successful();
        self::reset($probe, false);

        return $works ? $dir : null;
    }

    public static function isolate(string $command, string $root, string $schema, string $overlay): string
    {
        static $ids = null;
        $ids ??= '--map-user=' . (int) trim(Process::run('id -u')->output()) . ' --map-group=' . (int) trim(Process::run('id -g')->output());

        self::reset($overlay);
        $options = 'lowerdir=' . $schema . ':' . $root . '/database,upperdir=' . $overlay . '/upper,workdir=' . $overlay . '/work';
        $mount = 'mount -t overlay overlay -o ' . escapeshellarg($options) . ' ' . escapeshellarg($root . '/database');

        return 'unshare -rm sh -c ' . escapeshellarg($mount . ' && exec unshare ' . $ids . ' sh -c ' . escapeshellarg($command));
    }

    public static function reset(string $overlay, bool $create = true): void
    {
        if (File::isDirectory($overlay)) {
            Process::run('chmod -R u+rwx ' . escapeshellarg($overlay));
            File::deleteDirectory($overlay);
        }
        if ($create) {
            File::ensureDirectoryExists($overlay . '/upper');
            File::ensureDirectoryExists($overlay . '/work');
        }
    }

    public static function forget(string $name): void
    {
        foreach (File::isDirectory(config('ldev.test_schemas_dir')) ? File::directories(config('ldev.test_schemas_dir')) : [] as $dir) {
            if (preg_match('/^' . preg_quote($name, '/') . '(-baseline)?-[0-9a-f]{16}$/', basename($dir))) {
                File::deleteDirectory($dir);
            }
        }
    }

    protected function build(string $root, string $php, array $env, string $connection, string $database, string $file): bool
    {
        $scratch = substr($database, 0, 40) . '_ldev_schema_' . getmypid();
        $sql = fn (string $statement) => Process::timeout(60)->run('mariadb -uroot -h127.0.0.1 -e ' . escapeshellarg($statement));
        if (!$sql('CREATE DATABASE IF NOT EXISTS `' . $scratch . '`')->successful()) {
            return false;
        }

        File::ensureDirectoryExists(dirname($file));
        $tmp = $file . '.' . getmypid() . '.new';
        $prefix = TestEnvironment::commandPrefix(array_merge($env, ['APP_ENV' => 'testing', 'DB_CONNECTION' => $connection, 'DB_DATABASE' => $scratch, 'PAO_DISABLE' => 'true']));
        $artisan = fn (string $args) => Process::inProject($root)->timeout(600)->run($prefix . escapeshellarg($php) . ' artisan ' . $args . ' --no-interaction');

        $ok = $artisan('migrate:fresh --force')->successful()
            && $artisan('schema:dump --database=' . escapeshellarg($connection) . ' --path=' . escapeshellarg($tmp))->successful()
            && File::exists($tmp);
        $sql('DROP DATABASE IF EXISTS `' . $scratch . '`');

        $ok ? File::move($tmp, $file) : File::delete($tmp);
        if (!$ok) {
            File::deleteDirectory(dirname($file, 2));
        }

        return $ok;
    }
}
