#!/usr/bin/env bash
# Wallos Extended - update inside the LXC container (installed as the command "update").
#
#   update            install the newest release if there is one
#   update --force    reinstall the newest release
#   update v5.11.1-ext.2   install a specific release
#
# Before updating, the database is copied to /var/backups/wallos-extended/.
set -euo pipefail

REPO=${REPO:-tim247/wallos-extended}
APP_DIR=/var/www/wallos-extended
BACKUP_DIR=/var/backups/wallos-extended
KEEP_BACKUPS=10

GN=$'\e[32m'; RD=$'\e[31m'; YW=$'\e[33m'; BL=$'\e[36m'; CL=$'\e[0m'
msg_info() { printf ' %s…%s %s\n' "$YW" "$CL" "$1"; }
msg_ok() { printf ' %s✔%s %s\n' "$GN" "$CL" "$1"; }
msg_error() { printf ' %s✖%s %s\n' "$RD" "$CL" "$1" >&2; }

[ "$(id -u)" -eq 0 ] || { msg_error "Bitte als root ausfuehren."; exit 1; }
[ -d "$APP_DIR" ] || { msg_error "Keine Installation unter $APP_DIR gefunden."; exit 1; }

FORCE=0
TARGET=""
for arg in "$@"; do
    case "$arg" in
        --force) FORCE=1 ;;
        v*) TARGET=$arg ;;
        *) msg_error "Unbekannte Option: $arg"; exit 1 ;;
    esac
done

CURRENT=$(cat "$APP_DIR/RELEASE" 2>/dev/null || echo "unbekannt")
if [ -z "$TARGET" ]; then
    msg_info "Neueste Version abfragen"
    TARGET=$(curl -fsSL "https://api.github.com/repos/$REPO/releases/latest" | sed -n 's/.*"tag_name": *"\([^"]*\)".*/\1/p' | head -1)
    [ -n "$TARGET" ] || { msg_error "Konnte die neueste Version nicht ermitteln."; exit 1; }
fi
printf ' %sInstalliert:%s %s   %sVerfuegbar:%s %s\n' "$BL" "$CL" "$CURRENT" "$BL" "$CL" "$TARGET"

if [ "$TARGET" = "$CURRENT" ] && [ "$FORCE" -eq 0 ]; then
    msg_ok "Wallos Extended ist aktuell."
    exit 0
fi

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
BASE="https://github.com/$REPO/releases/download/$TARGET"
msg_info "Version $TARGET laden"
for f in wallos-extended-app.tar.gz install.sh update.sh SHA256SUMS; do
    curl -fsSL "$BASE/$f" -o "$TMP/$f" || { msg_error "Download fehlgeschlagen: $f"; exit 1; }
done
(cd "$TMP" && grep -E ' (wallos-extended-app.tar.gz|install.sh|update.sh)$' SHA256SUMS | sha256sum -c --quiet) \
    || { msg_error "Pruefsumme stimmt nicht - Update abgebrochen."; exit 1; }
msg_ok "Version $TARGET geladen und geprueft"

if [ -f "$APP_DIR/db/wallos.db" ]; then
    mkdir -p "$BACKUP_DIR"
    BACKUP="$BACKUP_DIR/wallos-$(date +%Y%m%d-%H%M%S)-$CURRENT.db"
    cp "$APP_DIR/db/wallos.db" "$BACKUP"
    ls -1t "$BACKUP_DIR"/wallos-*.db 2>/dev/null | tail -n +$((KEEP_BACKUPS + 1)) | xargs -r rm -f
    msg_ok "Datenbank gesichert: $BACKUP"
fi

msg_info "Update installieren"
bash "$TMP/install.sh" --update "$TMP/wallos-extended-app.tar.gz"
install -m 755 "$TMP/update.sh" /usr/local/bin/update
ln -sf /usr/local/bin/update /usr/bin/update # reachable after "pct enter", whose PATH may lack /usr/local/bin
msg_ok "Wallos Extended $CURRENT -> $TARGET aktualisiert"
