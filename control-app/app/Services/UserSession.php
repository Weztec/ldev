<?php

namespace App\Services;

class UserSession
{
    public static function env(): array
    {
        $runtimeDir = '/run/user/' . getmyuid();

        return [
            'XDG_RUNTIME_DIR' => $runtimeDir,
            'DBUS_SESSION_BUS_ADDRESS' => 'unix:path=' . $runtimeDir . '/bus',
        ];
    }
}
