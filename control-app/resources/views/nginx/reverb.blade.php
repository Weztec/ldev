
server {
    listen 80;
    listen 443 ssl;
    http2 on;
    server_name {{ $site->name }}-reverb.test;

    ssl_certificate {{ config('ldev.home') }}/.config/ldev/certs/test.pem;
    ssl_certificate_key {{ config('ldev.home') }}/.config/ldev/certs/test-key.pem;

    location / {
        proxy_pass http://127.0.0.1:{{ $site->reverb_port }};
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_read_timeout 60s;
    }
}
