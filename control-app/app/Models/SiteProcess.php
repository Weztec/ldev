<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteProcess extends Model
{

    const NAME_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,39}$/';

    const RESERVED_NAMES = ['queue', 'reverb', 'scheduler'];

    const PRESETS = [
        'horizon' => [
            'label' => 'Laravel Horizon',
            'name' => 'horizon',
            'command' => 'php artisan horizon',

            'stopwaitsecs' => 3600,
        ],
        'pulse' => [
            'label' => 'Laravel Pulse',
            'name' => 'pulse',
            'command' => 'php artisan pulse:work',
            'stopwaitsecs' => 10,
        ],
        'queue-listen' => [
            'label' => 'Queue listener (reloads code on every job — handy while testing)',
            'name' => 'queue-listen',
            'command' => 'php artisan queue:listen --tries=1',
            'stopwaitsecs' => 60,
        ],
        'queue-named' => [
            'label' => 'Queue worker for one specific queue',
            'name' => 'queue-custom',
            'command' => 'php artisan queue:work --queue=CHANGE-ME --sleep=3 --tries=3 --max-time=3600',
            'stopwaitsecs' => 3600,
        ],
        'scheduled' => [
            'label' => 'Artisan command on a schedule',
            'name' => 'scheduled-task',
            'command' => 'php artisan CHANGE-ME',
            'schedule_preset' => 'daily',
            'stopwaitsecs' => 30,
        ],
        'nightwatch' => [
            'label' => 'Laravel Nightwatch agent',
            'name' => 'nightwatch',
            'command' => 'php artisan nightwatch:agent',
            'stopwaitsecs' => 10,
        ],
    ];

    const SCHEDULE_PATTERN = '/^[0-9a-zA-Z*,\/-]+( [0-9a-zA-Z*,\/-]+){4}$/';

    const SCHEDULE_PRESETS = [
        'every-5' => ['label' => 'Every 5 minutes', 'cron' => '*/5 * * * *', 'timed' => false],
        'hourly' => ['label' => 'Hourly', 'cron' => '{m} * * * *', 'timed' => false],
        'daily' => ['label' => 'Daily', 'cron' => '{m} {h} * * *', 'timed' => true],
        'weekdays' => ['label' => 'Weekdays (Mon–Fri)', 'cron' => '{m} {h} * * 1-5', 'timed' => true],
        'weekly' => ['label' => 'Weekly (Mondays)', 'cron' => '{m} {h} * * 1', 'timed' => true],
        'twice-weekly' => ['label' => 'Twice a week (Mon & Thu)', 'cron' => '{m} {h} * * 1,4', 'timed' => true],
        'monthly' => ['label' => 'Monthly (1st)', 'cron' => '{m} {h} 1 * *', 'timed' => true],
        'twice-monthly' => ['label' => 'Twice a month (1st & 15th)', 'cron' => '{m} {h} 1,15 * *', 'timed' => true],
    ];

    protected $fillable = [
        'site_id',
        'name',
        'command',
        'schedule',
        'numprocs',
        'stopwaitsecs',
        'autostart',
        'autorestart',
        'enabled',
    ];

    protected $casts = [
        'autostart' => 'boolean',
        'autorestart' => 'boolean',
        'enabled' => 'boolean',
    ];

    public function supervisorCommand(): string
    {
        if (!$this->schedule) {
            return $this->command;
        }

        return '/usr/bin/python3 -u ' . base_path('resources/scripts/run-scheduled.py')
            . " '{$this->schedule}' -- " . $this->command;
    }

    public function scheduleLabel(): string
    {
        return $this->schedule ? "Cron: {$this->schedule}" : 'Continuous';
    }

    public static function nextRuns(string $cron, int $n = 3): array
    {
        if (!preg_match(self::SCHEDULE_PATTERN, $cron)) {
            return ['ok' => false, 'error' => 'Use 5 space-separated cron fields (minute hour day-of-month month day-of-week).'];
        }

        $result = \Illuminate\Support\Facades\Process::run(['/usr/bin/python3', base_path('resources/scripts/run-scheduled.py'), '--next', (string) $n, $cron]);
        $decoded = json_decode(trim($result->output()), true);

        return is_array($decoded) ? $decoded : ['ok' => false, 'error' => 'Could not check the schedule.'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
