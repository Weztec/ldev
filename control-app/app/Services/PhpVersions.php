<?php

namespace App\Services;

class PhpVersions
{
    public const MINIMUM = '7.4';

    protected static ?array $installed = null;

    public static function installed(): array
    {
        if (self::$installed !== null) {
            return self::$installed;
        }

        $versions = [];
        foreach (glob('/etc/opt/remi/php*/php-fpm.d/www.conf') ?: [] as $path) {
            if (!preg_match('#/php(\d)(\d{1,2})/php-fpm\.d/#', $path, $m)) {
                continue;
            }
            $version = $m[1] . '.' . $m[2];
            if (version_compare($version, self::MINIMUM, '>=')) {
                $versions[] = $version;
            }
        }

        usort($versions, 'version_compare');

        return self::$installed = array_values(array_unique($versions));
    }

    public static function latest(): ?string
    {
        $versions = self::installed();

        return $versions ? end($versions) : null;
    }

    public static function isInstalled(?string $version): bool
    {
        return in_array($version, self::installed(), true);
    }

    public static function fpmUnits(): array
    {
        return array_map(fn ($v) => 'php' . str_replace('.', '', $v) . '-php-fpm', self::installed());
    }

    public static function forget(): void
    {
        self::$installed = null;
    }
}
