# Xtream Vault

Ein Web-Frontend zum Browsen und Herunterladen von Filmen und Serien von Xtream-Codes-Servern. Downloads landen wahlweise lokal auf dem Server oder direkt in der Cloud via rclone.

---

## Features

**Mediathek & Suche**
- Filme und Serien nach Kategorie browsen — Grid- und Listenansicht
- Suche über alle konfigurierten Server gleichzeitig
- Suchverlauf als klickbare Vorschläge
- TMDB-Integration — Cover, Beschreibung, Bewertung, Erscheinungsjahr
- Sortierung nach Name (A–Z), Bewertung oder neuesten Einträgen
- Neue Releases — Überblick über Filme die seit dem letzten Cache-Run hinzugekommen sind
- Favoriten — Titel merken und separat verwalten
- „Zuletzt gesehen" — Badge auf bereits betrachteten Filmkarten

**Downloads**
- Download-Queue mit Prioritätsstufen (Hoch / Normal / Niedrig)
- Serien-Support — einzelne Episoden oder ganze Staffeln in die Queue legen
- Parallele Downloads — mehrere Server laden gleichzeitig, pro Server sequenziell
- Fortschrittsanzeige direkt im Queue-Item (Bytes, Geschwindigkeit, ETA)
- Dubletten-Erkennung — verhindert doppelte Downloads anhand von Titel-Ähnlichkeit
- Automatisches Naming — `Movies/[CC]/Kategorie/Titel.Jahr.mkv` und `TV Shows/[CC]/Serie/Staffel N/`
- Cloud-Speicher — Downloads direkt nach Google Drive, OneDrive, MEGA u.v.m. streamen (via rclone)
- VPN-Unterstützung — Downloads optional über WireGuard leiten

**Multi-Server**
- Beliebig viele Xtream-Server gleichzeitig aktiv
- Suche, Kategorien und Queue laufen über alle Server
- Cache, Queue und Download-Verlauf sind pro Server getrennt gespeichert
- Parallele oder sequenzielle Download-Strategie konfigurierbar
- Server können einzeln oder alle gleichzeitig auf Erreichbarkeit getestet werden
- Dashboard-Kacheln mit Klick-Info zu jedem Server (Cache-Alter, Queue-Status, Zugangsdaten)

**Benutzerverwaltung**
- Mehrere Benutzer mit Rollen (Admin, Editor, Viewer)
- Einladungslinks mit konfigurierbarer Gültigkeit und Rolle
- Aktivitätslog — wer hat wann was zur Queue hinzugefügt
- Rate-Limiting pro Benutzer und Stunde
- Wartungsmodus — sperrt alle Nicht-Admins aus
- Externe Benutzerverwaltung via REST-API mit IP-Whitelist

**Benachrichtigungen**
- Telegram-Bot-Integration
- Konfigurierbar pro Ereignis: Download abgeschlossen, Fehler, Queue-Run fertig, Speicherplatz niedrig

**UI & Sonstiges**
- 7 Themes — Dark, AMOLED, Midnight, Nord, Tokyo Night, Rosé Pine, Light
- 5 Sprachen — Deutsch, Englisch, Französisch, Spanisch, Italienisch (pro Benutzer wählbar)
- Theme wird auch auf der Login-Seite angezeigt
- Listenansicht und Grid-Ansicht
- In-App-Updates mit automatischem Backup
- API-Keys für externe Benutzerverwaltung

---

## Rollen

| Rolle | Browsen | Queue hinzufügen | Queue verwalten | Einstellungen |
|---|:---:|:---:|:---:|:---:|
| **Admin** | ✓ | ✓ | ✓ | ✓ |
| **Editor** | ✓ | ✓ | — | — |
| **Viewer** | ✓ | — | — | — |

---

## Installation

```bash
wget https://github.com/extend110/xtream-vault/archive/refs/heads/main.zip
unzip main.zip
cd xtream-vault-main
sudo bash install.sh
```

Das Installationsskript richtet Apache-Konfiguration, PHP-Einstellungen, Cronjobs, Verzeichnisrechte und optionales rclone ein.

---

## Erste Schritte

1. **Admin-Konto anlegen** — beim ersten Aufruf erscheint automatisch der Setup-Assistent
2. **Server hinzufügen** — unter Einstellungen → Server den ersten Xtream-Server eintragen und testen
3. **Zielordner konfigurieren** — lokaler Pfad oder rclone-Remote eintragen
4. **Cache aufbauen** — einmalig den Medien-Cache aufbauen damit Suche und neue Releases funktionieren
5. **Loslegen** — Filme oder Serien suchen, in die Queue legen und den Download starten

---

## Cronjobs

