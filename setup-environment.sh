#!/usr/bin/env bash
set -euo pipefail

if [[ $EUID -ne 0 || -z "${SUDO_USER:-}" ]]; then
    echo "This script must be run with sudo (not as a raw root login, e.g. 'su -') — it needs \$SUDO_USER to know which non-root account to configure"
    exit 1
fi

OLD_APP_DIR="/home/$SUDO_USER/.fldev"
NEW_APP_DIR="/home/$SUDO_USER/.ldev"
OLD_CFG_DIR="/home/$SUDO_USER/.config/fldev"
NEW_CFG_DIR="/home/$SUDO_USER/.config/ldev"

if [[ -d "$OLD_APP_DIR" || -d "$OLD_CFG_DIR" ]]; then
    echo "🔁 Migrating this machine from the old 'fldev' naming to 'ldev' (one-time, safe to re-run)..."

    for unit in fldev-dashboard fldev-mailpit fldev-minio fldev-renew-certs fldev-backup-databases fldev-scan-sites fldev-check-versions; do
        sudo -u "$SUDO_USER" XDG_RUNTIME_DIR="/run/user/$(id -u "$SUDO_USER")" systemctl --user stop "${unit}.service" 2>/dev/null || true
        sudo -u "$SUDO_USER" XDG_RUNTIME_DIR="/run/user/$(id -u "$SUDO_USER")" systemctl --user stop "${unit}.timer" 2>/dev/null || true
        sudo -u "$SUDO_USER" XDG_RUNTIME_DIR="/run/user/$(id -u "$SUDO_USER")" systemctl --user disable "${unit}.service" 2>/dev/null || true
        sudo -u "$SUDO_USER" XDG_RUNTIME_DIR="/run/user/$(id -u "$SUDO_USER")" systemctl --user disable "${unit}.timer" 2>/dev/null || true
        rm -f "/etc/systemd/user/${unit}.service" "/etc/systemd/user/${unit}.timer"
    done
    sudo -u "$SUDO_USER" XDG_RUNTIME_DIR="/run/user/$(id -u "$SUDO_USER")" systemctl --user daemon-reload 2>/dev/null || true

    [[ -d "$OLD_APP_DIR" && ! -e "$NEW_APP_DIR" ]] && mv "$OLD_APP_DIR" "$NEW_APP_DIR"

    [[ -d "$OLD_CFG_DIR" && ! -e "$NEW_CFG_DIR" ]] && mv "$OLD_CFG_DIR" "$NEW_CFG_DIR"
    chown -R "$SUDO_USER:$SUDO_USER" "$NEW_APP_DIR" "$NEW_CFG_DIR" 2>/dev/null || true

    rm -f /etc/nginx/conf.d/fldev.conf /etc/dnsmasq.d/fldev.conf /etc/polkit-1/rules.d/10-fldev.rules \
          /etc/my.cnf.d/99-fldev-skip-name-resolve.cnf /etc/php.d/99-fldev-mailpit.ini /etc/php.d/99-fldev-variables-order.ini
    for version in 74 80 81 82 83 84 85; do
        rm -f "/etc/opt/remi/php${version}/php.d/99-fldev-mailpit.ini" "/etc/opt/remi/php${version}/php.d/99-fldev-variables-order.ini"
    done

    rm -f /etc/supervisord.d/fldev-*.ini

    rm -f "/home/$SUDO_USER/.local/share/applications/fldev.desktop"

    sudo -u "$SUDO_USER" mc alias remove fldevlocal >/dev/null 2>&1 || true

    echo "✅ Migration done — continuing with normal setup under the new naming."
fi

echo "🚀 Starting Linux Dev environment setup..."

dnf install -y https://rpms.remirepo.net/fedora/remi-release-44.rpm
dnf config-manager enable remi

dnf install -y nginx dnsmasq mkcert nss-tools git curl wget unzip rsync \
    nodejs npm mariadb-server postgresql-server valkey memcached supervisor \
    php php-cli php-fpm php-mysqlnd php-pgsql php-gd php-mbstring \
    php-xml php-curl php-zip php-intl php-pecl-redis6 php-pecl-memcached php-pecl-xdebug3 \
    policycoreutils-python-utils podman

