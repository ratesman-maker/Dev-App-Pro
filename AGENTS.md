# Dev App Pro - Projektové informace

## UI pravidla
- NEPOUŽÍVAT stíny (shadow, box-shadow) v žádných komponentách - uživatel je explicitně zakázal
- Platí pro: toast notifikace, karty, dialogy, dropdowny, tlačítka, všechny UI elementy

## Verze softwaru
- PHP 8.5.4 (CLI: /usr/bin/php8.5, systémové z Ubuntu, chráněné APT pinningem)
- PHP 7.4, 8.0, 8.1, 8.2, 8.3, 8.4 (z packages.sury.org, per-project přes FPM)
- MariaDB 11.8.6
- Composer 2.10.3
- Apache 2.4.66 (Ubuntu)
- PHPUnit 11.5.56
- Node 22.22.1, npm 9.2.0
- Mailpit 1.31.1 (mail catcher, /home/ratesman/.local/bin/mailpit, systemd service)

## Webové nástroje
- phpMyAdmin: https://localhost/phpmyadmin/ (Apache conf: /etc/apache2/conf-enabled/phpmyadmin.conf)
- Mailpit UI: https://localhost/mailpit/ (Apache proxy: /etc/apache2/conf-available/mailpit.conf)
- Mailpit SMTP: 127.0.0.1:1025 (PHP sendmail_path nastaven pro všechny FPM verze)
- Mailpit systemd: /etc/systemd/system/mailpit.service (enabled, auto-start)

## Frontend
- Adresář: /var/www/devapppro/frontend/ (Vite + React 19 + TypeScript)
- Stack: React 19.3.0, React Router 7.18.3, Vite 7.3.6, TypeScript 5.9.3, Tailwind CSS 4.3.3
- Data: TanStack Query 5.102.8, TanStack Table 8.21.3
- UI: shadcn/ui (new-york, zinc), Lucide React 0.460.0, @radix-ui/react-slot 1.3.3, @radix-ui/react-label 2.1.15
- Build výstup: /var/www/devapppro/assets/dist/ (outDir: ../assets/dist)
- Dev server: port 5173, proxy /api a /fonts na http://localhost
- Příkazy: npm run dev | npm run build (tsc -b && vite build) | npm run lint
- CSS: src/styles/globals.css (Tailwind 4 @import + @theme, dark mode default, class="dark" v index.html)
- API klient: src/lib/api.ts (CSRF token z cookie csrf-token, hlavička X-CSRF-Token)
- shadcn/ui komponenty v src/components/ui/ (button, card, input, label, badge, dialog, select) - kopírované zdrojáky, ne npm
- Fonty: Inter a JetBrains Mono (WOFF2 placeholder v frontend/public/fonts/, dodání později)
- Stránky (všechny hotové):
  - LoginPage (login + reset hesla ve 4 stavech)
  - DashboardPage (styl shadcn dashboard-01: 4 metriky s trend badge, area chart příjmů/výdajů za 12M/6M/3M, tabulky klientů a aktivních projektů s řazením + klik na řádek → detail, pás "Co řešit" - faktury po splatnosti/termíny ≤14 dní/poslední poznámky)
  - GET /api/dashboard vrací: kpis (clients/projects/active/open/overdue), clients (aggregace projektů/faktur/příjmů/poslední poznámky/datum aktivity), projects (active, rozpočet/příjmy/faktury/termín), finance_series (12 měsíců income/expense z transakcí), attention (overdue_invoices, upcoming_deadlines včetně prošlých, recent_notes s entity_label)
  - Grafy: recharts 3.x + ui/chart.tsx (shadcn). POZOR: CSP style-src 'self' blokuje style tag injektovaný ChartStyle → ChartStyle je no-op a barvy chart proměnných (--color-*) se definují staticky v globals.css (třída .chart-finance)
  - dataKey v recharts musí odpovídat klíčům dat (income_cents/expense_cents), jinak se plochy nekreslí
  - ClientsPage (DataTable, CRUD, search, paginace, dropdown akce)
  - ProjectsPage (DataTable, CRUD, archive/restore, filtr status včetně archived, badge "Ze složky")
  - TasksPage (DataTable, CRUD, filtry projekt/status/priorita/overdue, fmtMinutes)
  - FinancePage (3 taby: Faktury/Platby/Transakce, finanční souhrn, PDF download)
  - NotesPage (DataTable, CRUD, polymorfní vazby, filtr entity_type)
  - FilesPage (DataTable, upload multipart, download, úprava vazeb, fmtBytes)
  - SettingsPage (4 taby: Profil/Firma/Aplikace/Vzhled, živý preview)
  - NotFoundPage (404)
