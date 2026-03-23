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
16. [Benutzerverwaltung](#benutzerverwaltung)
17. [Datensicherung](#datensicherung)
18. [Health-Check](#health-check)
19. [Externe API](#externe-api)
20. [Sicherheit](#sicherheit)
21. [Dateistruktur](#dateistruktur)

---

## Voraussetzungen

| Anforderung | Mindestversion / Hinweis |
|---|---|
| PHP | 8.0+ mit Extensions `curl`, `json`, `session`, `posix`, `zip`, `mbstring` |
| Webserver | Apache mit `mod_rewrite` und `mod_headers` |
| php.ini | `allow_url_fopen = On` |
| Optional: rclone | Für Cloud-Speicher-Integration |
| Optional: ffmpeg | Für Stream-Analyse im TMDB-Modal (`ffprobe`) |

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

Das Skript installiert alle Abhängigkeiten, richtet Apache ein, setzt Berechtigungen und konfiguriert Cronjobs automatisch. Optional: ffmpeg und rclone mitinstallieren.

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

---

## Server-Verwaltung

Xtream Vault unterstützt mehrere Xtream-Server. Jeder Server bekommt eine eindeutige ID (Hash aus IP + Port + Username). Downloads, Queue, Cache und Verlauf sind **pro Server getrennt** — ein Serverwechsel verliert keine Daten.

Unter **Einstellungen → Gespeicherte Server**:
- **↗ Wechseln** — lädt Zugangsdaten des gewählten Servers und startet neu
- **✏️ Umbenennen** — gibt dem Server einen sprechenden Namen
- **✕ Löschen** — entfernt ihn aus der Liste (Daten bleiben erhalten)

Beim Speichern neuer Zugangsdaten wird der Server automatisch gespeichert.

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

### Remote-Cache

Beim ersten Download-Run wird automatisch `data/rclone_cache.json` mit allen Dateinamen auf dem Remote angelegt. Damit werden bereits vorhandene Dateien vor dem Download-Start erkannt und übersprungen. Der Cache kann unter **Einstellungen → rclone → 🗂 Remote-Cache aktualisieren** manuell neu aufgebaut werden.

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
```

**Länderkürzel** werden automatisch aus dem Serientitel oder der Kategorie extrahiert. Titel ohne erkanntes Kürzel landen direkt in der Kategorie.

**Dateinamen:**
- Filme: `Titel.Jahr.ext`
- Episoden: `Serienname.SxxExx.ext` — Serienname kommt aus dem Xtream-Serientitel

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

Admins können in der **Benutzerverwaltung** für jeden User ein individuelles Limit setzen. Leer = Rollen-Standard, `0` = kein Zugriff, `5` = 5/h.

---

## Download-Queue

### Prioritäten

🔴 Hoch (1) / 🟡 Normal (2) / 🔵 Niedrig (3) — Admins können die Priorität direkt in der Queue-Ansicht ändern.

### Manuell starten

Downloads können über **▶ Starten** im Dashboard oder in der Queue-Ansicht manuell angestoßen werden. Der Worker verhindert parallele Instanzen über eine `data/cron.lock`-Datei.

### Speicherplatz-Prüfung (lokaler Modus)

Vor jedem Download wird die Dateigröße per HEAD-Request ermittelt und mit dem freien Speicherplatz verglichen (Dateigröße + 512 MB Puffer).

### Download abbrechen

Laufende Downloads können über **✕ Abbrechen** in der Progress-Card gestoppt werden (nur Admins).

### Fehlerbehandlung

Fehlgeschlagene Downloads werden als `error` markiert. Admins können sie über **↻ Retry** manuell neu einreihen.

### Download zurücksetzen

Bereits heruntergeladene VODs können über **↺ Reset** zurückgesetzt und neu zur Queue hinzugefügt werden.

### Doppelten-Prüfung beim Queue-Add

Beim Hinzufügen zur Queue wird in dieser Reihenfolge geprüft:
1. Bereits in der Queue vorhanden
2. Bereits in `downloaded.json` (heruntergeladen)
3. Dateiname bereits im rclone-Remote-Cache (nur rclone-Modus)

---

## Mediathek & Suche

### Medien-Cache

Der Cache (`library_cache_*.json` für Filme, `series_cache_*.json` für Serien) wird täglich um 4 Uhr automatisch aufgebaut. Er ist pro Server getrennt.

### Suche

Primär gegen den lokalen Cache — bei veraltetem oder leerem Cache Fallback auf den Xtream-Server. Ergebnisse werden in der Sitzung gecacht.

- **Debouncing:** 350ms nach dem letzten Tastendruck
- **Suchverlauf:** Die letzten 10 Suchanfragen und Kategorien pro User im Browser
- **Quellenhinweis:** Bei Cache-Ergebnissen erscheint ein „Aktualisieren"-Link

### Sortierung & Paginierung

Filme und Serien können nach Standard, A→Z oder Z→A sortiert werden. Ab 50 Items erscheint eine Seitennavigation.

---

## Neue Releases

Die View **🆕 Neu** zeigt alle Titel die seit dem letzten Cache-Run neu hinzugekommen sind. `cache_builder.php` vergleicht beim Run die aktuellen IDs mit den beim letzten Run bekannten und speichert neue Einträge in `data/new_releases.json`.

Beim ersten Run werden alle IDs als bekannt markiert — kein False-Positive mit tausenden „neuen" Titeln.

---

## Favoriten

Jeder Benutzer kann Filme und Serien mit ♥ als Favoriten markieren. Erreichbar unter **Favoriten** in der Navigation, filterbar nach Typ und durchsuchbar. Favoriten werden pro User in `data/users.json` gespeichert.

---

## TMDB-Integration

Beim Klick auf eine Film- oder Serienkarte öffnet sich ein Modal mit Daten von The Movie Database:

- Backdrop-Bild, Poster, Titel
- Beschreibung (auf Deutsch)
- Sternbewertung + Anzahl Bewertungen
- Genres, Erscheinungsjahr, Laufzeit
- **Stream-Info** via `ffprobe` (nur Filme): Auflösung, Codec, Bitrate, HDR, Audio, Dauer

### Setup

1. API-Key unter [themoviedb.org/settings/api](https://www.themoviedb.org/settings/api) erstellen (v3 auth)
2. **Einstellungen → 🎬 TMDB Integration** → Key eintragen → Speichern

### ffprobe installieren

```bash
sudo apt install ffmpeg
```

---

## Statistiken

Unter **📊 Statistiken** (nur Admins):

- **Gesamtdownloads** und **Gesamtvolumen** als KPI-Cards
- **Datenvolumen pro Monat** — Balkendiagramm mit Anzahl-Linie (Chart.js)
- **Top-User-Rangliste** — Downloads und Volumen pro User mit Fortschrittsbalken

Die Dateigröße wird seit diesem Update in `download_history.json` gespeichert. Ältere Einträge ohne Dateigröße werden bei der Anzahl berücksichtigt, beim Volumen als 0 gewertet.

---

## Telegram-Benachrichtigungen

Nach jedem abgeschlossenen Download wird eine Telegram-Nachricht gesendet.

### Setup

1. Bot erstellen: [@BotFather](https://t.me/BotFather) → `/newbot`
2. Chat-ID ermitteln: [@userinfobot](https://t.me/userinfobot) oder `https://api.telegram.org/bot<TOKEN>/getUpdates`
3. **Einstellungen → 📨 Telegram** → Token + Chat-ID eintragen → Speichern
4. **📨 Testnachricht senden** zur Überprüfung

### Nachrichtenformat

```
✅ Download abgeschlossen
🎬 Film: Inception.2010
📦 1.842 MB
```

---

## Benutzerverwaltung

Unter **👥 Benutzer** (nur Admins):

- **Benutzer anlegen** und bearbeiten (Rolle, Passwort)
- **🔑 Passwort zurücksetzen** durch Admin
- **Sperren / Entsperren** von Accounts
- **Queue-Limit** individuell pro User setzen
- **Download-Verlauf** pro User einsehen (Klick auf Username)
- **Aktivitätslog** für alle Aktionen

### Einladungslinks

Admins können unter **🔗 Einladung erstellen** einmalige Registrierungslinks generieren:

- Rolle und Gültigkeitsdauer (6h bis 7 Tage) wählbar
- Optionale Notiz
- Link unter `https://deine-domain.de/invite.php?token=...`
- Wird nach einmaliger Nutzung als verwendet markiert

---

## Datensicherung

Backup-Verwaltung unter **Einstellungen → 💾 Datensicherung**.

Gesichert werden alle Dateien in `data/` als ZIP-Archiv. Backups landen in `data/backups/` und werden nach 7 Kopien automatisch rotiert.

**Manuell ausführen:**
```bash
php /var/www/html/xtream/backup.php
```

**Wiederherstellen:** Einstellungen → Datensicherung → **↩ Restore**

---

## Health-Check

```
GET /api.php?action=health
```

Kein Login erforderlich. HTTP 200 wenn OK, 503 bei Wartung oder nicht konfiguriert.

```json
{
  "status": "ok",
  "configured": true,
  "maintenance": false,
  "queue": { "pending": 2, "downloading": 1, "errors": 0 },
  "cron": { "running": true, "pid": 12345 },
  "disk": { "free_bytes": 107374182400, "free_pct": 20.0 },
  "last_backup": "2026-03-23 03:00:00"
}
```

---

## Externe API

Externe Systeme können über API-Keys auf die Benutzerverwaltung zugreifen. Vollständige Dokumentation unter **📖 API-Dokumentation** (nur Admins).

**Authentifizierung:**
```
X-API-Key: xv_xxxxxxxxxxxx
```

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
- **Interne PHP-Dateien** (`config.php`, `auth.php`, `cron.php`, `cache_builder.php`, `backup.php`) sind per `.htaccess` gesperrt
- **Stream-URLs** werden nur serverseitig aufgebaut und nie an den Client übertragen
- **Gesperrte Benutzer** können sich nicht einloggen; der Wartungsmodus erlaubt nur Admin-Logins
- **Aktivitätslog** protokolliert alle relevanten Aktionen
- **API-Keys** können jederzeit widerrufen werden; vollständiger Key nur nach Passwort-Bestätigung einsehbar
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
├── invite.php             — Öffentliche Einladungs-Registrierungsseite
├── cache_builder.php      — Hintergrundprozess für Medien-Cache
├── cron.php               — Download-Worker (Cronjob)
├── backup.php             — Backup-Script (täglich 3 Uhr, max 7 Backups)
├── maintenance.php        — Wartungsseite
├── install.sh             — Automatisches Installationsskript
├── style.css              — Stylesheet
├── .htaccess              — Sicherheitsregeln
└── data/                  — Datendateien (automatisch erstellt)
    ├── config.json                    — Server- & App-Konfiguration
    ├── users.json                     — Benutzerdatenbank inkl. Favoriten
    ├── servers.json                   — Gespeicherte Xtream-Server
    ├── invites.json                   — Einladungslinks
    ├── queue_<server-id>.json         — Download-Queue (pro Server)
    ├── downloaded_<server-id>.json    — IDs heruntergeladener VODs (pro Server)
    ├── downloaded_index_<server-id>.json — Metadaten heruntergeladener VODs
    ├── download_history_<server-id>.json — Permanenter Download-Verlauf
    ├── library_cache_<server-id>.json — Film-Metadaten-Cache (pro Server)
    ├── series_cache_<server-id>.json  — Serien-Metadaten-Cache (pro Server)
    ├── new_releases.json              — Neue Titel seit letztem Cache-Run
    ├── rclone_cache.json              — Bekannte Dateien auf dem Remote
    ├── activity.json                  — Aktivitätslog
    ├── rate_limits.json               — Rate-Limit-Tracking
    ├── api_keys.json                  — API-Keys
    ├── progress.json                  — Aktueller Download-Fortschritt
    ├── cron.lock                      — Download-Worker Lock-Datei
    ├── cron.log                       — Download-Log
    ├── backup.log                     — Backup-Log
    └── backups/                       — Backup-Archiv
        └── backup_YYYY-MM-DD_HH-II-SS.zip
```
