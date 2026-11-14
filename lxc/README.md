# Wallos Extended als Proxmox-LXC-Container

Läuft ohne Docker direkt im Container: Debian 12, nginx, PHP-FPM, SQLite und Cron.
Etwa 300 MB Platz und 512–1024 MB RAM reichen.

## Installieren

**Schnellweg** – auf dem Proxmox-Host als root, lädt alles aus dem neuesten Release:

```bash
bash -c "$(curl -fsSL https://github.com/tim247/wallos-extended/releases/latest/download/create-lxc.sh)"
```

Oder mit den Dateien aus diesem Ordner:

1. Diesen Ordner `lxc` auf den Proxmox-Host kopieren:

   ```bash
   scp -r lxc root@PROXMOX-IP:/root/
   ```

2. Auf dem Proxmox-Host ausführen:

   ```bash
   cd /root/lxc
   bash create-lxc.sh
   ```

   Das Skript lädt die Debian-12-Vorlage, legt den Container an, installiert die App und
   zeigt am Ende die Adresse (`http://<IP>/`). Beim ersten Aufruf registrierst du den
   ersten Benutzer (wird Administrator).

Das Skript fragt wie die community-scripts nach **Standard-** oder **erweiterten Einstellungen** (Container-ID, Hostname, CPU, RAM, Speicher, Netzwerk, Zeitzone, Datenübernahme) und zeigt vor dem Anlegen eine Zusammenfassung.

Ohne Dialoge (z. B. für Automatisierung) mit `MODE=default` und Umgebungsvariablen:

```bash
MODE=default CTID=120 IP=192.168.1.50/24 GATEWAY=192.168.1.1 STORAGE=local-zfs bash create-lxc.sh
```

| Variable | Standard |
|---|---|
| `CTID` | nächste freie ID |
| `CT_HOSTNAME` | `wallos-extended` |
| `STORAGE` / `TEMPLATE_STORAGE` | `local-lvm` / `local` |
| `BRIDGE` | `vmbr0` |
| `IP` / `GATEWAY` | `dhcp` / – |
| `DISK_GB` / `RAM_MB` / `CORES` | `4` / `1024` / `2` |
| `TZ_NAME` | `Europe/Berlin` |
| `DB_FILE` | – (bestehende `wallos.db` übernehmen) |

## Bestehende Daten übernehmen

```bash
DB_FILE=/root/wallos.db bash create-lxc.sh
```

Die Datenbank wird nach dem Anlegen eingespielt und auf den neuen Stand migriert.

## Aktualisieren

Im Container (z. B. `pct enter 120`) einfach eingeben:

```bash
update
```

Das Skript fragt die neueste Version bei GitHub ab, sichert die Datenbank nach
`/var/backups/wallos-extended/` (die letzten 10 bleiben), prüft die Prüfsummen, ersetzt den Code und
migriert die Datenbank. Datenbank und hochgeladene Logos bleiben erhalten.

| Befehl | Wirkung |
|---|---|
| `update` | neueste Version installieren, falls neuer |
| `update --force` | neueste Version neu installieren |
| `update v5.11.1-ext.2` | bestimmte Version installieren |

**Ältere Installationen** ohne den Befehl `update` richten ihn einmalig so ein:

```bash
curl -fsSL https://github.com/tim247/wallos-extended/releases/latest/download/update.sh -o /usr/local/bin/update && chmod +x /usr/local/bin/update && update
```

## Wo liegt was?

| | |
|---|---|
| App | `/var/www/wallos-extended` |
| Datenbank | `/var/www/wallos-extended/db/wallos.db` |
| Cronjobs | `/etc/cron.d/wallos-extended` (Logs in `/var/log/wallos-extended/`) |
| nginx | `/etc/nginx/sites-available/wallos-extended` |

Sicherung: den ganzen Container mit `vzdump` sichern oder in der App unter Admin → Sicherung.

## Hinweise

- Ein reverse proxy (z. B. Nginx Proxy Manager) leitet einfach auf Port 80 des Containers.
- Home Assistant und Paperless im selben Netz: Adressen als IP eintragen.
- Die Cronjobs (Kündigungserinnerungen, Preisänderungen, Home-Assistant-Import um 23:55 Uhr) laufen automatisch.
