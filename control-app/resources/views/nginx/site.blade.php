
server {
    listen 80;
    listen 443 ssl;
    http2 on;
    server_name {{ $site->domain }} *.{{ $site->name }}.test;

    ssl_certificate {{ config('ldev.home') }}/.config/ldev/certs/{{ $site->name }}.pem;
    ssl_certificate_key {{ config('ldev.home') }}/.config/ldev/certs/{{ $site->name }}-key.pem;

    root {{ $site->document_root }};

    index index.php index.html index.htm;

    access_log {{ config('ldev.home') }}/.config/ldev/logs/{{ $site->domain }}-access.log;
    error_log {{ config('ldev.home') }}/.config/ldev/logs/{{ $site->domain }}-error.log;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/opt/remi/php{{ str_replace('.', '', $site->php_version) }}/run/php-fpm/www.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
@if($site->xdebug_enabled)
        fastcgi_param XDEBUG_MODE debug;
@endif
    }

    location ~ /\.ht {
        deny all;
    }
}
