<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use App\Models\Site;
use App\Services\NginxConfigGenerator;

class LdevRenewCerts extends Command
{
    protected $signature = 'ldev:renew-certs {--within=30 : Renew a cert if it expires within this many days}';
    protected $description = 'Renew the shared *.test certificate and any per-site certificate nearing expiry';

    public function handle(): int
    {
        $withinDays = (int) $this->option('within');

        $this->renewSharedCert($withinDays);

        $generator = new NginxConfigGenerator;
        foreach (Site::all() as $site) {
            if ($generator->renewCertIfNeeded($site, $withinDays)) {
                $this->info("Renewed certificate for {$site->name}.test");
            }
        }

        return 0;
    }

    protected function renewSharedCert(int $withinDays): void
    {
        $certPath = config('ldev.home') . '/.config/ldev/certs/test.pem';
        $keyPath = config('ldev.home') . '/.config/ldev/certs/test-key.pem';

        if (File::exists($certPath) && !(new NginxConfigGenerator)->certExpiresSoon($certPath, $withinDays)) {
            return;
        }

        Process::env(['HOME' => config('ldev.home')])->run(
            'mkcert -cert-file ' . escapeshellarg($certPath)
            . ' -key-file ' . escapeshellarg($keyPath)
            . ' ' . escapeshellarg('*.test') . ' ' . escapeshellarg('test')
        )->throw();

        $this->info('Renewed the shared *.test wildcard certificate.');

        Process::run('systemctl reload nginx');
    }
}