Das Installationsskript richtet folgende Cronjobs ein:

```bash
# Download-Worker — alle 30 Minuten
*/30 * * * * /usr/bin/php /var/www/html/xtream/cron.php >> /dev/null 2>&1

# Medien-Cache — täglich um 04:00 Uhr
0 4 * * * /usr/bin/php /var/www/html/xtream/cache_builder.php >> /dev/null 2>&1

# Datensicherung — täglich um 03:00 Uhr
0 3 * * * /usr/bin/php /var/www/html/xtream/backup.php >> /dev/null 2>&1
```

---

## Parallele Downloads

Wenn mehrere Server konfiguriert sind, kann `cron.php` pro Server einen eigenen Worker-Prozess starten — alle laufen parallel, aber jeder Server arbeitet seine Items sequenziell ab.

Einstellbar unter Einstellungen → Parallele Downloads:
- **Aktivieren/Deaktivieren** — bei Deaktivierung laufen alle Server nacheinander
- **Maximale parallele Downloads** — wie viele Server gleichzeitig starten dürfen (1–10)

---

## Cloud-Speicher (rclone)

Wenn rclone installiert und konfiguriert ist, können Downloads direkt in die Cloud gestreamt werden — ohne temporäre lokale Kopie.

```bash
sudo -u www-data rclone config
```

Unter Einstellungen → rclone: Remote-Name, Zielpfad und optional den Pfad zum rclone-Binary eintragen, dann Verbindung testen.

Unterstützte Dienste: Google Drive, OneDrive, Dropbox, MEGA, S3 und alle weiteren rclone-Remotes.

---

## Externe API

Über API-Keys können externe Systeme Benutzer anlegen, auflisten, sperren und löschen.

**Authentifizierung:**
```
X-API-Key: xv_...
```

**Verfügbare Endpoints:**

| Endpoint | Methode | Beschreibung |
|---|---|---|
| `external_create_user` | POST | Neuen Benutzer anlegen |
| `external_list_users` | GET | Alle Benutzer abrufen |
| `external_suspend_user` | POST | Benutzer sperren / entsperren |
| `external_update_user` | POST | Passwort oder Rolle ändern |
| `external_delete_user` | POST | Benutzer löschen |

API-Keys werden unter Einstellungen → API-Keys erstellt. Die IP-Whitelist (Einstellungen → API IP-Whitelist) schränkt den Zugriff auf bestimmte IPs oder CIDR-Blöcke ein.

---

## Dateistruktur

```
data/
├── config.json                  # Globale Einstellungen
├── servers.json                 # Xtream-Server-Liste
├── users.json                   # Benutzer
├── queue_{id}.json              # Download-Queue pro Server
├── downloaded_{id}.json         # Heruntergeladene IDs pro Server
├── downloaded_index_{id}.json   # Metadaten-Index pro Server
├── download_history_{id}.json   # Download-Verlauf pro Server
├── library_cache_{id}.json      # Film-Cache pro Server
├── series_cache_{id}.json       # Serien-Cache pro Server
├── new_releases.json            # Neue Releases (alle Server)
├── progress_{id}.json           # Live-Fortschritt pro Worker
├── cron.log                     # Download-Log
└── backups/                     # Automatische Backups
```

---

## Sicherheit

- Passwortgeschützte Benutzer mit bcrypt-Hashing
- CSRF-Schutz für alle schreibenden Aktionen
- Session-basierte Authentifizierung mit SameSite-Cookies
- Zugriff auf `data/` ist per Apache-Konfiguration gesperrt
- Wartungsmodus sperrt alle Nicht-Admins sofort aus
- IP-Whitelist für externe API-Zugriffe (einzelne IPs und CIDR-Blöcke)
- Rate-Limiting pro Benutzer und Stunde

---

## Updates

Unter Einstellungen → Updates kann auf neue Versionen geprüft und direkt aus der Oberfläche aktualisiert werden. Vor jedem Update wird automatisch ein Backup der Daten erstellt. Ein Badge in der Topbar weist auf verfügbare Updates hin.

---

## Datensicherung

Unter Einstellungen → Datensicherung können Backups erstellt und wiederhergestellt werden. Enthalten sind alle JSON-Dateien aus `data/` — Queue, Download-Verlauf, Benutzer, Einstellungen, Server-Konfiguration. Mediendateien sind nicht enthalten.

---

## Anforderungen

- PHP 8.1 oder neuer mit `php-curl`, `php-json`, `php-zip`, `php-mbstring`
- Apache (empfohlen) oder Nginx
- Optional: `rclone` für Cloud-Speicher, `wireguard` für VPN, `ffmpeg` für Stream-Info

---

## Lizenz

MIT License
