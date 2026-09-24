<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Site;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class TestEnvironment
{
    public const NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';
    public const DATABASE_PATTERN = '/^[A-Za-z0-9_]+$/';

    public static function configFile(string $root): ?string
    {
        return collect(['phpunit.xml', 'phpunit.xml.dist'])->first(fn ($file) => File::exists($root . '/' . $file));
    }

    public static function settingKey(Site $site): string
    {
        return 'test_env_site_' . $site->id;
    }

    public static function overrides(Site $site): array
    {
        $raw = Setting::get(self::settingKey($site));
        $data = $raw ? json_decode($raw, true) : [];

        return is_array($data) ? array_filter($data, fn ($value, $name) => is_string($value) && preg_match(self::NAME_PATTERN, $name), ARRAY_FILTER_USE_BOTH) : [];
    }

    public static function commandPrefix(array $overrides): string
    {
        if (!$overrides) {
            return '';
        }

        $pairs = [];
        foreach ($overrides as $name => $value) {
            $pairs[] = escapeshellarg($name . '=' . $value);
        }

        return 'env ' . implode(' ', $pairs) . ' ';
    }

    public function projectVariables(string $root): array
    {
        $file = self::configFile($root);
        $vars = [];
        $xml = $file ? @simplexml_load_file($root . '/' . $file) : false;
        foreach ($xml ? ($xml->xpath('/phpunit/php/env') ?: []) : [] as $node) {
            $vars[(string) $node['name']] = [
                'value' => (string) $node['value'],
                'force' => in_array(strtolower((string) $node['force']), ['true', '1'], true),
            ];
        }

        return $vars;
    }

    public function rows(Site $site): array
    {
        $project = $this->projectVariables($site->projectRoot());
        $overrides = self::overrides($site);
        $rows = [];

        foreach ($project as $name => $var) {
            $rows[] = [
                'name' => $name,
                'project' => $var['value'],
                'force' => $var['force'],
                'override' => array_key_exists($name, $overrides),
                'value' => $overrides[$name] ?? $var['value'],
            ];
        }
        foreach (array_diff_key($overrides, $project) as $name => $value) {
            $rows[] = ['name' => $name, 'project' => null, 'force' => false, 'override' => true, 'value' => $value];
        }

        return $rows;
    }

    public function saveRows(Site $site, array $rows): array
    {
        $overrides = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '' || empty($row['override'])) {
                continue;
            }
            if (!preg_match(self::NAME_PATTERN, $name)) {
                throw new \InvalidArgumentException('Invalid variable name: ' . $name);
            }
            $overrides[$name] = (string) ($row['value'] ?? '');
        }

        Setting::set(self::settingKey($site), json_encode($overrides));

        return $overrides;
    }

    public static function effective(array $rows): array
    {
        $values = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            if (!empty($row['override']) && empty($row['force'])) {
                $values[$name] = (string) ($row['value'] ?? '');
            } elseif (($row['project'] ?? null) !== null) {
                $values[$name] = (string) $row['project'];
            }
        }

        return $values;
    }

    public function ldevDatabaseDefaults(array $rows, string $siteName): array
    {
        $values = self::effective($rows);
        $connection = $values['DB_CONNECTION'] ?? 'mysql';

        $defaults = match ($connection) {
            'pgsql' => ['DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432', 'DB_USERNAME' => 'postgres', 'DB_PASSWORD' => ''],
            'mysql', 'mariadb' => ['DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_USERNAME' => 'root', 'DB_PASSWORD' => ''],
            default => [],
        };
        if ($defaults && empty($values['DB_DATABASE'])) {
            $defaults['DB_DATABASE'] = preg_replace('/[^A-Za-z0-9_]/', '_', $siteName) . '_testing';
        }

        foreach ($rows as $i => $row) {
            if (array_key_exists($row['name'], $defaults)) {
                if (($row['project'] ?? null) !== $defaults[$row['name']] || !empty($row['override'])) {
                    $rows[$i]['override'] = true;
                    $rows[$i]['value'] = $defaults[$row['name']];
                }
                unset($defaults[$row['name']]);
            }
        }
        foreach ($defaults as $name => $value) {
            $rows[] = ['name' => $name, 'project' => null, 'force' => false, 'override' => true, 'value' => $value];
        }

        return $rows;
    }

    public function databaseStatus(array $values): ?array
    {
        $connection = $values['DB_CONNECTION'] ?? null;
        $database = $values['DB_DATABASE'] ?? null;

        if (!in_array($connection, ['mysql', 'mariadb', 'pgsql'], true) || !$database || !preg_match(self::DATABASE_PATTERN, $database)) {
            return null;
        }

        $result = $connection === 'pgsql'
            ? Process::run('psql -h 127.0.0.1 -U postgres -tAc ' . escapeshellarg("SELECT 1 FROM pg_database WHERE datname = '{$database}'"))
            : Process::run('mariadb -h 127.0.0.1 -u root -N -e ' . escapeshellarg("SHOW DATABASES LIKE '" . str_replace('_', '\\_', $database) . "'"));

        return [
            'connection' => $connection,
            'database' => $database,
            'exists' => $result->successful() && trim($result->output()) !== '',
        ];
    }

    public function createDatabase(string $connection, string $database): void
    {
        if (!preg_match(self::DATABASE_PATTERN, $database)) {
            throw new \InvalidArgumentException('Invalid database name.');
        }

        if ($connection === 'pgsql') {
            Process::run('psql -h 127.0.0.1 -U postgres -c ' . escapeshellarg("CREATE DATABASE \"{$database}\""))->throw();
            return;
        }

        Process::run('mariadb -h 127.0.0.1 -u root -e ' . escapeshellarg("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"))->throw();
    }
}