for version in 74 80 81 82 83 84 85; do
    dnf install -y php${version} php${version}-php-fpm php${version}-php-cli \
        php${version}-php-mysqlnd php${version}-php-pgsql php${version}-php-gd \
        php${version}-php-mbstring php${version}-php-xml php${version}-php-curl \
        php${version}-php-zip php${version}-php-intl php${version}-php-pecl-redis6 \
        php${version}-php-pecl-memcached php${version}-php-pecl-xdebug3
done

for wwwconf in /etc/php-fpm.d/www.conf $(for v in 74 80 81 82 83 84 85; do echo "/etc/opt/remi/php${v}/php-fpm.d/www.conf"; done); do
    sed -i \
        -e 's/^;\?listen\.owner\s*=.*/listen.owner = nginx/' \
        -e 's/^;\?listen\.group\s*=.*/listen.group = nginx/' \
        -e 's/^;\?listen\.mode\s*=.*/listen.mode = 0660/' \
        -e 's/^listen\.acl_users\s*=.*/;listen.acl_users = apache/' \
        -e "s/^user\s*=.*/user = $SUDO_USER/" \
        -e "s/^group\s*=.*/group = $SUDO_USER/" \
        "$wwwconf"
done

curl -fSL --retry 3 --retry-delay 2 https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

npm install -g yarn

NVM_VERSION="v0.40.8"
if [[ ! -d "/home/$SUDO_USER/.nvm" ]]; then
    sudo -u "$SUDO_USER" bash -c "curl -fSL --retry 3 --retry-delay 2 https://raw.githubusercontent.com/nvm-sh/nvm/$NVM_VERSION/install.sh | bash"
fi

curl -fSL --retry 3 --retry-delay 2 https://github.com/axllent/mailpit/releases/latest/download/mailpit-linux-amd64.tar.gz | tar xzf - -C /usr/local/bin mailpit
curl -fSL --retry 3 --retry-delay 2 -o /usr/local/bin/meilisearch.new https://github.com/meilisearch/meilisearch/releases/latest/download/meilisearch-linux-amd64
chmod 0755 /usr/local/bin/meilisearch.new
mv /usr/local/bin/meilisearch.new /usr/local/bin/meilisearch

RUSTFS_PINNED_VERSION="1.0.0"
if ! /usr/local/bin/rustfs --version 2>/dev/null | head -1 | grep -qxF "rustfs ${RUSTFS_PINNED_VERSION}"; then
    RUSTFS_TMP="$(mktemp -d)"
    curl -fSL --retry 3 --retry-delay 2 -o "$RUSTFS_TMP/rustfs.zip" "https://github.com/rustfs/rustfs/releases/download/${RUSTFS_PINNED_VERSION}/rustfs-linux-x86_64-musl-v${RUSTFS_PINNED_VERSION}.zip"
    unzip -o -q "$RUSTFS_TMP/rustfs.zip" rustfs -d "$RUSTFS_TMP"
    install -m 0755 "$RUSTFS_TMP/rustfs" /usr/local/bin/rustfs.new
    mv /usr/local/bin/rustfs.new /usr/local/bin/rustfs
    rm -rf "$RUSTFS_TMP"
fi


sudo -u "$SUDO_USER" composer global require laravel/installer
if ! grep -qxF 'export PATH="$PATH:$HOME/.config/composer/vendor/bin"' "/home/$SUDO_USER/.bashrc"; then
    echo 'export PATH="$PATH:$HOME/.config/composer/vendor/bin"' >> "/home/$SUDO_USER/.bashrc"
fi

dnf config-manager addrepo --overwrite --from-repofile=https://pkg.cloudflare.com/cloudflared.repo
dnf install -y cloudflared

mkdir -p "/home/$SUDO_USER/.ldev/storage/s3-data"
mkdir -p "/home/$SUDO_USER/.ldev/storage/meilisearch"
mkdir -p "/home/$SUDO_USER/Sites"
mkdir -p "/home/$SUDO_USER/.config/ldev/"{nginx,php,certs,logs,supervisor,tunnels,mailpit}
chown -R "$SUDO_USER:$SUDO_USER" "/home/$SUDO_USER/.ldev" "/home/$SUDO_USER/Sites" "/home/$SUDO_USER/.config/ldev"

