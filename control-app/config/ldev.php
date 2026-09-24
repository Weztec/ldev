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

$minioEnvFile = $home . '/.config/ldev/minio.env';
$minioEnv = [];
if (is_file($minioEnvFile)) {
    foreach (file($minioEnvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        [$key, $value] = array_pad(explode('=', $line, 2), 2, null);
        if ($key !== null) {
            $minioEnv[$key] = $value;
        }
    }
}

return [

    'version' => '1.0.0',

    'platform' => 'fedora',

    'github_repository' => 'Weztec/ldev',

    'home' => $home,

    'os_username' => $resolveUsername(),

    'nginx_config_dir' => $home . '/.config/ldev/nginx',

    'tunnels_dir' => $home . '/.config/ldev/tunnels',

    'supervisor_config_dir' => '/etc/supervisord.d',

    'backups_dir' => $home . '/.config/ldev/backups',

    'site_configs_dir' => $home . '/.config/ldev/site-configs',

    'sites_path' => $home . '/Sites',

    'minio_data_dir' => $home . '/.ldev/storage/minio-data',

    'dependency_sandbox_dir' => $home . '/.ldev/storage/dependency-sandbox',

    'test_runs_dir' => $home . '/.ldev/storage/test-runs',

    'minio' => [
        'access_key' => $minioEnv['MINIO_ROOT_USER'] ?? 'ldevlocal',
        'secret_key' => $minioEnv['MINIO_ROOT_PASSWORD'] ?? '',
        'endpoint' => 'http://127.0.0.1:9000',
        'console' => 'http://127.0.0.1:9001',
    ],

    'services' => [
        'system' => [
            'nginx', 'php-fpm', 'mariadb', 'postgresql', 'valkey', 'memcached', 'supervisord',
            'php74-php-fpm', 'php80-php-fpm', 'php81-php-fpm', 'php82-php-fpm',
            'php83-php-fpm', 'php84-php-fpm', 'php85-php-fpm',
        ],
        'user' => ['ldev-mailpit', 'ldev-minio'],
    ],

    'certs_dir' => $home . '/.config/ldev/certs',
];
