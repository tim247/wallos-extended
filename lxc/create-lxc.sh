#!/usr/bin/env bash
# Wallos Extended - creates a Proxmox LXC container and installs the app in it.
# Run on the Proxmox host as root, either directly from GitHub:
#
#   bash -c "$(curl -fsSL https://github.com/tim247/wallos-extended/releases/latest/download/create-lxc.sh)"
#
# or from a folder that also holds install.sh, update.sh and wallos-extended-app.tar.gz:
#
#   bash create-lxc.sh
#
# Interactive by default (dialogs like the community-scripts). Without a terminal, or
# with MODE=default, the settings come from these environment variables:
#   CTID=120  CT_HOSTNAME=wallos-extended  STORAGE=local-lvm  TEMPLATE_STORAGE=local
#   BRIDGE=vmbr0  IP=dhcp|192.168.1.50/24  GATEWAY=192.168.1.1
#   DISK_GB=4  RAM_MB=1024  CORES=2  TZ_NAME=Europe/Berlin
#   DB_FILE=/root/wallos.db    existing database to take over
#   RELEASE=latest|v5.11.1-ext.1   REPO=tim247/wallos-extended
set -euo pipefail

APP="Wallos Extended"
REPO=${REPO:-tim247/wallos-extended}
RELEASE=${RELEASE:-latest}

GN=$'\e[32m'; RD=$'\e[31m'; YW=$'\e[33m'; BL=$'\e[36m'; BD=$'\e[1m'; CL=$'\e[0m'
msg_info() { printf ' %s…%s %s\n' "$YW" "$CL" "$1"; }
msg_ok() { printf ' %s✔%s %s\n' "$GN" "$CL" "$1"; }
msg_error() { printf ' %s✖%s %s\n' "$RD" "$CL" "$1" >&2; }
trap 'msg_error "Abgebrochen in Zeile $LINENO: $BASH_COMMAND"' ERR

header() {
    clear 2>/dev/null || true
    cat <<EOF
${BL}${BD}
 __      __        .__  .__                 ___________         __                   .___         .___
/  \\    /  \\_____  |  | |  |   ____  ______ \\_   _____/__  ____/  |_  ____   ____    __| _/____   __| _/
\\   \\/\\/   /\\__  \\ |  | |  |  /  _ \\/  ___/  |    __)_\\  \\/  /\\   __\\/ __ \\ /    \\  / __ |/ __ \\ / __ |
 \\        /  / __ \\|  |_|  |_(  <_> )___ \\   |        \\>    <  |  | \\  ___/|   |  \\/ /_/ \\  ___// /_/ |
  \\__/\\  /  (____  /____/____/\\____/____  > /_______  /__/\\_ \\ |__|  \\___  >___|  /\\____ |\\___  >____ |
       \\/        \\/                     \\/          \\/      \\/           \\/     \\/      \\/    \\/     \\/
${CL}
EOF
}

command -v pct >/dev/null || { msg_error "pct nicht gefunden - das Skript gehoert auf den Proxmox-Host."; exit 1; }
[ "$(id -u)" -eq 0 ] || { msg_error "Bitte als root ausfuehren."; exit 1; }
header

# ── Settings ─────────────────────────────────────────────────────────────────
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

ask() { # ask VAR "Title" "Question"  (keeps the current value as default)
    local value
    value=$(whiptail --backtitle "$APP" --title "$2" --inputbox "$3" 10 70 "${!1}" 3>&1 1>&2 2>&3) || exit 1
    printf -v "$1" '%s' "$value"
}

if [ -z "${MODE:-}" ] && [ -t 0 ] && [ -t 1 ] && command -v whiptail >/dev/null; then
    MODE=$(whiptail --backtitle "$APP" --title "$APP – LXC" --menu \
        "Container mit Debian 12, nginx, PHP und Cron anlegen.\n\nStandard: ID $CTID, $CORES Kerne, ${RAM_MB} MB RAM, ${DISK_GB} GB, DHCP" \
        14 70 2 default "Standard-Einstellungen" advanced "Erweiterte Einstellungen" 3>&1 1>&2 2>&3) || exit 1
    if [ "$MODE" = "advanced" ]; then
        ask CTID "Container-ID" "ID des neuen Containers"
        ask CT_HOSTNAME "Hostname" "Hostname des Containers"
        ask DISK_GB "Speicher" "Festplattengröße in GB"
        ask CORES "CPU" "Anzahl CPU-Kerne"
        ask RAM_MB "RAM" "Arbeitsspeicher in MB"
        ask STORAGE "Storage" "Storage für das Root-Dateisystem (z. B. local-lvm, local-zfs)"
        ask BRIDGE "Netzwerk" "Netzwerk-Bridge"
        ask IP "Netzwerk" "IP-Adresse mit Präfix (z. B. 192.168.1.50/24) oder dhcp"
        if [ "$IP" != "dhcp" ]; then ask GATEWAY "Netzwerk" "Gateway (z. B. 192.168.1.1)"; fi
        ask TZ_NAME "Zeitzone" "Zeitzone"
        ask DB_FILE "Datenübernahme" "Pfad zu einer vorhandenen wallos.db auf dem Host (leer = neu starten)"
    fi
    whiptail --backtitle "$APP" --title "Zusammenfassung" --yesno \
        "Container-ID:  $CTID\nHostname:      $CT_HOSTNAME\nCPU / RAM:     $CORES Kerne / $RAM_MB MB\nSpeicher:      $DISK_GB GB auf $STORAGE\nNetzwerk:      $BRIDGE, $IP ${GATEWAY:+(GW $GATEWAY)}\nZeitzone:      $TZ_NAME\nDatenbank:     ${DB_FILE:-neu}\nVersion:       $RELEASE\n\nJetzt anlegen?" \
        18 70 || exit 1