chgrp apache "/home/$SUDO_USER/.config/ldev/logs"
chmod 2775 "/home/$SUDO_USER/.config/ldev/logs"

selinux_label() {
    local path="$1" type="$2"
    semanage fcontext -a -t "$type" "${path}(/.*)?" 2>/dev/null \
        || semanage fcontext -m -t "$type" "${path}(/.*)?"
    restorecon -Rv "$path" >/dev/null
}
selinux_label "/home/$SUDO_USER/Sites" httpd_sys_rw_content_t
selinux_label "/home/$SUDO_USER/.config/ldev/nginx" httpd_config_t
selinux_label "/home/$SUDO_USER/.config/ldev/certs" httpd_config_t
selinux_label "/home/$SUDO_USER/.config/ldev/logs" httpd_log_t

cat > /etc/dnsmasq.d/ldev.conf <<EOF
bind-interfaces
listen-address=127.0.0.1
address=/test/127.0.0.1
EOF

mkdir -p /etc/systemd/resolved.conf.d
cat > /etc/systemd/resolved.conf.d/ldev-test.conf <<EOF
[Resolve]
DNS=127.0.0.1
Domains=~test
EOF
systemctl restart systemd-resolved

sudo -u "$SUDO_USER" mkcert -install
MKCERT_CAROOT="$(sudo -u "$SUDO_USER" mkcert -CAROOT)"
cp "$MKCERT_CAROOT/rootCA.pem" /etc/pki/ca-trust/source/anchors/ldev-mkcert-rootCA.pem
update-ca-trust

sudo -u "$SUDO_USER" mkcert -cert-file "/home/$SUDO_USER/.config/ldev/certs/test.pem" \
    -key-file "/home/$SUDO_USER/.config/ldev/certs/test-key.pem" \
    "*.test" "test"

cat > /etc/polkit-1/rules.d/10-ldev.rules <<EOF
polkit.addRule(function(action, subject) {
    if (action.id == "org.freedesktop.systemd1.manage-units" &&
        subject.user == "$SUDO_USER") {
        return polkit.Result.YES;
    }
});
EOF

cat > /usr/local/bin/ldev-manage-hosts <<'HOSTSEOF'
#!/bin/bash
set -euo pipefail

HOSTS_FILE="/etc/hosts"
BEGIN_MARKER="# BEGIN Linux Dev"
END_MARKER="# END Linux Dev"
HOSTNAME_RE='^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*\.test$'

usage() {
    echo "Usage: $0 {add|remove|list} [hostname]" >&2
    exit 1
}

action="${1:-}"
host="${2:-}"

case "$action" in
    add|remove)
        [[ -n "$host" ]] || usage
        [[ "$host" =~ $HOSTNAME_RE ]] || { echo "Refusing: '$host' is not a valid *.test hostname." >&2; exit 1; }
        ;;
    list) ;;
    *) usage ;;
esac

grep -qF "$BEGIN_MARKER" "$HOSTS_FILE" || printf '\n%s\n%s\n' "$BEGIN_MARKER" "$END_MARKER" >> "$HOSTS_FILE"

TMP="$(mktemp)"
trap 'rm -f "$TMP"' EXIT

case "$action" in
    list)
        awk -v b="$BEGIN_MARKER" -v e="$END_MARKER" '
            $0 == b { inblock = 1; next }
            $0 == e { inblock = 0 }
            inblock && NF { print $2 }
        ' "$HOSTS_FILE"
        ;;
    remove)
        awk -v h="$host" -v b="$BEGIN_MARKER" -v e="$END_MARKER" '
            $0 == b { inblock = 1 }
            $0 == e { inblock = 0 }
            inblock && $2 == h { next }
            { print }
        ' "$HOSTS_FILE" > "$TMP"
        cat "$TMP" > "$HOSTS_FILE"
        ;;
    add)
        awk -v h="$host" -v b="$BEGIN_MARKER" -v e="$END_MARKER" '
            $0 == b { inblock = 1 }
            $0 == e { inblock = 0 }
            inblock && $2 == h { next }
            { print }
        ' "$HOSTS_FILE" > "$TMP"
        awk -v h="$host" -v b="$BEGIN_MARKER" '
            { print }
            $0 == b { print "127.0.0.1\t" h }
        ' "$TMP" > "$HOSTS_FILE"
        ;;
