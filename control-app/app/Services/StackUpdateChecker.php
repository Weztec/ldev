<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

class StackUpdateChecker
{
    public const REPOS = ['fedora', 'updates', 'remi', 'cloudflared-stable'];

    public const PACKAGES = [
        'nginx', 'php??-php-*', 'mariadb-server', 'postgresql-server', 'valkey', 'memcached',
        'supervisor', 'mkcert', 'dnsmasq', 'nss-tools', 'cloudflared',
    ];

    public function check(): array
    {
        $repos = array_values(array_intersect(self::REPOS, $this->enabledRepos()));
        if (empty($repos)) {
            return ['packages' => [], 'error' => 'None of the stack repositories are enabled.'];
        }

        $repoArgs = implode(' ', array_map(fn ($r) => '--repo=' . escapeshellarg($r), $repos));
        $pkgArgs = implode(' ', array_map('escapeshellarg', self::PACKAGES));
        $format = escapeshellarg('%{name}|%{evr}|%{repoid}\n');

        $upgrades = $this->dnf("dnf repoquery --quiet --assumeno --upgrades --latest-limit=1 {$repoArgs} --qf {$format} {$pkgArgs}");
        if (!$upgrades->successful()) {
            return ['packages' => [], 'error' => trim($upgrades->errorOutput()) ?: 'dnf exited with code ' . $upgrades->exitCode()];
        }

        $available = $this->parse($upgrades->output());
        if (empty($available)) {
            return ['packages' => [], 'error' => null];
        }

        $names = implode(' ', array_map('escapeshellarg', array_keys($available)));
        $installed = $this->parse($this->dnf('dnf repoquery --quiet --installed --qf ' . escapeshellarg('%{name}|%{evr}\n') . " {$names}")->output());

        $packages = [];
        foreach ($available as $name => [$version, $repo]) {
            $packages[] = [
                'name' => $name,
                'installed' => $installed[$name][0] ?? null,
                'available' => $version,
                'repo' => $repo,
            ];
        }

        return ['packages' => $packages, 'error' => null];
    }

    protected function enabledRepos(): array
    {
        $output = $this->dnf('dnf repolist --enabled --quiet')->output();

        return collect(explode("\n", $output))
            ->map(fn ($line) => strtok(trim($line), " \t"))
            ->filter(fn ($id) => $id && $id !== 'repo')
            ->values()
            ->all();
    }

    protected function parse(string $output): array
    {
        $rows = [];
        foreach (explode("\n", trim($output)) as $line) {
            $parts = explode('|', trim($line));
            if (count($parts) >= 2 && $parts[0] !== '') {
                $rows[$parts[0]] = [$parts[1], $parts[2] ?? null];
            }
        }

        return $rows;
    }

    protected function dnf(string $command)
    {
        return Process::env([
            'HOME' => config('ldev.home'),
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
        ])->timeout(180)->run($command);
    }
}
