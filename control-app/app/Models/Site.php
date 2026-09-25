<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class Site extends Model
{

    const NAME_PATTERN = '/^[a-z0-9][a-z0-9-]*$/';

    public static function isValidName(string $name): bool
    {
        return (bool) preg_match(self::NAME_PATTERN, $name);
    }

    protected static function booted(): void
    {
        static::deleting(function (Site $site) {
            Setting::whereIn('key', [
                \App\Services\DependencyHealthChecker::settingKey($site),
                \App\Services\DependencySandbox::statusKey($site),
                \App\Services\TestRunner::statusKey($site),
                \App\Services\TestRunner::historyKey($site),
                \App\Services\TestEnvironment::settingKey($site),
            ])->delete();

            ComposerCredential::where('site_id', $site->id)->delete();

            $sandbox = \App\Services\DependencySandbox::dir($site);
            File::deleteDirectory($sandbox);
            File::deleteDirectory($sandbox . '-baseline');
            File::delete([
                config('ldev.dependency_sandbox_dir') . '/' . $site->name . '.log',
                config('ldev.test_runs_dir') . '/' . $site->name . '.log',
                config('ldev.dumps_dir') . '/' . $site->name . '.jsonl',
            ]);
        });
    }

    protected $fillable = [
        'name',
        'domain',
        'document_root',
        'php_version',
        'node_version',
        'xdebug_enabled',
        'environment_variables',
        'custom_nginx_config',
        'is_linked',
        'is_parked',
        'queue_worker_enabled',
        'queue_workers',
        'queue_sleep',
        'queue_tries',
        'queue_max_time',
        'queue_names',
        'reverb_enabled',
        'scheduler_enabled',
        'db_auto_backup_enabled',
        'supervisor_extra',
    ];

    protected $casts = [
        'environment_variables' => 'array',
        'xdebug_enabled' => 'boolean',
        'is_linked' => 'boolean',
        'is_parked' => 'boolean',
        'queue_worker_enabled' => 'boolean',
        'reverb_enabled' => 'boolean',
        'scheduler_enabled' => 'boolean',
        'db_auto_backup_enabled' => 'boolean',
    ];

    public function processes()
    {
        return $this->hasMany(SiteProcess::class)->orderBy('name');
    }

    public function getReverbPortAttribute(): int
    {
        $port = 8080 + $this->id;

        return $port === 8090 ? $port + 1 : $port;
    }

    public function projectRoot(): string
    {
        return Str::endsWith($this->document_root, '/public')
            ? Str::beforeLast($this->document_root, '/public')
            : $this->document_root;
    }

    public function databaseType(): ?string
    {
        $envPath = $this->projectRoot() . '/.env';
        if (!File::exists($envPath)) {
            return null;
        }

        if (preg_match('/^DB_CONNECTION=(.*)$/m', File::get($envPath), $matches)) {
            return trim($matches[1]) ?: null;
        }

        return null;
    }

    public function databaseName(): ?string
    {
        $envPath = $this->projectRoot() . '/.env';
        if (!File::exists($envPath) || !in_array($this->databaseType(), ['mysql', 'pgsql'], true)) {
            return null;
        }

        return preg_match('/^DB_DATABASE=(.*)$/m', File::get($envPath), $m) ? (trim($m[1], " \t\"'") ?: null) : null;
    }

    public function usesNode(): bool
    {
        return File::exists($this->projectRoot() . '/package.json');
    }

    public function usesS3(): bool
    {
        $envPath = $this->projectRoot() . '/.env';
        if (!File::exists($envPath)) {
            return false;
        }

        return (bool) preg_match('/^FILESYSTEM_DISK=s3\s*$/m', File::get($envPath));
    }

    public function usesMeilisearch(): bool
    {
        $envPath = $this->projectRoot() . '/.env';

        return File::exists($envPath) && (bool) preg_match('/^SCOUT_DRIVER=meilisearch\s*$/m', File::get($envPath));
    }

    public function subdomainWildcardName(): ?string
    {
        $routesDir = $this->projectRoot() . '/routes';
        if (!File::isDirectory($routesDir)) {
            return null;
        }

        foreach (File::files($routesDir) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            if (preg_match('/Route::domain\(\s*[\'"]\{(\w+)\}\./', File::get($file->getPathname()), $m)) {
                return $m[1];
            }
        }

        return null;
    }

    public function frameworkInfo(): ?array
    {
        $root = $this->projectRoot();

        $laravelFile = $root . '/vendor/laravel/framework/src/Illuminate/Foundation/Application.php';
        if (File::exists($laravelFile) && preg_match("/const VERSION = '([^']+)'/", File::get($laravelFile), $m)) {
            return ['name' => 'Laravel', 'version' => $m[1]];
        }

        $wpFile = $root . '/wp-includes/version.php';
        if (File::exists($wpFile) && preg_match('/\$wp_version\s*=\s*\'([^\']+)\'/', File::get($wpFile), $m)) {
            return ['name' => 'WordPress', 'version' => $m[1]];
        }

        return null;
    }

    public function isReachable(): bool
    {
        $result = Process::run(
            'curl -sk -o /dev/null -w "%{http_code}" --max-time 3 --resolve '
            . escapeshellarg("{$this->domain}:443:127.0.0.1") . ' '
            . escapeshellarg("https://{$this->domain}/")
        );
        $code = (int) trim($result->output());

        return $code > 0 && $code < 500;
    }

    public function diskUsageHuman(): ?string
    {
        $result = Process::run('du -sh ' . escapeshellarg($this->projectRoot()) . ' 2>/dev/null');
        if (!$result->successful()) {
            return null;
        }

        [$size] = preg_split('/\s+/', trim($result->output()));
        return $size ?: null;
    }

    public function fpmPoolStats(): array
    {
        $unit = 'php' . str_replace('.', '', $this->php_version) . '-php-fpm';
        $masterPid = trim(Process::run('systemctl show ' . escapeshellarg($unit) . ' -p MainPID --value')->output());

        if (!$masterPid || $masterPid === '0') {
            return ['running' => false];
        }

        $childPids = array_filter(explode("\n", trim(Process::run('pgrep -P ' . escapeshellarg($masterPid))->output())));
        if (empty($childPids)) {
            return ['running' => true, 'workers' => 0, 'memoryMb' => 0.0];
        }

        $rssKb = array_sum(array_map(
            'intval',
            array_filter(explode("\n", trim(Process::run('ps -o rss= -p ' . implode(',', $childPids))->output())))
        ));

        return ['running' => true, 'workers' => count($childPids), 'memoryMb' => round($rssKb / 1024, 1)];
    }

    public function backgroundProcessStatus(string $program): array
    {
        $name = "{$this->name}-{$program}";

        $result = $this->querySupervisorctl($name);
        if (!$result['reachable']) {
            return ['reachable' => false];
        }

        if (($result['lines'][0] ?? '') && str_contains($result['lines'][0], 'no such process')) {
            $result = $this->querySupervisorctl("{$name}:*");
        }

        if (!$result['reachable'] || empty($result['lines']) || str_contains($result['lines'][0], 'no such process')) {
            return ['reachable' => $result['reachable'], 'running' => false];
        }

        [, $status, $detail] = array_pad(preg_split('/\s+/', $result['lines'][0], 3), 3, '');

        $extra = count($result['lines']) > 1 ? ' (+' . (count($result['lines']) - 1) . ' more)' : '';

        return [
            'reachable' => true,
            'running' => strtoupper($status) === 'RUNNING',
            'status' => $status,
            'detail' => trim($detail) . $extra,
        ];
    }

    protected function querySupervisorctl(string $target): array
    {
        $result = Process::run('supervisorctl -s http://127.0.0.1:9002 status ' . escapeshellarg($target));
        $output = trim($result->output()) ?: trim($result->errorOutput());

        if ($output === '' || str_contains($output, 'refused') || str_contains($output, 'Error reading') || str_contains(strtolower($output), 'socket')) {
            return ['reachable' => false, 'lines' => []];
        }

        return ['reachable' => true, 'lines' => array_values(array_filter(explode("\n", $output)))];
    }

    public function queueJobCounts(): ?array
    {
        if (($this->frameworkInfo()['name'] ?? null) !== 'Laravel') {
            return null;
        }

        $script = <<<'PHP'
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$pending = $failed = null;
try { $pending = \Illuminate\Support\Facades\DB::table("jobs")->count(); } catch (\Throwable $e) {}
try { $failed = \Illuminate\Support\Facades\DB::table("failed_jobs")->count(); } catch (\Throwable $e) {}
echo json_encode(["pending" => $pending, "failed" => $failed]);
PHP;

        $result = Process::inProject($this->projectRoot())->run('php -r ' . escapeshellarg($script));
        $decoded = json_decode(trim($result->output()), true);

        return is_array($decoded) ? $decoded : null;
    }

    public function queueJobs(): ?array
    {
        if (($this->frameworkInfo()['name'] ?? null) !== 'Laravel') {
            return null;
        }

        $script = <<<'PHP'
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$limit = 50;
$out = ["pendingCount" => null, "failedCount" => null, "pending" => [], "failed" => []];
try {
    $out["pendingCount"] = \Illuminate\Support\Facades\DB::table("jobs")->count();
    $out["pending"] = \Illuminate\Support\Facades\DB::table("jobs")->orderBy("id")->limit($limit)->get()->map(function ($row) {
        $payload = json_decode($row->payload, true);
        return [
            "id" => $row->id,
            "queue" => $row->queue,
            "attempts" => $row->attempts,
            "job" => $payload["displayName"] ?? null,
            "created_at" => date("Y-m-d H:i:s", $row->created_at),
            "available_at" => date("Y-m-d H:i:s", $row->available_at),
        ];
    })->all();
} catch (\Throwable $e) {}
try {
    $out["failedCount"] = \Illuminate\Support\Facades\DB::table("failed_jobs")->count();
    $out["failed"] = \Illuminate\Support\Facades\DB::table("failed_jobs")->orderByDesc("failed_at")->limit($limit)->get()->map(function ($row) {
        $payload = json_decode($row->payload, true);
        return [
            "id" => $row->id,
            "uuid" => $row->uuid,
            "connection" => $row->connection,
            "queue" => $row->queue,
            "job" => $payload["displayName"] ?? null,
            "exception" => strtok((string) $row->exception, "\n"),
            "failed_at" => $row->failed_at,
        ];
    })->all();
} catch (\Throwable $e) {}
echo json_encode($out);
PHP;

        $result = Process::inProject($this->projectRoot())->run('php -r ' . escapeshellarg($script));
        $decoded = json_decode(trim($result->output()), true);

        return is_array($decoded) ? $decoded : null;
    }

    public function failedJobException(string $uuid): ?string
    {
        if (($this->frameworkInfo()['name'] ?? null) !== 'Laravel') {
            return null;
        }

        $uuidLiteral = var_export($uuid, true);

        $script = <<<PHP
require "vendor/autoload.php";
\$app = require "bootstrap/app.php";
\$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
\$row = \\Illuminate\\Support\\Facades\\DB::table("failed_jobs")->where("uuid", {$uuidLiteral})->first();
echo \$row ? \$row->exception : "";
PHP;

        $result = Process::inProject($this->projectRoot())->run('php -r ' . escapeshellarg($script));

        return $result->successful() && $result->output() !== '' ? $result->output() : null;
    }

    public function logFiles(int $limit = 25): array
    {
        $logsRoot = $this->projectRoot() . '/storage/logs';
        if (!File::isDirectory($logsRoot)) {
            return ['files' => [], 'truncated' => 0];
        }

        $all = collect(File::allFiles($logsRoot))
            ->filter(fn ($f) => $f->getExtension() === 'log')
            ->sortByDesc(fn ($f) => $f->getMTime())
            ->values();

        $shown = $all->take($limit);
        $files = [];
        foreach ($shown as $file) {
            $relative = ltrim(substr($file->getPathname(), strlen($logsRoot)), '/');
            $files[$file->getPathname()] = $relative;
        }

        return ['files' => $files, 'truncated' => $all->count() - $shown->count()];
    }

    public function gitStatus(): array
    {
        $path = $this->projectRoot();

        if (!File::isDirectory($path . '/.git')) {
            return ['hasRepo' => false];
        }

        $branch = trim(Process::inProject($path)->run('git rev-parse --abbrev-ref HEAD')->output());
        $dirty = trim(Process::inProject($path)->run('git status --porcelain')->output()) !== '';
        $hasUpstream = Process::inProject($path)->run('git rev-parse --abbrev-ref --symbolic-full-name @{u}')->successful();

        $ahead = 0;
        $behind = 0;
        if ($hasUpstream) {
            $counts = trim(Process::inProject($path)->run('git rev-list --left-right --count HEAD...@{u}')->output());
            [$ahead, $behind] = array_pad(array_map('intval', preg_split('/\s+/', $counts)), 2, 0);
        }

        return [
            'hasRepo' => true,
            'branch' => $branch !== '' ? $branch : null,
            'dirty' => $dirty,
            'hasUpstream' => $hasUpstream,
            'ahead' => $ahead,
            'behind' => $behind,

            'outOfSync' => $dirty || $ahead > 0 || $behind > 0,
        ];
    }
}
