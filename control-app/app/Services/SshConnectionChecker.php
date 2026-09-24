<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class SshConnectionChecker
{

    public const KNOWN_HOSTS = ['github.com', 'bitbucket.org'];

    public function diagnose(array $hosts = self::KNOWN_HOSTS): array
    {
        $hasKey = $this->hasLocalKey();

        $results = [];
        foreach ($hosts as $host) {
            $results[$host] = $hasKey
                ? $this->testHost($host)
                : ['ok' => false, 'message' => 'No local SSH key found.'];
        }

        return ['hasLocalKey' => $hasKey, 'hosts' => $results];
    }

    public function hasLocalKey(): bool
    {
        $sshDir = config('ldev.home') . '/.ssh';
        foreach (['id_ed25519', 'id_rsa', 'id_ecdsa', 'id_dsa'] as $name) {
            if (File::exists("{$sshDir}/{$name}")) {
                return true;
            }
        }

        $agent = Process::env(['HOME' => config('ldev.home')])->run('ssh-add -l');
        return $agent->successful() && !str_contains($agent->output(), 'no identities');
    }

    public function testHost(string $host): array
    {
        $result = Process::env(['HOME' => config('ldev.home')])->run(
            'ssh -T -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=5 git@' . escapeshellarg($host)
        );
        $output = trim($result->output() . "\n" . $result->errorOutput());

        if (str_contains($output, 'Permission denied')) {
            return ['ok' => false, 'message' => "No SSH key registered with {$host} for this identity."];
        }

        foreach (['Could not resolve hostname', 'Connection refused', 'Connection timed out', 'Operation timed out'] as $networkFailure) {
            if (str_contains($output, $networkFailure)) {
                return ['ok' => false, 'message' => "Could not reach {$host}: {$output}"];
            }
        }

        return ['ok' => true, 'message' => $output];
    }
}
