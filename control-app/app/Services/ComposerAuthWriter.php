<?php

namespace App\Services;

use App\Models\ComposerCredential;
use Illuminate\Support\Facades\File;

class ComposerAuthWriter
{
    public const SNAPSHOTS_KEPT = 20;

    protected function path(): string
    {
        return config('ldev.home') . '/.config/composer/auth.json';
    }

    public function snapshotDir(): string
    {
        return config('ldev.backups_dir') . '/composer-auth';
    }

    public function sync(array $removeHosts = []): void
    {
        $this->importMissing($removeHosts);

        $auth = $this->readAuth() ?? [];
        $existing = is_array($auth['http-basic'] ?? null) ? $auth['http-basic'] : [];

        $httpBasic = array_diff_key($existing, array_flip($removeHosts));
        foreach (ComposerCredential::global()->get() as $cred) {
            $secret = $this->secret($cred);
            if ($secret === null) {
                continue;
            }
            $httpBasic[$cred->host] = ['username' => $cred->username, 'password' => $secret];
        }

        ksort($httpBasic);
        $auth['http-basic'] = $httpBasic ?: new \stdClass();

        $this->write($auth);
    }

    public function importMissing(array $skipHosts = []): array
    {
        $auth = $this->readAuth();
        $entries = is_array($auth['http-basic'] ?? null) ? $auth['http-basic'] : [];

        $known = ComposerCredential::global()->pluck('host')->all();
        $imported = [];
        foreach ($entries as $host => $entry) {
            if (in_array($host, $known, true) || in_array($host, $skipHosts, true) || !is_array($entry)) {
                continue;
            }
            if (!isset($entry['username'], $entry['password']) || $entry['username'] === '' || $entry['password'] === '') {
                continue;
            }
            ComposerCredential::create(['site_id' => null, 'host' => $host, 'username' => $entry['username'], 'secret' => $entry['password']]);
            $imported[] = $host;
        }

        return $imported;
    }

    public function repair(): array
    {
        $report = ['imported' => [], 'restoredFromSnapshot' => false, 'rewritten' => false, 'unreadable' => []];

        $path = $this->path();
        $current = File::exists($path) ? json_decode(File::get($path), true) : null;
        if (!is_array($current)) {
            $snapshot = $this->latestValidSnapshot();
            if ($snapshot !== null) {
                if (File::exists($path)) {
                    $this->snapshot('corrupt');
                }
                $this->write($snapshot);
                $report['restoredFromSnapshot'] = true;
            }
        } elseif ($this->latestValidSnapshot() === null) {
            $this->snapshot();
        }

        $report['imported'] = $this->importMissing();

        $auth = $this->readAuth() ?? [];
        $entries = is_array($auth['http-basic'] ?? null) ? $auth['http-basic'] : [];
        $needsWrite = false;
        foreach (ComposerCredential::global()->get() as $cred) {
            $secret = $this->secret($cred);
            if ($secret === null) {
                $report['unreadable'][] = $cred->host;
                continue;
            }
            $entry = $entries[$cred->host] ?? null;
            if (!is_array($entry) || ($entry['username'] ?? null) !== $cred->username || ($entry['password'] ?? null) !== $secret) {
                $needsWrite = true;
            }
        }

        if ($needsWrite) {
            $this->sync();
            $report['rewritten'] = true;
        }

        return $report;
    }

    public function unreadableHosts(): array
    {
        return ComposerCredential::global()->get()
            ->filter(fn (ComposerCredential $cred) => $this->secret($cred) === null)
            ->pluck('host')
            ->values()
            ->all();
    }

    public function secret(ComposerCredential $cred): ?string
    {
        try {
            $secret = $cred->secret;
        } catch (\Throwable $e) {
            return null;
        }

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    protected function readAuth(): ?array
    {
        $path = $this->path();
        if (File::exists($path)) {
            $decoded = json_decode(File::get($path), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $this->latestValidSnapshot();
    }

    protected function write(array $auth): void
    {
        $path = $this->path();
        $dir = dirname($path);
        if (!File::isDirectory($dir)) {
            File::makeDirectory($dir, 0700, true);
        }

        $json = json_encode($auth, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Could not encode Composer auth.json.');
        }

        if (File::exists($path) && File::get($path) === $json) {
            return;
        }

        $tmp = $path . '.tmp-' . getmypid();
        File::put($tmp, $json);
        chmod($tmp, 0600);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Could not replace Composer auth.json.');
        }

        $this->snapshot();
    }

    protected function snapshot(string $suffix = ''): ?string
    {
        $path = $this->path();
        if (!File::exists($path)) {
            return null;
        }

        $dir = $this->snapshotDir();
        if (!File::isDirectory($dir)) {
            File::makeDirectory($dir, 0700, true);
        }
        @chmod($dir, 0700);

        $target = $dir . '/auth-' . now()->format('Ymd-His-u') . ($suffix ? "-{$suffix}" : '') . '.json';
        File::copy($path, $target);
        chmod($target, 0600);

        $this->pruneSnapshots();

        return $target;
    }

    protected function latestValidSnapshot(): ?array
    {
        $dir = $this->snapshotDir();
        if (!File::isDirectory($dir)) {
            return null;
        }

        $files = File::glob($dir . '/auth-*.json');
        rsort($files);
        foreach ($files as $file) {
            if (str_ends_with($file, '-corrupt.json')) {
                continue;
            }
            $decoded = json_decode(File::get($file), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    protected function pruneSnapshots(): void
    {
        $files = File::glob($this->snapshotDir() . '/auth-*.json');
        rsort($files);
        foreach (array_slice($files, self::SNAPSHOTS_KEPT) as $old) {
            @unlink($old);
        }
    }

    public function extractAuthFailureHost(string $errorOutput): ?string
    {
        if (preg_match('/The \'(https?:\/\/[^\']+)\' URL required authentication/i', $errorOutput, $m)
            || preg_match('/could not authenticate against ([^\s,]+)/i', $errorOutput, $m)) {
            return parse_url($m[1], PHP_URL_HOST) ?: $m[1];
        }

        return null;
    }
}
