<?php

use Livewire\Component;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use App\Models\Site;
use App\Services\NginxConfigGenerator;

new class extends Component {
    public $selected;
    public $lines = [];
    public $files = [];
    public $groups = [];

    public function mount()
    {
        $this->groups = $this->buildGroups();
        $this->files = $this->flattenGroups($this->groups);
        $this->selected = array_key_first($this->files);
        $this->loadLog();
    }

    public function selectLog($path)
    {

        if (!array_key_exists($path, $this->files)) {
            return;
        }

        $this->selected = $path;
        $this->loadLog();
    }

    public function refreshLog()
    {
        $this->groups = $this->buildGroups();
        $this->files = $this->flattenGroups($this->groups);
        $this->loadLog();
    }

    public function removeOrphanedGroup($name)
    {
        $groups = $this->buildGroups();

        if (!isset($groups[$name]) || !($groups[$name]['orphaned'] ?? false)) {
            return;
        }

        foreach (array_keys($groups[$name]['files']) as $path) {
            File::delete($path);
        }

        $this->refreshLog();
    }

    public function clearLog()
    {
        if (!$this->selected) {
            return;
        }

        $this->clearSpecificLog($this->selected);
    }

    public function clearSpecificLog($path)
    {
        if (!array_key_exists($path, $this->files)) {
            return;
        }

        if ($this->clearOneLog($path)) {
            (new NginxConfigGenerator)->reload();
        }

        $this->groups = $this->buildGroups();
        $this->files = $this->flattenGroups($this->groups);

        if ($this->selected === $path) {
            $this->loadLog();
        }
    }

    public function deleteSpecificLog($path)
    {

        if (!array_key_exists($path, $this->files) || $path === storage_path('logs/laravel.log')) {
            return;
        }

        File::delete($path);

        $this->groups = $this->buildGroups();
        $this->files = $this->flattenGroups($this->groups);

        if ($this->selected === $path) {
            $this->selected = array_key_first($this->files);
        }

        $this->loadLog();
    }

    public function clearAllLogs()
    {

        $this->groups = $this->buildGroups();
        $this->files = $this->flattenGroups($this->groups);

        $needsNginxReload = false;
        foreach (array_keys($this->files) as $path) {
            if ($this->clearOneLog($path)) {
                $needsNginxReload = true;
            }
        }

        if ($needsNginxReload) {
            (new NginxConfigGenerator)->reload();
        }

        $this->groups = $this->buildGroups();
        $this->files = $this->flattenGroups($this->groups);
        $this->loadLog();
    }

    protected function clearOneLog(string $path): bool
    {
        try {
            File::put($path, '');
            return false;
        } catch (\Throwable $e) {
            if (File::exists($path)) {
                File::delete($path);
            }
            return true;
        }
    }

    protected function buildGroups(): array
    {
        $groups = [];
        $other = [];

        $siteNames = [];
        foreach (Site::all() as $site) {
            $siteNames[] = $site->name;
            $projectLogs = $site->logFiles();
            $groups[$site->name]['files'] = $projectLogs['files'];
            if ($projectLogs['truncated'] > 0) {
                $groups[$site->name]['truncated'] = $projectLogs['truncated'];
            }
        }

        $logsDir = config('ldev.home') . '/.config/ldev/logs';
        if (File::isDirectory($logsDir)) {
            $infraFiles = [];
            foreach (File::files($logsDir) as $file) {
                $filename = $file->getFilename();
                $siteName = $this->siteNameFromLogFilename($filename);

                if ($siteName === null) {
                    $other[$file->getPathname()] = $filename;
                    continue;
                }

                $infraFiles[$siteName][$file->getPathname()] = $this->shortLabel($filename);
            }

            foreach ($infraFiles as $siteName => $files) {
                ksort($files);
                $groups[$siteName]['files'] = ($groups[$siteName]['files'] ?? []) + $files;
            }
        }

        foreach ($groups as $name => &$group) {
            $group['orphaned'] = !in_array($name, $siteNames, true);
        }
        unset($group);

        uksort($groups, function ($a, $b) use ($groups) {
            return $groups[$a]['orphaned'] <=> $groups[$b]['orphaned']
                ?: strcmp($a, $b);
        });

        if (!empty($other)) {
            ksort($other);
            $groups['Other'] = ['orphaned' => false, 'isOther' => true, 'files' => $other];
        }

        $dashboardLog = storage_path('logs/laravel.log');
        if (File::exists($dashboardLog)) {
            $groups = ['Linux Dev dashboard' => [
                'orphaned' => false,

                'protected' => true,
                'files' => [$dashboardLog => 'Application log'],
            ]] + $groups;
        }

        return $groups;
    }

    protected function flattenGroups(array $groups): array
    {
        $flat = [];
        foreach ($groups as $group) {
            $flat += $group['files'];
        }
        return $flat;
    }

    protected function siteNameFromLogFilename(string $filename): ?string
    {
        foreach (['.test-access.log', '.test-error.log', '-queue.log', '-reverb.log', '-scheduler.log'] as $suffix) {
            if (str_ends_with($filename, $suffix)) {
                return substr($filename, 0, -strlen($suffix));
            }
        }

        return null;
    }

    protected function shortLabel(string $filename): string
    {
        return match (true) {
            str_ends_with($filename, '.test-access.log') => 'nginx access',
            str_ends_with($filename, '.test-error.log') => 'nginx error',
            str_ends_with($filename, '-queue.log') => 'queue worker',
            str_ends_with($filename, '-reverb.log') => 'reverb',
            str_ends_with($filename, '-scheduler.log') => 'scheduler',
            default => $filename,
        };
    }

    protected function loadLog(): void
    {
        if (!$this->selected || !File::exists($this->selected)) {
            $this->lines = [];
            return;
        }

        $result = Process::run('tail -n 200 ' . escapeshellarg($this->selected));
        $this->lines = array_filter(explode("\n", $result->output()));
    }
};
