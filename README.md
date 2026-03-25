# Xtream Vault

Xtream Vault ist ein Web-Frontend zum Browsen und Herunterladen von Filmen und Serien von einem Xtream-Codes-Server. Downloads landen wahlweise lokal auf dem Server oder direkt in der Cloud (Google Drive, OneDrive, MEGA und mehr via rclone).

---

## Features

- **Mediathek** — Filme und Serien nach Kategorie browsen, mit Suche und Suchverlauf
- **Download-Queue** — Titel in die Queue legen und automatisch herunterladen lassen
- **Cloud-Speicher** — Downloads direkt in Google Drive, OneDrive, MEGA u.v.m. streamen
- **TMDB-Integration** — Cover, Beschreibung, Bewertung und Erscheinungsjahr zu jedem Titel
- **Neue Releases** — Überblick über neu hinzugekommene Filme seit dem letzten Besuch
- **Favoriten** — Titel merken und in einer eigenen Liste verwalten
- **Dubletten-Erkennung** — verhindert doppelte Downloads automatisch
- **Statistiken** — Datenvolumen, Downloads pro Monat und Top-Kategorien
- **Mehrere Server** — zwischen verschiedenen Xtream-Servern wechseln
- **Benutzerverwaltung** — mehrere Benutzer mit unterschiedlichen Rollen
- **Einladungslinks** — neue Benutzer per Link einladen
- **Telegram** — Benachrichtigungen bei abgeschlossenen Downloads
- **VPN** — Downloads optional über WireGuard leiten
- **7 Themes** — Dark, AMOLED, Midnight, Nord, Tokyo Night, Rosé Pine, Light
- **Mehrsprachig** — Deutsch und Englisch

---

## Installation

```bash
wget https://github.com/extend110/xtream-vault/archive/refs/heads/main.zip
unzip main.zip
cd xtream-vault-main
sudo bash install.sh
```

Das Installationsskript richtet alles automatisch ein — Webserver, Cronjobs und Verzeichnisrechte.

Danach die Seite im Browser öffnen und den Setup-Assistenten durchlaufen.

---

## Erste Schritte

1. **Server einrichten** — IP, Port, Benutzername und Passwort des Xtream-Servers eingeben und Verbindung testen
2. **Admin-Konto anlegen** — beim ersten Start wird automatisch ein Setup-Assistent gezeigt
3. **Cache aufbauen** — unter Einstellungen → Medien-Cache einmalig den Cache aufbauen, damit Suche und neue Releases funktionieren
4. **Loslegen** — Filme oder Serien suchen, in die Queue legen und den Download starten

---

## Rollen

| Rolle | Was sie darf |
|---|---|
| **Admin** | Alles — Einstellungen, Benutzer, Queue, Downloads |
| **Editor** | Filme und Serien browsen, zur Queue hinzufügen |
| **Viewer** | Nur browsen, kein Hinzufügen zur Queue |

---

## Cloud-Speicher (rclone)

Wenn rclone auf dem Server installiert ist, können Downloads direkt in die Cloud gestreamt werden — ohne temporäre lokale Kopie. Einfach in den Einstellungen den Remote-Namen und Zielpfad eintragen.

---

## Updates

Updates werden direkt aus der Oberfläche installiert — unter Einstellungen → Updates auf neue Version prüfen und mit einem Klick installieren. Vor dem Update wird automatisch ein Backup erstellt.

---

## Datensicherung

Unter Einstellungen → Datensicherung können Backups der Datenbank erstellt und wiederhergestellt werden (Queue, Download-Verlauf, Favoriten, Benutzer). Mediendateien sind nicht enthalten.

---

## Sicherheit

- Alle Benutzer sind passwortgeschützt
- Zugriff auf sensible Dateien ist gesperrt
- CSRF-Schutz für alle Aktionen
- Wartungsmodus sperrt alle Nicht-Admins aus

---

## Lizenz

MIT License — frei verwendbar und anpassbar.
