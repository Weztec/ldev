<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Site;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class DependencyHealthChecker
{
    protected $progress = null;
    protected int $done = 0;
    protected int $total = 0;

    public static function checkKey(Site $site): string
    {
        return 'deps_check_site_' . $site->id;
    }

    public static function checkStatus(Site $site): ?array
    {
        $raw = Setting::get(self::checkKey($site));
        $status = $raw ? json_decode($raw, true) : null;
        if (is_array($status) && $status['state'] === 'running') {
            $dead = !empty($status['pid'])
                ? !File::exists('/proc/' . (int) $status['pid'])
                : now()->diffInSeconds(\Illuminate\Support\Carbon::parse($status['startedAt'] ?? now())) > 60;
            if ($dead) {
                $status['state'] = 'interrupted';
            }
        }

        return $status;
    }

    public static function checkLog(Site $site): string
    {
        return config('ldev.dependency_sandbox_dir') . '/' . $site->name . '-check.log';
    }

    public static function launch(Site $site): void
    {
        if ((self::checkStatus($site)['state'] ?? null) === 'running') {
            return;
        }

        Setting::set(self::checkKey($site), json_encode([
            'state' => 'running',
            'pid' => null,
            'startedAt' => now()->toIso8601String(),
            'progress' => ['done' => 0, 'total' => 0, 'label' => 'Starting'],
        ]));

        File::ensureDirectoryExists(config('ldev.dependency_sandbox_dir'));
        Process::path(base_path())->run('bash -c ' . escapeshellarg(
            'setsid nohup ' . escapeshellarg(\Illuminate\Support\php_binary()) . ' artisan ldev:check-dependencies ' . escapeshellarg((string) $site->id)
            . ' > ' . escapeshellarg(self::checkLog($site)) . ' 2>&1 < /dev/null &'
        ));
    }

    protected function progress(string $label, int $addTotal = 0, bool $finished = false): void
    {
        $this->total += $addTotal;
        if ($finished) {
            $this->done++;
        }
        if ($this->progress) {
            ($this->progress)(['done' => min($this->done, $this->total), 'total' => max($this->total, 1), 'label' => $label]);
        }
    }
    public static function cached(Site $site): ?array
    {
        $raw = Setting::get(self::settingKey($site));

        return $raw ? json_decode($raw, true) : null;
    }

    public static function settingKey(Site $site): string
    {
        return 'deps_site_' . $site->id;
    }

    public function check(Site $site, ?callable $progress = null): array
    {
        $this->progress = $progress;
        $this->done = 0;
        $this->total = 0;
        $root = $site->projectRoot();
        $result = [
            'checkedAt' => now()->toIso8601String(),
            'advisories' => [],
            'composerOutdated' => [],
            'npmOutdated' => [],
            'errors' => [],
            'hasComposer' => File::exists($root . '/composer.lock'),
            'hasNpm' => File::exists($root . '/package-lock.json') && File::isDirectory($root . '/node_modules'),
        ];

        $advisor = new DependencyAdvisor($site, $root);
        $this->progress('Starting', ($result['hasComposer'] ? 2 : 0) + ($result['hasNpm'] ? 2 : 0));

        if ($result['hasComposer']) {
            $this->checkComposer($root, $result, $advisor);
        }

        if ($result['hasNpm']) {
            $this->checkNpm($site, $root, $result, $advisor);
        }

        Setting::set(self::settingKey($site), json_encode($result));

        return $result;
    }

    protected function checkComposer(string $root, array &$result, DependencyAdvisor $advisor): void
    {
        $this->progress('composer audit');
        $audit = Process::inProject($root)->timeout(120)->run('composer audit --format=json --locked --no-interaction');
        $data = json_decode($audit->output(), true);
        if (is_array($data) && array_key_exists('advisories', $data)) {
            foreach ((array) $data['advisories'] as $package => $items) {
                foreach ((array) $items as $item) {
                    $result['advisories'][] = [
                        'package' => $item['packageName'] ?? $package,
                        'title' => $item['title'] ?? '',
                        'cve' => $item['cve'] ?? null,
                        'severity' => $item['severity'] ?? null,
                        'affected' => $item['affectedVersions'] ?? null,
                        'link' => $item['link'] ?? null,
                        'manager' => 'composer',
                    ];
                }
            }
        } else {
            $result['errors'][] = 'composer audit: ' . $this->firstLine($audit->errorOutput() ?: $audit->output());
        }

        $this->progress('composer outdated', 0, true);
        $outdated = Process::inProject($root)->timeout(120)->run('composer outdated --format=json --no-interaction');
        $data = json_decode($outdated->output(), true);
        if (is_array($data) && array_key_exists('installed', $data)) {
            $advisor->useComposerOutdated((array) $data['installed']);
            $manifest = json_decode((string) @file_get_contents($root . '/composer.json'), true) ?: [];
            foreach ((array) $data['installed'] as $pkg) {
                if (empty($pkg['direct-dependency'])) {
                    continue;
                }
                $result['composerOutdated'][] = [
                    'name' => $pkg['name'] ?? '',
                    'current' => ltrim($pkg['version'] ?? '', 'v'),
                    'latest' => ltrim($pkg['latest'] ?? '', 'v'),
                    'major' => ($pkg['latest-status'] ?? '') === 'update-possible',
                    'range' => $manifest['require'][$pkg['name'] ?? ''] ?? $manifest['require-dev'][$pkg['name'] ?? ''] ?? null,
                ];
            }
        } else {
            $result['errors'][] = 'composer outdated: ' . $this->firstLine($outdated->errorOutput() ?: $outdated->output());
            $this->progress('composer outdated', 0, true);
            return;
        }
        $this->progress('composer outdated', 0, true);

        $this->advise($result, 'composer advice', function () use ($advisor, &$result) {
            $packages = collect($result['advisories'])->where('manager', 'composer')->pluck('package')->unique()->count();
            $this->progress('Working out what blocks each update', count($result['composerOutdated']) + $packages);
            foreach ($result['composerOutdated'] as $i => $row) {
                $this->progress('Checking ' . $row['name']);
                $result['composerOutdated'][$i]['advice'] = $advisor->composerRow($row);
                $this->progress('Checking ' . $row['name'], 0, true);
            }

            $affected = [];
            foreach ($result['advisories'] as $row) {
                if ($row['manager'] === 'composer') {
                    $affected[$row['package']][] = $row['affected'];
                }
            }
            $advice = [];
            foreach ($affected as $package => $ranges) {
                $this->progress('Checking the fix for ' . $package);
                $advice[$package] = $advisor->composerAdvisory($package, $ranges);
                $this->progress('Checking the fix for ' . $package, 0, true);
            }
            foreach ($result['advisories'] as $i => $row) {
                if (isset($advice[$row['package']]) && $row['manager'] === 'composer') {
                    $result['advisories'][$i]['advice'] = $advice[$row['package']];
                }
            }
        });
    }

    protected function advise(array &$result, string $label, callable $run): void
    {
        try {
            $run();
        } catch (\Throwable $e) {
            $result['errors'][] = $label . ': ' . $this->firstLine($e->getMessage());
        }
    }

    protected function checkNpm(Site $site, string $root, array &$result, DependencyAdvisor $advisor): void
    {
        $this->progress('npm outdated');
        $npm = self::npmProcess($site, $root)->timeout(120)->run('npm outdated --json');
        $this->progress('npm audit', 0, true);
        $data = json_decode($npm->output() ?: '{}', true);
        if (!is_array($data) || isset($data['error'])) {
            $result['errors'][] = 'npm outdated: ' . $this->firstLine($data['error']['summary'] ?? $npm->errorOutput());
            return;
        }

        $this->auditNpm($site, $root, $result);

        $manifest = json_decode((string) @file_get_contents($root . '/package.json'), true) ?: [];
        foreach ($data as $name => $pkg) {
            if (!is_array($pkg) || !isset($pkg['latest'])) {
                continue;
            }
            $current = $pkg['current'] ?? null;
            $result['npmOutdated'][] = [
                'name' => $name,
                'current' => $current,
                'wanted' => $pkg['wanted'] ?? null,
                'latest' => $pkg['latest'],
                'major' => $current && explode('.', $current)[0] !== explode('.', $pkg['latest'])[0],
                'range' => $manifest['dependencies'][$name] ?? $manifest['devDependencies'][$name] ?? $manifest['optionalDependencies'][$name] ?? null,
            ];
        }

        $this->progress('Working out what blocks each update', count($result['npmOutdated']), true);
        $this->advise($result, 'npm advice', function () use ($advisor, &$result) {
            $advisor->useNpmLock();
            foreach ($result['npmOutdated'] as $i => $row) {
                $this->progress('Checking ' . $row['name']);
                $result['npmOutdated'][$i]['advice'] = $advisor->npmRow($row);
                $this->progress('Checking ' . $row['name'], 0, true);
            }
        });
    }

    public const SECTIONS = ['security', 'npm-security', 'composer', 'npm'];

    public static function manager(string $section): string
    {
        return in_array($section, ['npm', 'npm-security'], true) ? 'npm' : 'composer';
    }

    public static function updatablePackages(?array $cached, string $section): array
    {
        $key = ['security' => 'advisories', 'npm-security' => 'advisories', 'composer' => 'composerOutdated', 'npm' => 'npmOutdated'][$section] ?? null;
        if (!$cached || !$key) {
            return [];
        }

        $rows = $cached[$key] ?? [];
        if (in_array($section, ['security', 'npm-security'], true)) {
            $manager = self::manager($section);
            $rows = array_filter($rows, fn ($row) => ($row['manager'] ?? 'composer') === $manager);
        }

        $field = in_array($section, ['security', 'npm-security'], true) ? 'package' : 'name';

        return array_values(array_unique(array_filter(array_column($rows, $field))));
    }

    public static function outsideRange(array $row, string $manager): bool
    {
        return $manager === 'npm'
            ? ($row['latest'] ?? null) !== ($row['wanted'] ?? $row['current'] ?? null)
            : (bool) ($row['major'] ?? false);
    }

    public static function inRange(array $row, string $manager): bool
    {
        return $manager === 'npm'
            ? ($row['wanted'] ?? null) !== null && $row['wanted'] !== ($row['current'] ?? null)
            : !($row['major'] ?? false);
    }

    public static function outdatedRow(?array $cached, string $manager, string $name): ?array
    {
        $key = $manager === 'npm' ? 'npmOutdated' : 'composerOutdated';

        return collect($cached[$key] ?? [])->firstWhere('name', $name);
    }

    public static function npmAdvisories(array $audit, ?string $root = null): ?array
    {
        if (!isset($audit['vulnerabilities']) || !is_array($audit['vulnerabilities'])) {
            return null;
        }
        $read = fn (string $file) => $root !== null && is_file($root . '/' . $file) ? (json_decode((string) file_get_contents($root . '/' . $file), true) ?: []) : [];
        $lock = $read('package-lock.json');
        $manifest = $read('package.json');

        $rows = [];
        foreach ($audit['vulnerabilities'] as $name => $vulnerability) {
            $via = (array) ($vulnerability['via'] ?? []);
            $sources = array_filter($via, 'is_array');
            if (!$sources) {
                $through = implode(', ', array_filter($via, 'is_string'));
                $rows[] = [
                    'package' => $name,
                    'title' => 'Vulnerable because it depends on ' . ($through ?: 'a vulnerable package'),
                    'cve' => null,
                    'severity' => $vulnerability['severity'] ?? null,
                    'affected' => $vulnerability['range'] ?? null,
                    'link' => null,
                    'manager' => 'npm',
                    'advice' => DependencyAdvisor::npmAdvisory($vulnerability, $name, $lock, $manifest),
                ];
                continue;
            }
            foreach ($sources as $source) {
                $url = $source['url'] ?? null;
                $rows[] = [
                    'package' => $name,
                    'title' => $source['title'] ?? '',
                    'cve' => $url && preg_match('/GHSA-[\w-]+/', $url, $m) ? $m[0] : null,
                    'severity' => $source['severity'] ?? ($vulnerability['severity'] ?? null),
                    'affected' => $source['range'] ?? ($vulnerability['range'] ?? null),
                    'link' => $url,
                    'manager' => 'npm',
                    'advice' => DependencyAdvisor::npmAdvisory($vulnerability, $name, $lock, $manifest),
                ];
            }
        }

        return $rows;
    }

    protected function auditNpm(Site $site, string $root, array &$result): void
    {
        $audit = self::npmProcess($site, $root)->timeout(120)->run('npm audit --json');
        $rows = self::npmAdvisories(json_decode($audit->output() ?: '{}', true) ?: [], $root);
        if ($rows === null) {
            $result['errors'][] = 'npm audit: ' . $this->firstLine($audit->errorOutput() ?: $audit->output());
            return;
        }

        array_push($result['advisories'], ...$rows);
    }

    public static function npmProcess(Site $site, string $root)
    {
        $path = getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin';
        if ($site->node_version) {
            $manager = new NodeVersionManager;
            $resolved = $manager->resolveInstalledDir($site->node_version);
            if ($resolved) {
                $path = $manager->binPath($resolved) . ':' . $path;
            }
        }

        return Process::path($root)->env(array_merge(
            AppServiceProvider::dashboardEnvKeysToClear(),
            ['HOME' => config('ldev.home'), 'PATH' => $path]
        ));
    }

    protected function firstLine(string $text): string
    {
        $line = trim(strtok(trim($text), "\n") ?: '');

        return $line === '' ? 'no output' : mb_strimwidth($line, 0, 200, '…');
    }
}
