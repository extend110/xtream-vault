# Xtream Vault

Ein PHP/HTML-Frontend zum Browsen, Verwalten und automatischen Herunterladen von VODs von einem Xtream-Codes-Server.

## Dateien

```
xtream-frontend/
├── index.php           – Haupt-Frontend (UI)
├── api.php             – Backend-API (alle Endpoints)
├── auth.php            – Authentifizierung, Rollen, Rate-Limiting
├── config.php          – Zentrale Konfiguration & Hilfsfunktionen
├── login.php           – Login-Seite & Ersteinrichtungs-Wizard
├── cache_builder.php   – Hintergrundprozess für Medien-Cache
├── cron.php            – Download-Worker (läuft als Cronjob)
├── .htaccess           – Sicherheitsregeln, sperrt sensitive Dateien
└── data/               – Alle Datendateien (automatisch erstellt)
    ├── config.json         – Server-Konfiguration
    ├── users.json          – Benutzerdatenbank
    ├── queue.json          – Download-Queue
    ├── downloaded.json     – Liste heruntergeladener VODs
    ├── activity.json       – Aktivitätslog
    ├── rate_limits.json    – Rate-Limit-Tracking
    ├── api_keys.json       – API-Keys für externe Zugriffe
    ├── library_cache.json  – Film-Metadaten-Cache
    ├── series_cache.json   – Serien-Metadaten-Cache
    ├── progress.json       – Aktueller Download-Fortschritt
    └── cron.log            – Download-Log
```

## Voraussetzungen

- PHP 8.0+ mit den Extensions: `curl`, `json`, `session`, `posix`
- Apache mit `mod_rewrite` und `mod_headers`
- `allow_url_fopen = On` in `php.ini`

## Installation (GitHub)

git clone https://github.com/extend110/xtream-vault.git
sudo chmod +x install.sh
sudo ./install.sh

## Installation (manuell)

### 1. Dateien hochladen

```bash
scp -r xtream-frontend/ user@server:/var/www/html/xtream/
```

### 2. Berechtigungen setzen

```bash
sudo chown -R www-data:www-data /var/www/html/xtream/
sudo chmod -R 755 /var/www/html/xtream/
```

### 3. Apache konfigurieren

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

### 4. Cronjobs einrichten

```bash
sudo crontab -e -u www-data
```

```
# Downloads alle 30 Minuten verarbeiten
*/30 * * * * flock -n /tmp/xtream_cron.lock php /var/www/html/xtream/cron.php

# Medien-Cache täglich um 4 Uhr neu aufbauen
0 4 * * * php /var/www/html/xtream/cache_builder.php
```

### 5. Ersteinrichtung

1. `https://deine-domain.de` aufrufen
2. Admin-Account anlegen (Setup-Wizard)
3. In den Einstellungen den Xtream-Server konfigurieren
4. Verbindung testen
5. Ersten Medien-Cache aufbauen (Einstellungen → Medien-Cache → "Cache jetzt aufbauen")

---

## Rollen & Berechtigungen

| Berechtigung              | admin | editor | viewer |
|---------------------------|:-----:|:------:|:------:|
| Browsen (Movies/Serien)   | ✅    | ✅     | ✅     |
| Mediathek (Dashboard)     | –     | ✅     | ✅     |
| Suche (Film + Serien)     | ✅    | ✅     | ✅     |
| Queue ansehen             | ✅    | ✅     | ❌     |
| Queue hinzufügen          | ✅    | ✅ (3/h)| ❌    |
| Eigene Queue-Einträge entfernen | ✅ | ✅ | ❌  |
| Queue leeren / verwalten  | ✅    | ❌     | ❌     |
| Cron-Log                  | ✅    | ❌     | ❌     |
| Einstellungen             | ✅    | ❌     | ❌     |
| Benutzerverwaltung        | ✅    | ❌     | ❌     |

Das stündliche Queue-Limit für `editor` ist in `auth.php` konfigurierbar:
```php
const QUEUE_ADD_HOURLY_LIMIT = [
    'admin'  => null,   // unbegrenzt
    'editor' => 3,
    'viewer' => 0,
];
```

---

## Externer API-Endpoint

Benutzer können über einen API-Key von externen Systemen angelegt werden.

**API-Key erstellen:** Einstellungen → API-Keys → "+ API-Key erstellen"

**Endpoint:**
```
POST /api.php?action=external_create_user
X-API-Key: xv_...
Content-Type: application/json

{
  "username": "max",
  "password": "sicher123",
  "role": "viewer"
}
```

**Antwort:**
```json
{ "ok": true, "id": "abc123", "username": "max", "role": "viewer" }
```

---

## Medien-Cache

Der Cache speichert Titel, Cover und Kategorien aller VODs lokal in `data/library_cache.json` und `data/series_cache.json`.

- Wird **automatisch** nach jedem Download-Run durch `cron.php` neu aufgebaut
- Kann **manuell** über Einstellungen → Medien-Cache angestoßen werden
- Für viewer/editor ist das Dashboard eine persönliche Mediathek mit Such- und Kategoriefilter

---

## Sicherheit

- Alle sensitiven Dateien (`data/`, `config.php`, `auth.php`, `cron.php`, `cache_builder.php`) sind per `.htaccess` vor direktem HTTP-Zugriff geschützt
- `stream_url` (enthält Xtream-Zugangsdaten) wird nur an Admins ausgeliefert; viewer/editor empfangen sie nie im JSON
- Gesperrte Benutzer (`suspended: true`) können sich nicht einloggen
- Jede Aktion wird im Aktivitätslog (`data/activity.json`) protokolliert

---

## rclone — Cloud-Speicher Integration

rclone ermöglicht das direkte Streamen von VODs in einen Cloud-Speicher (Google Drive, OneDrive, Dropbox, S3, etc.) ohne lokale Zwischenspeicherung.

### Installation

```bash
# Automatische Installation (empfohlen)
curl https://rclone.org/install.sh | sudo bash

# Version prüfen
rclone version
```

### Remote konfigurieren

```bash
# Interaktiver Setup-Assistent
sudo -u www-data rclone config

# Beispiel für Google Drive:
# Name: gdrive
# Type: drive
# → Browser-Authentifizierung folgen

# Verbindung testen
sudo -u www-data rclone lsd gdrive:
```

### In Xtream Vault aktivieren

1. **Einstellungen → rclone** öffnen
2. **rclone aktivieren** anhaken
3. **Remote-Name** eingeben (z.B. `gdrive`)
4. **Ziel-Pfad** eingeben (z.B. `Media/VOD`)
5. **rclone testen** — prüft Binary und Remote-Verbindung
6. **Speichern**

### Ordnerstruktur im Cloud-Speicher

Downloads landen unter:
```
{Remote}:{Pfad}/{Movies|TV Shows}/{Kategorie}/{Titel}.{ext}
```

Beispiel mit Remote `gdrive`, Pfad `Media`:
```
gdrive:Media/Movies/Action/Inception.mkv
gdrive:Media/TV Shows/Drama/Breaking Bad S01E01.mkv
```

### Hinweise

- Im rclone-Modus wird `DEST_PATH` ignoriert
- Die Progress-Anzeige zeigt „☁️ Streaming…" statt Bytes/Geschwindigkeit (rclone liefert keinen Byte-Fortschritt beim Streamen)
- rclone muss als `www-data`-User konfiguriert sein: `sudo -u www-data rclone config`
- Konfigurationsdatei liegt unter `/var/www/.config/rclone/rclone.conf`
