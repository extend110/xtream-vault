#!/bin/bash
set -e

# ═══════════════════════════════════════════════════════════════
#  Xtream Vault — Installationsskript
# ═══════════════════════════════════════════════════════════════

GREEN="\e[32m"
YELLOW="\e[33m"
RED="\e[31m"
CYAN="\e[36m"
BOLD="\e[1m"
RESET="\e[0m"

log()     { echo -e "${GREEN}[INFO]${RESET}  $1"; }
warn()    { echo -e "${YELLOW}[WARN]${RESET}  $1"; }
error()   { echo -e "${RED}[ERROR]${RESET} $1"; }
section() { echo -e "\n${CYAN}${BOLD}▶ $1${RESET}"; }

# ── Root-Check ────────────────────────────────────────────────
if [ "$EUID" -ne 0 ]; then
    error "Bitte als root ausführen: sudo bash install.sh"
    exit 1
fi

echo ""
echo -e "${BOLD}╔══════════════════════════════════════╗${RESET}"
echo -e "${BOLD}║        Xtream Vault Installer        ║${RESET}"
echo -e "${BOLD}╚══════════════════════════════════════╝${RESET}"
echo ""

# ── Konfiguration ─────────────────────────────────────────────
PROJECT_PATH="/var/www/html/xtream"
APACHE_CONF_PATH="/etc/apache2/sites-available/xtream.conf"
PHP_BIN="/usr/bin/php"

# ── Domain / IP abfragen ──────────────────────────────────────
read -rp "🌐 Domain eingeben (leer lassen für Server-IP): " DOMAIN
if [ -z "$DOMAIN" ]; then
    warn "Keine Domain angegeben → verwende Server-IP"
    DOMAIN="_"
fi

# ── rclone installieren? ──────────────────────────────────────
read -rp "☁️  rclone für Cloud-Speicher installieren? [j/N]: " INSTALL_RCLONE
INSTALL_RCLONE="${INSTALL_RCLONE,,}"

echo ""

# ── Pakete installieren ───────────────────────────────────────
section "Pakete installieren"
apt-get update -qq
apt-get install -y -qq \
    apache2 \
    php \
    php-curl \
    php-json \
    php-mbstring \
    php-xml \
    php-zip \
    php-cli \
    php-common \
    libapache2-mod-php \
    curl \
    cron \
    ffmpeg
log "Pakete installiert"

# ── PHP-Version prüfen ────────────────────────────────────────
section "PHP prüfen"
PHP_VERSION=$($PHP_BIN -r "echo PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;")
PHP_MAJOR=$($PHP_BIN -r "echo PHP_MAJOR_VERSION;")

if [ "$PHP_MAJOR" -lt 8 ]; then
    error "PHP 8.0+ erforderlich. Installierte Version: $PHP_VERSION"
    exit 1
fi
log "PHP $PHP_VERSION ✓"

# ── php.ini: allow_url_fopen aktivieren ───────────────────────
section "PHP konfigurieren"
PHP_INI=$($PHP_BIN -i 2>/dev/null | grep "Loaded Configuration File" | awk '{print $5}')
if [ -f "$PHP_INI" ]; then
    if grep -q "allow_url_fopen = Off" "$PHP_INI"; then
        sed -i 's/allow_url_fopen = Off/allow_url_fopen = On/' "$PHP_INI"
        log "allow_url_fopen aktiviert"
    else
        log "allow_url_fopen bereits aktiv"
    fi
else
    warn "php.ini nicht gefunden — bitte manuell prüfen: allow_url_fopen = On"
fi

# ── ffmpeg installieren ───────────────────────────────────────
section "ffmpeg installieren"
if command -v ffprobe &>/dev/null; then
    log "ffprobe bereits installiert: $(ffprobe -version 2>&1 | head -1)"
else
    apt-get install -y -qq ffmpeg
    log "ffmpeg/ffprobe installiert"
fi

# ── Projektdateien kopieren ───────────────────────────────────
section "Dateien installieren"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [ "$SCRIPT_DIR" != "$PROJECT_PATH" ]; then
    mkdir -p "$PROJECT_PATH"
    cp -r "$SCRIPT_DIR"/. "$PROJECT_PATH"/
    log "Dateien nach $PROJECT_PATH kopiert"
