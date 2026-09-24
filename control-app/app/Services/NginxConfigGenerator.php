<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class NginxConfigGenerator
{
    public function generate(Site $site): void
    {
        $this->ensureCert($site);

        File::put($this->sitePath($site), view('nginx.site', ['site' => $site])->render());

        if ($site->reverb_enabled) {
            File::put($this->reverbPath($site), view('nginx.reverb', ['site' => $site])->render());
        } else {
            $this->deleteIfExists($this->reverbPath($site));
        }

        $this->reloadNginx();
        $this->syncHostsEntries($site);
    }

    public function remove(Site $site): void
    {
        $this->deleteIfExists($this->sitePath($site));
        $this->deleteIfExists($this->reverbPath($site));
        $this->reloadNginx();

        $this->withHostsManager(function (HostsFileManager $hosts) use ($site) {
            $hosts->remove("{$site->name}.test");
            $hosts->remove("{$site->name}-reverb.test");
        });
    }

    protected function syncHostsEntries(Site $site): void
    {
        $this->withHostsManager(function (HostsFileManager $hosts) use ($site) {
            $hosts->add("{$site->name}.test");

            if ($site->reverb_enabled) {
                $hosts->add("{$site->name}-reverb.test");
            } else {
                $hosts->remove("{$site->name}-reverb.test");
            }
        });
    }

    protected function withHostsManager(\Closure $callback): void
    {
        try {
            $callback(new HostsFileManager);
        } catch (\Throwable $e) {

        }
    }

    protected function sitePath(Site $site): string
    {
        return config('ldev.nginx_config_dir') . '/' . $site->domain . '.conf';
    }

    protected function ensureCert(Site $site): void
    {
        $certPath = $this->certPath($site);
        $keyPath = $this->keyPath($site);

        if (File::exists($certPath) && File::exists($keyPath) && !$this->certExpiresSoon($certPath, 30)) {
            return;
        }

        $this->regenerateCert($certPath, $keyPath, "{$site->name}.test", "*.{$site->name}.test");
    }

    public function renewCertIfNeeded(Site $site, int $withinDays = 30): bool
    {
        $certPath = $this->certPath($site);
        $keyPath = $this->keyPath($site);

        if (File::exists($certPath) && !$this->certExpiresSoon($certPath, $withinDays)) {
            return false;
        }

        $this->regenerateCert($certPath, $keyPath, "{$site->name}.test", "*.{$site->name}.test");
        $this->reloadNginx();

        return true;
    }

    protected function regenerateCert(string $certPath, string $keyPath, string ...$names): void
    {
        File::ensureDirectoryExists(dirname($certPath));

        Process::env(['HOME' => config('ldev.home')])->run(
            'mkcert -cert-file ' . escapeshellarg($certPath)
            . ' -key-file ' . escapeshellarg($keyPath)
            . ' ' . implode(' ', array_map('escapeshellarg', $names))
        )->throw();
    }

    public function certExpiresSoon(string $certPath, int $withinDays): bool
    {
        if (!File::exists($certPath)) {
            return true;
        }

        $parsed = @openssl_x509_parse(File::get($certPath));
        if (!$parsed || !isset($parsed['validTo_time_t'])) {
            return true;
        }

        return $parsed['validTo_time_t'] <= now()->addDays($withinDays)->timestamp;
    }

    protected function certPath(Site $site): string
    {
        return config('ldev.home') . "/.config/ldev/certs/{$site->name}.pem";
    }

    protected function keyPath(Site $site): string
    {
        return config('ldev.home') . "/.config/ldev/certs/{$site->name}-key.pem";
    }

    protected function reverbPath(Site $site): string
    {
        return config('ldev.nginx_config_dir') . '/' . $site->name . '-reverb.conf';
    }

    protected function deleteIfExists(string $path): void
    {
        if (File::exists($path)) {
            File::delete($path);
        }
    }

    protected function reloadNginx(): void
    {

        Process::run('systemctl reload nginx');
    }

    public function reload(): void
    {
        $this->reloadNginx();
    }
}
