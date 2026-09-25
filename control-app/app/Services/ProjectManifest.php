<?php

namespace App\Services;

use App\Models\Site;
use App\Models\SiteProcess;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class ProjectManifest
{
    public const FILENAME = 'ldev.json';

    public const PHP_VERSIONS = ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'];

    public const DATABASE_DRIVERS = ['sqlite', 'mysql', 'pgsql', 'none'];

    public const SERVICES = [
        'mariadb' => 'mariadb-server',
        'postgresql' => 'postgresql-server',
        'valkey' => 'valkey',
        'memcached' => 'memcached',
        'nginx' => 'nginx',
    ];

    protected const KEYS = ['php', 'node', 'database', 's3', 'meilisearch', 'xdebug', 'queue', 'reverb', 'scheduler', 'jobs', 'requires'];

    protected const QUEUE_BOUNDS = [
        'workers' => ['queue_workers', 1, 20],
        'sleep' => ['queue_sleep', 0, 60],
        'tries' => ['queue_tries', 1, 20],
        'max_time' => ['queue_max_time', 60, 86400],
    ];

    public array $warnings = [];

    protected static ?array $installedVersions = null;

    public function path(string $projectPath): string
    {
        return rtrim($projectPath, '/') . '/' . self::FILENAME;
    }

    public function exists(string $projectPath): bool
    {
        return File::exists($this->path($projectPath));
    }

    public function read(string $projectPath): ?array
    {
        $this->warnings = [];
        $path = $this->path($projectPath);
        if (!File::exists($path)) {
            return null;
        }

        $data = json_decode(File::get($path), true);
        if (!is_array($data) || array_is_list($data) && $data !== []) {
            $this->warnings[] = self::FILENAME . ' is not a valid JSON object, so it was ignored.';
            return null;
        }

        return $this->normalise($data);
    }

    public function normalise(array $data): array
    {
        $out = [];

        foreach (array_diff(array_keys($data), self::KEYS) as $unknown) {
            $this->warnings[] = 'Unknown key "' . $unknown . '" ignored.';
        }

        if (array_key_exists('php', $data)) {
            $php = is_scalar($data['php']) ? (string) $data['php'] : '';
            if (in_array($php, self::PHP_VERSIONS, true)) {
                $out['php'] = $php;
            } else {
                $this->warnings[] = 'php: ' . $this->show($data['php']) . ' is not an installed version (' . implode(', ', self::PHP_VERSIONS) . ').';
            }
        }

        if (array_key_exists('node', $data)) {
            $node = is_scalar($data['node']) ? (string) $data['node'] : '';
            if (in_array($node, NodeVersionManager::KNOWN_VERSIONS, true)) {
                $out['node'] = $node;
            } else {
                $this->warnings[] = 'node: ' . $this->show($data['node']) . ' is not a known version (' . implode(', ', NodeVersionManager::KNOWN_VERSIONS) . ').';
            }
        }

        foreach (['s3', 'meilisearch', 'xdebug', 'reverb', 'scheduler'] as $flag) {
            if (array_key_exists($flag, $data)) {
                is_bool($data[$flag]) ? $out[$flag] = $data[$flag] : $this->warnings[] = "{$flag}: must be true or false.";
            }
        }

        if (array_key_exists('database', $data)) {
            $database = $this->normaliseDatabase($data['database']);
            if ($database) {
                $out['database'] = $database;
            }
        }

        if (array_key_exists('queue', $data)) {
            $queue = $this->normaliseQueue($data['queue']);
            if ($queue) {
                $out['queue'] = $queue;
            }
        }

        if (array_key_exists('jobs', $data)) {
            $out['jobs'] = $this->normaliseJobs($data['jobs']);
        }

        if (array_key_exists('requires', $data)) {
            $requires = $this->normaliseRequires($data['requires']);
            if ($requires) {
                $out['requires'] = $requires;
            }
        }

        return $out;
    }

    protected function normaliseDatabase(mixed $value): ?array
    {
        if (!is_array($value)) {
            $this->warnings[] = 'database: must be an object such as {"driver": "mysql"}.';
            return null;
        }

        $out = [];
        $driver = $value['driver'] ?? null;
        if (!in_array($driver, self::DATABASE_DRIVERS, true)) {
            $this->warnings[] = 'database.driver: must be one of ' . implode(', ', self::DATABASE_DRIVERS) . '.';
            return null;
        }
        $out['driver'] = $driver;

        if (isset($value['name'])) {
            if (!in_array($driver, ['mysql', 'pgsql'], true)) {
                $this->warnings[] = 'database.name: only used with mysql or pgsql, ignored.';
            } elseif (is_string($value['name']) && preg_match('/^[a-z0-9_]{1,64}$/', $value['name'])) {
                $out['name'] = $value['name'];
            } else {
                $this->warnings[] = 'database.name: lowercase letters, digits and underscores only (max 64).';
            }
        }

        if (array_key_exists('auto_backup', $value)) {
            is_bool($value['auto_backup']) ? $out['auto_backup'] = $value['auto_backup'] : $this->warnings[] = 'database.auto_backup: must be true or false.';
        }

        return $out;
    }

    protected function normaliseQueue(mixed $value): ?array
    {
        if (!is_array($value)) {
            $this->warnings[] = 'queue: must be an object such as {"enabled": true}.';
            return null;
        }

        $out = [];
        if (array_key_exists('enabled', $value)) {
            is_bool($value['enabled']) ? $out['enabled'] = $value['enabled'] : $this->warnings[] = 'queue.enabled: must be true or false.';
        }

        if (array_key_exists('queues', $value)) {
            if (is_string($value['queues']) && preg_match('/^[a-zA-Z0-9_,-]+$/', $value['queues'])) {
                $out['queues'] = $value['queues'];
            } else {
                $this->warnings[] = 'queue.queues: a comma-separated list of letters, digits, hyphens and underscores.';
            }
        }

        foreach (self::QUEUE_BOUNDS as $key => [, $min, $max]) {
            if (!array_key_exists($key, $value)) {
                continue;
            }
            if (is_int($value[$key]) && $value[$key] >= $min && $value[$key] <= $max) {
                $out[$key] = $value[$key];
            } else {
                $this->warnings[] = "queue.{$key}: a whole number from {$min} to {$max}.";
            }
        }

        return $out ?: null;
    }

    protected function normaliseJobs(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            $this->warnings[] = 'jobs: must be a list.';
            return [];
        }

        $jobs = [];
        foreach ($value as $i => $job) {
            $name = is_array($job) ? ($job['name'] ?? null) : null;
            $command = is_array($job) ? ($job['command'] ?? null) : null;
            $schedule = is_array($job) ? ($job['schedule'] ?? null) : null;

            if (!is_string($name) || !preg_match(SiteProcess::NAME_PATTERN, $name) || in_array($name, SiteProcess::RESERVED_NAMES, true)) {
                $this->warnings[] = "jobs[{$i}]: invalid or reserved name, skipped.";
                continue;
            }
            if (!is_string($command) || $command === '' || mb_strlen($command) > 500 || preg_match('/[\x00-\x1f\x7f]/', $command)) {
                $this->warnings[] = "jobs[{$i}] ({$name}): invalid command, skipped.";
                continue;
            }
            if ($schedule !== null && (!is_string($schedule) || !preg_match(SiteProcess::SCHEDULE_PATTERN, $schedule))) {
                $this->warnings[] = "jobs[{$i}] ({$name}): invalid cron schedule, skipped.";
                continue;
            }

            $jobs[$name] = [
                'name' => $name,
                'command' => $command,
                'schedule' => $schedule,
                'numprocs' => $this->boundedInt($job['numprocs'] ?? 1, 1, 20, 1),
                'stopwaitsecs' => $this->boundedInt($job['stopwaitsecs'] ?? 10, 1, 86400, 10),
                'autostart' => is_bool($job['autostart'] ?? null) ? $job['autostart'] : true,
                'autorestart' => is_bool($job['autorestart'] ?? null) ? $job['autorestart'] : true,
                'enabled' => is_bool($job['enabled'] ?? null) ? $job['enabled'] : true,
            ];
        }

        return array_values($jobs);
    }

    protected function normaliseRequires(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value) && $value !== []) {
            $this->warnings[] = 'requires: must be an object such as {"mariadb": ">=11.4"}.';
            return [];
        }

        $parser = new VersionParser;
        $out = [];
        foreach ($value as $service => $constraint) {
            if (!isset(self::SERVICES[$service])) {
                $this->warnings[] = 'requires: unknown service "' . $service . '" (known: ' . implode(', ', array_keys(self::SERVICES)) . ').';
                continue;
            }
            if (!is_string($constraint) || strlen($constraint) > 100) {
                $this->warnings[] = "requires.{$service}: must be a version constraint such as \">=11.4\".";
                continue;
            }
            try {
                $parser->parseConstraints($constraint);
                $out[$service] = $constraint;
            } catch (\Throwable $e) {
                $this->warnings[] = "requires.{$service}: \"{$constraint}\" is not a valid version constraint.";
            }
        }

        return $out;
    }

    public function fromSite(Site $site, ?array $existing = null): array
    {
        $manifest = ['php' => $site->php_version];

        if ($site->node_version && $site->usesNode()) {
            $manifest['node'] = $site->node_version;
        }

        $driver = $site->databaseType();
        if (in_array($driver, self::DATABASE_DRIVERS, true)) {
            $manifest['database'] = array_filter([
                'driver' => $driver,
                'name' => $site->databaseName(),
            ]) + ['auto_backup' => (bool) $site->db_auto_backup_enabled];
        }

        $manifest['s3'] = $site->usesS3();
        $manifest['meilisearch'] = $site->usesMeilisearch();
        $manifest['xdebug'] = (bool) $site->xdebug_enabled;
        $manifest['queue'] = [
            'enabled' => (bool) $site->queue_worker_enabled,
            'queues' => $site->queue_names ?: 'default',
            'workers' => (int) $site->queue_workers,
            'sleep' => (int) $site->queue_sleep,
            'tries' => (int) $site->queue_tries,
            'max_time' => (int) $site->queue_max_time,
        ];
        $manifest['reverb'] = (bool) $site->reverb_enabled;
        $manifest['scheduler'] = (bool) $site->scheduler_enabled;
        $manifest['jobs'] = $site->processes()->orderBy('name')->get()
            ->map(fn ($p) => [
                'name' => $p->name,
                'command' => $p->command,
                'schedule' => $p->schedule,
                'numprocs' => (int) $p->numprocs,
                'stopwaitsecs' => (int) $p->stopwaitsecs,
                'autostart' => (bool) $p->autostart,
                'autorestart' => (bool) $p->autorestart,
                'enabled' => (bool) $p->enabled,
            ])->all();

        if (!empty($existing['requires'])) {
            $manifest['requires'] = $existing['requires'];
        }

        return $manifest;
    }

    public function write(Site $site): string
    {
        $path = $this->path($site->projectRoot());
        $existing = $this->read($site->projectRoot());
        File::put($path, json_encode($this->fromSite($site, $existing), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        return $path;
    }

    public function setValues(string $projectPath, array $values): void
    {
        $path = $this->path($projectPath);
        $data = File::exists($path) ? json_decode(File::get($path), true) : [];
        if (!is_array($data)) {
            throw new \RuntimeException(self::FILENAME . ' is not valid JSON, so it was not changed.');
        }

        File::put($path, json_encode(array_merge($data, $values), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    public function differences(Site $site, array $manifest): array
    {
        $current = $this->flatten($this->fromSite($site));
        $wanted = $this->flatten(array_diff_key($manifest, ['requires' => true]));

        $diffs = [];
        foreach ($wanted as $key => $value) {
            $have = $current[$key] ?? null;
            if ($have !== $value) {
                $diffs[] = ['key' => $key, 'file' => $this->show($value), 'current' => $have === null ? 'not set' : $this->show($have)];
            }
        }

        return $diffs;
    }

    protected function flatten(array $manifest): array
    {
        $flat = [];
        foreach ($manifest as $key => $value) {
            if ($key === 'jobs') {
                foreach ($value as $job) {
                    $flat["jobs.{$job['name']}"] = trim(($job['schedule'] ? "[{$job['schedule']}] " : '') . $job['command'] . ($job['enabled'] ? '' : ' (disabled)'));
                }
            } elseif (is_array($value)) {
                foreach ($value as $sub => $subValue) {
                    $flat["{$key}.{$sub}"] = $subValue;
                }
            } else {
                $flat[$key] = $value;
            }
        }

        return $flat;
    }

    public function apply(Site $site, array $manifest, bool $includeDatabase = false): array
    {
        $applied = [];
        $warnings = [];
        $path = $site->projectRoot();

        if ($includeDatabase && isset($manifest['database']) && in_array($manifest['database']['driver'], ['sqlite', 'mysql', 'pgsql'], true)) {
            $driver = $manifest['database']['driver'];
            if ($site->databaseType() !== $driver || isset($manifest['database']['name']) && $site->databaseName() !== $manifest['database']['name']) {
                try {
                    (new DatabaseProvisioner)->provision($path, $driver, $manifest['database']['name'] ?? $site->name);
                    $applied[] = "database ({$driver})";
                } catch (\Throwable $e) {
                    $warnings[] = 'Database from ' . self::FILENAME . ' was not set up: ' . $e->getMessage();
                }
            }
        }

        $updates = [];
        if (isset($manifest['php']) && $manifest['php'] !== $site->php_version) {
            $updates['php_version'] = $manifest['php'];
        }
        foreach (['xdebug' => 'xdebug_enabled', 'scheduler' => 'scheduler_enabled'] as $key => $column) {
            if (isset($manifest[$key]) && $manifest[$key] !== (bool) $site->{$column}) {
                $updates[$column] = $manifest[$key];
            }
        }
        if (isset($manifest['database']['auto_backup']) && $manifest['database']['auto_backup'] !== (bool) $site->db_auto_backup_enabled) {
            $updates['db_auto_backup_enabled'] = $manifest['database']['auto_backup'];
        }
        if (isset($manifest['queue'])) {
            if (isset($manifest['queue']['enabled']) && $manifest['queue']['enabled'] !== (bool) $site->queue_worker_enabled) {
                $updates['queue_worker_enabled'] = $manifest['queue']['enabled'];
            }
            if (isset($manifest['queue']['queues']) && $manifest['queue']['queues'] !== $site->queue_names) {
                $updates['queue_names'] = $manifest['queue']['queues'];
            }
            foreach (self::QUEUE_BOUNDS as $key => [$column]) {
                if (isset($manifest['queue'][$key]) && $manifest['queue'][$key] !== (int) $site->{$column}) {
                    $updates[$column] = $manifest['queue'][$key];
                }
            }
        }
        if ($updates) {
            $site->update($updates);
            $applied = array_merge($applied, array_keys($updates));
        }

        if (($manifest['reverb'] ?? false) && !$site->reverb_enabled) {
            $site->update(['reverb_enabled' => true]);
            (new ReverbProvisioner)->provision($path, $site);
            $applied[] = 'reverb_enabled';
        } elseif (($manifest['reverb'] ?? null) === false && $site->reverb_enabled) {
            $site->update(['reverb_enabled' => false]);
            $applied[] = 'reverb_enabled';
        }

        if (($manifest['s3'] ?? false) && !$site->usesS3()) {
            try {
                (new S3Provisioner)->provision($path, $site->name);
                $applied[] = 's3';
            } catch (\Throwable $e) {
                $warnings[] = 'S3 bucket from ' . self::FILENAME . ' was not set up: ' . $e->getMessage();
            }
        }

        if (($manifest['meilisearch'] ?? null) === true && !$site->usesMeilisearch()) {
            (new MeilisearchProvisioner)->enable($path);
            $applied[] = 'meilisearch';
        } elseif (($manifest['meilisearch'] ?? null) === false && $site->usesMeilisearch()) {
            (new MeilisearchProvisioner)->disable($path);
            $applied[] = 'meilisearch';
        }

        if (!empty($manifest['jobs'])) {
            $jobWarning = $this->applyJobs($site, $manifest['jobs']);
            $jobWarning ? $warnings[] = $jobWarning : $applied[] = 'jobs';
        }

        if (isset($manifest['node']) && $manifest['node'] !== $site->node_version) {
            if ($site->usesNode()) {
                try {
                    (new NodeVersionManager)->npmInstallAndBuild($path, $manifest['node']);
                    $site->update(['node_version' => $manifest['node']]);
                    $applied[] = 'node_version';
                } catch (\Throwable $e) {
                    $warnings[] = "Node {$manifest['node']} from " . self::FILENAME . ' was not applied: ' . $e->getMessage();
                }
            } else {
                $site->update(['node_version' => $manifest['node']]);
                $applied[] = 'node_version';
            }
        }

        (new NginxConfigGenerator)->generate($site);
        (new SupervisorConfigGenerator)->generate($site);

        return [$applied, $warnings];
    }

    protected function applyJobs(Site $site, array $jobs): ?string
    {
        $before = $site->processes()->get()->keyBy('name');

        foreach ($jobs as $job) {
            $site->processes()->updateOrCreate(['name' => $job['name']], array_diff_key($job, ['name' => true]));
        }
        $site->unsetRelation('processes');

        if ($problem = (new SupervisorConfigGenerator)->check($site)) {
            foreach ($jobs as $job) {
                $previous = $before->get($job['name']);
                $previous
                    ? $site->processes()->where('name', $job['name'])->update($previous->only(['command', 'schedule', 'numprocs', 'stopwaitsecs', 'autostart', 'autorestart', 'enabled']))
                    : $site->processes()->where('name', $job['name'])->delete();
            }
            $site->unsetRelation('processes');

            return 'Jobs from ' . self::FILENAME . " were not applied: {$problem}";
        }

        return null;
    }

    public function checkRequirements(array $requires): array
    {
        if (!$requires) {
            return [];
        }

        $installed = $this->installedVersions();

        $results = [];
        foreach ($requires as $service => $constraint) {
            $version = $installed[self::SERVICES[$service]] ?? null;
            $results[] = [
                'service' => $service,
                'constraint' => $constraint,
                'installed' => $version,
                'ok' => $version !== null && Semver::satisfies($version, $constraint),
            ];
        }

        return $results;
    }

    protected function installedVersions(): array
    {
        if (self::$installedVersions !== null) {
            return self::$installedVersions;
        }

        $output = Process::run('rpm -q --qf ' . escapeshellarg('%{NAME} %{VERSION}\n') . ' ' . implode(' ', array_map('escapeshellarg', self::SERVICES)))->output();

        $installed = [];
        foreach (explode("\n", trim($output)) as $line) {
            $parts = explode(' ', trim($line));
            if (count($parts) === 2 && preg_match('/^\d+(\.\d+)*$/', $parts[1])) {
                $installed[$parts[0]] = $parts[1];
            }
        }

        return self::$installedVersions = $installed;
    }

    public function unmetRequirements(Site $site): array
    {
        $manifest = $this->read($site->projectRoot());

        return array_values(array_filter($this->checkRequirements($manifest['requires'] ?? []), fn ($r) => !$r['ok']));
    }

    protected function boundedInt(mixed $value, int $min, int $max, int $default): int
    {
        return is_int($value) && $value >= $min && $value <= $max ? $value : $default;
    }

    protected function show(mixed $value): string
    {
        return is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES);
    }
}