esac
HOSTSEOF
chmod 0755 /usr/local/bin/ldev-manage-hosts
chown root:root /usr/local/bin/ldev-manage-hosts

SUDOERS_STAGE="$(mktemp)"
cat > "$SUDOERS_STAGE" <<EOF
Defaults!/usr/local/bin/ldev-manage-hosts !requiretty
$SUDO_USER ALL=(root) NOPASSWD: /usr/local/bin/ldev-manage-hosts
EOF
if visudo -cf "$SUDOERS_STAGE" >/dev/null 2>&1; then
    install -m 0440 -o root -g root "$SUDOERS_STAGE" /etc/sudoers.d/ldev-hosts
else
    echo "⚠️  Generated sudoers rule for ldev-manage-hosts failed validation — skipping (dynamic /etc/hosts management won't work until this is fixed)." >&2
fi
rm -f "$SUDOERS_STAGE"

cat > /usr/local/bin/ldev-install-php <<'PHPINSTALLEOF'
#!/bin/bash
set -euo pipefail

VERSION="${1:-}"
[[ "$VERSION" =~ ^[0-9]{2,3}$ ]] || { echo "Usage: $0 <version, e.g. 86 for PHP 8.6>" >&2; exit 1; }

REAL_USER="${SUDO_USER:-$(logname)}"

dnf install -y "php${VERSION}" "php${VERSION}-php-fpm" "php${VERSION}-php-cli" \
    "php${VERSION}-php-mysqlnd" "php${VERSION}-php-pgsql" "php${VERSION}-php-gd" \
    "php${VERSION}-php-mbstring" "php${VERSION}-php-xml" "php${VERSION}-php-curl" \
    "php${VERSION}-php-zip" "php${VERSION}-php-intl" "php${VERSION}-php-pecl-redis6" \
    "php${VERSION}-php-pecl-memcached" "php${VERSION}-php-pecl-xdebug3"

WWWCONF="/etc/opt/remi/php${VERSION}/php-fpm.d/www.conf"
[[ -f "$WWWCONF" ]] || { echo "Expected $WWWCONF after install — package layout may have changed." >&2; exit 1; }

sed -i \
    -e 's/^;\?listen\.owner\s*=.*/listen.owner = nginx/' \
    -e 's/^;\?listen\.group\s*=.*/listen.group = nginx/' \
    -e 's/^;\?listen\.mode\s*=.*/listen.mode = 0660/' \
    -e 's/^listen\.acl_users\s*=.*/;listen.acl_users = apache/' \
    -e "s/^user\s*=.*/user = $REAL_USER/" \
    -e "s/^group\s*=.*/group = $REAL_USER/" \
    "$WWWCONF"

systemctl enable "php${VERSION}-php-fpm"
systemctl restart "php${VERSION}-php-fpm"

echo "Installed and started php${VERSION}-php-fpm"
PHPINSTALLEOF
chmod 0755 /usr/local/bin/ldev-install-php
chown root:root /usr/local/bin/ldev-install-php

SUDOERS_STAGE="$(mktemp)"
cat > "$SUDOERS_STAGE" <<EOF
Defaults!/usr/local/bin/ldev-install-php !requiretty
$SUDO_USER ALL=(root) NOPASSWD: /usr/local/bin/ldev-install-php
EOF
if visudo -cf "$SUDOERS_STAGE" >/dev/null 2>&1; then
    install -m 0440 -o root -g root "$SUDOERS_STAGE" /etc/sudoers.d/ldev-install-php
else
    echo "⚠️  Generated sudoers rule for ldev-install-php failed validation — skipping (installing new PHP versions from the dashboard won't work until this is fixed)." >&2
fi
rm -f "$SUDOERS_STAGE"

cat > /etc/systemd/user/ldev-mailpit.service <<EOF
[Unit]
Description=Linux Dev Mailpit

