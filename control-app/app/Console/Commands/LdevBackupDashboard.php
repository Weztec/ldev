<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\DashboardBackupManager;

class LdevBackupDashboard extends Command
{
    protected $signature = 'ldev:backup-dashboard {--keep=14 : How many snapshots to retain}';
    protected $description = 'Snapshot the dashboard\'s own database and .env, and prune old snapshots';

    public function handle(): int
    {
        $manager = new DashboardBackupManager;

        try {
            $snap = $manager->backup();
            $manager->prune((int) $this->option('keep'));
        } catch (\Throwable $e) {
            $this->error("Dashboard backup failed: {$e->getMessage()}");
            return 1;
        }

        $this->info("Dashboard backed up: {$snap['path']} (" . round($snap['dbSize'] / 1024) . ' KB)');

        return 0;
    }
}
