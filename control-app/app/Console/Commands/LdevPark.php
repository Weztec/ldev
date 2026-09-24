<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ParkedDirectory;

class LdevPark extends Command
{
    protected $signature = 'ldev:park {directory?}';
    protected $description = 'Park a directory for automatic site serving';

    public function handle()
    {
        $path = $this->argument('directory') ?? getcwd();
        $real = realpath($path);
        if (!$real || !is_dir($real)) {
            $this->error("Directory not found: $path");
            return 1;
        }

        ParkedDirectory::firstOrCreate(['path' => $real]);
        $this->info("Parked: $real");
        $this->call('ldev:scan-parked');
        return 0;
    }
}
