#!/bin/bash
#
# setup-sudoers.sh - Vytvoří sudoers pravidlo pro WordPress installer.
#
# Umožňuje uživateli www-data spouštět CLI install script jako root bez hesla.
#
# Použití: sudo bash setup-sudoers.sh
#
set -euo pipefail

SUDOERS_FILE="/etc/sudoers.d/devapppro-wp-install"
SCRIPT_PATH="/var/www/devapppro/cli/install-wordpress.php"

echo "Vytvářím sudoers pravidlo pro WordPress installer..."

# Vytvoření sudoers souboru
cat > "$SUDOERS_FILE" <<EOF
# Dev App Pro - WordPress Installer
# Povoluje www-data spouštět install-wordpress.php jako root bez hesla
www-data ALL=(root) NOPASSWD: /usr/bin/php ${SCRIPT_PATH} *
EOF

# Nastavení práv 0440
chmod 0440 "$SUDOERS_FILE"
echo "Práva nastavena na 0440."

# Validace syntaxe sudoers
echo "Validuji syntaxi sudoers..."
if visudo -c -f "$SUDOERS_FILE"; then
    echo "OK: Sudoers pravidlo je platné."
    echo "Soubor: $SUDOERS_FILE"
else
    echo "CHYBA: Sudoers pravidlo je neplatné! Odstraňuji soubor."
    rm -f "$SUDOERS_FILE"
    exit 1
fi

echo "Hotovo. WordPress installer je nyní k dispozici pro www-data."
