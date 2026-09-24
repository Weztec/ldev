<?php

namespace App\Services;

use App\Models\Site;
use App\Models\SiteProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class SupervisorConfigValidator
{

    public function validate(Site $site, string $rendered): ?string
    {
        if ($problem = $this->checkExtra($site)) {
            return $problem;
        }

        return $this->runSupervisorParser($site, $rendered);
    }

    public function checkExtra(Site $site): ?string
    {
        $extra = (string) $site->supervisor_extra;
        if (trim($extra) === '') {
            return null;
        }

        if (preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $extra)) {
            return 'Extra config contains control characters.';
        }

        $prefix = $site->name . '-';
        $reserved = array_map(fn ($r) => $prefix . $r, SiteProcess::RESERVED_NAMES);
        $taken = $site->processes->pluck('name')->map(fn ($n) => $prefix . $n)->all();
        $seen = [];

        if (!preg_match('/^\s*(\[|;|#|$)/', ltrim($extra, "\r\n")) || !preg_match('/^\s*\[program:/m', $extra)) {
            return 'Extra config must start with a [program:' . $prefix . 'name] section.';
        }

        foreach (preg_split('/\r\n|\r|\n/', $extra) as $line) {
            if (!preg_match('/^\s*\[(.*)$/', $line, $m)) {
                continue;
            }

            if (!preg_match('/^program:(' . preg_quote($prefix, '/') . '[a-z0-9][a-z0-9_-]*)\]\s*$/', $m[1], $p)) {
                return "Only [program:{$prefix}...] sections are allowed here (found \"[{$m[1]}\").";
            }

            $program = $p[1];
            if (in_array($program, $reserved, true)) {
                return "{$program} is one of this site's built-in programs — pick another name.";
            }
            if (in_array($program, $taken, true)) {
                return "{$program} is already defined in the job list above.";
            }
            if (isset($seen[$program])) {
                return "{$program} is defined twice.";
            }
            $seen[$program] = true;
        }

        return null;
    }

    protected function runSupervisorParser(Site $site, string $rendered): ?string
    {
        $candidate = tempnam(sys_get_temp_dir(), 'ldev-sv-');
        File::put($candidate, $rendered);

        try {
            $own = config('ldev.supervisor_config_dir') . '/ldev-' . $site->name . '.ini';
            $others = array_values(array_filter(
                glob(config('ldev.supervisor_config_dir') . '/*.ini') ?: [],
                fn ($f) => $f !== $own
            ));

            $result = Process::inProject(base_path())->run(array_merge(
                ['/usr/bin/python3', base_path('resources/scripts/validate-supervisor.py'), $candidate],
                $others
            ));
        } finally {
            File::delete($candidate);
        }

        $decoded = json_decode(trim($result->output()), true);
        if (!is_array($decoded) || !array_key_exists('ok', $decoded)) {

            return 'Could not validate the Supervisor config: ' . (trim($result->errorOutput()) ?: 'validator produced no result') . '.';
        }

        return $decoded['ok'] ? null : (string) ($decoded['error'] ?? 'Invalid Supervisor config.');
    }
}
