<?php

use Livewire\Component;
use Illuminate\Support\Facades\Process;
use App\Services\PhpVersionInstaller;

new class extends Component {
    public $versions = [];
    public $installerAvailable = false;
    public $newVersion = '';
    public $installing = false;
    public $installError = null;
    public $installNote = null;

    public function mount()
    {
        $this->installerAvailable = (new PhpVersionInstaller)->isAvailable();
        $this->refreshStatus();
    }

    protected function unitFor(string $version): string
    {
        return 'php' . str_replace('.', '', $version) . '-php-fpm';
    }

    public function refreshStatus()
    {
        $this->versions = [];

        $result = Process::run("systemctl list-unit-files 'php*-php-fpm.service' --no-legend");
        foreach (explode("\n", trim($result->output())) as $line) {
            if (!preg_match('/^php(\d{2,3})-php-fpm\.service/', trim($line), $m)) {
                continue;
            }

            $code = $m[1];
            $version = substr($code, 0, 1) . '.' . substr($code, 1);
            $this->versions[$version] = trim(Process::run('systemctl is-active ' . escapeshellarg($this->unitFor($version)))->output()) === 'active';
        }
    }

    public function toggle($version)
    {

        if (!array_key_exists($version, $this->versions)) {
            return;
        }

        $action = $this->versions[$version] ? 'stop' : 'start';
        Process::run('systemctl ' . $action . ' ' . escapeshellarg($this->unitFor($version)));
        $this->refreshStatus();
    }

    public function installVersion()
    {
        $this->installError = null;
        $this->installNote = null;

        $input = trim($this->newVersion);

        $normalized = preg_match('/^\d\.\d$/', $input) ? str_replace('.', '', $input) : $input;

        if (!preg_match('/^\d{2,3}$/', $normalized)) {
            $this->installError = 'Enter a version like "8.6".';
            return;
        }

        $this->installing = true;
        try {
            (new PhpVersionInstaller)->install($normalized);
            $this->newVersion = '';
            $this->installNote = 'Installed successfully.';
            $this->refreshStatus();
        } catch (\Throwable $e) {
            $this->installError = $e->getMessage();
        } finally {
            $this->installing = false;
        }
    }
};
