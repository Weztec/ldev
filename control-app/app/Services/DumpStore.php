<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Facades\File;

class DumpStore
{
    public const KEEP = 100;

    public const ENV_KEYS = ['VAR_DUMPER_FORMAT', 'VAR_DUMPER_SERVER'];

    public function enabled(Site $site): bool
    {
        return (new EnvFileWriter)->get($site->projectRoot(), 'VAR_DUMPER_FORMAT') === 'server';
    }

    public function enable(Site $site): void
    {
        (new EnvFileWriter)->update($site->projectRoot(), [
            'VAR_DUMPER_FORMAT' => 'server',
            'VAR_DUMPER_SERVER' => config('ldev.dump_server'),
        ]);
    }

    public function disable(Site $site): void
    {
        (new EnvFileWriter)->remove($site->projectRoot(), self::ENV_KEYS);
    }

    public function serverRunning(): bool
    {
        [$host, $port] = explode(':', config('ldev.dump_server'));
        $socket = @fsockopen($host, (int) $port, $errno, $errstr, 0.3);
        if ($socket) {
            fclose($socket);

            return true;
        }

        return false;
    }

    public function path(string $siteName): string
    {
        return config('ldev.dumps_dir') . '/' . $siteName . '.jsonl';
    }

    public function append(string $siteName, array $entry): void
    {
        $path = $this->path($siteName);
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n", FILE_APPEND | LOCK_EX);

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        if (count($lines) > self::KEEP * 2) {
            file_put_contents($path, implode("\n", array_slice($lines, -self::KEEP)) . "\n", LOCK_EX);
        }
    }

    public function latest(string $siteName, int $limit = 50): array
    {
        $path = $this->path($siteName);
        if (!File::exists($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        return array_values(array_filter(array_map(
            fn ($line) => json_decode($line, true),
            array_reverse(array_slice($lines, -$limit))
        )));
    }

    public function clear(string $siteName): void
    {
        File::delete($this->path($siteName));
    }
}
