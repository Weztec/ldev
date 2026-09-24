<?php

namespace App\Providers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{

    public function register(): void
    {

    }

    public function boot(): void
    {

        Process::macro('inProject', function (string $path) {

            return $this->path($path)->env(array_merge(
                AppServiceProvider::dashboardEnvKeysToClear(),
                [
                    'HOME' => config('ldev.home'),
                    'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
                ]
            ));
        });
    }

    public static function dashboardEnvKeysToClear(): array
    {
        static $keys = null;

        if ($keys === null) {
            $keys = [];
            $envPath = app()->environmentFilePath();
            if (File::exists($envPath)) {
                foreach (explode("\n", File::get($envPath)) as $line) {
                    if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=/', trim($line), $matches)) {
                        $keys[$matches[1]] = false;
                    }
                }
            }
        }

        return $keys;
    }
}
