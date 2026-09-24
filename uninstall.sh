#!/usr/bin/env bash
set -uo pipefail

if [[ $EUID -ne 0 || -z "${SUDO_USER:-}" ]]; then
    echo "This script must be run with sudo (not as a raw root login, e.g. 'su -') — it needs \$SUDO_USER to know which non-root account to configure"
    exit 1
fi

echo "🧹 Uninstalling Linux Dev..."

USER_ID="$(id -u "$SUDO_USER")"

sudo -u "$SUDO_USER" XDG_RUNTIME_DIR="/run/user/$USER_ID" systemctl --user disable --now ldev-dashboard.service 2>/dev/null
sudo -u "$SUDO_USER" XDG_RUNTIME_DIR="/run/user/$USER_ID" systemctl --user disable --now ldev-mailpit.service 2>/dev/null
sudo -u "$SUDO_USER" XDG_RUNTIME_DIR="/run/user/$USER_ID" systemctl --user disable --now ldev-minio.service 2>/dev/null
sudo -u "$SUDO_USER" XDG_RUNTIME_DIR="/run/user/$USER_ID" systemctl --user disable --now ldev-renew-certs.timer 2>/dev/null
sudo -u "$SUDO_USER" XDG_RUNTIME_DIR="/run/user/$USER_ID" systemctl --user disable --now ldev-scan-sites.timer 2>/dev/null
sudo -u "$SUDO_USER" XDG_RUNTIME_DIR="/run/user/$USER_ID" systemctl --user disable --now ldev-backup-databases.timer 2>/dev/null
sudo -u "$SUDO_USER" XDG_RUNTIME_DIR="/run/user/$USER_ID" systemctl --user disable --now ldev-check-versions.timer 2>/dev/null
sudo -u "$SUDO_USER" XDG_RUNTIME_DIR="/run/user/$USER_ID" systemctl --user disable --now ldev-check-health.timer 2>/dev/null
rm -f /etc/systemd/user/ldev-dashboard.service /etc/systemd/user/ldev-mailpit.service /etc/systemd/user/ldev-minio.service \
    /etc/systemd/user/ldev-renew-certs.service /etc/systemd/user/ldev-renew-certs.timer \
    /etc/systemd/user/ldev-scan-sites.service /etc/systemd/user/ldev-scan-sites.timer \
    /etc/systemd/user/ldev-backup-databases.service /etc/systemd/user/ldev-backup-databases.timer \
    /etc/systemd/user/ldev-check-versions.service /etc/systemd/user/ldev-check-versions.timer \
    /etc/systemd/user/ldev-check-health.service /etc/systemd/user/ldev-check-health.timer
sudo -u "$SUDO_USER" XDG_RUNTIME_DIR="/run/user/$USER_ID" systemctl --user daemon-reload 2>/dev/null

rm -f /etc/polkit-1/rules.d/10-ldev.rules

rm -f /etc/sudoers.d/ldev-hosts
if [[ -x /usr/local/bin/ldev-manage-hosts ]]; then
    awk '
        $0 == "# BEGIN Linux Dev" { inblock = 1; next }
        $0 == "# END Linux Dev" { inblock = 0; next }
        !inblock { print }
    ' /etc/hosts > /etc/hosts.ldev-uninstall-tmp && cat /etc/hosts.ldev-uninstall-tmp > /etc/hosts
    rm -f /etc/hosts.ldev-uninstall-tmp
fi
rm -f /usr/local/bin/ldev-manage-hosts

rm -f /etc/sudoers.d/ldev-install-php /usr/local/bin/ldev-install-php
rm -f /etc/dnsmasq.d/ldev.conf
rm -f /etc/systemd/resolved.conf.d/ldev-test.conf
rm -f /etc/nginx/conf.d/ldev.conf

rm -f /etc/supervisord.d/ldev.ini
rm -f /etc/supervisord.d/ldev-*.ini
chown root:root /etc/supervisord.d 2>/dev/null
rm -f /etc/php.d/99-ldev-mailpit.ini
rm -f /etc/php.d/99-ldev-variables-order.ini
for version in 74 80 81 82 83 84 85; do
    rm -f "/etc/opt/remi/php${version}/php.d/99-ldev-mailpit.ini"
    rm -f "/etc/opt/remi/php${version}/php.d/99-ldev-variables-order.ini"
done
rm -f /etc/pki/ca-trust/source/anchors/ldev-mkcert-rootCA.pem
rm -f /etc/my.cnf.d/99-ldev-skip-name-resolve.cnf

echo "Reloading systemd-resolved, nginx, supervisord, mariadb, and the system CA trust store..."
systemctl restart systemd-resolved 2>/dev/null
systemctl reload nginx 2>/dev/null
systemctl restart supervisord 2>/dev/null
systemctl restart mariadb 2>/dev/null
update-ca-trust 2>/dev/null

rm -rf "/home/$SUDO_USER/.ldev/app"
rm -rf "/home/$SUDO_USER/.ldev/scripts"
rm -rf "/home/$SUDO_USER/.config/ldev"
rm -rf "/home/$SUDO_USER/.ldev/storage/dependency-sandbox"
rm -rf "/home/$SUDO_USER/.ldev/storage/test-runs"
rm -f "/home/$SUDO_USER/.local/share/applications/ldev.desktop"

echo "✅ Linux Dev uninstalled."
echo "Left untouched on purpose: installed packages (nginx, php, mariadb, postgresql, valkey,"
echo "memcached, supervisor, node, composer, mkcert, mailpit, minio), /home/$SUDO_USER/Sites"
echo "(your project files) and /home/$SUDO_USER/.ldev/storage (MinIO bucket data), if any exist there."
echo "Remove those by hand if you actually want them gone too."
