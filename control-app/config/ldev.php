<?php

$resolveHome = function (): string {
    $uid = getmyuid();
    foreach (@file('/etc/passwd', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $fields = explode(':', $line);
        if ((int) ($fields[2] ?? -1) === $uid) {
            return $fields[5] ?? '';
        }
    }
    return (string) getenv('HOME');
};
$home = $resolveHome();

$resolveUsername = function (): string {
    $uid = getmyuid();
    foreach (@file('/etc/passwd', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $fields = explode(':', $line);
        if ((int) ($fields[2] ?? -1) === $uid) {
            return $fields[0] ?? 'dev';
        }
    }
    return 'dev';
};

$resolveTimezone = function (): string {
    $link = @readlink('/etc/localtime');
    if ($link && preg_match('#zoneinfo/(.+)$#', $link, $m) && in_array($m[1], timezone_identifiers_list(), true)) {
        return $m[1];
    }
    return date_default_timezone_get();
};

$sourcePathFile = $home . '/.config/ldev/source-path';
$sourcePath = is_file($sourcePathFile) ? trim((string) file_get_contents($sourcePathFile)) : '';

$s3EnvFile = $home . '/.config/ldev/s3.env';
$s3Env = [];
if (is_file($s3EnvFile)) {
    foreach (file($s3EnvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        [$key, $value] = array_pad(explode('=', $line, 2), 2, null);
        if ($key !== null) {
            $s3Env[$key] = $value;
        }
    }
}

return [

    'version' => '1.1.0',

    'platform' => 'fedora',

    'github_repository' => 'Weztec/ldev',

    'home' => $home,

    'os_username' => $resolveUsername(),

    'timezone' => $resolveTimezone(),

    'source_path' => $sourcePath !== '' && is_dir($sourcePath) ? $sourcePath : null,

    'nginx_config_dir' => $home . '/.config/ldev/nginx',

    'tunnels_dir' => $home . '/.config/ldev/tunnels',

    'supervisor_config_dir' => '/etc/supervisord.d',

    'backups_dir' => $home . '/.config/ldev/backups',

    'site_configs_dir' => $home . '/.config/ldev/site-configs',

    'sites_path' => $home . '/Sites',

    's3_data_dir' => $home . '/.ldev/storage/s3-data',

    'dependency_sandbox_dir' => $home . '/.ldev/storage/dependency-sandbox',

    'test_runs_dir' => $home . '/.ldev/storage/test-runs',

    'dumps_dir' => $home . '/.ldev/storage/dumps',

    'dump_server' => '127.0.0.1:9912',

    'meilisearch_url' => 'http://127.0.0.1:7700',

    's3' => [
        'access_key' => $s3Env['RUSTFS_ACCESS_KEY'] ?? 'ldevlocal',
        'secret_key' => $s3Env['RUSTFS_SECRET_KEY'] ?? '',
        'endpoint' => 'http://127.0.0.1:9000',
        'console' => 'http://127.0.0.1:9001/rustfs/console/',
    ],

    'services' => [
        'system' => [
            'nginx', 'php-fpm', 'mariadb', 'postgresql', 'valkey', 'memcached', 'supervisord',
            'php74-php-fpm', 'php80-php-fpm', 'php81-php-fpm', 'php82-php-fpm',
            'php83-php-fpm', 'php84-php-fpm', 'php85-php-fpm',
        ],
        'user' => ['ldev-mailpit', 'ldev-rustfs', 'ldev-meilisearch', 'ldev-dumps'],
    ],

    'certs_dir' => $home . '/.config/ldev/certs',
];
