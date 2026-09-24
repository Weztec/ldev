<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\DashboardBackupManager;

class LdevRestoreDashboard extends Command
{
    protected $signature = 'ldev:restore-dashboard {snapshot? : Snapshot name (omit to list them)} {--force : Skip the confirmation}';
    protected $description = 'List dashboard backups, or restore the database and .env from one';

    public function handle(): int
    {
        $manager = new DashboardBackupManager;
        $name = $this->argument('snapshot');

        if (!$name) {
            $rows = array_map(fn ($s) => [
                $s['name'], date('Y-m-d H:i:s', $s['time']), round($s['dbSize'] / 1024) . ' KB', $s['hasEnv'] ? 'yes' : 'NO',
            ], $manager->list());

            $rows ? $this->table(['Snapshot', 'Taken', 'Database', '.env'], $rows) : $this->warn("No backups in {$manager->directory()}");

            return 0;
        }

        if (!$this->option('force') && !$this->confirm("Replace the dashboard's database and .env with snapshot {$name}? The current ones are copied aside first.")) {
            return 1;
        }

        try {
            $result = $manager->restore($name);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return 1;
        }

        $this->info("Restored {$result['restored']}. Previous state kept in {$result['safetyCopy']}.");
        $this->line('Restart the dashboard to pick it up: systemctl --user restart ldev-dashboard.service');

        return 0;
    }
}
