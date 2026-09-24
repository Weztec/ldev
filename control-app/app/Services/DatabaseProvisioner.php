<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class DatabaseProvisioner
{

    public function provision(string $projectPath, string $type, string $name, ?string $existing = null): void
    {
        if ($existing !== null && $type === 'sqlite') {
            throw new \InvalidArgumentException('SQLite has no existing databases to pick from — it is a file inside the project.');
        }

        match ($type) {
            'sqlite' => $this->provisionSqlite($projectPath),
            'mysql' => $this->provisionMysql($projectPath, $name, $existing),
            'pgsql' => $this->provisionPgsql($projectPath, $name, $existing),
            default => throw new \InvalidArgumentException("Unsupported database driver: {$type}"),
        };
    }

    public function existingDatabases(string $type): array
    {
        $result = match ($type) {
            'mysql' => Process::inProject(base_path())->run('mariadb -h 127.0.0.1 -u root -N -e ' . escapeshellarg('SHOW DATABASES')),
            'pgsql' => Process::inProject(base_path())->run('psql -h 127.0.0.1 -U postgres -tAc ' . escapeshellarg('SELECT datname FROM pg_database WHERE datistemplate = false ORDER BY datname')),
            default => throw new \InvalidArgumentException("Only mysql and pgsql have a database list, not: {$type}"),
        };

        if (!$result->successful()) {
            throw new \RuntimeException("Could not list {$type} databases: " . (trim($result->errorOutput()) ?: 'server not reachable'));
        }

        $builtIn = $type === 'mysql' ? ['information_schema', 'mysql', 'performance_schema', 'sys'] : ['postgres'];

        return collect(explode("\n", trim($result->output())))
            ->map(fn ($n) => trim($n))
            ->filter(fn ($n) => $n !== '' && !in_array($n, $builtIn, true) && preg_match('/^[A-Za-z0-9_-]+$/', $n))
            ->values()
            ->all();
    }

    protected function assertExistingDatabase(string $type, string $database): string
    {
        if (!in_array($database, $this->existingDatabases($type), true)) {
            throw new \InvalidArgumentException("There is no {$type} database named \"{$database}\" on this server.");
        }

        return $database;
    }

    protected function provisionSqlite(string $projectPath): void
    {
        $dbFile = $projectPath . '/database/database.sqlite';
        if (!File::exists($dbFile)) {
            File::ensureDirectoryExists(dirname($dbFile));
            File::put($dbFile, '');
        }

        $this->updateEnv($projectPath, [
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $dbFile,
        ]);
    }

    protected function provisionMysql(string $projectPath, string $name, ?string $existing = null): void
    {
        $database = $existing !== null ? $this->assertExistingDatabase('mysql', $existing) : $this->databaseName($name);

        if ($existing === null) {
            $sql = "CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";

            Process::run('mariadb -h 127.0.0.1 -u root -e ' . escapeshellarg($sql))->throw();
        }

        $this->updateEnv($projectPath, [
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_DATABASE' => $database,
            'DB_USERNAME' => 'root',
            'DB_PASSWORD' => '',
        ]);

        $this->provisionWordPressConfigIfPresent($projectPath, $database);
    }

    protected function provisionWordPressConfigIfPresent(string $projectPath, string $database): void
    {
        $samplePath = $projectPath . '/wp-config-sample.php';
        if (!File::exists($samplePath)) {
            return;
        }

        $configPath = $projectPath . '/wp-config.php';
        if (!File::exists($configPath)) {
            File::copy($samplePath, $configPath);
        }

        $contents = File::get($configPath);

        $contents = preg_replace("/define\(\s*'DB_NAME',\s*'[^']*'\s*\);/", "define( 'DB_NAME', '{$database}' );", $contents);
        $contents = preg_replace("/define\(\s*'DB_USER',\s*'[^']*'\s*\);/", "define( 'DB_USER', 'root' );", $contents);
        $contents = preg_replace("/define\(\s*'DB_PASSWORD',\s*'[^']*'\s*\);/", "define( 'DB_PASSWORD', '' );", $contents);
        $contents = preg_replace("/define\(\s*'DB_HOST',\s*'[^']*'\s*\);/", "define( 'DB_HOST', '127.0.0.1' );", $contents);

        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $key) {
            $contents = preg_replace(
                "/define\(\s*'{$key}',\s*'[^']*'\s*\);/",
                "define( '{$key}', '" . str_replace("'", '', base64_encode(random_bytes(48))) . "' );",
                $contents
            );
        }

        File::put($configPath, $contents);
    }

    protected function provisionPgsql(string $projectPath, string $name, ?string $existing = null): void
    {
        $database = $existing !== null ? $this->assertExistingDatabase('pgsql', $existing) : $this->databaseName($name);

        $checkSql = "SELECT 1 FROM pg_database WHERE datname = '{$database}'";
        $exists = Process::run('psql -h 127.0.0.1 -U postgres -tAc ' . escapeshellarg($checkSql));

        if ($existing === null && trim($exists->output()) !== '1') {
            $createSql = "CREATE DATABASE \"{$database}\"";
            Process::run('psql -h 127.0.0.1 -U postgres -c ' . escapeshellarg($createSql))->throw();
        }

        $this->updateEnv($projectPath, [
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '5432',
            'DB_DATABASE' => $database,
            'DB_USERNAME' => 'postgres',
            'DB_PASSWORD' => '',
        ]);
    }

    public function databaseName(string $name): string
    {
        $database = str_replace('-', '_', $name);

        if (!preg_match('/^[a-z0-9_]{1,64}$/', $database)) {
            throw new \InvalidArgumentException('Database names may only use lowercase letters, digits, hyphens and underscores (max 64 characters).');
        }

        return $database;
    }

    protected function updateEnv(string $projectPath, array $values): void
    {
        (new EnvFileWriter)->update($projectPath, $values);
    }
}
