#!/usr/bin/env bash
set -euo pipefail

if [[ $EUID -ne 0 || -z "${SUDO_USER:-}" ]]; then
    echo "This script must be run with sudo (not as a raw root login, e.g. 'su -') — it needs \$SUDO_USER to know which non-root account to configure"
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if ! command -v composer >/dev/null || ! command -v php >/dev/null; then
    echo "composer/php not found — run setup-environment.sh first" >&2
    exit 1
fi

echo "🚀 Deploying Linux Dev control app..."

if [[ ! -d "$SCRIPT_DIR/control-app" ]]; then
    echo "control-app/ not found next to deploy-app.sh — nothing to overlay onto the Laravel skeleton" >&2
    exit 1
fi

if [[ ! -f "/home/$SUDO_USER/.ldev/app/artisan" ]]; then

    APP_DIR="/home/$SUDO_USER/.ldev/app"
    sudo -u "$SUDO_USER" bash -c "export PATH=\"\$PATH:\$HOME/.config/composer/vendor/bin\"; laravel new \"$APP_DIR\" --no-interaction"
fi

if [[ -f "/home/$SUDO_USER/.ldev/app/artisan" ]]; then
    sudo -u "$SUDO_USER" php "/home/$SUDO_USER/.ldev/app/artisan" ldev:backup-dashboard \
        || echo "⚠️  Dashboard backup failed — continuing, but there is no fresh snapshot to fall back on" >&2
fi
rsync -a "$SCRIPT_DIR/control-app/" "/home/$SUDO_USER/.ldev/app/"
sudo -u "$SUDO_USER" mkdir -p "/home/$SUDO_USER/.config/ldev"
printf '%s\n' "$SCRIPT_DIR" | sudo -u "$SUDO_USER" tee "/home/$SUDO_USER/.config/ldev/source-path" >/dev/null
for retired in \
    2024_01_01_000007_add_worker_flags_to_sites_table.php \
    2024_01_01_000009_add_node_version_to_sites_table.php \
    2024_01_01_000010_add_expires_at_to_repository_tokens_table.php \
    2024_01_01_000011_add_queue_worker_tuning_to_sites_table.php \
    2024_01_01_000013_add_scheduler_and_auto_backup_to_sites_table.php \
    2024_01_01_000014_add_queue_names_to_sites_table.php \
    2024_01_01_000017_add_site_id_to_composer_credentials_table.php; do
    rm -f "/home/$SUDO_USER/.ldev/app/database/migrations/$retired"
done

curl -fSL --retry 3 --retry-delay 2 https://www.adminer.org/latest.php -o "/home/$SUDO_USER/.ldev/app/public/adminer.php.new"
mv "/home/$SUDO_USER/.ldev/app/public/adminer.php.new" "/home/$SUDO_USER/.ldev/app/public/adminer.php"

chown -R "$SUDO_USER:$SUDO_USER" "/home/$SUDO_USER/.ldev/app"

cd "/home/$SUDO_USER/.ldev/app"

sudo -u "$SUDO_USER" composer update
sudo -u "$SUDO_USER" npm install

sudo -u "$SUDO_USER" npm install -D tailwindcss @tailwindcss/vite
sudo -u "$SUDO_USER" npm run build
[[ -f .env ]] || sudo -u "$SUDO_USER" cp .env.example .env

grep -q '^APP_KEY=.\+' .env || sudo -u "$SUDO_USER" php artisan key:generate
sudo -u "$SUDO_USER" php artisan migrate --force
sudo -u "$SUDO_USER" php artisan ldev:generate-token

cat > /etc/systemd/user/ldev-dashboard.service <<EOF
[Unit]
Description=Linux Dev Dashboard

[Service]
ExecStart=/usr/bin/php /home/$SUDO_USER/.ldev/app/artisan serve --host=127.0.0.1 --port=8090
Restart=always

[Install]
WantedBy=default.target
EOF

cat > /etc/systemd/user/ldev-dumps.service <<EOF
[Unit]
Description=Linux Dev dump() collector

[Service]
ExecStart=/usr/bin/php /home/$SUDO_USER/.ldev/app/artisan ldev:dump-server
Restart=always

[Install]
WantedBy=default.target
EOF

loginctl enable-linger "$SUDO_USER"
sudo -u "$SUDO_USER" XDG_RUNTIME_DIR="/run/user/$(id -u "$SUDO_USER")" systemctl --user daemon-reload
sudo -u "$SUDO_USER" XDG_RUNTIME_DIR="/run/user/$(id -u "$SUDO_USER")" systemctl --user enable ldev-dashboard.service ldev-dumps.service
sudo -u "$SUDO_USER" XDG_RUNTIME_DIR="/run/user/$(id -u "$SUDO_USER")" systemctl --user restart ldev-dashboard.service ldev-dumps.service

cat > /etc/systemd/user/ldev-renew-certs.service <<EOF
[Unit]
Description=Linux Dev certificate renewal check

[Service]
Type=oneshot
ExecStart=/usr/bin/php /home/$SUDO_USER/.ldev/app/artisan ldev:renew-certs
EOF

cat > /etc/systemd/user/ldev-renew-certs.timer <<EOF
[Unit]
Description=Run the Linux Dev certificate renewal check daily

[Timer]
OnCalendar=daily
Persistent=true

[Install]
WantedBy=timers.target
EOF