[Service]
ExecStart=/usr/local/bin/mailpit --smtp 127.0.0.1:1025 --listen 127.0.0.1:8025 --database /home/$SUDO_USER/.config/ldev/mailpit/mailpit.db
Restart=always

[Install]
WantedBy=default.target
EOF

cat > /etc/php.d/99-ldev-mailpit.ini <<EOF
sendmail_path = "/usr/local/bin/mailpit sendmail -S 127.0.0.1:1025"
EOF

cat > /etc/php.d/99-ldev-variables-order.ini <<EOF
variables_order = "EGPCS"
EOF

for version in 74 80 81 82 83 84 85; do
    cat > "/etc/opt/remi/php${version}/php.d/99-ldev-mailpit.ini" <<EOF
sendmail_path = "/usr/local/bin/mailpit sendmail -S 127.0.0.1:1025"
EOF
    cat > "/etc/opt/remi/php${version}/php.d/99-ldev-variables-order.ini" <<EOF
variables_order = "EGPCS"
EOF
done

S3_ENV_FILE="/home/$SUDO_USER/.config/ldev/s3.env"
OLD_MINIO_ENV_FILE="/home/$SUDO_USER/.config/ldev/minio.env"
S3_ACCESS=""
S3_SECRET=""
if [[ -f "$S3_ENV_FILE" ]]; then
    S3_ACCESS="$(grep '^RUSTFS_ACCESS_KEY=' "$S3_ENV_FILE" | cut -d= -f2-)"
    S3_SECRET="$(grep '^RUSTFS_SECRET_KEY=' "$S3_ENV_FILE" | cut -d= -f2-)"
elif [[ -f "$OLD_MINIO_ENV_FILE" ]]; then
    S3_ACCESS="$(grep '^MINIO_ROOT_USER=' "$OLD_MINIO_ENV_FILE" | cut -d= -f2-)"
    S3_SECRET="$(grep '^MINIO_ROOT_PASSWORD=' "$OLD_MINIO_ENV_FILE" | cut -d= -f2-)"
fi
S3_ACCESS="${S3_ACCESS:-ldevlocal}"
S3_SECRET="${S3_SECRET:-$(openssl rand -hex 20)}"
cat > "$S3_ENV_FILE" <<EOF
RUSTFS_ACCESS_KEY=$S3_ACCESS
RUSTFS_SECRET_KEY=$S3_SECRET
EOF
chown "$SUDO_USER:$SUDO_USER" "$S3_ENV_FILE"
chmod 600 "$S3_ENV_FILE"

cat > /etc/systemd/user/ldev-rustfs.service <<EOF
[Unit]
Description=Linux Dev S3 storage (RustFS)

[Service]
EnvironmentFile=/home/$SUDO_USER/.config/ldev/s3.env
ExecStart=/usr/local/bin/rustfs server --address 127.0.0.1:9000 --console-enable --console-address 127.0.0.1:9001 /home/$SUDO_USER/.ldev/storage/s3-data
Restart=always

[Install]
WantedBy=default.target
EOF

cat > /etc/systemd/user/ldev-meilisearch.service <<EOF
[Unit]
Description=Linux Dev Meilisearch

[Service]
ExecStart=/usr/local/bin/meilisearch --db-path /home/$SUDO_USER/.ldev/storage/meilisearch/data.ms --dump-dir /home/$SUDO_USER/.ldev/storage/meilisearch/dumps --snapshot-dir /home/$SUDO_USER/.ldev/storage/meilisearch/snapshots --http-addr 127.0.0.1:7700 --env development --no-analytics
Restart=always

[Install]
WantedBy=default.target
EOF

if [[ ! -s /var/lib/pgsql/data/PG_VERSION ]]; then
    postgresql-setup --initdb
fi

systemctl enable nginx dnsmasq mariadb postgresql valkey memcached supervisord
systemctl restart nginx dnsmasq mariadb postgresql valkey memcached supervisord

systemctl enable php-fpm
systemctl restart php-fpm
for version in 74 80 81 82 83 84 85; do
    systemctl enable "php${version}-php-fpm"
    systemctl restart "php${version}-php-fpm"
done

