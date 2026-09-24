<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\NewProjectDetector;

class LdevScanSites extends Command
{
    protected $signature = 'ldev:scan-sites';
    protected $description = 'Detect any project directory dropped directly into the sites path and link it automatically';

    public function handle(): int
    {
        $detector = new NewProjectDetector;

        foreach ($detector->findCandidates() as $name) {
            [$site, $applied, $setupWarning] = $detector->link($name);

            if ($setupWarning) {
                $this->warn("Detected '$name' but its own setup step failed, continuing anyway: " . $setupWarning);
            }
            if ($applied) {
                $this->info("  Auto-enabled: " . implode(', ', $applied));
            }

            $this->info("Detected and linked new project: {$site->domain}");
        }

        return 0;
    }
}
