<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class ReverbProvisioner
{
    public function provision(string $projectPath, Site $site): void
    {

        $envWriter = new EnvFileWriter;
        $reverbKeys = [];
        foreach (['REVERB_APP_ID', 'REVERB_APP_KEY', 'REVERB_APP_SECRET'] as $key) {
            if (!$envWriter->get($projectPath, $key)) {
                $reverbKeys[$key] = Str::random(20);
            }
        }
        $envWriter->update($projectPath, $reverbKeys);

        Process::inProject($projectPath)->run('composer require laravel/reverb --with-all-dependencies')->throw();

        File::delete($projectPath . '/config/broadcasting.php');
        Process::inProject($projectPath)->run('php artisan install:broadcasting --reverb --no-interaction --without-node')->throw();

        Process::inProject($projectPath)->run('php artisan reverb:install --no-interaction')->throw();

        Process::inProject($projectPath)->run('npm install --save-dev laravel-echo pusher-js --ignore-scripts')->throw();

        $envWriter->update($projectPath, [

            'REVERB_HOST' => '127.0.0.1',
            'REVERB_PORT' => $site->reverb_port,
            'REVERB_SCHEME' => 'http',

            'VITE_REVERB_HOST' => "{$site->name}-reverb.test",
            'VITE_REVERB_PORT' => 443,
            'VITE_REVERB_SCHEME' => 'https',
        ]);
    }
}
