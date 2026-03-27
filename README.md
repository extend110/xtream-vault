# Xtream Vault

Ein Web-Frontend zum Browsen und Herunterladen von Filmen und Serien von Xtream-Codes-Servern. Downloads landen wahlweise lokal auf dem Server oder direkt in der Cloud via rclone.

---

## Features

**Mediathek & Suche**
- Filme und Serien nach Kategorie browsen
- Suche über alle konfigurierten Server gleichzeitig
- Suchverlauf als klickbare Vorschläge
- TMDB-Integration — Cover, Beschreibung, Bewertung, Erscheinungsjahr
- Neue Releases — Überblick über Filme die seit dem letzten Cache-Run hinzugekommen sind
- Favoriten — Titel merken und separat verwalten

**Downloads**
- Download-Queue — Titel einreihen und automatisch herunterladen lassen
- Serien-Support — einzelne Episoden oder ganze Staffeln in die Queue legen
- Dubletten-Erkennung — verhindert doppelte Downloads anhand von Titel-Ähnlichkeit
- Automatisches Naming — `Movies/[CC]/Kategorie/Titel.Jahr.mkv` und `TV Shows/[CC]/Serie/Staffel N/`
- Cloud-Speicher — Downloads direkt nach Google Drive, OneDrive, MEGA u.v.m. streamen (via rclone)
- VPN-Unterstützung — Downloads optional über WireGuard leiten

**Multi-Server**
- Beliebig viele Xtream-Server gleichzeitig aktiv
- Suche, Kategorien und Queue laufen über alle Server
- Cache und Queue sind pro Server getrennt gespeichert
- Server können einzeln oder alle gleichzeitig auf Erreichbarkeit getestet werden

**Benutzerverwaltung**
- Mehrere Benutzer mit Rollen (Admin, Editor, Viewer)
- Einladungslinks mit konfigurierbarer Gültigkeit und Rolle
- Aktivitätslog — wer hat wann was zur Queue hinzugefügt
- Rate-Limiting pro Benutzer und Stunde
- Wartungsmodus — sperrt alle Nicht-Admins aus

**Benachrichtigungen**
- Telegram-Bot-Integration
- Konfigurierbar pro Ereignis: Download abgeschlossen, Fehler, Queue-Run fertig, Speicherplatz niedrig

**UI & Sonstiges**
- 7 Themes — Dark, AMOLED, Midnight, Nord, Tokyo Night, Rosé Pine, Light
- Deutsch und Englisch (pro Benutzer wählbar)
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

Das Installationsskript richtet Webserver-Konfiguration, Cronjobs und Verzeichnisrechte automatisch ein.

---

## Erste Schritte

1. **Admin-Konto anlegen** — beim ersten Aufruf erscheint automatisch der Setup-Assistent
2. **Server hinzufügen** — unter Einstellungen → Server den ersten Xtream-Server eintragen und testen
3. **Einstellungen speichern** — Zielordner und weitere Optionen konfigurieren
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

## Cloud-Speicher (rclone)

Wenn rclone auf dem Server installiert und konfiguriert ist, können Downloads direkt in die Cloud gestreamt werden — ohne temporäre lokale Kopie.

Einstellungen → rclone: Remote-Name und Zielpfad eintragen, Verbindung testen.

Unterstützte Dienste: Google Drive, OneDrive, Dropbox, MEGA, S3 und alle weiteren rclone-Remotes.

---

## Dateistruktur

```
data/
├── config.json              # Globale Einstellungen
├── servers.json             # Xtream-Server-Liste
├── users.json               # Benutzer
├── queue_{id}.json          # Download-Queue pro Server
├── downloaded_{id}.json     # Heruntergeladene IDs pro Server
├── library_cache_{id}.json  # Film-Cache pro Server
├── series_cache_{id}.json   # Serien-Cache pro Server
├── new_releases.json        # Neue Releases (alle Server)
├── cron.log                 # Download-Log
└── backups/                 # Automatische Backups
```

---

## Sicherheit

- Passwortgeschützte Benutzer mit bcrypt-Hashing
- CSRF-Schutz für alle schreibenden Aktionen
- Session-basierte Authentifizierung mit SameSite-Cookies
- Zugriff auf `data/` ist per `.htaccess` gesperrt
- Wartungsmodus sperrt alle Nicht-Admins sofort aus

---

## Updates

Unter Einstellungen → Updates kann auf neue Versionen geprüft und direkt aus der Oberfläche aktualisiert werden. Vor jedem Update wird automatisch ein Backup der Daten erstellt (ohne vorherige Backups).

---

## Datensicherung

Unter Einstellungen → Datensicherung können Backups erstellt und wiederhergestellt werden. Enthalten sind alle JSON-Dateien — Queue, Download-Verlauf, Benutzer, Einstellungen, Server-Konfiguration. Mediendateien sind nicht enthalten.

---

## Anforderungen

- PHP 8.1 oder neuer
- Webserver (Apache oder Nginx)
- `php-zip`, `php-curl`, `php-json`
- Optional: `rclone` für Cloud-Speicher, `zip` für Backups

---

## Lizenz

MIT License
