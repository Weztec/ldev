<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Process;

class DesktopNotifier
{
    public const TIMEOUTS = [0, 10, 30, 60];

    public static function timeoutSeconds(): int
    {
        $value = (int) Setting::get('notification_timeout_seconds', '0');

        return in_array($value, self::TIMEOUTS, true) ? $value : 0;
    }

    public static function enabled(): bool
    {
        return Setting::get('desktop_notifications', '1') === '1';
    }

    public function send(string $title, string $body, string $urgency = 'normal'): bool
    {
        if (!self::enabled()) {
            return false;
        }

        if (!in_array($urgency, ['low', 'normal', 'critical'], true)) {
            $urgency = 'normal';
        }

        $result = Process::env(array_merge(UserSession::env(), [
            'HOME' => config('ldev.home'),
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
        ]))->run(
            'notify-send --app-name=' . escapeshellarg('Linux Dev')
            . ' --icon=' . escapeshellarg('network-server')
            . ' --urgency=' . escapeshellarg($urgency)
            . ' --expire-time=' . escapeshellarg((string) (self::timeoutSeconds() * 1000))
            . ' ' . escapeshellarg($title) . ' ' . escapeshellarg($body)
        );

        return $result->successful();
    }
}
