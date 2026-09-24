<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use App\Models\Setting;
use App\Services\ComposerAuthWriter;
use App\Services\DesktopNotifier;
use App\Services\SystemInfo;
use App\Services\UserSession;

class LdevCheckHealth extends Command
{
    protected $signature = 'ldev:check-health';
    protected $description = 'Check for failed services, expiring certificates and low disk space, and send a desktop notification for new problems';

    public function handle(): int
    {
        $this->repairComposerAuth();

        $problems = $this->collectProblems();

        $previous = json_decode(Setting::get('health_alerted', '[]'), true) ?: [];
        $new = array_diff_key($problems, $previous);
        Setting::set('health_alerted', json_encode($problems));

        foreach ($problems as $message) {
            $this->warn($message);
        }

        if (!empty($new)) {
            $critical = collect(array_keys($new))->contains(fn ($k) => str_starts_with($k, 'service:'));
            $sent = (new DesktopNotifier)->send(
                count($new) === 1 ? 'Linux Dev needs attention' : count($new) . ' Linux Dev problems',
                implode("\n", $new),
                $critical ? 'critical' : 'normal'
            );
            $this->line($sent ? 'Desktop notification sent.' : 'Desktop notification not sent.');
        }

        return 0;
    }

    protected function repairComposerAuth(): void
    {
        try {
            $report = (new ComposerAuthWriter)->repair();
        } catch (\Throwable $e) {
            $this->warn('Composer auth check failed: ' . $e->getMessage());
            return;
        }

        if ($report['restoredFromSnapshot']) {
            $this->info('Composer auth.json restored from its latest snapshot.');
        }
        if ($report['imported']) {
            $this->info('Composer credentials recovered from auth.json: ' . implode(', ', $report['imported']));
        }
        if ($report['rewritten']) {
            $this->info('Composer auth.json rebuilt from the dashboard database.');
        }
    }

    protected function collectProblems(): array
    {
        $problems = [];

        foreach ((new ComposerAuthWriter)->unreadableHosts() as $host) {
            $problems["composer:{$host}"] = "Composer credential for {$host} can't be decrypted; re-enter it in Settings";
        }

        foreach (config('ldev.services.system') as $service) {
            if (trim(Process::run('systemctl is-failed ' . escapeshellarg($service))->output()) === 'failed') {
                $problems["service:{$service}"] = "{$service} has failed";
            }
        }
        foreach (config('ldev.services.user') as $service) {
            $state = trim(Process::env(UserSession::env())->run('systemctl --user is-failed ' . escapeshellarg($service))->output());
            if ($state === 'failed') {
                $problems["service:{$service}"] = "{$service} has failed";
            }
        }

        $info = new SystemInfo;

        $certDays = (int) Setting::get('health_cert_warn_days', '7');
        foreach ($info->certExpiries() as $cert) {
            if ($cert['daysLeft'] <= $certDays) {
                $problems["cert:{$cert['name']}"] = $cert['daysLeft'] < 0
                    ? "Certificate {$cert['name']} has expired"
                    : "Certificate {$cert['name']} expires in {$cert['daysLeft']} day(s)";
            }
        }

        $diskPercent = (float) Setting::get('health_disk_warn_percent', '10');
        foreach ($info->disks() as $disk) {
            if ($disk['freePercent'] < $diskPercent) {
                $problems["disk:{$disk['path']}"] = "Low disk space for {$disk['label']}: {$disk['freePercent']}% free (" . SystemInfo::humanBytes($disk['free']) . ')';
            }
        }

        return $problems;
    }
}
