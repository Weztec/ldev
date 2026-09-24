<?php

namespace App\Services;

use App\Models\Setting;

class UpdateSummary
{
    public function notices(): array
    {
        $notices = [];

        $ldev = $this->read('ldev');
        if (VersionChecker::ldevUpdateAvailable($ldev)) {
            $notices[] = ['key' => 'ldev', 'label' => 'Linux Dev', 'detail' => $ldev['latest'] . ' released'];
        }

        $php = $this->read('php');
        if (!empty($php['newCycles'])) {
            $notices[] = ['key' => 'php', 'label' => 'PHP', 'detail' => implode(', ', $php['newCycles']) . ' released'];
        }

        $node = $this->read('node');
        if ($node && ($node['isNew'] ?? false)) {
            $notices[] = ['key' => 'node', 'label' => 'Node.js', 'detail' => 'LTS ' . $node['latestLtsVersion'] . ' released'];
        }

        foreach (['laravel' => 'Laravel', 'livewire' => 'Livewire', 'flux' => 'Flux'] as $key => $label) {
            $check = $this->read($key);
            if ($check && !($check['satisfiesCurrent'] ?? true)) {
                $notices[] = ['key' => $key, 'label' => $label, 'detail' => $check['latest'] . ' (new major version)'];
            }
        }

        $dnf = $this->read('dnf');
        $count = count($dnf['packages'] ?? []);
        if ($count > 0) {
            $notices[] = ['key' => 'dnf', 'label' => 'System packages', 'detail' => $count . ' update' . ($count === 1 ? '' : 's') . ' available'];
        }

        return $notices;
    }

    public function fingerprint(): ?string
    {
        $notices = $this->notices();

        return empty($notices) ? null : substr(sha1(json_encode($notices)), 0, 12);
    }

    protected function read(string $key): ?array
    {
        $raw = Setting::get("version_check_{$key}");

        return $raw ? json_decode($raw, true) : null;
    }
}
