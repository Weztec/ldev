<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ParkedDirectory;
use App\Models\Site;
use App\Services\NginxConfigGenerator;
use App\Services\SupervisorConfigGenerator;

class LdevScanParked extends Command
{
    protected $signature = 'ldev:scan-parked';
    protected $description = 'Scan parked directories and link each immediate subdirectory as a *.test site';

    public function handle()
    {
        $nginxGenerator = new NginxConfigGenerator;
        $supervisorGenerator = new SupervisorConfigGenerator;

        foreach (ParkedDirectory::all() as $parked) {
            if (!is_dir($parked->path)) {
                continue;
            }

            foreach (scandir($parked->path) as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $projectPath = $parked->path . '/' . $entry;
                if (!is_dir($projectPath)) {
                    continue;
                }

                if (!Site::isValidName($entry)) {
                    $this->warn("Skipping '$entry' — not a valid site name (lowercase alphanumeric/hyphens only).");
                    continue;
                }

                $documentRoot = is_dir($projectPath . '/public') ? $projectPath . '/public' : $projectPath;

                $site = Site::updateOrCreate(
                    ['name' => $entry],
                    [
                        'domain' => $entry . '.test',
                        'document_root' => $documentRoot,
                        'is_parked' => true,
                    ]
                );

                $nginxGenerator->generate($site);
                $supervisorGenerator->generate($site);
            }
        }

        $this->info('Parked directories scanned.');
        return 0;
    }
}
