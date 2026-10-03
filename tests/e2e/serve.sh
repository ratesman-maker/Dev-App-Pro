#!/usr/bin/env bash
# Dev server pro Playwright E2E testy.
# php -S s testovací DB (devapppro_test) + seednutým E2E uživatelem.
# Port 8099 - nesmí kolidovat s PHPUnit test serverem (8080).
set -euo pipefail
cd "$(dirname "$0")/../.."

export DB_HOST="${DB_HOST:-127.0.0.1}"
export DB_NAME="${DB_NAME:-devapppro_test}"
export DB_USER="${DB_USER:-devapppro}"
export DB_PASS="${DB_PASS:-devapppro_secret}"
export DEVAPPPRO_WP_AUTOLOGIN_SECRET="${DEVAPPPRO_WP_AUTOLOGIN_SECRET:-e2e_wp_autologin_secret}"
export DEVAPPPRO_CREDENTIALS_ENCRYPTION_KEY="${DEVAPPPRO_CREDENTIALS_ENCRYPTION_KEY:-e2e_credentials_encryption_key}"

php tests/e2e/seed-user.php
exec php -S 127.0.0.1:8099 tests/test-router.php
