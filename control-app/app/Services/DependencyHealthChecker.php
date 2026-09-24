<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Site;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class DependencyHealthChecker
{
    public static function cached(Site $site): ?array
    {
        $raw = Setting::get(self::settingKey($site));

        return $raw ? json_decode($raw, true) : null;
    }

    public static function settingKey(Site $site): string
    {
        return 'deps_site_' . $site->id;
    }

    public function check(Site $site): array
    {
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

        if ($result['hasComposer']) {
            $this->checkComposer($root, $result);
        }

        if ($result['hasNpm']) {
            $this->checkNpm($site, $root, $result);
        }

        Setting::set(self::settingKey($site), json_encode($result));

        return $result;
    }

    protected function checkComposer(string $root, array &$result): void
    {
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

        $outdated = Process::inProject($root)->timeout(120)->run('composer outdated --direct --format=json --no-interaction');
        $data = json_decode($outdated->output(), true);
        if (is_array($data) && array_key_exists('installed', $data)) {
            foreach ((array) $data['installed'] as $pkg) {
                $result['composerOutdated'][] = [
                    'name' => $pkg['name'] ?? '',
                    'current' => ltrim($pkg['version'] ?? '', 'v'),
                    'latest' => ltrim($pkg['latest'] ?? '', 'v'),
                    'major' => ($pkg['latest-status'] ?? '') === 'update-possible',
                ];
            }
        } else {
            $result['errors'][] = 'composer outdated: ' . $this->firstLine($outdated->errorOutput() ?: $outdated->output());
        }
    }

    protected function checkNpm(Site $site, string $root, array &$result): void
    {
        $npm = self::npmProcess($site, $root)->timeout(120)->run('npm outdated --json');
        $data = json_decode($npm->output() ?: '{}', true);
        if (!is_array($data) || isset($data['error'])) {
            $result['errors'][] = 'npm outdated: ' . $this->firstLine($data['error']['summary'] ?? $npm->errorOutput());
            return;
        }

        $this->auditNpm($site, $root, $result);

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
            ];
        }
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

    public static function outdatedRow(?array $cached, string $manager, string $name): ?array
    {
        $key = $manager === 'npm' ? 'npmOutdated' : 'composerOutdated';

        return collect($cached[$key] ?? [])->firstWhere('name', $name);
    }

    public static function npmAdvisories(array $audit): ?array
    {
        if (!isset($audit['vulnerabilities']) || !is_array($audit['vulnerabilities'])) {
            return null;
        }

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
                ];
            }
        }

        return $rows;
    }

    protected function auditNpm(Site $site, string $root, array &$result): void
    {
        $audit = self::npmProcess($site, $root)->timeout(120)->run('npm audit --json');
        $rows = self::npmAdvisories(json_decode($audit->output() ?: '{}', true) ?: []);
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