else
    log "Skript läuft bereits aus $PROJECT_PATH"
fi

# ── data/-Verzeichnis anlegen ─────────────────────────────────
mkdir -p "$PROJECT_PATH/data/backups"
log "data/-Verzeichnis erstellt"

# ── Berechtigungen setzen ─────────────────────────────────────
section "Berechtigungen setzen"
chown -R www-data:www-data "$PROJECT_PATH"
chmod -R 755 "$PROJECT_PATH"
chmod -R 775 "$PROJECT_PATH/data"
chmod +x "$PROJECT_PATH/install.sh" 2>/dev/null || true
log "Berechtigungen gesetzt"

# ── Apache konfigurieren ──────────────────────────────────────
section "Apache konfigurieren"
cat > "$APACHE_CONF_PATH" <<EOL
<VirtualHost *:80>
    ServerName $DOMAIN
    DocumentRoot $PROJECT_PATH

    <Directory $PROJECT_PATH>
        AllowOverride All
        Require all granted
        Options -Indexes
    </Directory>

    <Directory $PROJECT_PATH/data>
        Require all denied
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/xtream_error.log
    CustomLog \${APACHE_LOG_DIR}/xtream_access.log combined
</VirtualHost>
EOL

a2dissite 000-default.conf 2>/dev/null || true
a2ensite xtream.conf
a2enmod rewrite headers
apache2ctl configtest
systemctl reload apache2
log "Apache konfiguriert und neu geladen"

# ── Cronjobs einrichten ───────────────────────────────────────
section "Cronjobs einrichten"
CRON_TMP=$(mktemp)
crontab -u www-data -l 2>/dev/null | grep -v "xtream\|cache_builder\|backup\.php" > "$CRON_TMP" || true

cat >> "$CRON_TMP" <<EOL
*/30 * * * * $PHP_BIN $PROJECT_PATH/cron.php >> /dev/null 2>&1
0 4 * * * $PHP_BIN $PROJECT_PATH/cache_builder.php >> /dev/null 2>&1
0 3 * * * $PHP_BIN $PROJECT_PATH/backup.php >> /dev/null 2>&1
EOL

crontab -u www-data "$CRON_TMP"
rm -f "$CRON_TMP"
log "Cronjobs eingerichtet (alle 30 Min Downloads, 4 Uhr Cache, 3 Uhr Backup)"

# ── git einrichten ────────────────────────────────────────────
section "git einrichten"
if ! command -v git &>/dev/null; then
    apt-get install -y -qq git
    log "git installiert"
else
    log "git bereits installiert"
fi

# Falls Projektverzeichnis kein git-Repo ist, remote setzen
if [ ! -d "$PROJECT_PATH/.git" ]; then
    cd "$PROJECT_PATH"
    git init -q
    git remote add origin https://github.com/extend110/xtream-vault.git 2>/dev/null || true
    git fetch origin main -q 2>/dev/null || true
    git checkout -q main 2>/dev/null || true
    log "git-Repository initialisiert"
else
    log "git-Repository bereits vorhanden"
fi

# version.json mit aktuellem Commit befüllen
CURRENT_COMMIT=$(git -C "$PROJECT_PATH" rev-parse HEAD 2>/dev/null || echo "unknown")
cat > "$PROJECT_PATH/version.json" <<EOL
{
  "commit": "$CURRENT_COMMIT",
  "updated_at": "$(date '+%Y-%m-%d %H:%M:%S')"
}
EOL
log "version.json aktualisiert (Commit: ${CURRENT_COMMIT:0:7})"
section "WireGuard installieren"
if command -v wg &>/dev/null; then
    log "WireGuard bereits installiert: $(wg --version 2>&1 | head -1)"
else
    apt-get install -y -qq wireguard
    log "WireGuard installiert"
fi

# sudo-Rechte für www-data: wg-quick, ip rule, ip route
SUDOERS_FILE="/etc/sudoers.d/xtream-vpn"
if [ ! -f "$SUDOERS_FILE" ]; then
    cat > "$SUDOERS_FILE" <<'EOL'
