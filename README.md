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
9. [Mediathek & Suche](#mediathek--suche)
10. [Favoriten](#favoriten)
11. [Datensicherung](#datensicherung)
12. [Health-Check](#health-check)
13. [Externe API](#externe-api)
14. [Sicherheit](#sicherheit)
15. [Dateistruktur](#dateistruktur)

---

## Voraussetzungen

| Anforderung | Mindestversion / Hinweis |
|---|---|
| PHP | 8.0+ mit Extensions `curl`, `json`, `session`, `posix`, `zip`, `mbstring` |
| Webserver | Apache mit `mod_rewrite` und `mod_headers` |
| php.ini | `allow_url_fopen = On` |
| Optional: rclone | Für Cloud-Speicher-Integration |

PHP-Erweiterungen installieren:
```bash
sudo apt install php-curl php-zip php-mbstring php-json
```

---

## Installation

### Schnellinstallation (empfohlen)

```bash
sudo bash install.sh
```

Das Skript installiert alle Abhängigkeiten, richtet Apache ein, setzt Berechtigungen und konfiguriert Cronjobs automatisch.

### Manuelle Installation

**1. Dateien hochladen**

```bash
scp -r xtream-frontend/ user@server:/var/www/html/xtream/
```

**2. Berechtigungen setzen**

```bash
sudo chown -R www-data:www-data /var/www/html/xtream/
sudo chmod -R 755 /var/www/html/xtream/
sudo chmod -R 775 /var/www/html/xtream/data/
```

**3. Apache konfigurieren**

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

> **HTTPS empfohlen:**
> ```bash
> sudo apt install certbot python3-certbot-apache
> sudo certbot --apache -d deine-domain.de
> ```

**4. Cronjobs**

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

Falls die automatische Einrichtung fehlschlägt:
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
7. Über **Einstellungen → Cache aufbauen** die Mediathek zum ersten Mal laden

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

Externe Systeme können über API-Keys auf die Benutzerverwaltungs-API zugreifen. Verwaltung unter **Einstellungen → API-Keys**. Der vollständige Key kann nach Passwort-Bestätigung einmalig eingesehen werden.

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

Im rclone-Modus zeigt die Progress-Card echten Fortschritt mit Bytes, Geschwindigkeit und ETA.

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
    Dark/
      Staffel 1/
        Dark.S01E01.mkv
      Staffel 2/
        Dark.S02E01.mkv
```

**Länderkürzel** werden automatisch aus Serientitel oder Kategorie extrahiert (`DE`, `US`, `DACH`, `MULTI` etc.). Titel ohne erkanntes Kürzel landen direkt in der Kategorie.

**Dateinamen:**
- Filme: `Titel.Jahr.ext`
- Episoden: `Serienname.SxxExx.ext` — alles nach dem Episode-Code wird entfernt
- Doppelpunkte im Titel werden durch `-` ersetzt, Umlaute bleiben erhalten

**Unterstützte Eingabeformate für Episoden:**
- `Dark - S01E01 - Folge 1` → `Dark.S01E01.mkv`
- `dark.s01e01.german.720p` → `dark.S01E01.mkv`
- `power.book IV force S02E01` → `power book IV force.S02E01.mkv`
- `power.book.iv.force.s01e01.german.dl.720p` → `power book iv force.S01E01.mkv`

---

## Rollen & Berechtigungen

| Berechtigung | admin | editor | viewer |
|---|:---:|:---:|:---:|
| Movies browsen | ✅ | ✅* | ✅* |
| Serien browsen | ✅ | ✅* | ✅* |
| Suche | ✅ | ✅ | ✅ |
| Favoriten | ✅ | ✅ | ✅ |
| Queue ansehen | ✅ | ✅ | ❌ |
| Queue hinzufügen | ✅ | ✅ (Limit) | ❌ |
| Queue verwalten / leeren | ✅ | ❌ | ❌ |
| Download abbrechen / zurücksetzen | ✅ | ❌ | ❌ |
| Cron-Log | ✅ | ❌ | ❌ |
| Einstellungen | ✅ | ❌ | ❌ |
| Benutzerverwaltung | ✅ | ❌ | ❌ |
| API-Dokumentation | ✅ | ❌ | ❌ |

*\* Kann vom Admin pro Inhaltstyp deaktiviert werden*

### Queue-Limits

Das stündliche Queue-Limit ist in `auth.php` pro Rolle konfigurierbar:

```php
const QUEUE_ADD_HOURLY_LIMIT = [
    'admin'  => null,  // unbegrenzt
    'editor' => 3,     // 3 Anfragen/Stunde
    'viewer' => 0,     // kein Zugriff
];
```

Admins können in der **Benutzerverwaltung** für jeden User ein individuelles Limit setzen, das das Rollen-Limit überschreibt. Leer = Rollen-Standard, `0` = kein Zugriff, `5` = 5/h.

---

## Download-Queue

### Prioritäten

🔴 Hoch (1) / 🟡 Normal (2) / 🔵 Niedrig (3) — Admins können die Priorität direkt in der Queue-Ansicht ändern.

### Manuell starten

Downloads können über **▶ Starten** im Dashboard oder in der Queue-Ansicht manuell angestoßen werden, ohne den nächsten Cron-Lauf abzuwarten.

### Speicherplatz-Prüfung (lokaler Modus)

Vor jedem Download wird die Dateigröße per HEAD-Request ermittelt und mit dem freien Speicherplatz verglichen (Dateigröße + 512 MB Puffer). Bei zu wenig Platz wird der Run abgebrochen.

### Download abbrechen

Laufende Downloads können über **✕ Abbrechen** in der Progress-Card gestoppt werden (nur Admins). Das Item wird zurück auf `pending` gesetzt.

### Fehlerbehandlung

Fehlgeschlagene Downloads werden als `error` markiert. Admins können sie über **↻ Retry** manuell neu einreihen.

### Download zurücksetzen

Bereits heruntergeladene VODs können über **↺ Reset** zurückgesetzt werden (Filmkarten, Queue-Done-Items, Episoden-Modal, Dashboard). Das Item wird aus der Heruntergeladen-Liste entfernt und kann neu zur Queue hinzugefügt werden — nützlich wenn Dateien manuell gelöscht wurden.

---

## Mediathek & Suche

### Medien-Cache

Der Cache (`library_cache.json` für Filme, `series_cache.json` für Serien) wird täglich um 4 Uhr automatisch aufgebaut und kann manuell über **Einstellungen → Medien-Cache** aktualisiert werden.

### Suche

Die Suche läuft primär gegen den lokalen Cache — schnell, kein Xtream-Server-Traffic. Bei veraltetem oder leerem Cache fällt sie automatisch auf den Xtream-Server zurück. Ergebnisse werden innerhalb einer Sitzung gecacht.

- **Debouncing:** Anfrage erst 350ms nach dem letzten Tastendruck
- **Suchverlauf:** Die letzten 10 Suchanfragen und zuletzt angesehene Kategorien werden pro User im Browser gespeichert
- **Quellenhinweis:** Bei Cache-Ergebnissen erscheint ein Hinweis mit „Aktualisieren"-Link

### Sortierung & Paginierung

Filme und Serien können nach Standard, A→Z oder Z→A sortiert werden. Ab 50 Items erscheint eine Seitennavigation.

---

## Favoriten

Jeder Benutzer kann Filme und Serien mit dem ♥-Button als Favoriten markieren. Erreichbar unter **Favoriten** in der Navigation, filterbar nach Typ (Alle / Filme / Serien) und durchsuchbar. VODs können direkt aus den Favoriten zur Queue hinzugefügt werden.

Favoriten werden pro User in `data/users.json` gespeichert.

---

## Datensicherung

Backup-Verwaltung unter **Einstellungen → 💾 Datensicherung**.

Gesichert werden alle Dateien in `data/` als ZIP-Archiv. Backups landen in `data/backups/` und werden nach 7 Kopien automatisch rotiert.

**Manuell ausführen:**
```bash
php /var/www/html/xtream/backup.php
```

**Wiederherstellen:** Einstellungen → Datensicherung → **↩ Restore** — stellt alle JSON-Dateien aus dem Archiv wieder her und validiert jede Datei vor dem Schreiben.

**Backup-Log:** `data/backup.log`

---

## Health-Check

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

## Externe API

Externe Systeme können über API-Keys auf die Benutzerverwaltung zugreifen. Die vollständige Dokumentation ist unter **📖 API-Dokumentation** in der Navigation verfügbar (nur Admins).

**Authentifizierung:**
```
X-API-Key: xv_xxxxxxxxxxxx
```
oder als Query-Parameter: `?api_key=xv_xxxxxxxxxxxx`

### Verfügbare Endpoints

| Endpoint | Methode | Beschreibung |
|---|---|---|
| `external_create_user` | GET / POST | Benutzer anlegen |
| `external_list_users` | GET | Alle Benutzer auflisten |
| `external_suspend_user` | GET / POST | Benutzer sperren / entsperren |
| `external_update_user` | POST | Passwort oder Rolle ändern |
| `external_delete_user` | GET / POST | Benutzer löschen |

---

## Sicherheit

- **`data/`-Verzeichnis** ist per `.htaccess` vollständig vor HTTP-Zugriff geschützt
- **Stream-URLs** werden nur serverseitig aufgebaut und nie an den Client übertragen
- **Gesperrte Benutzer** können sich nicht einloggen; der Wartungsmodus erlaubt nur Admin-Logins
- **Aktivitätslog** protokolliert alle relevanten Aktionen
- **Rate-Limiting** für editor-Accounts: konfigurierbares stündliches Queue-Limit
- **API-Keys** können jederzeit widerrufen oder gelöscht werden; vollständiger Key nur nach Passwort-Bestätigung einsehbar
- **HTTPS** wird empfohlen (Let's Encrypt / Certbot)

---

## Dateistruktur

```
xtream-frontend/
├── index.php              — Haupt-Frontend (gesamte UI)
├── api.php                — Backend-API (alle Endpoints)
├── auth.php               — Authentifizierung, Rollen, Rate-Limiting
├── config.php             — Zentrale Konfiguration & Hilfsfunktionen
├── login.php              — Login & Ersteinrichtungs-Wizard
├── cache_builder.php      — Hintergrundprozess für Medien-Cache (Filme + Serien)
├── cron.php               — Download-Worker (Cronjob)
├── backup.php             — Backup-Script (täglich 3 Uhr, max 7 Backups)
├── maintenance.php        — Wartungsseite
├── install.sh             — Automatisches Installationsskript
├── .htaccess              — Sicherheitsregeln
└── data/                  — Datendateien (automatisch erstellt)
    ├── config.json            — Server- & App-Konfiguration
    ├── users.json             — Benutzerdatenbank inkl. Favoriten & Limits
    ├── queue.json             — Download-Queue
    ├── downloaded.json        — IDs heruntergeladener VODs
    ├── downloaded_index.json  — Metadaten heruntergeladener VODs
    ├── download_history.json  — Permanenter Download-Verlauf (max. 200)
    ├── library_cache.json     — Film-Metadaten-Cache
    ├── series_cache.json      — Serien-Metadaten-Cache
    ├── activity.json          — Aktivitätslog
    ├── rate_limits.json       — Rate-Limit-Tracking
    ├── api_keys.json          — API-Keys
    ├── progress.json          — Aktueller Download-Fortschritt
    ├── cancel.lock            — Abbruch-Signal (temporär)
    ├── cron.log               — Download-Log
    ├── backup.log             — Backup-Log
    └── backups/               — Backup-Archiv (automatisch erstellt)
        └── backup_YYYY-MM-DD_HH-II-SS.zip
```