fi
[ -z "$DB_FILE" ] || [ -f "$DB_FILE" ] || { msg_error "Datenbank nicht gefunden: $DB_FILE"; exit 1; }
pct status "$CTID" >/dev/null 2>&1 && { msg_error "Container-ID $CTID ist schon vergeben."; exit 1; }

# ── App files: next to this script, otherwise from the GitHub release ────────
HERE=$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" 2>/dev/null && pwd || echo /nonexistent)
if [ ! -f "$HERE/wallos-extended-app.tar.gz" ] || [ ! -f "$HERE/install.sh" ]; then
    HERE=$(mktemp -d)
    trap 'rm -rf "$HERE"' EXIT
    if [ "$RELEASE" = "latest" ]; then
        BASE="https://github.com/$REPO/releases/latest/download"
    else
        BASE="https://github.com/$REPO/releases/download/$RELEASE"
    fi
    msg_info "App-Dateien laden ($RELEASE)"
    for f in wallos-extended-app.tar.gz install.sh update.sh SHA256SUMS; do
        curl -fsSL "$BASE/$f" -o "$HERE/$f" || { msg_error "Download fehlgeschlagen: $f"; exit 1; }
    done
    (cd "$HERE" && grep -E ' (wallos-extended-app.tar.gz|install.sh|update.sh)$' SHA256SUMS | sha256sum -c --quiet) \
        || { msg_error "Pruefsumme stimmt nicht."; exit 1; }
    msg_ok "App-Dateien geladen und geprueft"
fi

# ── Container ────────────────────────────────────────────────────────────────
msg_info "Debian-12-Vorlage bereitstellen"
pveam update >/dev/null
TEMPLATE=$(pveam available --section system | awk '/debian-12-standard/ {print $2}' | sort -V | tail -1)
[ -n "$TEMPLATE" ] || { msg_error "Keine Debian-12-Vorlage gefunden."; exit 1; }
if ! pveam list "$TEMPLATE_STORAGE" | grep -q "$TEMPLATE"; then
    pveam download "$TEMPLATE_STORAGE" "$TEMPLATE" >/dev/null
fi
msg_ok "Vorlage $TEMPLATE"

msg_info "Container $CTID ($CT_HOSTNAME) anlegen"
NET="name=eth0,bridge=$BRIDGE,ip=$IP"
[ -n "$GATEWAY" ] && NET="$NET,gw=$GATEWAY"
pct create "$CTID" "$TEMPLATE_STORAGE:vztmpl/$TEMPLATE" \
    --hostname "$CT_HOSTNAME" \
    --cores "$CORES" --memory "$RAM_MB" --swap 512 \
    --rootfs "$STORAGE:$DISK_GB" \
    --net0 "$NET" \
    --ostype debian --unprivileged 1 --onboot 1 \
    --tags wallos-extended \
    --description "Wallos Extended – https://github.com/$REPO (Update im Container: update)" >/dev/null
pct start "$CTID"
msg_ok "Container $CTID gestartet"

msg_info "Auf das Netzwerk im Container warten"
for _ in $(seq 1 60); do
    pct exec "$CTID" -- getent hosts deb.debian.org >/dev/null 2>&1 && break
    sleep 2
done
pct exec "$CTID" -- getent hosts deb.debian.org >/dev/null || { msg_error "Container hat kein Netzwerk/DNS."; exit 1; }
msg_ok "Netzwerk bereit"

msg_info "$APP installieren (dauert ein paar Minuten)"
pct push "$CTID" "$HERE/wallos-extended-app.tar.gz" /root/wallos-extended-app.tar.gz
pct push "$CTID" "$HERE/install.sh" /root/install.sh
[ -f "$HERE/update.sh" ] && pct push "$CTID" "$HERE/update.sh" /root/update.sh
pct exec "$CTID" -- env TZ_NAME="$TZ_NAME" bash /root/install.sh >/tmp/wallos-extended-install-$CTID.log 2>&1 \
    || { msg_error "Installation fehlgeschlagen, Log: /tmp/wallos-extended-install-$CTID.log"; exit 1; }
pct exec "$CTID" -- rm -f /root/wallos-extended-app.tar.gz
msg_ok "$APP installiert"

if [ -n "$DB_FILE" ]; then
    msg_info "Vorhandene Datenbank uebernehmen"
    pct push "$CTID" "$DB_FILE" /root/wallos.db
    pct exec "$CTID" -- bash -c '
        install -o www-data -g www-data -m 755 /root/wallos.db /var/www/wallos-extended/db/wallos.db &&
        cd /var/www/wallos-extended &&
        runuser -u www-data -- php endpoints/db/migrate.php >/dev/null &&
        rm /root/wallos.db'
    msg_ok "Datenbank uebernommen und aktualisiert"
fi

ADDRESS=$(pct exec "$CTID" -- hostname -I | awk '{print $1}')
echo
msg_ok "${BD}$APP laeuft:${CL} ${BL}http://$ADDRESS/${CL}"
echo "   Beim ersten Aufruf den ersten Benutzer registrieren (wird Administrator)."
echo "   Update spaeter:  pct enter $CTID  und dann  update"
