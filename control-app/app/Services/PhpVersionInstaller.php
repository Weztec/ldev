<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

class PhpVersionInstaller
{

    public function install(string $version): void
    {
        if (!preg_match('/^\d{2,3}$/', $version)) {
            throw new \InvalidArgumentException("'{$version}' isn't a valid PHP version identifier (expected e.g. \"86\" for PHP 8.6).");
        }

        Process::timeout(300)->run('sudo /usr/local/bin/ldev-install-php ' . escapeshellarg($version))->throw();
    }

    public function isAvailable(): bool
    {
        return is_executable('/usr/local/bin/ldev-install-php');
    }
}
