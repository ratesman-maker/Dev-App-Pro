#!/bin/bash
#
# Rollback skript - vrátí Apache, PHP, SSL a DB do stavu před změnami.
# Použití: sudo /var/www/devapppro/cli/rollback-php-versions.sh
#
set -euo pipefail

BACKUP_PATH_FILE="/var/www/devapppro/.backup-path"

if [ ! -f "$BACKUP_PATH_FILE" ]; then
    echo "CHYBA: Záloha nebyla nalezena ($BACKUP_PATH_FILE neexistuje)."
    exit 1
fi

BACKUP_DIR=$(cat "$BACKUP_PATH_FILE")

if [ ! -d "$BACKUP_DIR" ]; then
    echo "CHYBA: Záloha neexistuje: $BACKUP_DIR"
    exit 1
fi

echo "=== Rollback z $BACKUP_DIR ==="

# 1. Zastavit Apache
echo "=== Zastavuji Apache ==="
systemctl stop apache2 2>&1 || true

# 2. Obnovit Apache konfiguraci
echo "=== Obnovuji Apache konfiguraci ==="
rm -rf /etc/apache2/sites-available/devapppro-projects 2>/dev/null || true
cp -a "$BACKUP_DIR/apache2/"* /etc/apache2/

# 3. Obnovit SSL
echo "=== Obnovuji SSL ==="
cp -a "$BACKUP_DIR/ssl/"* /etc/apache2/ssl/

# 4. Obnovit PHP konfiguraci
echo "=== Obnovuji PHP konfiguraci ==="
cp -a "$BACKUP_DIR/php/"* /etc/php/

# 5. Odstranit sury repozitář pokud existuje
if [ -f /etc/apt/sources.list.d/sury-php.list ]; then
    echo "=== Odstraňuji Sury repozitář ==="
    rm -f /etc/apt/sources.list.d/sury-php.list
    rm -f /usr/share/keyrings/debsuryorg-archive-keyring.gpg
    apt update 2>&1 | tail -3
fi

# 6. Odstranit apt pinning
if [ -f /etc/apt/preferences.d/sury-php-pin ]; then
    rm -f /etc/apt/preferences.d/sury-php-pin
fi

# 7. Odstranit sury PHP verze (ponechat systémové 8.5)
echo "=== Odstraňuji sury PHP verze ==="
for ver in 5.6 7.0 7.1 7.2 7.3 7.4 8.0 8.1 8.2 8.3 8.4; do
    if dpkg -l "php${ver}-fpm" &>/dev/null; then
        apt-get purge -y "php${ver}-*" 2>&1 | tail -3
    fi
done

# 8. Restartovat PHP 8.5 FPM
echo "=== Restartuji PHP 8.5 FPM ==="
systemctl restart php8.5-fpm 2>&1 || true

# 9. Test Apache konfigurace
echo "=== Testuji Apache konfiguraci ==="
if apache2ctl configtest 2>&1; then
    echo "=== Startuji Apache ==="
    systemctl start apache2
    echo "=== Apache běží ==="
    systemctl status apache2 2>&1 | head -5
else
    echo "CHYBA: Apache konfigurace je neplatná i po rollbacku!"
    echo "Zkouším nouzový start..."
    systemctl start apache2 2>&1 || true
fi

# 10. Obnovit DB (volitelné - pouze na vyžádání)
if [ "${1:-}" = "--restore-db" ]; then
    echo "=== Obnovuji DB ==="
    mariadb -u root devapppro < "$BACKUP_DIR/devapppro-db.sql"
    echo "DB obnovena."
fi

echo ""
echo "=== Rollback dokončen ==="
echo "Záloha: $BACKUP_DIR"
echo ""
echo "Ověřte:"
echo "  curl -sk https://localhost/ | head -5"
echo "  curl -sk https://test-blog.localhost/ | head -5"