mariadb -u root -e "
    CREATE USER IF NOT EXISTS 'root'@'127.0.0.1' IDENTIFIED BY '';
    GRANT ALL PRIVILEGES ON *.* TO 'root'@'127.0.0.1' WITH GRANT OPTION;
    FLUSH PRIVILEGES;
"

cat > /etc/my.cnf.d/99-ldev-skip-name-resolve.cnf <<EOF
[mariadb]
skip_name_resolve
EOF
systemctl restart mariadb

PG_HBA="/var/lib/pgsql/data/pg_hba.conf"
if ! grep -q "^host.*all.*all.*127.0.0.1/32.*trust" "$PG_HBA"; then
    sed -i '/^host.*127\.0\.0\.1\/32/d; /^host.*::1\/128/d' "$PG_HBA"
    {
        echo "host    all             all             127.0.0.1/32            trust"
        echo "host    all             all             ::1/128                 trust"
    } >> "$PG_HBA"
    systemctl restart postgresql
fi

mkdir -p /etc/supervisord.d
rm -f /etc/supervisord.d/ldev.ini
chown "$SUDO_USER:$SUDO_USER" /etc/supervisord.d

if ! grep -q '^\[inet_http_server\]' /etc/supervisord.conf; then
    cat >> /etc/supervisord.conf <<'EOF'

[inet_http_server]
port=127.0.0.1:9002
EOF
fi

systemctl restart supervisord

loginctl enable-linger "$SUDO_USER"
sudo -u "$SUDO_USER" XDG_RUNTIME_DIR="/run/user/$(id -u "$SUDO_USER")" systemctl --user enable ldev-mailpit.service
sudo -u "$SUDO_USER" XDG_RUNTIME_DIR="/run/user/$(id -u "$SUDO_USER")" systemctl --user restart ldev-mailpit.service
if [[ -f /etc/systemd/user/ldev-minio.service ]]; then
    sudo -u "$SUDO_USER" XDG_RUNTIME_DIR="/run/user/$(id -u "$SUDO_USER")" systemctl --user disable --now ldev-minio.service 2>/dev/null || true
    rm -f /etc/systemd/user/ldev-minio.service
    sudo -u "$SUDO_USER" XDG_RUNTIME_DIR="/run/user/$(id -u "$SUDO_USER")" systemctl --user daemon-reload
fi
sudo -u "$SUDO_USER" XDG_RUNTIME_DIR="/run/user/$(id -u "$SUDO_USER")" systemctl --user enable ldev-rustfs.service
sudo -u "$SUDO_USER" XDG_RUNTIME_DIR="/run/user/$(id -u "$SUDO_USER")" systemctl --user restart ldev-rustfs.service
sudo -u "$SUDO_USER" XDG_RUNTIME_DIR="/run/user/$(id -u "$SUDO_USER")" systemctl --user enable ldev-meilisearch.service
sudo -u "$SUDO_USER" XDG_RUNTIME_DIR="/run/user/$(id -u "$SUDO_USER")" systemctl --user restart ldev-meilisearch.service

S3_READY=no
for i in $(seq 1 20); do
    if curl -fs http://127.0.0.1:9000/health >/dev/null 2>&1; then
        S3_READY=yes
        break
    fi
    sleep 1
done
if [[ $S3_READY != yes ]]; then
    echo "⚠️  The local S3 storage (ldev-rustfs) did not start — check: systemctl --user status ldev-rustfs" >&2
fi

OLD_MINIO_DATA="/home/$SUDO_USER/.ldev/storage/minio-data"
OLD_COPY_MARKER="/home/$SUDO_USER/.ldev/storage/s3-data/.copied-from-minio"
if [[ -e "$OLD_COPY_MARKER" ]]; then
    rm -rf "$OLD_MINIO_DATA" "$OLD_COPY_MARKER"
