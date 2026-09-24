<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Facades\Process;

class HostsFileManager
{
    protected const HOSTNAME_PATTERN = '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*\.test$/';

    public function add(string $hostname): void
    {
        $this->run('add', $hostname);
    }

    public function remove(string $hostname): void
    {
        $this->run('remove', $hostname);
    }

    public function all(): array
    {
        $result = Process::run('sudo /usr/local/bin/ldev-manage-hosts list');
        if ($result->failed()) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", $result->output()))));
    }

    public function forSite(Site $site): array
    {
        $base = "{$site->name}.test";
        $reverb = "{$site->name}-reverb.test";
        $suffix = ".{$site->name}.test";

        $mine = array_filter($this->all(), fn ($h) => $h === $base || $h === $reverb || str_ends_with($h, $suffix));

        return [
            'automatic' => array_values(array_filter($mine, fn ($h) => $h === $base || $h === $reverb)),
            'manual' => array_values(array_filter($mine, fn ($h) => $h !== $base && $h !== $reverb)),
        ];
    }

    public function isValidSubdomainFor(Site $site, string $hostname): bool
    {
        return preg_match(self::HOSTNAME_PATTERN, $hostname) === 1
            && str_ends_with($hostname, ".{$site->name}.test")
            && $hostname !== "{$site->name}.test";
    }

    protected function run(string $action, string $hostname): void
    {
        if (!preg_match(self::HOSTNAME_PATTERN, $hostname)) {
            throw new \InvalidArgumentException("'{$hostname}' is not a valid *.test hostname.");
        }

        Process::run(
            'sudo /usr/local/bin/ldev-manage-hosts ' . escapeshellarg($action) . ' ' . escapeshellarg($hostname)
        )->throw();
    }
}
