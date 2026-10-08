# Dev App Pro — pravidla pro agenty

Provozní fakta a konvence. Historie oprav/incidentů je v `docs/` (opravy.md,
incidenty.md, restore-fs-cesty-fonty.md, acl-masky-projekty.md) — sem nepatří.

## Pevná pravidla

- **UI**: žádné `shadow`/`box-shadow`, žádné inline styly (CSP `style-src 'self'`),
  texty česky, shadcn/ui komponenty, reuse DataTable/dialogů
- **DB**: každá změna = `database/migration_XXX.sql` (idempotentní) + sync `schema.sql`
  + aplikovat na `devapppro` i `devapppro_copy`. `schema.sql` = zdroj pravdy pro testy
- **Backend**: validace v kontroleru (422 s `fields`), částky/součty na serveru,
  prepared statements + whitelisty sloupců; bootstrap error handler převádí i
  `@`-potlačená varování na výjimky — žádné `@` bez try/catch
- **Frontend**: po změně `npm run build` (tsc -b && vite build) + vizuální kontrola;
  TanStack Query pro server data, optimistic delete pattern (onMutate → onError
  rollback → onSettled invalidate), `prefetch="intent"` na NavLink, sdílené
  konstanty z `frontend/src/lib/constants.ts` (DEFAULT_PER_PAGE atd.)
- **Testy**: nová funkce = nové testy (unit/integration, `@group <modul>`),
  jen `devapppro_test` DB; před pushem prochází `bin/hooks/pre-push` (unit+smoke)
- **Git**: feature větve `feat|fix|docs|chore/<nazev>` → push → PR → CI → merge.
  Docs/changelog commity přímo na main až PO merge. Commity česky, bez footeru,
  identita přes `git -c user.name="Miroslav Bartík" -c user.email="jajsem@miroslavbartik.cz"`.
  Nikdy force-push, nikdy `--no-verify`
- **Proces**: ověřovat reálné chování (ne jen `php -l`), česky, destruktivní
  operace po potvrzení s rollback plánem; po ladění uklidit dočasné soubory

## Stack

- PHP 8.5 (systémové, `/usr/bin/php8.5`) + FPM 7.4/8.0–8.5 z packages.sury.org,
  APT pinning `/etc/apt/preferences.d/sury-php-pin`