- Hooks: useAuth, useTheme, useSidebar, useClients, useProjects, useTasks, useInvoices, useInvoicePayments, useTransactions, useNotes, useFiles, useSettings
- Komponenty: DataTable, ConfirmDialog, AttachmentSelect, layout/ (Sidebar, Topbar, AppLayout, UserMenu), formulářové dialogy pro každou entitu
- Routing: ProtectedRoute (auth check), BrowserRouter, vše pod ProtectedRoute kromě /login

## Databáze
- Produkční: `devapppro` (admin heslo: admin123)
- Testovací: `devapppro_test` (admin heslo: test123)
- Uživatel DB: `devapppro` (heslo: `devapppro_secret`)
- Host: 127.0.0.1, port 3306
- Tabulky: users, company_profile, settings, login_attempts, clients, projects, tasks, invoices, invoice_payments, transactions, notes, noteables, files, fileables

## Apache
- Bind: 127.0.0.1:80 (jen localhost)
- Vhost: /etc/apache2/sites-available/devapppro-localhost.conf
- Per-project vhosty: /etc/apache2/sites-available/devapppro-projects/ (auto-generováno)
- Vhost generátor: cli/generate-vhosts.php (maže i zastaralé .conf soubory projektů, které se už negenerují)
- ports.conf záloha: /etc/apache2/ports.conf.bak.20260911
- mod_rewrite: povolen
- .htaccess: AllowOverride All

## Per-project PHP verze
- Sury repozitář: packages.sury.org/php/ (oficiální pro Ubuntu 26.04)
- APT pinning: /etc/apt/preferences.d/sury-php-pin (systémové PHP 8.5 chráněno, priorita 1001)
- PHP verze: 7.4, 8.0, 8.1, 8.2, 8.3, 8.4, 8.5 (každá vlastní FPM)
- FPM pool: /etc/php/{ver}/fpm/pool.d/www.conf (user=ratesman, group=ratesman)
- DB: projects.php_version, php_version_jobs (fronta pro worker)
- API: POST /api/projects/{id}/php-version, GET /api/tools/php-versions, GET /api/tools/php-version-jobs
- Worker: cli/change-php-version.php --process-pending (root)
- Systemd timer: devapppro-php-version.timer (každých 5s) + devapppro-php-version.service
- Log: /var/log/devapppro-php-version.log
- Rollback: cli/rollback-php-versions.sh (obnoví Apache, PHP, SSL, DB)
- Záloha: /root/devapppro-backup-20260912-143623/
- Frontend: usePhpVersionJobs detekuje dokončení jobu → invaliduje projects query

## Mazání projektů
- DELETE /api/projects/{id} - vytvoří job v project_delete_jobs
- Worker: cli/delete-project.php --process-pending (root)
- Systemd timer: devapppro-delete-project.timer (každých 5s)
- Maže: soubory na disku, DB, DB uživatele, Apache vhost, SSL certifikát, wp_installs záznam
- DB root heslo načteno z /root/.my.cnf přes parse_ini_file
- Log: /var/log/devapppro-delete-project.log

