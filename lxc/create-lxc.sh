#!/usr/bin/env bash
# Wallos Extended - creates a Proxmox LXC container and installs the app in it.
# Run on the Proxmox host as root, either directly from GitHub:
#
#   bash -c "$(curl -fsSL https://github.com/tim247/wallos-extended/releases/latest/download/create-lxc.sh)"
#
# or from a folder that also holds install.sh and wallos-extended-app.tar.gz:
#
#   bash create-lxc.sh
#
# Settings (all optional, pass as environment variables):
#   CTID=120  CT_HOSTNAME=wallos-extended  STORAGE=local-lvm  TEMPLATE_STORAGE=local
#   BRIDGE=vmbr0  IP=dhcp|192.168.1.50/24  GATEWAY=192.168.1.1
#   DISK_GB=4  RAM_MB=1024  CORES=2  TZ_NAME=Europe/Berlin
#   DB_FILE=/root/wallos.db    existing database to take over
set -euo pipefail

REPO=${REPO:-tim247/wallos-extended}
RELEASE=${RELEASE:-latest}
command -v pct >/dev/null || { echo "pct nicht gefunden - das Skript gehoert auf den Proxmox-Host." >&2; exit 1; }

# Use the files next to this script, otherwise download them from the GitHub release
# (also when the script itself was started via curl ... | bash).
HERE=$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" 2>/dev/null && pwd || echo /nonexistent)
if [ ! -f "$HERE/wallos-extended-app.tar.gz" ] || [ ! -f "$HERE/install.sh" ]; then
    HERE=$(mktemp -d)
    trap 'rm -rf "$HERE"' EXIT
    if [ "$RELEASE" = "latest" ]; then
        BASE="https://github.com/$REPO/releases/latest/download"
    else
        BASE="https://github.com/$REPO/releases/download/$RELEASE"
    fi
    echo "==> App-Dateien von $BASE laden"
    for f in wallos-extended-app.tar.gz install.sh SHA256SUMS; do
        curl -fsSL "$BASE/$f" -o "$HERE/$f" || { echo "Download fehlgeschlagen: $f" >&2; exit 1; }
    done
    (cd "$HERE" && grep -E ' (wallos-extended-app.tar.gz|install.sh)$' SHA256SUMS | sha256sum -c --quiet) \
        || { echo "Pruefsumme stimmt nicht." >&2; exit 1; }
fi

CTID=${CTID:-$(pvesh get /cluster/nextid)}
CT_HOSTNAME=${CT_HOSTNAME:-wallos-extended}
STORAGE=${STORAGE:-local-lvm}
TEMPLATE_STORAGE=${TEMPLATE_STORAGE:-local}
BRIDGE=${BRIDGE:-vmbr0}
IP=${IP:-dhcp}
GATEWAY=${GATEWAY:-}
DISK_GB=${DISK_GB:-4}
RAM_MB=${RAM_MB:-1024}
CORES=${CORES:-2}
TZ_NAME=${TZ_NAME:-Europe/Berlin}
DB_FILE=${DB_FILE:-}

echo "==> Debian-12-Vorlage bereitstellen"
pveam update >/dev/null
TEMPLATE=$(pveam available --section system | awk '/debian-12-standard/ {print $2}' | sort -V | tail -1)
[ -n "$TEMPLATE" ] || { echo "Keine Debian-12-Vorlage gefunden." >&2; exit 1; }
if ! pveam list "$TEMPLATE_STORAGE" | grep -q "$TEMPLATE"; then
    pveam download "$TEMPLATE_STORAGE" "$TEMPLATE"
fi

echo "==> Container $CTID ($CT_HOSTNAME) anlegen"
NET="name=eth0,bridge=$BRIDGE,ip=$IP"
[ -n "$GATEWAY" ] && NET="$NET,gw=$GATEWAY"
pct create "$CTID" "$TEMPLATE_STORAGE:vztmpl/$TEMPLATE" \
    --hostname "$CT_HOSTNAME" \
    --cores "$CORES" --memory "$RAM_MB" --swap 512 \
    --rootfs "$STORAGE:$DISK_GB" \
    --net0 "$NET" \
    --ostype debian --unprivileged 1 --onboot 1
pct start "$CTID"

echo "==> Auf das Netzwerk im Container warten"
for _ in $(seq 1 60); do
    pct exec "$CTID" -- getent hosts deb.debian.org >/dev/null 2>&1 && break
    sleep 2
done
pct exec "$CTID" -- getent hosts deb.debian.org >/dev/null || { echo "Container hat kein Netzwerk/DNS." >&2; exit 1; }

echo "==> App installieren"
pct push "$CTID" "$HERE/wallos-extended-app.tar.gz" /root/wallos-extended-app.tar.gz
pct push "$CTID" "$HERE/install.sh" /root/install.sh
pct exec "$CTID" -- env TZ_NAME="$TZ_NAME" bash /root/install.sh

if [ -n "$DB_FILE" ]; then
    echo "==> Vorhandene Datenbank uebernehmen"
    pct push "$CTID" "$DB_FILE" /root/wallos.db
    pct exec "$CTID" -- bash -c '
        install -o www-data -g www-data -m 755 /root/wallos.db /var/www/wallos-extended/db/wallos.db &&
        runuser -u www-data -- php /var/www/wallos-extended/endpoints/db/migrate.php >/dev/null &&
        rm /root/wallos.db'
fi

ADDRESS=$(pct exec "$CTID" -- hostname -I | awk '{print $1}')
echo
echo "Container $CTID laeuft: http://$ADDRESS/"
echo "Shell im Container:      pct enter $CTID"
