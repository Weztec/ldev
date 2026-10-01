<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\Site;
use App\Services\DependencyHealthChecker;
use Illuminate\Console\Command;

class LdevCheckDependencies extends Command
{
    protected $signature = 'ldev:check-dependencies {site}';
    protected $description = 'Check one project for security advisories and outdated packages, reporting progress for the dashboard';

    public function handle(): int
    {
        $site = Site::find($this->argument('site'));
        if (!$site) {
            $this->error('Unknown site.');
            return self::FAILURE;
        }

        $status = DependencyHealthChecker::checkStatus($site) ?? ['startedAt' => now()->toIso8601String()];
        $status['state'] = 'running';
        $status['pid'] = getmypid();
        $save = function () use (&$status, $site) {
            Setting::set(DependencyHealthChecker::checkKey($site), json_encode($status));
        };
        $save();

        try {
            (new DependencyHealthChecker)->check($site, function (array $progress) use (&$status, $save) {
                $status['progress'] = $progress;
                $save();
            });
            $status['state'] = 'done';
        } catch (\Throwable $e) {
            $status['state'] = 'failed';
            $status['error'] = $e->getMessage();
        }

        $status['finishedAt'] = now()->toIso8601String();
        $save();

        return $status['state'] === 'done' ? self::SUCCESS : self::FAILURE;
    }
}
