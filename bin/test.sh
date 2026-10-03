#!/usr/bin/env bash
# Spouštění testů podle profilů.
# Použití:
#   bin/test.sh smoke               - kritická cesta (8 rychlých testů), po každé změně
#   bin/test.sh unit                - unit testy (repositáře, služby) bez HTTP serveru
#   bin/test.sh integration         - všechny API testy
#   bin/test.sh integration <modul> - jen modul (auth, clients, projects, tasks, invoices,
#                                     finance, notes, files, settings, dashboard)
#   bin/test.sh security            - bezpečnostní testy
#   bin/test.sh e2e                 - Playwright E2E (build frontendu + php -S + browser testy)
#   bin/test.sh full                - kompletní sada (před push)
set -euo pipefail
cd "$(dirname "$0")/.."

PHPUNIT="vendor/bin/phpunit"
CMD="${1:-}"
shift || true

usage() {
    sed -n '2,12p' "$0" | sed 's/^# \{0,1\}//'
}

case "$CMD" in
    smoke)
        exec "$PHPUNIT" --group smoke
        ;;
    unit)
        exec "$PHPUNIT" --testsuite Unit
        ;;
    integration)
        if [ -n "${1:-}" ]; then
            exec "$PHPUNIT" --testsuite Integration --group "$1"
        fi
        exec "$PHPUNIT" --testsuite Integration
        ;;
    security)
        exec "$PHPUNIT" --testsuite Security
        ;;
    e2e)
        # Playwright E2E: potřebuje build frontendu (assets/dist) + devapppro_test DB
        (cd frontend && npm run build && npx playwright test "$@")
        ;;
    full)
        exec "$PHPUNIT"
        ;;
    *)
        usage
        exit 1
        ;;
esac