## Restore záloh
- CLI worker: cli/restore-backup.php --process-pending (root, cron každou minutu)
- CLI wrapper: cli/import-duplicator.php <archiv> <slug> [--wait] - zařadí restore job z příkazové řádky
- Podpora: ZIP archivy, Duplicator Pro DAF
- Kroky: extracting → creating_db → importing_sql → configuring → replacing_urls → regenerating_ssl → completed
- Search/replace: serialization-aware (opravuje délky s:XX:), nahrazuje URL i cesty na disku
- Detekce staré cesty: z WP options (recently_edited, et_images_temp_folder, upload_path)
- Po importu maže cache (et-cache, wp-content/cache - jinak Divi ikony = číslice) a Duplicator pozůstatky (installer.php, dup-installer/ - obsahuje dump DB)
- Deaktivuje pluginy škodlivé na localhostu: bezpečnostní (BBQ/WPS Hide Login/limit-login/RSS/Complianz - blokují "localhost" v URL, schovávají admin), cache, worker (ManageWP). Duplicator NEdeaktivovat - uživatel ho používá denně pro zálohy. SEO a funkční pluginy zůstávají.
- Pozor: @unserialize v restore potřebuje try/catch - bootstrap error handler převádí i @-potlačená varování na ErrorException
- Frontend: progress bar s popisem aktuálního kroku, polling 1s
- Dokumentace postupu: docs/duplicator-import.md
- Log: /var/log/devapppro-restore.log

## Faktury (PDF + QR platba)
- Položky faktury: tabulka `invoice_items` (description, quantity DECIMAL(12,3), unit, unit_price_cents) - migration_016
- `invoices.taxable_date` = DZP (datum uskutečnění zdanitelného plnění, ZDPH §28) - výchozí issue_date
- API: POST/PUT /api/invoices přijímá `items[]` + `taxable_date`; subtotal se počítá na serveru z položek (round(qty*price))
- PDF: InvoicePdfService (mPDF 8.3 + mpdf/qrcode 1.2.2 - composer)
- QR Platba (SPD 1.0, standard ČBA): ACC=IBAN (fallback profil → bank_account přepočet na IBAN), AM, CC, X-SS (VS), MSG (číslo faktury, ASCII, max 60 znaků, mezery %20)
- QR tag v mPDF: `<barcode code="..." type="QR" error="M" size="4" />` (POZOR: `<qrcode>` tag v mPDF 8.3 NEexistuje!; size=4 → 100pt)
- Prohlášení DPH: "Plátce DPH" dle profilu DIČ, jinak "Nejsem plátce DPH"
- Frontend: tlačítko "Vystavit fakturu" (detail klienta s předvyplněným klientem + Finance tab), editor položek, auto-download PDF po vytvoření
- Položky se zobrazují v InvoiceDetailModal
- PDF endpoint: GET /api/invoices/{id}/pdf (frontend volá bez .php, Apache rewrite zachovává REQUEST_URI)

## GitHub pravidla (dohodnuto 19. 9. 2026)
- Repo: https://github.com/ratesman-maker/Dev-App-Pro (soukromé, větev main)
- Identita commitů: Miroslav Bartík <jajsem@miroslavbartik.cz> (přes `git -c user.name=... -c user.email=...` nebo env proměnné, NEměnit git config)
- **Větvení**: feature větve — každá změna ve větvi `feat/<nazev>` / `fix/<nazev>`; po dokončení merge do main (`git merge --no-ff`), pak push
- **Frekvence**: commitovat průběžně po logických celcích, **push po milníku nebo na vyžádání uživatele**
- **Commit zprávy**: česky, stručně "co a proč", BEZ Devin footeru (žádné "Generated with Devin" ani "Co-Authored-By")
- **Nikdy force-push** (historie se nepřepisuje), nikdy nemazat větve bez vědomí uživatele
- Před merge/pushem vždy `git pull` (vyhnout se konfliktům); pull s rebase jen když je to bezpečné
- **Necommituje se**: `.env`, `config/*.php`, `storage/`, logy, vendor/node_modules/dist (hlídá .gitignore), WordPress weby v /run/media/ratesman/Projekty (jsou mimo repo)
- Credentials: token v ~/.git-credentials (600), push přes `git -c credential.helper=store push`
- Po každé migraci DB aktualizovat schema.sql, po každé funkci README.md (je-li relevantní)

