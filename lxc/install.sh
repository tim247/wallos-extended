#!/usr/bin/env bash
# Wallos Extended - native installation in a Debian 12/13 container (no Docker).
#
#   install.sh [app-tarball]            first installation
#   install.sh --update [app-tarball]   replace the code, keep database and logos
#
# Runs inside the LXC container as root (create-lxc.sh does that for you).
set -euo pipefail

APP_DIR=/var/www/wallos-extended
LOG_DIR=/var/log/wallos-extended
TZ_NAME=${TZ_NAME:-Europe/Berlin}

if [ "${1:-}" = "--update" ]; then
    shift
fi
TARBALL=${1:-/root/wallos-extended-app.tar.gz}

[ "$(id -u)" -eq 0 ] || { echo "Bitte als root ausfuehren." >&2; exit 1; }
[ -f "$TARBALL" ] || { echo "App-Archiv nicht gefunden: $TARBALL" >&2; exit 1; }

echo "==> Pakete installieren"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq nginx php-fpm php-cli php-sqlite3 php-gd php-intl php-zip php-curl \
    php-mbstring php-xml php-imagick cron curl ca-certificates tzdata unzip >/dev/null

PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
echo "    PHP $PHP_VER"

ln -sf "/usr/share/zoneinfo/$TZ_NAME" /etc/localtime
echo "$TZ_NAME" > /etc/timezone

echo "==> App nach $APP_DIR kopieren (Datenbank und Logos bleiben erhalten)"
TMP=$(mktemp -d)
mkdir -p "$TMP/new" "$TMP/keep" "$APP_DIR"
tar -xzf "$TARBALL" -C "$TMP/new"
[ -d "$APP_DIR/db" ] && mv "$APP_DIR/db" "$TMP/keep/db"
[ -d "$APP_DIR/images/uploads/logos" ] && mv "$APP_DIR/images/uploads/logos" "$TMP/keep/logos"
find "$APP_DIR" -mindepth 1 -delete
cp -a "$TMP/new/." "$APP_DIR/"
if [ -d "$TMP/keep/db" ]; then
    rm -rf "$APP_DIR/db"
    mv "$TMP/keep/db" "$APP_DIR/db"
fi
if [ -d "$TMP/keep/logos" ]; then
    mkdir -p "$APP_DIR/images/uploads"
    rm -rf "$APP_DIR/images/uploads/logos"
    mv "$TMP/keep/logos" "$APP_DIR/images/uploads/logos"
fi
rm -rf "$TMP"

mkdir -p "$APP_DIR/db" "$APP_DIR/images/uploads/logos/avatars" "$LOG_DIR"
chown -R www-data:www-data "$APP_DIR" "$LOG_DIR"
chmod -R 755 "$APP_DIR/db" "$APP_DIR/images/uploads/logos"

echo "==> PHP und nginx einrichten"
cat > "/etc/php/$PHP_VER/fpm/conf.d/99-wallos-extended.ini" <<EOF
upload_max_filesize = 64M
post_max_size = 64M
memory_limit = 256M
date.timezone = $TZ_NAME
EOF

cat > /etc/nginx/sites-available/wallos-extended <<EOF
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;

    root $APP_DIR;
    index index.php;
    client_max_body_size 64M;

    location / {
        try_files \$uri \$uri/ /index.php?\$args;
    }

    # never serve the database folder, scripts in uploads or internal includes
    location ^~ /db/ {
        deny all;
        return 403;
    }
    location ~ \.db\$ {
        deny all;
        return 403;
    }
    location ~* images/uploads/logos/.*\.php\$ {
        deny all;
        return 403;
    }
    location ~* ^/includes/.*\.php\$ {
        deny all;
        return 403;
    }

    location ~ \.php\$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php$PHP_VER-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
    }
}
EOF
ln -sf /etc/nginx/sites-available/wallos-extended /etc/nginx/sites-enabled/wallos-extended
rm -f /etc/nginx/sites-enabled/default

echo "==> Cronjobs (Kuendigungserinnerungen, Preisaenderungen, Home Assistant ...)"
tr -d '\r' < "$APP_DIR/cronjobs" | grep -E '^[0-9*]' \
    | sed -E "s#^(([^ ]+ +){5})#\1www-data #; s#/usr/local/bin/php#/usr/bin/php#g; s#/var/www/html#$APP_DIR#g; s#/var/log/cron#$LOG_DIR#g" \
    > /etc/cron.d/wallos-extended
chmod 644 /etc/cron.d/wallos-extended

echo "==> Datenbank anlegen / aktualisieren"
# run from the app folder: migrate.php resolves its includes relative to the working directory
as_app() { (cd "$APP_DIR" && runuser -u www-data -- php "$@"); }
as_app "$APP_DIR/endpoints/cronjobs/createdatabase.php" >/dev/null
as_app "$APP_DIR/endpoints/db/migrate.php" >/dev/null
chown -R www-data:www-data "$APP_DIR/db"
chmod -R 755 "$APP_DIR/db"
# first run of the daily jobs (need internet; failures are not fatal)
for job in updatenextpayment updateexchange checkforupdates; do
    as_app "$APP_DIR/endpoints/cronjobs/$job.php" >/dev/null 2>&1 || true
done

echo "==> Update-Befehl einrichten"
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
if [ -f "$HERE/update.sh" ]; then
    install -m 755 "$HERE/update.sh" /usr/local/bin/update
fi
cat > /etc/motd <<EOF

  Wallos Extended $(cat "$APP_DIR/RELEASE" 2>/dev/null || echo "")
  Web:     http://$(hostname -I | awk '{print $1}')/
  Update:  update   (sichert die Datenbank, installiert die neueste Version)
  Daten:   $APP_DIR/db

EOF

echo "==> Dienste starten"
if [ -d /run/systemd/system ]; then
    systemctl enable --now "php$PHP_VER-fpm" nginx cron >/dev/null 2>&1
    systemctl restart "php$PHP_VER-fpm" nginx cron
else
    echo "    (kein systemd erkannt - Dienste bitte selbst starten)"
fi

echo
echo "Fertig. Wallos Extended laeuft unter http://$(hostname -I | awk '{print $1}')/"
