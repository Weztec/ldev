<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class SupervisorConfigGenerator
{

    public function generate(Site $site): void
    {
        $config = $this->render($site);
        if ($config === null) {
            $this->remove($site);
            return;
        }

        if ($problem = (new SupervisorConfigValidator)->validate($site, $config)) {
            throw new \RuntimeException($problem);
        }

        File::put($this->configPath($site), $config);
        $this->reloadSupervisor();
    }

    public function check(Site $site): ?string
    {
        $config = $this->render($site);

        return $config === null ? null : (new SupervisorConfigValidator)->validate($site, $config);
    }

    protected function render(Site $site): ?string
    {
        $site->loadMissing('processes');

        if (!$site->queue_worker_enabled && !$site->reverb_enabled && !$site->scheduler_enabled
            && !$site->processes->where('enabled', true)->count() && !filled($site->supervisor_extra)) {
            return null;
        }

        return view('supervisor.worker', [
            'site' => $site,
            'projectRoot' => $site->projectRoot(),
            'owner' => trim(Process::run('whoami')->output()),
        ])->render();
    }

    public function remove(Site $site): void
    {
        $path = $this->configPath($site);
        if (File::exists($path)) {
            File::delete($path);
            $this->reloadSupervisor();
        }
    }

    protected function configPath(Site $site): string
    {

        return config('ldev.supervisor_config_dir') . '/ldev-' . $site->name . '.ini';
    }

    protected function reloadSupervisor(): void
    {

        Process::run('systemctl restart supervisord');
    }
}