## Testy
- Spuštění: `cd /var/www/devapppro && ./vendor/bin/phpunit`
- Test DB se resetuje před každým testem (setUp/tearDown)
- Test server: PHP built-in server na portu 8080 (tests/test-router.php)
- Produkční aplikace: Apache na 127.0.0.1:80
- POZOR: bootstrap error handler převádí i @-potlačená varování na ErrorException → TestCase::waitForServer musí fsockopen obalit try/catch (opraveno)
- TestCase předává serveru i DEVAPPPRO_WP_AUTOLOGIN_SECRET / DEVAPPPRO_CREDENTIALS_ENCRYPTION_KEY (test dummy hodnoty)
- schema.sql MUSÍ odpovídat live DB (drop+create celé DB, testy na tom stojí); po migracích vždy doplnit nové tabulky/sloupce
- schema.sql má SET FOREIGN_KEY_CHECKS=0 jen na začátku a =1 na konci (žádné SET uprostřed - jinak DROP projektů selže na 1451 při datech v child tabulkách)

## Konfigurace
- config/config.php - aplikace (APP_NAME, RATE_LIMIT, BCRYPT_COST)
- config/database.php - PDO připojení
- bootstrap.php - autoloading, session, db() funkce
- .htaccess - rewrite, bezpečnostní hlavičky, cache

## Struktura
- src/ - PHP kód (PSR-4: DevAppPro\)
- src/Core/ - Constants.php, Container.php (DI)
- src/Services/ - služby (InvoicePdfService, ProjectSyncService, ImageService, CryptoService)
- api/ - API vstupní body (thin, volají controllery)
- cli/ - CLI skripty (sync-projects.php, cleanup-login-attempts.php, fix-backup-permissions.php, cleanup-orphaned-files.php, session-gc.php)
- config/ - konfigurace
- database/ - SQL (schema, seed, seed_test, migrace)
- tests/ - PHPUnit testy (Unit, Integration, Security)
- storage/ - fyzické soubory (storage/{YYYY}/{MM}/{uuid}.ext) - přístup blokován přes .htaccess
- docs/ - dokumentace (00-10, revize.md, opravy.md)
- plany/ - implementační plány
- .devin/agents/ - subagenti (researcher, reviewer, test-runner, implementer)
- frontend/ - React frontend (Vite, src/, public/fonts/)
- frontend/src/components/shared/ - sdílené komponenty (DataTable, DetailModal, ConfirmDialog, atd.)
- frontend/src/lib/constants.ts - centralizované konstanty a labely
- frontend/src/types/index.ts - sdílené TypeScript typy
- frontend/src/hooks/useDebounce.ts - debounce hook pro vyhledávání
- frontend/src/components/ui/PageSkeleton.tsx - skeleton loading komponenta
- assets/dist/ - build výstup frontendu (index.html, assets/{js,css})
- src/Libs/DupArchive/ - Duplicator Pro DAF knihovna (extrakce .daf souborů)

## Cron úlohy (/etc/cron.d/devapppro-cleanup)
- 2:00 denně - DB záloha (mysqldump → /var/backups/devapppro/, retence 30 dní) - cli/backup-db.php
- 3:00 denně - mazání starých login_attempts (24h) - cli/cleanup-login-attempts.php
- 3:30 denně - PHP session GC - cli/session-gc.php (pod www-data)
- 4:00 denně - oprava oprávnění záloh na 600 - cli/fix-backup-permissions.php
- 5:00 neděle - mazání osiřelých souborů v storage/ - cli/cleanup-orphaned-files.php

