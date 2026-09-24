<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class CloudflareTunnelManager
{
    public function start(Site $site): string
    {
        if ($existing = $this->publicUrl($site)) {
            return $existing;
        }

        File::ensureDirectoryExists($this->tunnelsDir());

        $logPath = $this->logPath($site);
        $result = Process::env([
            'HOME' => config('ldev.home'),

            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
        ])->run(
            'bash -c ' . escapeshellarg(
                'nohup cloudflared tunnel --url http://127.0.0.1:80 --http-host-header=' . escapeshellarg($site->domain)
                . ' > ' . escapeshellarg($logPath) . ' 2>&1 & echo $!'
            )
        );
        $result->throw();

        $pid = trim($result->output());
        File::put($this->pidPath($site), $pid);

        $url = null;
        for ($i = 0; $i < 40; $i++) {
            usleep(500_000);
            $url = $this->extractUrlFromLog($logPath);
            if ($url) {
                break;
            }
        }

        if (!$url) {
            $this->stop($site);
            throw new \RuntimeException('cloudflared did not report a tunnel URL in time — check ' . $logPath);
        }

        File::put($this->urlPath($site), $url);

        return $url;
    }

    public function stop(Site $site): void
    {
        $pidPath = $this->pidPath($site);
        if (File::exists($pidPath)) {
            $pid = trim(File::get($pidPath));
            if ($pid !== '' && $this->isRunning((int) $pid)) {
                Process::run('kill ' . (int) $pid);
            }
            File::delete($pidPath);
        }
        File::delete($this->urlPath($site));
    }

    public function publicUrl(Site $site): ?string
    {
        $pidPath = $this->pidPath($site);
        if (!File::exists($pidPath)) {
            return null;
        }

        $pid = (int) trim(File::get($pidPath));
        if (!$this->isRunning($pid)) {

            File::delete($pidPath);
            File::delete($this->urlPath($site));

            return null;
        }

        $urlPath = $this->urlPath($site);

        return File::exists($urlPath) ? trim(File::get($urlPath)) : null;
    }

    protected function extractUrlFromLog(string $logPath): ?string
    {
        if (!File::exists($logPath)) {
            return null;
        }

        return preg_match('~https://[a-z0-9-]+\.trycloudflare\.com~', File::get($logPath), $matches)
            ? $matches[0]
            : null;
    }

    protected function isRunning(int $pid): bool
    {
        return $pid > 0 && File::isDirectory("/proc/{$pid}");
    }

    protected function pidPath(Site $site): string
    {
        return $this->tunnelsDir() . "/{$site->name}.pid";
    }

    protected function urlPath(Site $site): string
    {
        return $this->tunnelsDir() . "/{$site->name}.url";
    }

    protected function logPath(Site $site): string
    {
        return $this->tunnelsDir() . "/{$site->name}.log";
    }

    protected function tunnelsDir(): string
    {
        return config('ldev.tunnels_dir');
    }
}