sudo -u "$SUDO_USER" XDG_RUNTIME_DIR="/run/user/$(id -u "$SUDO_USER")" systemctl --user enable ldev-renew-certs.timer
sudo -u "$SUDO_USER" XDG_RUNTIME_DIR="/run/user/$(id -u "$SUDO_USER")" systemctl --user restart ldev-renew-certs.timer

cat > /etc/systemd/user/ldev-scan-sites.service <<EOF
[Unit]
Description=Linux Dev new-project detection scan

[Service]
Type=oneshot
ExecStart=/usr/bin/php /home/$SUDO_USER/.ldev/app/artisan ldev:scan-sites
EOF

cat > /etc/systemd/user/ldev-scan-sites.timer <<EOF
[Unit]
Description=Run the Linux Dev new-project detection scan every 5 minutes

[Timer]
OnBootSec=1min
OnUnitActiveSec=5min

[Install]
WantedBy=timers.target
EOF

sudo -u "$SUDO_USER" XDG_RUNTIME_DIR="/run/user/$(id -u "$SUDO_USER")" systemctl --user enable ldev-scan-sites.timer
sudo -u "$SUDO_USER" XDG_RUNTIME_DIR="/run/user/$(id -u "$SUDO_USER")" systemctl --user restart ldev-scan-sites.timer

cat > /etc/systemd/user/ldev-backup-databases.service <<EOF
[Unit]
Description=Linux Dev automatic database backup

[Service]
Type=oneshot
ExecStart=/usr/bin/php /home/$SUDO_USER/.ldev/app/artisan ldev:backup-databases
EOF

cat > /etc/systemd/user/ldev-backup-databases.timer <<EOF
[Unit]
Description=Run the Linux Dev automatic database backup daily

[Timer]
OnCalendar=daily
Persistent=true

[Install]
WantedBy=timers.target
EOF

sudo -u "$SUDO_USER" XDG_RUNTIME_DIR="/run/user/$(id -u "$SUDO_USER")" systemctl --user enable ldev-backup-databases.timer
sudo -u "$SUDO_USER" XDG_RUNTIME_DIR="/run/user/$(id -u "$SUDO_USER")" systemctl --user restart ldev-backup-databases.timer

cat > /etc/systemd/user/ldev-check-versions.service <<EOF
[Unit]
Description=Linux Dev upstream version check

[Service]
Type=oneshot
ExecStart=/usr/bin/php /home/$SUDO_USER/.ldev/app/artisan ldev:check-versions
EOF

cat > /etc/systemd/user/ldev-check-versions.timer <<EOF
[Unit]
Description=Run the Linux Dev upstream version check daily

[Timer]
OnCalendar=daily
Persistent=true

[Install]
WantedBy=timers.target
EOF

sudo -u "$SUDO_USER" XDG_RUNTIME_DIR="/run/user/$(id -u "$SUDO_USER")" systemctl --user enable ldev-check-versions.timer
sudo -u "$SUDO_USER" XDG_RUNTIME_DIR="/run/user/$(id -u "$SUDO_USER")" systemctl --user restart ldev-check-versions.timer

cat > /etc/systemd/user/ldev-check-health.service <<EOF
[Unit]
Description=Linux Dev health check (failed services, certificates, disk space)

[Service]
Type=oneshot
ExecStart=/usr/bin/php /home/$SUDO_USER/.ldev/app/artisan ldev:check-health
EOF

cat > /etc/systemd/user/ldev-check-health.timer <<EOF
[Unit]
Description=Run the Linux Dev health check every 5 minutes

[Timer]
OnBootSec=2min
OnUnitActiveSec=5min

[Install]
WantedBy=timers.target
EOF

sudo -u "$SUDO_USER" XDG_RUNTIME_DIR="/run/user/$(id -u "$SUDO_USER")" systemctl --user enable ldev-check-health.timer
sudo -u "$SUDO_USER" XDG_RUNTIME_DIR="/run/user/$(id -u "$SUDO_USER")" systemctl --user restart ldev-check-health.timer

mkdir -p "/home/$SUDO_USER/.ldev/scripts"
cat > "/home/$SUDO_USER/.ldev/scripts/launch-dashboard.sh" <<'EOF'
#!/bin/bash
systemctl --user is-active --quiet ldev-dashboard.service || systemctl --user start ldev-dashboard.service
sleep 1
TOKEN="$(cat "$HOME/.config/ldev/token" 2>/dev/null)"
xdg-open "http://127.0.0.1:8090/?token=${TOKEN}"
EOF
chmod +x "/home/$SUDO_USER/.ldev/scripts/launch-dashboard.sh"

mkdir -p "/home/$SUDO_USER/.local/share/applications"
cat > "/home/$SUDO_USER/.local/share/applications/ldev.desktop" <<EOF
[Desktop Entry]
Version=1.0
Type=Application
Name=Linux Dev
Comment=Local Development Dashboard
Exec=/home/$SUDO_USER/.ldev/scripts/launch-dashboard.sh
Icon=/home/$SUDO_USER/.ldev/app/public/icon-256.png
Terminal=false
Categories=Development;WebDevelopment;
StartupNotify=true
EOF
chown -R "$SUDO_USER:$SUDO_USER" "/home/$SUDO_USER/.ldev/scripts" "/home/$SUDO_USER/.local/share/applications"

echo "✅ Linux Dev control app deployed!"
echo "Dashboard running at http://127.0.0.1:8090"
echo "Token stored in ~/.config/ldev/token"
