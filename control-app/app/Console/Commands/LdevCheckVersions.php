<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Setting;
use App\Models\Site;
use App\Services\DependencyHealthChecker;
use App\Services\DesktopNotifier;
use App\Services\StackUpdateChecker;
use App\Services\UpdateSummary;
use App\Services\VersionChecker;

class LdevCheckVersions extends Command
{
    protected $signature = 'ldev:check-versions {--skip-sites : Skip the per-site Composer/npm dependency checks}';
    protected $description = 'Check upstream for newer PHP/Node/Laravel/Livewire/Flux versions, stack package updates and site dependency health';

    public function handle(): int
    {
        $checker = new VersionChecker;

        $ldev = $checker->checkLdev();
        if ($ldev) {
            Setting::set('version_check_ldev', json_encode($ldev));
            if (VersionChecker::ldevUpdateAvailable($ldev)) {
                $this->info("Linux Dev: {$ldev['latest']} available (installed " . config('ldev.version') . ')');
            }
        }

        $php = $checker->checkPhp();
        if ($php) {
            Setting::set('version_check_php', json_encode($php));
            if (!empty($php['newCycles'])) {
                $this->info('PHP: new cycle(s) available — ' . implode(', ', $php['newCycles']));
            }
        }

        $node = $checker->checkNode();
        if ($node) {
            Setting::set('version_check_node', json_encode($node));
            if ($node['isNew']) {
                $this->info("Node: LTS {$node['latestLtsVersion']} available (not yet in the known-versions list)");
            }
        }

        $npm = $checker->checkNpm();
        if ($npm) {
            Setting::set('version_check_npm', json_encode($npm));
            foreach (VersionChecker::npmUpgradable($npm) as $node) {
                $this->info("npm {$npm['latest']} available for Node {$node['node']} (has {$node['npm']})");
            }
        }

        foreach ([
            'laravel' => ['laravel', 'framework', '^13.0'],
            'livewire' => ['livewire', 'livewire', '^4.0'],
            'flux' => ['livewire', 'flux', '^2.0'],
        ] as $key => [$vendor, $package, $constraint]) {
            $result = $checker->checkPackagist($vendor, $package, $constraint);
            if ($result) {
                Setting::set("version_check_{$key}", json_encode($result));
                if (!$result['satisfiesCurrent']) {
                    $this->info(ucfirst($key) . " {$result['latest']} available — outside this app's current {$constraint} constraint");
                }
            }
        }

        $dnf = (new StackUpdateChecker)->check();
        Setting::set('version_check_dnf', json_encode($dnf));
        if ($dnf['error']) {
            $this->warn('System packages: ' . $dnf['error']);
        } elseif (!empty($dnf['packages'])) {
            $this->info('System packages: ' . count($dnf['packages']) . ' update(s) available');
        }

        Setting::set('version_check_last_run', now()->toIso8601String());

        if (!$this->option('skip-sites')) {
            $deps = new DependencyHealthChecker;
            foreach (Site::all() as $site) {
                $result = $deps->check($site);
                if (!empty($result['advisories'])) {
                    $this->info("{$site->name}: " . count($result['advisories']) . ' security advisory(ies)');
                }
            }
        }

        $this->notifyIfChanged();

        return 0;
    }

    protected function notifyIfChanged(): void
    {
        $summary = new UpdateSummary;
        $fingerprint = $summary->fingerprint();
        if ($fingerprint === Setting::get('updates_notified_fingerprint')) {
            return;
        }

        Setting::set('updates_notified_fingerprint', $fingerprint);
        if ($fingerprint === null) {
            return;
        }

        $body = collect($summary->notices())->map(fn ($n) => "{$n['label']}: {$n['detail']}")->implode("\n");
        (new DesktopNotifier)->send('Updates available', $body);
    }
}