fi
if [[ -x /usr/local/bin/minio && -d "$OLD_MINIO_DATA" && -n "$(ls -A "$OLD_MINIO_DATA" 2>/dev/null)" && $S3_READY == yes ]]; then
    echo "Moving your buckets from MinIO into RustFS (one time only)..."
    MC_TMP="$(mktemp -d)"
    chown "$SUDO_USER:$SUDO_USER" "$MC_TMP"
    MC_RELEASE="RELEASE.2025-08-13T08-35-41Z"
    curl -fSL --retry 3 --retry-delay 2 -o "$MC_TMP/mc" "https://github.com/minio/mc/releases/download/${MC_RELEASE}/mc.linux-amd64.${MC_RELEASE}"
    chmod 0755 "$MC_TMP/mc"
    MC=(sudo -u "$SUDO_USER" "$MC_TMP/mc" --config-dir "$MC_TMP/config")
    sudo -u "$SUDO_USER" env MINIO_ROOT_USER="$S3_ACCESS" MINIO_ROOT_PASSWORD="$S3_SECRET" \
        /usr/local/bin/minio server "$OLD_MINIO_DATA" --address 127.0.0.1:9010 --console-address 127.0.0.1:9011 >/dev/null 2>&1 &
    OLD_MINIO_PID=$!
    COPY_RESULT=failed
    for i in $(seq 1 20); do
        if "${MC[@]}" alias set old http://127.0.0.1:9010 "$S3_ACCESS" "$S3_SECRET" >/dev/null 2>&1; then
            COPY_RESULT=ok
            break
        fi
        sleep 1
    done
    if [[ $COPY_RESULT == ok ]] && "${MC[@]}" alias set new http://127.0.0.1:9000 "$S3_ACCESS" "$S3_SECRET" >/dev/null 2>&1; then
        while read -r bucket; do
            [[ -z "$bucket" ]] && continue
            if ! "${MC[@]}" mb --ignore-existing "new/$bucket" >/dev/null \
                || ! "${MC[@]}" mirror --overwrite --quiet "old/$bucket" "new/$bucket" >/dev/null \
                || [[ -n "$("${MC[@]}" diff "old/$bucket" "new/$bucket" 2>&1)" ]]; then
                COPY_RESULT=failed
            fi
        done < <("${MC[@]}" ls old | awk '{print $NF}' | sed 's#/$##')
    else
        COPY_RESULT=failed
    fi
    kill "$OLD_MINIO_PID" 2>/dev/null || true
    wait "$OLD_MINIO_PID" 2>/dev/null || true
    rm -rf "$MC_TMP"
    if [[ $COPY_RESULT == ok ]]; then
        rm -rf "$OLD_MINIO_DATA"
        echo "Buckets moved into RustFS and checked; MinIO has been removed."
    else
        echo "⚠️  Some MinIO buckets could not be copied into RustFS. The old data is untouched in $OLD_MINIO_DATA; re-run this script to try again." >&2
    fi
fi
if [[ ! -d "$OLD_MINIO_DATA" ]]; then
    rm -f /usr/local/bin/minio "$OLD_MINIO_ENV_FILE"
fi

if [[ -x /usr/local/bin/mc ]]; then
    sudo -u "$SUDO_USER" /usr/local/bin/mc alias remove ldevlocal >/dev/null 2>&1 || true
    sudo -u "$SUDO_USER" /usr/local/bin/mc alias remove fldevlocal >/dev/null 2>&1 || true
    rm -f /usr/local/bin/mc
fi
MC_CONFIG="/home/$SUDO_USER/.mc/config.json"
if [[ -f "$MC_CONFIG" ]] && python3 -c "import json,sys; sys.exit(0 if set(json.load(open(sys.argv[1]))['aliases']) <= {'gcs','local','play','s3'} else 1)" "$MC_CONFIG" 2>/dev/null; then
    rm -rf "/home/$SUDO_USER/.mc"
fi

cat > /etc/nginx/conf.d/ldev.conf <<EOF
include /home/$SUDO_USER/.config/ldev/nginx/*.conf;
access_log /home/$SUDO_USER/.config/ldev/logs/nginx-access.log;
error_log /home/$SUDO_USER/.config/ldev/logs/nginx-error.log;
EOF
systemctl reload nginx

echo "✅ Linux Dev environment setup complete!"
echo "Mailpit UI running at http://127.0.0.1:8025"
echo "S3 storage (RustFS) console running at http://127.0.0.1:9001/rustfs/console/ (username and password in ~/.config/ldev/s3.env)"
echo "Run deploy-app.sh next to build and start the Linux Dev control app."
