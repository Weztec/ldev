<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class LdevGenerateToken extends Command
{
    protected $signature = 'ldev:generate-token';
    protected $description = 'Generate the dashboard API token and store it in ~/.config/ldev/token';

    public function handle()
    {

        $dir = config('ldev.home') . '/.config/ldev';
        $path = $dir . '/token';

        if (file_exists($path)) {
            $this->info("Token already exists at $path");
            return 0;
        }

        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $token = Str::random(40);
        file_put_contents($path, $token . PHP_EOL);
        chmod($path, 0600);

        $this->info("Token generated and stored in $path");
        return 0;
    }
}