# Xtream Vault: www-data darf VPN-Befehle ohne Passwort ausführen
www-data ALL=(root) NOPASSWD: /usr/bin/wg-quick up *, /usr/bin/wg-quick down *
www-data ALL=(root) NOPASSWD: /usr/bin/wg showconf *
www-data ALL=(root) NOPASSWD: /usr/sbin/ip rule show, /usr/sbin/ip rule add *, /usr/sbin/ip rule del *
www-data ALL=(root) NOPASSWD: /usr/sbin/ip -6 rule show, /usr/sbin/ip -6 rule add *, /usr/sbin/ip -6 rule del *
www-data ALL=(root) NOPASSWD: /usr/sbin/ip route add *, /usr/sbin/ip route del *
EOL
    chmod 0440 "$SUDOERS_FILE"
    log "sudoers-Eintrag für VPN erstellt"
else
    log "sudoers-Eintrag bereits vorhanden"
fi
if [ "$INSTALL_RCLONE" = "j" ] || [ "$INSTALL_RCLONE" = "y" ]; then
    section "rclone installieren"
    if command -v rclone &>/dev/null; then
        log "rclone bereits installiert: $(rclone version | head -1)"
    else
        curl -fsSL https://rclone.org/install.sh | bash
        log "rclone installiert: $(rclone version | head -1)"
    fi
    echo ""
    warn "rclone Remote noch nicht konfiguriert."
    warn "Nach der Installation ausführen:"
    warn "  sudo -u www-data rclone config"
fi

# ── Server-IP ermitteln ───────────────────────────────────────
SERVER_IP=$(hostname -I | awk '{print $1}')

# ── Fertig ────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}${BOLD}✓ Installation abgeschlossen!${RESET}"
echo ""
echo -e "${CYAN}${BOLD}🌐 Zugriff:${RESET}"
if [ "$DOMAIN" = "_" ]; then
    echo -e "   → ${BOLD}http://$SERVER_IP${RESET}"
else
    echo -e "   → ${BOLD}http://$DOMAIN${RESET}"
fi
echo ""
echo -e "${CYAN}${BOLD}📋 Nächste Schritte:${RESET}"
echo "   1. Browser öffnen und Setup-Wizard ausfüllen"
echo "   2. Admin-Account anlegen"
echo "   3. Einstellungen → Xtream-Server konfigurieren"
echo "   4. Verbindung testen und Speichern"
echo "   5. Einstellungen → Cache aufbauen"
echo ""
echo -e "${CYAN}${BOLD}🔒 VPN (WireGuard) einrichten:${RESET}"
echo "   1. Konfigurationsdatei ablegen:"
echo "      sudo nano /etc/wireguard/wg0.conf"
echo "      sudo chmod 600 /etc/wireguard/wg0.conf"
echo "   2. In Xtream Vault: Einstellungen → VPN → Interface wg0 → aktivieren"
echo ""
echo -e "${YELLOW}⚠️  Wichtig:${RESET} SSH/Webseite bleiben erreichbar durch Policy-Based Routing."
echo "   Nur www-data (cron.php) nutzt den VPN-Tunnel."
echo "   Notfall-Reset falls Verbindung verloren geht:"
echo "      sudo wg-quick down wg0"
IP_CMD=/usr/sbin/ip
echo "      sudo \$IP_CMD rule del uidrange \$(id -u www-data)-\$(id -u www-data) lookup 51820 priority 100 2>/dev/null"
if [ "$INSTALL_RCLONE" = "j" ] || [ "$INSTALL_RCLONE" = "y" ]; then
    echo ""
    echo -e "${CYAN}${BOLD}☁️  rclone einrichten:${RESET}"
    echo "   sudo -u www-data rclone config"
fi
echo ""
echo -e "${CYAN}${BOLD}🔒 HTTPS (empfohlen):${RESET}"
echo "   sudo apt install certbot python3-certbot-apache"
if [ "$DOMAIN" != "_" ]; then
    echo "   sudo certbot --apache -d $DOMAIN"
fi
echo ""
