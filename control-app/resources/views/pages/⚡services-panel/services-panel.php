<?php

use Livewire\Component;
use Illuminate\Support\Facades\Process;
use App\Services\SystemInfo;
use App\Services\UserSession;

new class extends Component {
    public $services = [];
    public $states = [];
    public $versions = [];
    public $system = [];
    public $storage = [];

    protected array $systemServices = [];
    protected array $userServices = [];

    public function boot()
    {
        $this->systemServices = config('ldev.services.system');
        $this->userServices = config('ldev.services.user');
    }

    public function mount()
    {
        $this->refreshStatus();
        $this->detectVersions();
        $this->refreshStorage();
    }

    public function poll()
    {
        $this->refreshStatus();
    }

    public function refreshStorage()
    {
        $this->storage = (new SystemInfo)->storage();
    }

    protected function detectVersions(): void
    {
        foreach (array_merge($this->systemServices, $this->userServices) as $service) {
            $this->versions[$service] = $this->detectVersion($service);
        }
    }

    protected function detectVersion(string $service): ?string
    {

        if (preg_match('/^php(\d{2})-php-fpm$/', $service, $m)) {
            return $this->runVersionCommand("php{$m[1]} -v 2>&1", '/PHP ([\d.]+)/');
        }

        [$command, $pattern] = match ($service) {
            'nginx' => ['nginx -v 2>&1', '/nginx\/([\d.]+)/'],
            'mariadb' => ['mariadb --version 2>&1', '/from ([\d.]+)-MariaDB/'],
            'postgresql' => ['psql --version 2>&1', '/\(PostgreSQL\) ([\d.]+)/'],
            'valkey' => ['valkey-server --version 2>&1', '/v=([\d.]+)/'],
            'memcached' => ['memcached --version 2>&1', '/memcached ([\d.]+)/'],
            'supervisord' => ['supervisord --version 2>&1', '/([\d.]+)/'],
            'php-fpm' => ['php -v 2>&1', '/PHP ([\d.]+)/'],

            'ldev-mailpit' => ['mailpit version 2>&1', '/v([\d.]+)/'],

            'ldev-minio' => ['minio --version 2>&1', '/version (RELEASE\.[\w-]+)/'],
            default => [null, null],
        };

        return $command ? $this->runVersionCommand($command, $pattern) : null;
    }

    protected function runVersionCommand(string $command, string $pattern): ?string
    {
        $output = Process::run($command)->output();
        return preg_match($pattern, $output, $m) ? $m[1] : null;
    }

    protected function userServiceEnv(): array
    {
        return UserSession::env();
    }

    public function refreshStatus()
    {
        foreach ($this->systemServices as $service) {
            $this->states[$service] = trim(Process::run('systemctl is-active ' . escapeshellarg($service))->output());
        }
        foreach ($this->userServices as $service) {
            $this->states[$service] = trim(Process::env($this->userServiceEnv())->run('systemctl --user is-active ' . escapeshellarg($service))->output());
        }
        foreach ($this->states as $service => $state) {
            $this->services[$service] = $state === 'active';
        }

        $this->system = (new SystemInfo)->live();
    }

    public function toggle($service)
    {

        $isUserService = in_array($service, $this->userServices, true);
        if (!$isUserService && !in_array($service, $this->systemServices, true)) {
            return;
        }

        $action = $this->services[$service] ? 'stop' : 'start';
        $prefix = $isUserService ? 'systemctl --user' : 'systemctl';
        $command = "$prefix $action " . escapeshellarg($service);
        if ($isUserService) {
            Process::env($this->userServiceEnv())->run($command);
        } else {
            Process::run($command);
        }
        $this->refreshStatus();
    }
};
