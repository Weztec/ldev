<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class SystemInfo
{
    public function live(): array
    {
        $load = sys_getloadavg() ?: [null, null, null];

        return [
            'os' => $this->osName(),
            'kernel' => php_uname('r'),
            'uptime' => $this->uptime(),
            'load' => array_map(fn ($v) => $v === null ? null : round($v, 2), $load),
            'cpus' => $this->cpuCount(),
            'memory' => $this->memory(),
            'disks' => $this->disks(),
        ];
    }

    public function storage(): array
    {
        return [
            'databases' => $this->databaseSizes(),
            'certs' => $this->certExpiries(),
        ];
    }

    public function disks(): array
    {
        $paths = [
            'Sites' => config('ldev.sites_path'),
            'Storage' => dirname(config('ldev.s3_data_dir')),
        ];

        $disks = [];
        $seen = [];
        foreach ($paths as $label => $path) {
            if (!is_dir($path)) {
                continue;
            }
            $total = @disk_total_space($path);
            $free = @disk_free_space($path);
            if (!$total || $free === false) {
                continue;
            }
            $key = $total . ':' . $free;
            if (isset($seen[$key])) {
                $disks[$seen[$key]]['label'] .= ' & ' . $label;
                continue;
            }
            $seen[$key] = count($disks);
            $disks[] = [
                'label' => $label,
                'path' => $path,
                'total' => (int) $total,
                'free' => (int) $free,
                'freePercent' => round($free / $total * 100, 1),
            ];
        }

        return $disks;
    }

    public function certExpiries(): array
    {
        $dir = config('ldev.certs_dir');
        if (!File::isDirectory($dir)) {
            return [];
        }

        $certs = [];
        foreach (File::glob($dir . '/*.pem') as $path) {
            if (str_ends_with($path, '-key.pem')) {
                continue;
            }
            $parsed = @openssl_x509_parse(File::get($path));
            if (!$parsed || !isset($parsed['validTo_time_t'])) {
                continue;
            }
            $certs[] = [
                'name' => basename($path, '.pem'),
                'expiresAt' => (int) $parsed['validTo_time_t'],
                'daysLeft' => (int) floor(($parsed['validTo_time_t'] - time()) / 86400),
            ];
        }

        usort($certs, fn ($a, $b) => $a['expiresAt'] <=> $b['expiresAt']);

        return $certs;
    }

    public function databaseSizes(): array
    {
        $mariadb = Process::run('mariadb -h 127.0.0.1 -u root -N -e ' . escapeshellarg('SELECT COALESCE(SUM(data_length+index_length),0) FROM information_schema.tables'));
        $pgsql = Process::run('psql -h 127.0.0.1 -U postgres -tAc ' . escapeshellarg('SELECT SUM(pg_database_size(datname)) FROM pg_database'));

        return [
            'MariaDB' => $mariadb->successful() && is_numeric(trim($mariadb->output())) ? (int) trim($mariadb->output()) : null,
            'PostgreSQL' => $pgsql->successful() && is_numeric(trim($pgsql->output())) ? (int) trim($pgsql->output()) : null,
        ];
    }

    public static function humanBytes(?int $bytes): string
    {
        if ($bytes === null) {
            return 'unknown';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return ($i === 0 ? (int) $value : number_format($value, 1)) . ' ' . $units[$i];
    }

    protected function osName(): ?string
    {
        if (!is_readable('/etc/os-release')) {
            return null;
        }

        return preg_match('/^PRETTY_NAME="?([^"\n]+)"?$/m', file_get_contents('/etc/os-release'), $m) ? $m[1] : null;
    }

    protected function uptime(): ?int
    {
        $raw = @file_get_contents('/proc/uptime');

        return $raw ? (int) floatval(explode(' ', $raw)[0]) : null;
    }

    protected function cpuCount(): ?int
    {
        $raw = @file_get_contents('/proc/cpuinfo');

        return $raw ? max(1, substr_count($raw, "\nprocessor") + (str_starts_with($raw, 'processor') ? 1 : 0)) : null;
    }

    protected function memory(): ?array
    {
        $raw = @file_get_contents('/proc/meminfo');
        if (!$raw) {
            return null;
        }

        preg_match('/^MemTotal:\s+(\d+)/m', $raw, $total);
        preg_match('/^MemAvailable:\s+(\d+)/m', $raw, $available);
        if (!$total || !$available) {
            return null;
        }

        $totalBytes = (int) $total[1] * 1024;
        $availableBytes = (int) $available[1] * 1024;

        return [
            'total' => $totalBytes,
            'used' => $totalBytes - $availableBytes,
            'usedPercent' => round(($totalBytes - $availableBytes) / $totalBytes * 100, 1),
        ];
    }
}