## Cron úlohy (/etc/cron.d/devapppro-sync)
- * * * * * - synchronizace projektů ze složek - cli/sync-projects.php
- * * * * * - restore záloh worker - cli/restore-backup.php --process-pending
- * * * * * - kontrola termínů úkolů a faktur - cli/check-deadlines.php (log: /var/log/devapppro-deadlines.log)

## Notifikace
- DB tabulka: notifications (id, user_id, type, title, message, entity_type, entity_id, is_read, created_at)
- Backend: NotificationRepository, NotificationService, NotificationApiController
- API: /api/notifications (GET seznam, GET /unread-count, PUT /{id}/read, PUT /read-all, DELETE /{id}, DELETE /read)
- Spouštěče: CRUD akce (úkoly, faktury, platby, transakce) + termínové připomínky (cron)
- Termínové: X dní před termínem úkolu/splatností faktury, jednorázově po termínu
- Deduplikace: termínové notifikace se nevytvoří duplicitně (exists() kontrola)
- Nastavení: SettingsPage → tab Notifikace (notif_* klíče v settings tabulce, notif_days_before)
- Frontend: useNotifications hook, NotificationBell v Topbar (zvoník s počítadlem nepřečtených)
- Auto-refresh: 30s polling unread count + notifications list

## MariaDB tuning (/etc/mysql/mariadb.conf.d/60-devapppro-tuning.cnf)
- innodb_buffer_pool_size = 1G (z 128 MB, systém má 30 GB RAM)
- innodb_flush_log_at_trx_commit = 2 (rychlejší, přijatelné pro lokální dev)
- innodb_log_file_size = 256M
- query_cache_size = 0 (vypnuto)
- innodb_buffer_pool_instances = 8
- innodb_io_capacity = 1000 (SSD)

