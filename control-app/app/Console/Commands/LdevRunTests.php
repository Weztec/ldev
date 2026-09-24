<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\TestRunner;
use Illuminate\Console\Command;

class LdevRunTests extends Command
{
    protected $signature = 'ldev:run-tests {site} {tests?*} {--all} {--coverage}';
    protected $description = 'Run a project\'s tests file by file and store the results for the dashboard';

    public function handle(): int
    {
        $site = Site::find($this->argument('site'));
        if (!$site) {
            $this->error('Unknown site.');
            return self::FAILURE;
        }

        $discovered = TestRunner::discover($site->projectRoot())['files'];
        $selection = TestRunner::normaliseSelection($discovered, (array) $this->argument('tests'), (bool) $this->option('all'));

        if (!$selection) {
            $this->error('No known tests given.');
            return self::FAILURE;
        }

        (new TestRunner)->run($site, $selection, (bool) $this->option('coverage'));

        return self::SUCCESS;
    }
}