- MariaDB 11.8.6, Apache 2.4.66 (jen 127.0.0.1:80/443), Composer 2.10.3,
  PHPUnit 11.5.56, Node 22.22.1, Mailpit (UI https://localhost/mailpit/, SMTP 127.0.0.1:1025)
- phpMyAdmin: https://localhost/phpmyadmin/
- DB: `devapppro` (live), `devapppro_copy` (test kopie pro ověřování), `devapppro_test`
  (PHPUnit, resetuje se); user `devapppro`/`devapppro_secret`, host 127.0.0.1
- Admin appky: admin/admin123 (live), test123 (test DB); app: https://localhost

## Frontend

- `frontend/` (Vite 7 + React 19 + TS + Tailwind 4, shadcn new-york/zinc),
  build → `assets/dist/` (Apache servíruje), dev server :5173
- API klient `src/lib/api.ts` (CSRF z cookie `csrf-token` → `X-CSRF-Token`)
- Stránky: Login, Dashboard (recharts grafy — POZOR: CSP blokuje ChartStyle,
  barvy grafů staticky v globals.css jako `.chart-finance`; dataKey musí sedět),
  Clients, Projects, Tasks, Finance (Faktury/Platby/Transakce), Notes, Files,
  Settings, Backups, NotFound
- Hooks: useAuth, useTheme, useSidebar + per-entity useXxx; sdílené komponenty
  v `frontend/src/components/shared/` a `ui/` (shadcn kopie, ne npm)

## Apache a per-project vhosty

- Hlavní vhost `/etc/apache2/sites-available/devapppro-localhost.conf`, bind 127.0.0.1
- Projektové vhosty: `cli/generate-vhosts.php` → `sites-available/devapppro-projects/`,
  Include přes `devapppro-projects.conf` (a2ensite). Generátor maže stale confy,
  validuje názvy složek (jen validní hostname — jinak by jeden špatný conf shodil
  configtest všem), zapisuje `.user.ini` do docrootu
- Typy projektů: `wordpress` (wp-config) / `php` (index.php) / `static` (jen HTML —
  vhost bez FPM) — detekce v sync, typ se přepočítává při změně obsahu složky
- Sync projektů: cron `cli/sync-projects.php`, exclusion list v ProjectSyncService

## Workery a fronty (všechny jako root, systemd timery 5 s / cron 1 min)

| Funkce | Tabulka jobů | Worker | Log |
|---|---|---|---|
| PHP verze | `php_version_jobs` | `cli/change-php-version.php` | /var/log/devapppro-php-version.log |
| Hosting/SSL | `project_hosting_jobs` | `cli/regenerate-project-hosting.php` | /var/log/devapppro-hosting.log |
| Mazání projektu | `project_delete_jobs` | `cli/delete-project.php` | /var/log/devapppro-delete-project.log |
| Restore záloh | `backup_restores` | `cli/restore-backup.php --process-pending` | /var/log/devapppro-restore.log |

- Hosting pipeline: job → mkcert (jako ratesman, CAROOT=~/.local/share/mkcert) →
  generate-vhosts → configtest → reload. Cert `/etc/apache2/ssl/localhost.crt`
  musí obsahovat chain (leaf + rootCA)
- Delete maže: soubory, DB, DB user `@localhost`+`@127.0.0.1`, vhost, cert, záznamy
- PHP rollback: `cli/rollback-php-versions.sh` (záloha /root/devapppro-backup-20260912-143623/)
- Denní cron `/etc/cron.d/devapppro-cleanup`: 2:00 DB záloha (backup-db.php,
  retence 30 dní do /var/backups/devapppro/), 3:00 login_attempts, 3:30 session GC
  (www-data), 4:00 fix-backup-permissions, 4:15 ACL normalizace projektů
  (normalize-project-acls.php), ne 5:00 orphaned files (www-data)

## Restore záloh

- `cli/import-duplicator.php <archiv> <slug> [--wait]` nebo UI /projects/backups
- Kroky: extracting → creating_db → importing_sql → configuring → replacing_urls →
  regenerating_ssl → completed
- Search/replace serialization-aware (URL + FS cesty); detekce staré cesty:
  options → Duplicator descriptor → frekvenční scan options; post-replace sweep
  doplní zbylé rooty; fix-upy: DELETE downloaded_font_files + dirsize transient +
  fonts/*.css (Kadence lokální fonty — viz docs/restore-fs-cesty-fonty.md)
- Maže et-cache/wp-content/cache (Divi ikony), Duplicator pozůstatky
  (installer.php, dup-installer/); deaktivuje pluginy škodlivé na localhostu
  (bezpečnostní, cache, ManageWP — Duplicator NEdeaktivovat)
- ACL: restore/install normalizuje ACL přes AclService::normalizeProjectTree
  (root workery + umask → mask clipping; post-mortem docs/acl-masky-projekty.md)
- wp-config: FS_METHOD=direct, DEVAPPPRO_SECRET pro auto-login mu-plugin
  (`cli/wp-mu-plugin.php` → `wp-content/mu-plugins/devapppro-autologin.php`)
- BACKUPS_DIR v config.php (výchozí ~/projekty/Zalohy)

## Faktury

- `invoices` + `invoice_items` + `invoice_payments`; `taxable_date` = DZP;
  subtotal/DPH na serveru (round(qty*price))
- PDF: InvoicePdfService (mPDF + mpdf/qrcode), QR SPD 1.0 (`<barcode type="QR">`,
  NIKDY `<qrcode>`), ACC=IBAN (fallback přepočet z bank_account)
- Zmrazení: přechod na sent/paid/overdue → PDF do `storage/invoices/` a endpoint
  ho servíruje (`invoices.frozen_pdf`, interní sloupec); draft = živá generace
- Číslování: settings `invoice_number_format` ({year}{seq:03d}), `invoice_seq`
- UI: Finance → Faktury, tlačítko v detailu klienta; auto-download po vytvoření

## Testy

- `bin/test.sh smoke|unit|integration [modul]|security|e2e|full` nebo `vendor/bin/phpunit`
- `UnitTestCase` (bez serveru) vs `TestCase` (PHP -S :8080 + Guzzle)
- E2E Playwright: `tests/e2e/serve.sh` na :8099, seed e2e_admin
- CI: `.github/workflows/tests.yml` (config/*.php jsou gitignored → CI generuje)

## Struktura

`src/` (PSR-4 DevAppPro\, Core/ Container+Constants, Services/, Repositories/,
Controllers/) · `api/` thin entrypointy · `cli/` workery · `database/` schema.sql
+ migration_XXX.sql · `tests/` · `storage/` (Require all denied, soubory jen přes
/api/files) · `docs/` · `frontend/` · `.devin/` (skills, agents) · `src/Libs/DupArchive/`

## Bezpečnost (aktuální stav)

- CSP strict (`style-src 'self'` bez unsafe-inline), security hlavičky, CORS jen localhost
- Secrets: `DEVAPPPRO_WP_AUTOLOGIN_SECRET` + `DEVAPPPRO_CREDENTIALS_ENCRYPTION_KEY`
  vyžadované env (fail-fast pro web, CLI jen varuje); config/*.php = 640, gitignored
- Upload: EXT_MIME_MAP validace, max 10 MB, jen PDF/obrázky/texty/ZIP/DOCX/XLSX
- is_safe_path() + is_symlink_safe() pro path traversal, enforce_input_limits(),
  sanitize_for_log(), IBAN mod-97, session hijack detekce (IP/UA)
- .htaccess blokuje config/, src/, vendor/, cli/, docs/, .devin/, .env a `..` v URL

## MariaDB tuning (60-devapppro-tuning.cnf)

innodb_buffer_pool_size=1G, flush_log_at_trx_commit=2, log_file_size=256M,
io_capacity=1000, query_cache off — lokální dev parametry
