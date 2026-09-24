<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class GitInitializer
{

    public function initIfNeeded(string $projectPath): void
    {
        if (File::isDirectory($projectPath . '/.git')) {
            return;
        }

        [$name, $email] = $this->identity();

        Process::inProject($projectPath)->run('git init')->throw();
        Process::inProject($projectPath)->run('git add -A')->throw();
        Process::inProject($projectPath)->run(
            'git -c user.name=' . escapeshellarg($name)
            . ' -c user.email=' . escapeshellarg($email)
            . ' commit -m ' . escapeshellarg('Initial commit')
        )->throw();
        Process::inProject($projectPath)->run('git branch -M main')->throw();
    }

    public function identity(): array
    {
        $name = trim(Process::env(['HOME' => config('ldev.home')])->run('git config --global user.name')->output());
        $email = trim(Process::env(['HOME' => config('ldev.home')])->run('git config --global user.email')->output());

        $username = config('ldev.os_username');

        return [
            $name !== '' ? $name : $username,
            $email !== '' ? $email : "{$username}@localhost",
        ];
    }
}
