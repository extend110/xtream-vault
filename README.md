# Xtream Vault

Ein PHP-Frontend zum Browsen, Verwalten und automatischen Herunterladen von VODs von einem Xtream-Codes-Server. Unterstützt lokale Speicherung sowie direktes Streaming in Cloud-Speicher via rclone (Google Drive, MEGA, OneDrive, etc.).

---

## Inhaltsverzeichnis

1. [Voraussetzungen](#voraussetzungen)
2. [Installation](#installation)
3. [Ersteinrichtung](#ersteinrichtung)
4. [Konfiguration](#konfiguration)
5. [rclone — Cloud-Speicher](#rclone--cloud-speicher)
6. [Ordnerstruktur der Downloads](#ordnerstruktur-der-downloads)
7. [Rollen & Berechtigungen](#rollen--berechtigungen)
8. [Download-Queue](#download-queue)
9. [Favoriten](#favoriten)
10. [Externer API-Endpoint](#externer-api-endpoint)
11. [Sicherheit](#sicherheit)
12. [Dateistruktur](#dateistruktur)

---

## Voraussetzungen

| Anforderung | Mindestversion / Hinweis |
|---|---|
| PHP | 8.0+ mit Extensions `curl`, `json`, `session`, `posix` |
| Webserver | Apache mit `mod_rewrite` und `mod_headers` |
| php.ini | `allow_url_fopen = On` |
| Optional: rclone | Für Cloud-Speicher-Integration |

---

## Installation

### 1. Dateien hochladen

```bash
scp -r xtream-frontend/ user@server:/var/www/html/xtream/
```

### 2. Berechtigungen setzen

```bash
sudo chown -R www-data:www-data /var/www/html/xtream/
sudo chmod -R 755 /var/www/html/xtream/
sudo chmod -R 775 /var/www/html/xtream/data/
```

### 3. Apache konfigurieren

Neue Konfigurationsdatei anlegen:

```bash
sudo nano /etc/apache2/sites-available/xtream.conf
```

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

> **HTTPS empfohlen:** Mit Let's Encrypt und Certbot:
> ```bash
> sudo apt install certbot python3-certbot-apache
> sudo certbot --apache -d deine-domain.de
> ```

### 4. Cronjobs

Die Cronjobs werden beim ersten Login **automatisch eingerichtet** (sofern `www-data` Crontab-Rechte hat). Zur manuellen Überprüfung:

```bash
sudo -u www-data crontab -l
```

Erwartete Einträge:
```
*/30 * * * * /usr/bin/php /var/www/html/xtream/cron.php >> /dev/null 2>&1
0 4 * * * /usr/bin/php /var/www/html/xtream/cache_builder.php >> /dev/null 2>&1
0 3 * * * /usr/bin/php /var/www/html/xtream/backup.php >> /dev/null 2>&1
```

Falls die automatische Einrichtung fehlschlägt, manuell hinzufügen:

```bash
sudo crontab -e -u www-data
```

---

## Ersteinrichtung

1. Im Browser `https://deine-domain.de` aufrufen
2. Den **Setup-Wizard** ausfüllen — Admin-Benutzername und Passwort vergeben
3. Nach dem Login zu **Einstellungen** navigieren
4. **Xtream-Server** konfigurieren: IP/Domain, Port, Benutzername, Passwort
5. **Verbindung testen** — bei Erfolg speichern
6. **Ziel-Pfad** angeben (lokaler Speicher) oder rclone aktivieren (Cloud)
7. Über das Dashboard → **Cache aufbauen** die Mediathek zum ersten Mal laden

---

## Konfiguration

Alle Einstellungen sind über **Einstellungen** im Frontend erreichbar.

### Xtream-Server

| Feld | Beschreibung |
|---|---|
| Server IP / Domain | Adresse des Xtream-Codes-Servers |
| Port | Standard: 80 |
| Benutzername | Xtream-Zugangsdaten |
| Passwort | Xtream-Zugangsdaten |

### Download-Ziel

| Modus | Beschreibung |
|---|---|
| Lokal | Absoluter Pfad auf dem Server, z.B. `/mnt/nas/media` |
| rclone | Direktes Streaming in Cloud-Speicher (kein lokaler Zwischenspeicher) |

### Editor / Viewer — Sichtbarkeit

Admins können Movies und Serien für editor/viewer-Accounts separat ausblenden.

### API-Keys

Externe Systeme können über API-Keys neue Benutzer anlegen. Verwaltung unter **Einstellungen → API-Keys**.

---

## rclone — Cloud-Speicher

rclone ermöglicht das direkte Streamen von VODs in einen Cloud-Speicher ohne lokale Zwischenspeicherung.

### rclone installieren

```bash
curl https://rclone.org/install.sh | sudo bash
rclone version
```

### Remote konfigurieren

Die Konfiguration muss als `www-data`-User erfolgen:

```bash
sudo -u www-data rclone config
```

Beispiel für MEGA:
```
n) New remote
name> mega
Storage> mega
user> deine@email.de
password> [Passwort eingeben]
```

Beispiel für Google Drive:
```
n) New remote
name> gdrive
Storage> drive
# Browser-Authentifizierung folgen
```

Verbindung testen:
```bash
sudo -u www-data rclone lsd mega:
```

> **Konfigurationsdatei:** `/var/www/.config/rclone/rclone.conf`

### rclone in Xtream Vault aktivieren

1. **Einstellungen → rclone** öffnen
2. **rclone aktivieren** anhaken
3. **Remote-Name** eingeben (z.B. `mega`)
4. **Ziel-Pfad** eingeben (z.B. `Media/VOD`)
5. Mit **Verbindung testen** prüfen
6. **Speichern**

### Fortschrittsanzeige

Im rclone-Modus zeigt die Progress-Card echten Fortschritt mit Bytes, Geschwindigkeit und ETA. Davor erscheint ein pulsierender Ladebalken.

---

## Ordnerstruktur der Downloads

### Filme

```
Movies/
  DE/
    Action/
      Der Pate.1972.mkv
  US/
    Action/
      Inception.2010.mkv
```

### Serien

```
TV Shows/
  DE/
    Drama/
      Dark/
        Staffel 1/
          Dark.S01E01.mkv
        Staffel 2/
          Dark.S02E01.mkv
```

**Länderkürzel** werden automatisch aus Titel oder Kategorie extrahiert (`DE`, `US`, `DACH`, `MULTI` etc.). Titel ohne erkanntes Kürzel landen direkt in der Kategorie.

**Dateinamen:**
- Filme: `Titel.Jahr.ext`
- Episoden: `Serienname.SxxExx.ext` — alles nach dem Episode-Code wird entfernt

---

## Rollen & Berechtigungen

| Berechtigung | admin | editor | viewer |
|---|:---:|:---:|:---:|
| Movies browsen | ✅ | ✅* | ✅* |
| Serien browsen | ✅ | ✅* | ✅* |
| Suche | ✅ | ✅ | ✅ |
| Favoriten | ✅ | ✅ | ✅ |
| Mediathek | ✅ | ✅ | ✅ |
| Queue ansehen | ✅ | ✅ | ❌ |
| Queue hinzufügen | ✅ | ✅ (3/h) | ❌ |
| Queue verwalten / leeren | ✅ | ❌ | ❌ |
| Absender in Queue sehen | ✅ | ❌ | ❌ |
| Download abbrechen | ✅ | ❌ | ❌ |
| Cron-Log | ✅ | ❌ | ❌ |
| Einstellungen | ✅ | ❌ | ❌ |
| Benutzerverwaltung | ✅ | ❌ | ❌ |

*\* Kann vom Admin pro Inhaltstyp deaktiviert werden*

Das stündliche Queue-Limit für `editor` ist in `auth.php` konfigurierbar:

```php
const QUEUE_ADD_HOURLY_LIMIT = [
    'admin'  => null,  // unbegrenzt
    'editor' => 3,
    'viewer' => 0,
];
```

---

## Download-Queue

### Prioritäten

🔴 Hoch (1) / 🟡 Normal (2) / 🔵 Niedrig (3) — Admins können die Priorität direkt in der Queue-Ansicht ändern.

### Speicherplatz-Prüfung (lokaler Modus)

Vor jedem Download wird per HEAD-Request die Dateigröße ermittelt und mit dem freien Speicherplatz verglichen. Benötigt: Dateigröße + 512 MB Puffer. Bei zu wenig Platz wird der Run abgebrochen.

### Download abbrechen

Laufende Downloads können über **✕ Abbrechen** in der Progress-Card gestoppt werden (nur Admins). Das Item wird zurück auf `pending` gesetzt.

### Fehlerbehandlung

Fehlgeschlagene Downloads werden als `error` markiert. Admins können sie über **↻ Retry** manuell neu einreihen.

---

## Favoriten

Jeder Benutzer kann Filme und Serien mit dem ♥-Button als Favoriten markieren. Erreichbar unter **Favoriten** in der Navigation, filterbar nach Typ und durchsuchbar.

Favoriten werden pro User in `data/users.json` gespeichert.

---

## Externer API-Endpoint

Benutzer können von externen Systemen über einen API-Key angelegt werden.

**API-Key erstellen:** Einstellungen → API-Keys → „+ API-Key erstellen"

### Per GET

```
GET /api.php?action=external_create_user&api_key=xv_...&username=max&password=sicher123&role=viewer
```

### Per POST mit JSON-Body

```http
POST /api.php?action=external_create_user
X-API-Key: xv_...
Content-Type: application/json

{
  "username": "max",
  "password": "sicher123",
  "role": "viewer"
}
```

**Mögliche Rollen:** `viewer`, `editor`, `admin`

**Antwort (Erfolg):**
```json
{ "ok": true, "id": "abc123", "username": "max", "role": "viewer" }
```

---

## Health-Check Endpoint

Gibt den aktuellen Status der Anwendung zurück — kein Login erforderlich.

```
GET /api.php?action=health
```

**Antwort (HTTP 200 wenn OK, 503 bei Wartung oder nicht konfiguriert):**
```json
{
  "status": "ok",
  "timestamp": "2026-03-19 10:00:00",
  "configured": true,
  "maintenance": false,
  "queue": { "pending": 2, "downloading": 1, "errors": 0 },
  "cron": { "running": true, "pid": 12345 },
  "disk": { "free_bytes": 107374182400, "total_bytes": 536870912000, "free_pct": 20.0 },
  "last_backup": "2026-03-19 03:00:00"
}
```

Mögliche `status`-Werte: `ok`, `unconfigured`, `maintenance`

---

## Datensicherung

Backup-Verwaltung unter **Einstellungen → 💾 Datensicherung**.

Gesichert werden alle Dateien in `data/` (Konfiguration, User, Queue, Favoriten, Verlauf etc.) als ZIP-Archiv. Backups landen in `data/backups/` und werden nach 7 Kopien automatisch rotiert.

**Manuell ausführen:**
```bash
php /var/www/html/xtream/backup.php
```

**Backup-Log:** `data/backup.log`

---

## Sicherheit

- **`data/`-Verzeichnis** ist per `.htaccess` vollständig vor HTTP-Zugriff geschützt
- **Stream-URLs** (enthalten Xtream-Zugangsdaten) werden nur serverseitig aufgebaut
- **Gesperrte Benutzer** können sich nicht einloggen
- **Aktivitätslog** protokolliert alle relevanten Aktionen
- **Rate-Limiting** für editor-Accounts: max. 3 Queue-Adds/Stunde (konfigurierbar)
- **API-Keys** können jederzeit widerrufen oder gelöscht werden

---

## Dateistruktur

```
xtream-frontend/
├── index.php              — Haupt-Frontend (gesamte UI)
├── api.php                — Backend-API (alle Endpoints)
├── auth.php               — Authentifizierung, Rollen, Rate-Limiting
├── config.php             — Zentrale Konfiguration & Hilfsfunktionen
├── login.php              — Login & Ersteinrichtungs-Wizard
├── cache_builder.php      — Hintergrundprozess für Medien-Cache
├── cron.php               — Download-Worker (Cronjob)
├── .htaccess              — Sicherheitsregeln
└── data/                  — Datendateien (automatisch erstellt)
    ├── config.json            — Server- & App-Konfiguration
    ├── users.json             — Benutzerdatenbank inkl. Favoriten
    ├── queue.json             — Download-Queue
    ├── downloaded.json        — IDs heruntergeladener VODs
    ├── downloaded_index.json  — Metadaten heruntergeladener VODs
    ├── download_history.json  — Permanenter Download-Verlauf (max. 200)
    ├── library_cache.json     — Film-Metadaten-Cache
    ├── activity.json          — Aktivitätslog
    ├── rate_limits.json       — Rate-Limit-Tracking
    ├── api_keys.json          — API-Keys
    ├── progress.json          — Aktueller Download-Fortschritt
    ├── cancel.lock            — Abbruch-Signal (temporär)
    └── cron.log               — Download-Log
```
