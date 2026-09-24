<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Site;
use App\Services\DatabaseBackupManager;

class LdevBackupDatabases extends Command
{
    protected $signature = 'ldev:backup-databases {--keep=7 : How many backups to retain per site}';
    protected $description = 'Back up every site\'s database automatically and prune old backups';

    public function handle(): int
    {
        $keep = (int) $this->option('keep');
        $manager = new DatabaseBackupManager;

        $this->call('ldev:backup-dashboard');

        foreach (Site::all() as $site) {

            if (!$site->db_auto_backup_enabled || !$site->databaseType()) {
                continue;
            }

            try {
                $manager->backup($site);
                $manager->prune($site, $keep);
                $this->info("Backed up {$site->name}");
            } catch (\Throwable $e) {
                $this->warn("Failed to back up {$site->name}: {$e->getMessage()}");
            }
        }

        return 0;
    }
}
