#!/bin/bash

set -e

# === Farben ===
GREEN="\e[32m"
YELLOW="\e[33m"
RED="\e[31m"
RESET="\e[0m"

log() {
    echo -e "${GREEN}[INFO]${RESET} $1"
}

warn() {
    echo -e "${YELLOW}[WARN]${RESET} $1"
}

error() {
    echo -e "${RED}[ERROR]${RESET} $1"
}

# === Root-Check ===
if [ "$EUID" -ne 0 ]; then
  error "Bitte als root ausführen (sudo ./install.sh)"
  exit 1
fi

# === Domain abfragen ===
echo ""
read -p "🌐 Domain eingeben (leer lassen für Server-IP): " DOMAIN

if [ -z "$DOMAIN" ]; then
    warn "Keine Domain angegeben → verwende Server-IP"
    DOMAIN="_"
fi

# === KONFIGURATION ===
WEB_ROOT="/var/www"
PROJECT_PATH="/var/www/html/xtream"
REPO_URL="https://github.com/extend110/xtream-vault.git"
APACHE_CONF="xtream.conf"
APACHE_CONF_PATH="/etc/apache2/sites-available/$APACHE_CONF"

log "Starte Xtream Vault Installation..."

# === Pakete installieren ===
log "Installiere benötigte Pakete..."
apt update
apt install -y apache2 php php-curl php-json php-cli php-common php-mbstring php-xml libapache2-mod-php curl

# === Berechtigungen ===
log "Setze Berechtigungen..."
chown -R www-data:www-data $WEB_ROOT
chmod -R 755 $WEB_ROOT

# === Apache Config erstellen ===
log "Erstelle Apache VirtualHost..."

cat > $APACHE_CONF_PATH <<EOL
<VirtualHost *:80>
    ServerName $DOMAIN
    DocumentRoot $PROJECT_PATH

    <Directory $PROJECT_PATH>
        AllowOverride All
        Require all granted
    </Directory>

    <Directory $PROJECT_PATH/data>
        Require all denied
    </Directory>
</VirtualHost>
EOL

# === Apache Setup ===
log "Deaktiviere Default-Site..."
a2dissite 000-default.conf || true

log "Aktiviere Xtream Site..."
a2ensite $APACHE_CONF

log "Aktiviere benötigte Module..."
a2enmod rewrite headers

log "Teste Apache Konfiguration..."
apache2ctl configtest

log "Lade Apache neu..."
systemctl reload apache2

# === PHP Config prüfen ===
log "Prüfe allow_url_fopen..."
PHP_INI=$(php -i | grep "Loaded Configuration File" | awk '{print $5}')

if grep -q "allow_url_fopen = Off" "$PHP_INI"; then
    warn "Aktiviere allow_url_fopen..."
    sed -i 's/allow_url_fopen = Off/allow_url_fopen = On/' "$PHP_INI"
    systemctl restart apache2
fi

# === Cronjobs ===
log "Richte Cronjobs ein..."

CRON_TMP=$(mktemp)

cat <<EOL > $CRON_TMP
*/30 * * * * flock -n /tmp/xtream_cron.lock php $PROJECT_PATH/cron.php
0 4 * * * php $PROJECT_PATH/cache_builder.php
EOL

crontab -u www-data $CRON_TMP
rm $CRON_TMP

# === rclone installieren ===
log "Installiere rclone..."
curl https://rclone.org/install.sh | bash

# === Server-IP anzeigen ===
SERVER_IP=$(hostname -I | awk '{print $1}')

# === Fertig ===
log "Installation abgeschlossen!"
echo ""
echo -e "${GREEN}🌐 Zugriff:${RESET}"

if [ "$DOMAIN" = "_" ]; then
    echo "→ http://$SERVER_IP"
else
    echo "→ http://$DOMAIN"
fi

echo ""
echo "👤 Setup im Browser durchführen"
echo ""
echo "Optional:"
echo "sudo -u www-data rclone config"
