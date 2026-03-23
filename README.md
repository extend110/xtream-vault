# Xtream Vault

Ein PHP-Frontend zum Browsen, Verwalten und automatischen Herunterladen von VODs von einem Xtream-Codes-Server. Unterstützt lokale Speicherung sowie direktes Streaming in Cloud-Speicher via rclone (Google Drive, MEGA, OneDrive, etc.).

---

## Inhaltsverzeichnis

1. [Voraussetzungen](#voraussetzungen)
2. [Installation](#installation)
3. [Ersteinrichtung](#ersteinrichtung)
4. [Konfiguration](#konfiguration)
5. [Server-Verwaltung](#server-verwaltung)
6. [rclone — Cloud-Speicher](#rclone--cloud-speicher)
7. [Ordnerstruktur der Downloads](#ordnerstruktur-der-downloads)
8. [Rollen & Berechtigungen](#rollen--berechtigungen)
9. [Download-Queue](#download-queue)
10. [Mediathek & Suche](#mediathek--suche)
11. [Neue Releases](#neue-releases)
12. [Favoriten](#favoriten)
13. [TMDB-Integration](#tmdb-integration)
14. [Statistiken](#statistiken)
15. [Telegram-Benachrichtigungen](#telegram-benachrichtigungen)
16. [VPN (WireGuard)](#vpn-wireguard)
17. [Themes](#themes)
18. [Benutzerverwaltung](#benutzerverwaltung)
19. [Einladungslinks](#einladungslinks)
20. [Updates](#updates)
21. [Datensicherung](#datensicherung)
22. [Health-Check](#health-check)
23. [Externe API](#externe-api)
24. [Sicherheit](#sicherheit)
25. [Dateistruktur](#dateistruktur)

---

## Voraussetzungen

| Anforderung | Mindestversion / Hinweis |
|---|---|
| PHP | 8.0+ mit `curl`, `json`, `session`, `zip`, `mbstring` |
| Webserver | Apache mit `mod_rewrite` und `mod_headers` |
| php.ini | `allow_url_fopen = On` |
| Optional: rclone | Für Cloud-Speicher-Integration |
| Optional: ffmpeg | Für Stream-Analyse im TMDB-Modal (`ffprobe`) |
| Optional: WireGuard | Für VPN-geschützte Downloads |

---

## Installation

### Schnellinstallation (empfohlen)

```bash
# ZIP herunterladen und entpacken
wget https://github.com/extend110/xtream-vault/archive/refs/heads/main.zip
unzip main.zip
cd xtream-vault-main

# Installationsskript ausführen
sudo bash install.sh
```

`install.sh` installiert alle Abhängigkeiten, richtet Apache ein, setzt Berechtigungen und Cronjobs, und richtet WireGuard-Sudoers ein. `install.sh`, `README.md` und `.gitignore` werden dabei **nicht** ins Webroot kopiert.

### Manuelle Installation

**1. Dateien hochladen** (ohne `install.sh`, `README.md`, `.gitignore`, `data/`)

```bash
scp *.php style.css .htaccess user@server:/var/www/html/xtream/
```

**2. Berechtigungen setzen**

```bash
sudo chown -R www-data:www-data /var/www/html/xtream/
sudo chmod -R 755 /var/www/html/xtream/
sudo chmod -R 775 /var/www/html/xtream/data/
```

**3. Apache konfigurieren**

```apache
<VirtualHost *:80>
    ServerName deine-domain.de
    DocumentRoot /var/www/html/xtream

    <Directory /var/www/html/xtream>
        AllowOverride All
        Require all granted
    </Directory>

    <Directory /var/www/html/xtream/data>
        Require all denied
    </Directory>
</VirtualHost>
```

```bash
sudo a2ensite xtream.conf
sudo a2enmod rewrite headers
sudo systemctl reload apache2
```

**4. Cronjobs** (`sudo -u www-data crontab -e`)

```
*/30 * * * * /usr/bin/php /var/www/html/xtream/cron.php >> /dev/null 2>&1
0 4 * * * /usr/bin/php /var/www/html/xtream/cache_builder.php >> /dev/null 2>&1
0 3 * * * /usr/bin/php /var/www/html/xtream/backup.php >> /dev/null 2>&1
```

> **HTTPS empfohlen:**
> ```bash
> sudo apt install certbot python3-certbot-apache
> sudo certbot --apache -d deine-domain.de
> ```

---

## Ersteinrichtung

1. Im Browser `http://deine-domain.de` aufrufen
2. **Setup-Wizard** ausfüllen — Admin-Benutzername und Passwort vergeben
3. **Einstellungen → Xtream-Server** konfigurieren: IP/Domain, Port, Benutzername, Passwort
4. **Verbindung testen** — bei Erfolg speichern
5. **Ziel-Pfad** angeben (lokal) oder rclone aktivieren (Cloud)
6. **Einstellungen → Cache aufbauen** — Mediathek zum ersten Mal laden

---

## Konfiguration

### Xtream-Server

| Feld | Beschreibung |
|---|---|
| Server IP / Domain | Adresse des Xtream-Codes-Servers |
| Port | Standard: 80 |
| Benutzername / Passwort | Xtream-Zugangsdaten |

### Download-Ziel

| Modus | Beschreibung |
|---|---|
| Lokal | Absoluter Pfad, z.B. `/mnt/nas/media` |
| rclone | Direktes Streaming in Cloud-Speicher |

---

## Server-Verwaltung

Mehrere Xtream-Server werden unterstützt. Jeder Server bekommt eine eindeutige ID (Hash aus IP + Port + Username). Downloads, Queue, Cache und Verlauf sind **pro Server getrennt**.

Unter **Einstellungen → Gespeicherte Server:**
- **↗ Wechseln** — lädt Zugangsdaten und startet neu
- **✏️ Umbenennen** — gibt dem Server einen sprechenden Namen
- **✕ Löschen** — entfernt ihn aus der Liste (Daten bleiben erhalten)

---

## rclone — Cloud-Speicher

rclone ermöglicht das direkte Streamen von VODs in einen Cloud-Speicher ohne lokale Zwischenspeicherung.

### rclone installieren

```bash
curl https://rclone.org/install.sh | sudo bash
```

### Remote konfigurieren (als www-data)

```bash
sudo -u www-data rclone config
```

> Konfigurationsdatei: `/var/www/.config/rclone/rclone.conf`

### In Xtream Vault aktivieren

1. Einstellungen → rclone aktivieren
2. Remote-Name eingeben (z.B. `mega`)
3. Ziel-Pfad eingeben (z.B. `Media/VOD`)
4. Verbindung testen → Speichern

### Remote-Cache

Beim ersten Download-Run wird `data/rclone_cache.json` angelegt. Bereits vorhandene Dateien werden damit vor dem Download erkannt und übersprungen. Manuell aktualisieren: **Einstellungen → rclone → 🗂 Remote-Cache aktualisieren**

---

## Ordnerstruktur der Downloads

```
Movies/
  DE/
    Action/
      Inception.2010.mkv

TV Shows/
  DE/
    Dark/
      Staffel 1/
        Dark.S01E01.mkv
```

Länderkürzel werden automatisch aus dem Titel oder der Kategorie extrahiert.

---

## Rollen & Berechtigungen

| Berechtigung | admin | editor | viewer |
|---|:---:|:---:|:---:|
| Movies / Serien browsen | ✅ | ✅* | ✅* |
| Suche & Favoriten | ✅ | ✅ | ✅ |
| Queue ansehen | ✅ | ✅ | ❌ |
| Queue hinzufügen | ✅ | ✅ (Limit) | ❌ |
| Queue verwalten / leeren | ✅ | ❌ | ❌ |
| Einstellungen | ✅ | ❌ | ❌ |
| Benutzerverwaltung | ✅ | ❌ | ❌ |
| Statistiken | ✅ | ❌ | ❌ |

*Kann vom Admin pro Inhaltstyp deaktiviert werden*

Editoren und Viewer sehen in der Queue und im Download-Verlauf nur ihre **eigenen** Einträge.

### Queue-Limits

Konfigurierbar in `auth.php` pro Rolle, individuell überschreibbar pro User unter **Benutzerverwaltung**.

---

## Download-Queue

- **Prioritäten:** 🔴 Hoch / 🟡 Normal / 🔵 Niedrig
- **Manuell starten:** Dashboard → ▶ Starten
- **Abbrechen:** nur Admins, über die Progress-Card
- **Retry:** fehlgeschlagene Downloads über ↻ manuell neu einreihen
- **Reset:** heruntergeladene VODs zurücksetzen und neu einreihen

### Doppelten-Prüfung beim Queue-Add

1. Bereits in der Queue
2. Bereits heruntergeladen (`downloaded.json`)
3. Dateiname im rclone-Remote-Cache (nur rclone-Modus)

---

## Mediathek & Suche

- **Cache** wird täglich um 4 Uhr automatisch aufgebaut (pro Server getrennt)
- **Suche** primär gegen lokalen Cache, Fallback auf Xtream-Server
- **Suchverlauf:** letzte 10 Suchanfragen im Browser gespeichert
- **Sortierung:** Standard, A→Z, Z→A
- **Paginierung** ab 50 Items

---

## Neue Releases

Die View **🆕 Neu** zeigt alle Titel die seit dem letzten Cache-Run neu hinzugekommen sind und noch nicht heruntergeladen wurden.

- Titel bleiben sichtbar bis sie **heruntergeladen** oder **manuell entfernt** (✕-Button) werden
- Beim ersten Run werden alle IDs als bekannt markiert — kein False-Positive
- Nach einem Download verschwindet der Titel automatisch aus der Liste

---

## Favoriten

Jeder Benutzer kann Filme und Serien mit ♥ als Favoriten markieren. Erreichbar unter **Favoriten**, filterbar und durchsuchbar. Gespeichert pro User in `data/users.json`.

---

## TMDB-Integration

Klick auf eine Karte öffnet ein Modal mit Daten von The Movie Database:
- Backdrop, Poster, Beschreibung, Bewertung, Genres
- **Stream-Info** via `ffprobe` (Auflösung, Codec, Bitrate, HDR, Audio, Dauer)

### Setup

1. API-Key unter [themoviedb.org/settings/api](https://www.themoviedb.org/settings/api) erstellen
2. Einstellungen → 🎬 TMDB → Key eintragen → Speichern

---

## Statistiken

Unter **📊 Statistiken** (nur Admins):

- KPI-Cards: Gesamtdownloads, Gesamtvolumen
- **Datenvolumen pro Monat** — Balkendiagramm
- **Downloads pro Monat** — Balkendiagramm
- **Top Kategorien** — horizontales Balkendiagramm (Top 15)
- **Top User** — Rangliste mit Fortschrittsbalken

---

## Telegram-Benachrichtigungen

Nach jedem abgeschlossenen Download wird eine Telegram-Nachricht gesendet.

### Setup

1. Bot erstellen: [@BotFather](https://t.me/BotFather) → `/newbot`
2. Chat-ID ermitteln: [@userinfobot](https://t.me/userinfobot)
3. Einstellungen → 📨 Telegram → Token + Chat-ID → Speichern
4. **📨 Testnachricht senden** zur Überprüfung

### Nachrichtenformat

```
✅ Download abgeschlossen
🎬 Film: Inception.2010
📦 1.842 MB
```

---

## VPN (WireGuard)

Downloads können über einen WireGuard-Tunnel geleitet werden. Nur der `www-data`-Prozess (`cron.php`) nutzt den Tunnel — SSH, Apache und alle anderen Dienste bleiben auf dem normalen Gateway.

### Voraussetzungen

```bash
sudo apt install wireguard
```

### Konfiguration ablegen

```bash
sudo nano /etc/wireguard/wg0.conf
sudo chmod 600 /etc/wireguard/wg0.conf
```

### sudo-Rechte einrichten

```bash
sudo tee /etc/sudoers.d/xtream-vpn << 'EOF'
www-data ALL=(root) NOPASSWD: /usr/bin/wg-quick up *, /usr/bin/wg-quick down *
www-data ALL=(root) NOPASSWD: /usr/bin/wg showconf *, /usr/bin/wg show *
www-data ALL=(root) NOPASSWD: /usr/sbin/ip rule show, /usr/sbin/ip rule add *, /usr/sbin/ip rule del *
www-data ALL=(root) NOPASSWD: /usr/sbin/ip -6 rule show, /usr/sbin/ip -6 rule add *, /usr/sbin/ip -6 rule del *
www-data ALL=(root) NOPASSWD: /usr/sbin/ip route add *, /usr/sbin/ip route del *
EOF
sudo chmod 0440 /etc/sudoers.d/xtream-vpn
sudo visudo -c -f /etc/sudoers.d/xtream-vpn && echo "OK"
```

> **Pfad prüfen:** `which ip` — auf Ubuntu meist `/usr/sbin/ip`

### In Xtream Vault aktivieren

Einstellungen → 🔒 VPN → Interface `wg0` → aktivieren → Speichern

Der VPN-Status-Badge in der Topbar zeigt den aktuellen Zustand (automatische Aktualisierung alle 30 Sekunden).

### Notfall-Reset

Falls SSH-Verbindung nach `vpn_connect` verloren geht (z.B. über Hoster-Konsole):

```bash
sudo wg-quick down wg0
sudo ip rule del uidrange $(id -u www-data)-$(id -u www-data) lookup 51820 priority 100 2>/dev/null
```

Nach einem Server-Neustart sind alle `ip rule` Einträge weg (nicht persistent).

---

## Themes

Jeder Benutzer kann unter **👤 Mein Profil** ein Design wählen:

| Theme | Beschreibung |
|---|---|
| Dark | Standard — dunkles Design mit gelbem Akzent |
| AMOLED | Reines Schwarz für OLED-Displays |
| Midnight | Dunkles Blau mit blauen Akzenten |
| Light | Helles Design mit lila Akzenten |

Die Auswahl wird pro User im Browser (`localStorage`) gespeichert.

---

## Benutzerverwaltung

Unter **👥 Benutzer** (nur Admins):

- Benutzer anlegen, bearbeiten, sperren, löschen
- **🔑 Passwort zurücksetzen** durch Admin
- **Queue-Limit** individuell pro User setzen
- **Download-Verlauf** pro User einsehen (Klick auf Username)
- **Aktivitätslog** für alle Aktionen

---

## Einladungslinks

Admins können unter **🔗 Einladung erstellen** einmalige Registrierungslinks generieren:

- Rolle und Gültigkeitsdauer (6h bis 7 Tage) wählbar
- Öffentliche Registrierungsseite: `https://deine-domain.de/invite.php?token=...`
- Nach einmaliger Nutzung als verwendet markiert

---

## Updates

Unter **Einstellungen → 🔄 Updates**:

1. **🔍 Auf Updates prüfen** — vergleicht lokalen Commit mit GitHub
2. **⬆️ Update installieren** — lädt ZIP von GitHub herunter, entpackt und installiert

Vor jedem Update wird automatisch ein Backup von `data/` erstellt. `install.sh`, `README.md` und `.gitignore` werden beim Update nicht überschrieben. Die Seite lädt nach dem Update automatisch neu.

---

## Datensicherung

Unter **Einstellungen → 💾 Datensicherung**:

- Sichert alle Dateien in `data/` als ZIP
- Automatisch täglich um 3 Uhr, maximal 7 Backups
- **↩ Restore** stellt ein Backup wieder her

```bash
# Manuell ausführen
php /var/www/html/xtream/backup.php
```

---

## Health-Check

```
GET /api.php?action=health
```

Kein Login erforderlich. HTTP 200 wenn OK, 503 bei Wartung.

```json
{
  "status": "ok",
  "configured": true,
  "maintenance": false,
  "queue": { "pending": 2, "downloading": 1, "errors": 0 },
  "disk": { "free_bytes": 107374182400, "free_pct": 20.0 },
  "last_backup": "2026-03-23 03:00:00"
}
```

---

## Externe API

Authentifizierung via Header `X-API-Key: xv_...`

Vollständige Dokumentation unter **📖 API-Dokumentation** (nur Admins).

| Endpoint | Beschreibung |
|---|---|
| `external_create_user` | Benutzer anlegen |
| `external_list_users` | Alle Benutzer auflisten |
| `external_suspend_user` | Benutzer sperren / entsperren |
| `external_update_user` | Passwort oder Rolle ändern |
| `external_delete_user` | Benutzer löschen |

---

## Sicherheit

- `data/` vollständig vor HTTP-Zugriff geschützt (`.htaccess`)
- Interne PHP-Dateien (`config.php`, `auth.php`, `cron.php`, `cache_builder.php`, `backup.php`) per `.htaccess` gesperrt
- Stream-URLs werden nur serverseitig aufgebaut
- Aktivitätslog protokolliert alle relevanten Aktionen
- HTTPS wird empfohlen (Let's Encrypt / Certbot)

---

## Dateistruktur

```
/var/www/html/xtream/
├── index.php              — Haupt-Frontend (gesamte UI)
├── api.php                — Backend-API (alle Endpoints)
├── auth.php               — Authentifizierung, Rollen, Rate-Limiting
├── config.php             — Zentrale Konfiguration & Hilfsfunktionen
├── login.php              — Login & Ersteinrichtungs-Wizard
├── invite.php             — Öffentliche Einladungs-Registrierungsseite
├── cache_builder.php      — Hintergrundprozess für Medien-Cache
├── cron.php               — Download-Worker (Cronjob)
├── backup.php             — Backup-Script
├── maintenance.php        — Wartungsseite
├── style.css              — Stylesheet
├── .htaccess              — Sicherheitsregeln
└── data/                  — Datendateien (automatisch erstellt)
    ├── config.json
    ├── users.json
    ├── servers.json
    ├── invites.json
    ├── queue_<id>.json
    ├── downloaded_<id>.json
    ├── downloaded_index_<id>.json
    ├── download_history_<id>.json
    ├── library_cache_<id>.json
    ├── series_cache_<id>.json
    ├── new_releases.json
    ├── rclone_cache.json
    ├── activity.json
    ├── api_keys.json
    ├── progress.json
    ├── cron.lock / cron.log
    └── backups/
```