## Bezpečnost
- Apache vhost: Require local (jen localhost)
- storage/.htaccess: Require all denied (soubory jen přes /api/files/{id}/download)
- Session: cookie_lifetime=0, detekce krádeže (IP/UA)
- Upload limit: 10 MB (vhost), 1 MB (JSON body)
- Bezpečnostní hlavičky: X-Frame-Options, X-XSS-Protection, Permissions-Policy, CSP
- CSP: script-src 'self' (strict, bez unsafe-inline/unsafe-eval), style-src 'self' 'unsafe-inline' (React inline styly)
- CORS: /api/ cesty (Access-Control-Allow-Origin: https://localhost)
- IBAN validace: mod-97 kontrola v InvoiceApiController::isValidIban()
- Chybové odpovědi logovány se sanitizací (sanitize_for_log v json_response)
- 500 stránka: set_exception_handler v bootstrap.php, logy do /var/log/devapppro/error.log
- APP_DEBUG=false (produkční režim, generické chybové zprávy)
- SQL injection: LIMIT/OFFSET bind parametry ve všech repozitářích
- Path traversal: is_safe_path() a is_symlink_safe() v helpers.php
- Délkové limity: enforce_input_limits() v json_input() (max 65535 znaků, 100 prvků)
- config.php oprávnění: 640
- Secrets: WP_AUTOLOGIN_SECRET a CREDENTIALS_ENCRYPTION_KEY v .env (načítáno přes Apache SetEnv, .gitignore chráněno)
- .htaccess blokuje citlivé složky: config/, src/, vendor/, cli/, docs/, .devin/, .env
- is_safe_path() pro path traversal ochranu v FileApiController, BackupsApiController, WorklogApiController
- Vhost: `<VirtualHost 127.0.0.1:80>` a `127.0.0.1:443` (jen localhost)
- ports.conf: `Listen 127.0.0.1:80` a `Listen 127.0.0.1:443`
- .htaccess blokuje `..` v URL (path traversal defense)
- DI: Container.php s auto-wiring, `repo()` metoda v ApiController, 100 `new` volání nahrazeno v 16 controllerech
- Self-service profil: PUT /api/users/me/profile (jméno, e-mail), PUT /api/users/me/password (změna hesla)
- Inline editace statusu úkolu v TasksPage
- useCallback pro handlery v 6 stránkách (ProjectsPage, FilesPage, NotesPage, ClientsPage, TasksPage, WorklogPage)
- FilesPage zobrazuje thumbnail obrázky místo ikon
- user_id u souborů se ukládá ze session (FileApiController)

## Zálohy a restore
- BACKUPS_DIR v config.php - složka se zip/daf zálohami (výchozí /run/media/ratesman/Projekty/Zalohy)
- src/Controllers/BackupsApiController.php - API (GET /api/backups, GET /api/backups/restores, POST /api/backups/restore)
- src/Repositories/BackupRestoreRepository.php - restore jobs
- cli/restore-backup.php --process-pending - cron (root, každou minutu)
- DB tabulka: backup_restores (status: pending → extracting → creating_db → importing_sql → configuring → replacing_urls → regenerating_ssl → completed/failed)
- Podporované formáty: .zip (standardní unzip), .daf (Duplicator Pro Archive Format přes DupArchive knihovnu)
- DAF SQL cesta: dup-installer/dup_descriptors_*/db_dumps/*-dump.sql
- DAF old URL: čtena z wp_options.siteurl po importu (spolehlivější než archive.txt)
- Table prefix: detekován z archive.txt (wp_tableprefix) nebo z názvu tabulky options
- URL nahrazení: SQL REPLACE přes všechny textové sloupce (http i https varianty)
- SSL: mkcert musí běžet s CAROOT=/home/ratesman/.local/share/mkcert (ne root CA!) - generuje do /tmp, pak kopíruje do /etc/apache2/ssl/
- SSL certifikát: /etc/apache2/ssl/localhost.crt musí obsahovat chain (leaf + CA) - cat leaf.crt rootCA.pem > localhost.crt
- SSL CA: mkcert -install nainstaluje CA do systémového trust store + Firefox + Chrome (vyžaduje restart prohlížeče)
- SSL nové domény: mkcert -cert-file /tmp/localhost.crt -key-file /tmp/localhost.key "localhost" "*.localhost" "novy-projekt.localhost" && cat /tmp/localhost.crt ~/.local/share/mkcert/rootCA.pem | sudo tee /etc/apache2/ssl/localhost.crt > /dev/null && sudo cp /tmp/localhost.key /etc/apache2/ssl/localhost.key && sudo systemctl restart apache2
- Frontend: /projects/backups - seznam záloh, tlačítko "Vytvořit projekt", stav restore jobů

## Vlna 8 — Bezpečnost, modularita, výkonnost

### Bezpečnost
- PDO::query() audit: DashboardApiController má whitelist tabulek/sloupců/expreseů; ostatní query() volání používají interní fixní řetězce
- Upload validace: EXT_MIME_MAP v FileApiController kontroluje kompatibilitu MIME↔extension; max 10 MB; blokuje EXE/SH/BAT/PHP/JS/HTML
- Povolené upload typy: PDF, PNG, JPEG, GIF, WebP, SVG, TXT, CSV, DOCX, XLSX, ZIP
- Délková omezení: 58 polí v 14 controllerech (jména 100, email 255, popisy 5000, adresy 500, IČO/DIČ 50, účet/IBAN/SWIFT 50, názvy 200, URL 2000, heslo 100)
- Secrets: config/config.php vyžaduje env proměnné DEVAPPPRO_WP_AUTOLOGIN_SECRET a DEVAPPPRO_CREDENTIALS_ENCRYPTION_KEY (žádný fallback, runtime výjimka chybí-li)
- Generické zprávy: audit všech controllerů, žádný nevrací $e->getMessage() uživateli

### Modularita
- SettingsPage.tsx rozdělen na ProfileTab.tsx a CompanyTab.tsx (824→~290 řádků)
- useFormDialog.ts hook pro sdílenou logiku stavu formuláře/chyby/submit
- Fail-fast validace: controllery validují vstupy na začátku metod, 422 pro neplatný vstup

### Výkonnost
- Vite: minify 'esbuild', manual react-vendor chunk, React.lazy route splitting, Rollup ES moduly
- PHP-FPM: pm=dynamic, max_children=10, start_servers=4, min_spare=2, max_spare=6
- PDO: ATTR_PERSISTENT=true v config/database.php
- Xdebug 3.5.0: start_with_request=trigger (trigger-based, ne každý request), módy debug,develop,coverage,trace
- JSON payload: clients ~494 B, projects ~451 B; SELECT * ponechán kvůli frontend kontraktu

## Vlna 9 — Cron, constants, CSP, prefetch, optimistic updates

### Bezpečnost
- Cron DB zálohy: denní v 2:00 přes /etc/cron.d/devapppro-cleanup (cli/backup-db.php, retence 30 dní, mode 600)
- CSP strict: style-src 'self' (bez unsafe-inline); 2 inline styly nahrazeny CSS proměnnou --progress + .progress-bar třídou
- Secrets fail-fast: config/config.php vrací HTTP 500 pro web requesty bez env proměnných; CLI skripty fungují (jen varují)

### Modularita
- Constants: DEFAULT_PER_PAGE=50, MAX_PER_PAGE=100 (PHP i frontend)
- 8 controllerů + UserRepository používá Constants::DEFAULT_PER_PAGE/MAX_PER_PAGE
- 3 controllery používají Constants::*_STATUSES (PROJECT/TASK/INVOICE)
- 6 frontend stránek používá DEFAULT_PER_PAGE z @/lib/constants

### Výkonnost
- Prefetch: prefetch="intent" na všech NavLink v Sidebar.tsx (React Router 7)
- Optimistic updates: 13 delete hooků s onMutate (okamžitá změna cache) → onError (rollback) → onSettled (invalidate)
- Hooks: useDeleteTask, useDeleteProject, useDeleteClient, useDeleteNote, useDeleteWorklog, useDeleteFile, useDeleteTransaction, useDeleteInvoice, useDeleteInvoicePayment, useDeleteCredential, useDeleteBackup, useDeleteRestore, useDeleteWpInstall

## Automatická regenerace SSL + vhostů

### Kdy se spouští
- Při vytvoření projektu s `folder_path` (POST /api/projects)
- Při úpravě projektu, kdy se `folder_path` změní nebo nastaví (PUT /api/projects/{id})
- Při odebrání `folder_path` z projektu

### Jak to funguje
1. ProjectApiController vytvoří job v `project_hosting_jobs` tabulce (status=pending)
2. systemd timer `devapppro-hosting.timer` (každých 5s) spouští `devapppro-hosting.service`
3. Worker `cli/regenerate-project-hosting.php --process-pending` (jako root):
   - regenerating_ssl: mkcert s všechny doménami aktivních projektů s folder_path
   - generating_vhosts: `cli/generate-vhosts.php` vygeneruje per-project vhosty
   - reloading: `apache2ctl configtest` + `systemctl reload apache2`
4. Frontend `useHostingJobs()` hook polluje stav jobů (refetchInterval 2s když jsou aktivní)
5. ProjectsPage zobrazuje badge "Generuji SSL" a zakáže tlačítko náhledu během regenerace

### DB tabulka
- `project_hosting_jobs`: id, project_id, action (create/update/remove), folder_path, status (pending→regenerating_ssl→generating_vhosts→reloading→completed/failed), error_message

### Systemd
- Timer: /etc/systemd/system/devapppro-hosting.timer (enabled, OnUnitActiveSec=5)
- Service: /etc/systemd/system/devapppro-hosting.service (User=root, oneshot)
- Log: /var/log/devapppro-hosting.log

### API
- GET /api/projects/hosting-jobs — stav posledních 20 hosting jobů (pro frontend polling)

### Frontend
- useHostingJobs() hook v useProjects.ts (polling 2s při aktivních jobech)
- ProjectsPage: badge "Generuji SSL (N)" v hlavičce, zakázané tlačítko náhledu při aktivním jobu
- ActionButtons: podpora `disabled` prop

## Oprava vhost routing (2026-09-14)

### Problém
- Per-project vhosty používaly `<VirtualHost *:80>` a `*:443`
- Hlavní vhost Dev App Pro používá `127.0.0.1:80` a `127.0.0.1:443`
- Apache preferuje konkrétní IP před wildcard, takže se Dev App Pro vhost použil pro všechny domény
- Následek: statické projekty přesměrovány na `/login` (React SPA)

### Oprava
- `cli/generate-vhosts.php`: `*:80` → `127.0.0.1:80`, `*:443` → `127.0.0.1:443`
- `/etc/apache2/sites-available/wildcard-localhost.conf`: stejná oprava
- `DirectoryIndex`: pro statické projekty `index.html index.php`, pro WordPress `index.php index.html`
- `type` z DB se používá pro určení pořadí DirectoryIndex

## Auto-login WordPress - oprava 2026-09-14

### Problém
- WP projekty byly restore ze záloh, ne instalovány přes install-wordpress.php
- MU plugin pro auto-login chyběl ve všech WP instalacích
- DEVAPPPRO_SECRET chyběl ve všech wp-config.php
- Auto-login nemohl fungovat

### Oprava
- MU plugin (`cli/wp-mu-plugin.php`) zkopírován do `wp-content/mu-plugins/devapppro-autologin.php` pro 7 aktivních WP projektů
- `define('DEVAPPPRO_SECRET', '...')` přidáno do `wp-config.php` před `require_once ABSPATH` pro 7 aktivních WP projektů
- Klokner (id=34, archivovaný) vynechán
- Zálohy wp-config.php vytvořeny (.bak.{timestamp})

### Ověření
- detectWpAdminUser: funguje pro všech 7 projektů (čte admina z WP DB)
- Auto-login: 302 redirect na wp-admin pro všech 7 projektů
- WP cookies: 3 cookies (logged_in, sec, sec) po přihlášení pro všech 7 projektů

### WP projekty a admin uživatelé
- gympark (id=31): Jan Rohlik
- heartofaristocrat (id=32): Andrea
- hemska (id=33): miroslavbartik
- klokner-novy (id=35): bartimir
- obereggen-anna (id=36): admin4632
- skloprozeny (id=37): admin8328
- zakladniskola (id=38): urona
- klokner (id=34): archivovaný (vynechán)

### Druhá oprava - .htaccess a WP_HOME/WP_SITEURL

**Problém**: Všechny WP projekty měly `.htaccess` nastavený pro subdirectory instalaci:
- `RewriteBase /gympark/` místo `RewriteBase /`
- `RewriteRule . /gympark/index.php [L]` místo `RewriteRule . /index.php [L]`

To způsobovalo, že WordPress přesměrovával na neexistující cesty a auto-login končil na `wp-login.php?reauth=1`.

**zakladniskola** měl navíc `WP_HOME`/`WP_SITEURL` v `wp-config.php` nastavené na `https://localhost/zakladniskola` místo `https://zakladniskola.localhost`.

**Oprava**:
- `.htaccess`: `RewriteBase /` + `RewriteRule . /index.php [L]` pro všech 7 projektů
- `zakladniskola/wp-config.php`: `WP_HOME`/`WP_SITEURL` → `https://zakladniskola.localhost`
- Zálohy vytvořeny

**Ověření**: Všech 7 projektů - 302 na wp-admin, 200 OK, WP cookies, dashboard načten.
