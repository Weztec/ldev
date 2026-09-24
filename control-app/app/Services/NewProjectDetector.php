<?php

namespace App\Services;

use App\Models\Site;
use App\Models\SiteProcess;
use App\Models\Setting;

class NewProjectDetector
{

    public function findCandidates(): array
    {
        $sitesPath = config('ldev.sites_path');
        if (!is_dir($sitesPath)) {
            return [];
        }

        $existingNames = Site::pluck('name')->all();
        $candidates = [];

        foreach (scandir($sitesPath) as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir("$sitesPath/$entry")) {
                continue;
            }

            if (in_array($entry, $existingNames, true) || !Site::isValidName($entry)) {
                continue;
            }

            $candidates[] = $entry;
        }

        sort($candidates);

        return $candidates;
    }

    protected const KNOWN_PHP_VERSIONS = ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'];

    public function resolveConfig(string $name): array
    {
        $backup = (new SiteConfigBackup)->restore($name);

        return [
            'php_version' => $backup['php_version'] ?? $this->defaultPhpVersion(),
            'node_version' => $backup['node_version'] ?? null,
            'xdebug_enabled' => $backup['xdebug_enabled'] ?? false,
            'queue_worker_enabled' => $backup['queue_worker_enabled'] ?? false,
            'queue_workers' => $backup['queue_workers'] ?? 1,
            'queue_sleep' => $backup['queue_sleep'] ?? 3,
            'queue_tries' => $backup['queue_tries'] ?? 3,
            'queue_max_time' => $backup['queue_max_time'] ?? 3600,
            'reverb_enabled' => $backup['reverb_enabled'] ?? false,
            'supervisor_extra' => $backup['supervisor_extra'] ?? null,
            'processes' => $backup['processes'] ?? [],
            'from_backup' => $backup !== null,
        ];
    }

    public function link(string $name, bool $autoApplyFeatures = true, array $overrides = []): array
    {
        $sitesPath = config('ldev.sites_path');
        $projectPath = "$sitesPath/$name";
        $documentRoot = is_dir("$projectPath/public") ? "$projectPath/public" : $projectPath;

        $configBackup = new SiteConfigBackup;
        $hadBackup = $configBackup->restore($name) !== null;
        $resolved = array_merge($this->resolveConfig($name), $overrides);

        $phpVersion = in_array($resolved['php_version'], self::KNOWN_PHP_VERSIONS, true)
            ? $resolved['php_version']
            : $this->defaultPhpVersion();
        $nodeVersion = in_array($resolved['node_version'], NodeVersionManager::KNOWN_VERSIONS, true)
            ? $resolved['node_version']
            : null;

        $site = Site::create([
            'name' => $name,
            'domain' => "$name.test",
            'document_root' => $documentRoot,

            'php_version' => $phpVersion,
            'node_version' => $nodeVersion,
            'xdebug_enabled' => (bool) $resolved['xdebug_enabled'],
            'queue_worker_enabled' => (bool) $resolved['queue_worker_enabled'],
            'queue_workers' => max(1, min(20, (int) $resolved['queue_workers'])),
            'queue_sleep' => max(0, min(60, (int) $resolved['queue_sleep'])),
            'queue_tries' => max(1, min(20, (int) $resolved['queue_tries'])),
            'queue_max_time' => max(60, min(86400, (int) $resolved['queue_max_time'])),

            'is_linked' => true,
        ]);

        (new GitInitializer)->initIfNeeded($projectPath);

        $setupWarning = $this->restoreCustomJobs($site, $resolved);

        try {
            (new ProjectSetupPipeline)->run($projectPath);
        } catch (\RuntimeException $e) {
            $setupWarning = $e->getMessage();
        }

        (new EnvFileWriter)->update($projectPath, ['APP_URL' => "https://{$name}.test"]);

        $applied = $autoApplyFeatures
            ? (new ProjectFeatureDetector)->detectAndApply($site, $projectPath)
            : [];

        if ($resolved['reverb_enabled'] && !$site->reverb_enabled) {
            $site->update(['reverb_enabled' => true]);
            (new ReverbProvisioner)->provision($projectPath, $site);
            $applied[] = 'reverb';
        }

        if ($hadBackup) {
            $configBackup->forget($name);
        }

        (new NginxConfigGenerator)->generate($site);
        (new SupervisorConfigGenerator)->generate($site);

        return [$site, $applied, $setupWarning];
    }

    protected function restoreCustomJobs(Site $site, array $resolved): ?string
    {
        $jobs = $resolved['processes'] ?? [];
        $extra = $resolved['supervisor_extra'] ?? null;
        if (!$jobs && !filled($extra)) {
            return null;
        }

        foreach ($jobs as $job) {
            $schedule = $job['schedule'] ?? null;
            if (!is_array($job) || !preg_match(SiteProcess::NAME_PATTERN, (string) ($job['name'] ?? ''))
                || in_array($job['name'], SiteProcess::RESERVED_NAMES, true)
                || !is_string($job['command'] ?? null) || preg_match('/[\x00-\x1f\x7f]/', $job['command'])
                || ($schedule !== null && !preg_match(SiteProcess::SCHEDULE_PATTERN, (string) $schedule))) {
                continue;
            }

            $site->processes()->updateOrCreate(['name' => $job['name']], [
                'command' => mb_substr($job['command'], 0, 500),
                'schedule' => $schedule,
                'numprocs' => max(1, min(20, (int) ($job['numprocs'] ?? 1))),
                'stopwaitsecs' => max(1, min(86400, (int) ($job['stopwaitsecs'] ?? 10))),
                'autostart' => (bool) ($job['autostart'] ?? true),
                'autorestart' => (bool) ($job['autorestart'] ?? true),
                'enabled' => (bool) ($job['enabled'] ?? true),
            ]);
        }

        $site->supervisor_extra = filled($extra) ? (string) $extra : null;
        $site->unsetRelation('processes');

        if ($problem = (new SupervisorConfigGenerator)->check($site)) {
            $site->processes()->delete();
            $site->unsetRelation('processes');
            $site->supervisor_extra = null;

            return "Custom Supervisor jobs from the earlier config were not restored: {$problem}";
        }

        $site->save();

        return null;
    }

    protected function defaultPhpVersion(): string
    {
        $knownVersions = self::KNOWN_PHP_VERSIONS;
        $latest = end($knownVersions);

        $configured = Setting::get('default_php_version', $latest);

        return in_array($configured, $knownVersions, true) ? $configured : $latest;
    }
}
