<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Facades\File;

class SiteConfigBackup
{
    public function backup(Site $site): void
    {
        $path = $this->path($site->name);
        File::ensureDirectoryExists(dirname($path));

        File::put($path, json_encode([
            'php_version' => $site->php_version,
            'node_version' => $site->node_version,
            'xdebug_enabled' => $site->xdebug_enabled,
            'queue_worker_enabled' => $site->queue_worker_enabled,
            'queue_workers' => $site->queue_workers,
            'queue_sleep' => $site->queue_sleep,
            'queue_tries' => $site->queue_tries,
            'queue_max_time' => $site->queue_max_time,
            'reverb_enabled' => $site->reverb_enabled,
            'supervisor_extra' => $site->supervisor_extra,
            'processes' => $site->processes()->get()
                ->map(fn ($p) => $p->only(['name', 'command', 'schedule', 'numprocs', 'stopwaitsecs', 'autostart', 'autorestart', 'enabled']))
                ->all(),
        ], JSON_PRETTY_PRINT));
    }

    public function restore(string $name): ?array
    {
        $path = $this->path($name);
        if (!File::exists($path)) {
            return null;
        }

        return json_decode(File::get($path), true);
    }

    public function forget(string $name): void
    {
        File::delete($this->path($name));
    }

    protected function path(string $name): string
    {
        return config('ldev.site_configs_dir') . "/{$name}.json";
    }
}
