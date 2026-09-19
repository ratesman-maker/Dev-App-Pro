# Kontrolní checklisty

Kontrolní seznamy pro implementaci podle tří priorit: Bezpečnost, Modularita, Rychlost.
Každou položku odškrtnout až po reálném ověření (ne jen syntax check).

---

## 1. Bezpečnost (priorita 1)

### 1.1 Autentizace

- [ ] Login přes `POST /api/auth/login` s `username` + `password`
- [ ] Hesla hashována bcrypt (cost 12) přes `password_hash()`
- [ ] Verifikace přes `password_verify()` (načasování konstantní)
- [ ] Po úspěšném loginu: `session_regenerate_id(true)`
- [ ] Po neúspěšném loginu: generická zpráva "Neplatné přihlašovací údaje"
- [ ] Žádné "remember me" tlačítko
- [ ] `session.cookie_lifetime = 0` (expiruje při zavření prohlížeče)
- [ ] Logout: `session_destroy()` + smazání cookie + `session_regenerate_id(true)`

### 1.2 Reset hesla

- [ ] `POST /api/auth/password-hint` - vrátí hint pro username (nepřihlášený)
- [ ] `POST /api/auth/reset-password` - reset hesla (nepřihlášený)
- [ ] `PUT /api/users/me/password-hint` - nastavení hintu (přihlášený)
- [ ] Minimální délka hesla: 8 znaků
- [ ] Kontrola shody `new_password` a `new_password_confirm`
- [ ] Po resetu: zničit všechny sessions uživatele
- [ ] Po resetu: nutné přihlášení (žádné auto-login)
- [ ] Rate limit: 5 pokusů/hodinu na IP (reset i hint)

### 1.3 Session hardening

- [ ] `session.save_handler = files` (filesystem, ne DB)
- [ ] `session.cookie_httponly = 1`
- [ ] `session.cookie_samesite = Lax`
- [ ] `session.use_strict_mode = 1`
- [ ] `session.use_only_cookies = 1`
- [ ] `session.gc_maxlifetime = 7200`
- [ ] `session.cookie_lifetime = 0`
- [ ] Session krádež detekce: kontrola IP + User-Agent přes `$_SESSION`

### 1.4 Rate limiting

- [ ] `login_attempts` tabulka pro rate limiting
- [ ] 5 neúspěšných pokusů za hodinu na IP → blok
- [ ] 5 neúspěšných pokusů za hodinu na username → blok
- [ ] Rate limit platí i pro reset hesla a hint endpoint
- [ ] Cron mazání starých `login_attempts` (24h)

### 1.5 CSRF ochrana

- [ ] CSRF token generován při loginu, uložen v `$_SESSION`
- [ ] Token v hlavičce `X-CSRF-Token` pro každý POST/PUT/DELETE
- [ ] Validace tokenu na serveru pro každý mutační request
- [ ] Token rotován při loginu

### 1.6 XSS ochrana

- [ ] Veškerý výstup do HTML escapován (`htmlspecialchars` v PHP)
- [ ] React default escapuje JSX (`{value}` bez `dangerouslySetInnerHTML`)
- [ ] `Content-Security-Policy` hlavička nastavena
- [ ] Žádné `eval()`, `new Function()`, `innerHTML` s uživatelským vstupem

### 1.7 SQL Injection

- [ ] Všechny DB dotazy přes PDO prepared statements
- [ ] Žádné string concatenation pro SQL
- [ ] Žádné `query()` s uživatelským vstupem
- [ ] Bind parametrů typově (`PDO::PARAM_INT`, `PDO::PARAM_STR`)

### 1.8 Bezpečnostní hlavičky

- [ ] `X-Content-Type-Options: nosniff`
- [ ] `X-Frame-Options: DENY`
- [ ] `Referrer-Policy: strict-origin-when-cross-origin`
- [ ] `Content-Security-Policy` (strict, bez unsafe-inline)
- [ ] `X-XSS-Protection: 1; mode=block`
- [ ] `Permissions-Policy` (restrictive)

### 1.9 Validace vstupu

- [ ] Všechny API vstupy validovány (typ, délka, formát)
- [ ] Fail-fast: 422 pro neplatný vstup
- [ ] JSON body max velikost (viz limity vstupu)
- [ ] Upload validace: MIME typ, velikost, extension
- [ ] IČO: 8 číslic (CZ)
- [ ] DIČ: `CZ` + 8-10 číslic
- [ ] Email: platný formát
- [ ] IBAN: validní formát

### 1.10 Konfigurace a tajemství

- [ ] `config.php` mimo webroot (nebo `.htaccess` Deny)
- [ ] DB heslo v `config.php`, ne v kódu
- [ ] `APP_DEBUG = false` v produkci
- [ ] Žádné secrets v gitu (`.gitignore` pro `config.php`)
- [ ] Žádné hardcoded hesla v kódu

### 1.11 Error handling

- [ ] Produkce: `display_errors = Off`
- [ ] Produkce: `log_errors = On`
- [ ] Generické chybové zprávy pro uživatele (ne stack trace)
- [ ] Logy do `/var/log/devapppro/`
- [ ] 500 stránka bez detailů

### 1.12 CORS

- [ ] `Access-Control-Allow-Origin: http://127.0.0.1` (jen localhost)
- [ ] Povolené metody: GET, POST, PUT, DELETE
- [ ] Povolené hlavičky: Content-Type, X-CSRF-Token
- [ ] Credentials: true (pro cookies)

### 1.13 Souborové oprávnění

- [ ] `/var/www/devapppro/` - `755`, vlastník `www-data`
- [ ] `config.php` - `640` (čitelný jen pro www-data)
- [ ] `storage/` - `750`, vlastník `www-data`
- [ ] `assets/dist/` - `755`, čitelný pro všechny
- [ ] `api/`, `src/` - `755`, vlastník `www-data`
- [ ] Logy - `640`, vlastník `www-data`

### 1.14 Apache bind na 127.0.0.1

- [ ] Apache vhost `Listen 127.0.0.1:80`
- [ ] Žádné `Listen 80` (jen 127.0.0.1)
- [ ] `<Directory>` s `Require local`
- [ ] Přístup jen z localhost

### 1.15 Sanitizace logů

- [ ] Nikdy nelogovat hesla (ani hash)
- [ ] Nikdy nelogovat celé SQL dotazy
- [ ] Nikdy nelogovat emaily, telefony, adresy klientů
- [ ] Nikdy nelogovat finanční částky
- [ ] Nikdy nelogovat obsah poznámek nebo souborů
- [ ] Error log: generická zpráva + timestamp + kód chyby
- [ ] Security log: IP + username + výsledek (ne heslo)

### 1.16 Zabezpečení záloh

- [ ] Zálohy DB v `BACKUP_DIR` (mimo webroot)
- [ ] Zálohy `600` oprávnění (jen vlastník)
- [ ] `BACKUP_RETENTION_DAYS = 30` (staré mazat)
- [ ] Zálohy neobsahují secrets v plaintextu

### 1.17 Ochrana proti directory traversal

- [ ] `realpath()` validace pro všechny cesty
- [ ] Žádné `..` v cestách
- [ ] Upload cesty validovány
- [ ] Download endpoint kontroluje `realpath()` před odesláním
- [ ] Symlinky zakázány (`is_link()` check)

### 1.18 Limity velikosti vstupu

- [ ] `post_max_size = 10M`
- [ ] `upload_max_filesize = 10M`
- [ ] JSON body max 1MB pro API
- [ ] Pole max 100 položek
- [ ] String max 500 znaků (kromě textových polí)
- [ ] Textová pole (description, note, content) max 65535 znaků

### 1.19 Sanitizace chybových zpráv

- [ ] Generické zprávy ("Něco se pokazilo", ne "SQL error in /var/www/...")
- [ ] Kódy chyb (400, 401, 403, 404, 422, 500)
- [ ] Žádné cesty v chybových zprávách
- [ ] Žádné DB struktury v chybových zprávách

### 1.20 Upload ochrana

- [ ] Povolené typy: PDF, PNG, WebP, JPG, DOCX, XLSX, ZIP
- [ ] Zakázané: EXE, SH, BAT, PHP, JS, HTML
- [ ] Max velikost 10MB
- [ ] MIME kontrolá (ne jen extension)
- [ ] Soubory mimo webroot (`storage/`)
- [ ] Apache blokuje přímý přístup k `storage/`
- [ ] Download přes PHP endpoint s autorizací
- [ ] JPG konvertován na WebP při uložení

---

## 2. Modularita (priorita 2)

### 2.1 Vrstvená architektura

- [ ] Frontend (React SPA) nezná PHP
- [ ] API (PHP endpoints) nezná frontend
- [ ] Doména (src/) nezná API ani frontend
- [ ] Repository nezná Controller
- [ ] Controller nezná Repository přímo (přes DI)
- [ ] Žádné kruhové závislosti

### 2.2 Backend moduly (PHP)

- [ ] PSR-4 autoloading (Composer)
- [ ] Repository pattern (ClientRepository, ProjectRepository, ...)
- [ ] Controller abstrakce (Core/ApiController)
- [ ] Repository abstrakce (Core/Repository)
- [ ] Dependency injection (ne statické volání)
- [ ] `src/helpers.php` pro sdílené funkce
- [ ] `src/Core/` pro základní třídy

### 2.3 Frontend moduly (React)

- [ ] `components/ui/` - shadcn/ui komponenty
- [ ] `components/layout/` - AppShell, Sidebar, Topbar
- [ ] `components/{module}/` - specifické (clients, projects, tasks, ...)
- [ ] `components/shared/` - sdílené (DataTable, EmptyState, ErrorState)
- [ ] `hooks/` - useAuth, useApi, useClients, useProjects, ...
- [ ] `lib/` - api.ts, utils.ts, constants.ts
- [ ] `types/` - TypeScript typy per modul
- [ ] `pages/` - page komponenty (lazy loaded)

### 2.4 Pravidla modularity

- [ ] **Max 500 řádků na soubor** (520 tolerováno, 530 ne)
- [ ] **Max 4 úrovně zanoření logiky** (if, for, while, try/catch, callback)
- [ ] JSX zanoření se nepočítá (jen logika)
- [ ] **Rule of Three** - extrahovat při 3. výskytu stejné logiky
- [ ] Žádné duplikace sdílených funkcí
- [ ] Sdílené backend funkce v `src/helpers.php` nebo `src/Core/`
- [ ] Sdílené frontend funkce v `lib/utils.ts` nebo `hooks/`
- [ ] High cohesion, low coupling
- [ ] Jedna zodpovědnost na třídu/komponentu
- [ ] Kompozice nad dědičností
- [ ] Žádné magické hodnoty (konstanty/enum)
- [ ] Fail-fast validace
- [ ] Explicitní chování nad implicitním
- [ ] Dodržovat konvence pojmenování
- [ ] Separation of concerns
- [ ] Pure funkce kde možno
- [ ] Stabilní rozhraní a závislosti

### 2.5 Konfigurace modulů

- [ ] `config/config.php` - aplikace
- [ ] `config/database.php` - DB připojení
- [ ] Konstanty v `src/Core/Constants.php` (statusy, enum)
- [ ] Frontend konstanty v `lib/constants.ts`
- [ ] Žádné hardcoded hodnoty v kódu

---

## 3. Rychlost (priorita 3)

### 3.1 Frontend rychlost

- [ ] Vite produkční build (esbuild minifikace)
- [ ] Tree shaking (žádný nepoužitý kód)
- [ ] Route-based code splitting (lazy importy)
- [ ] Lokální WOFF2 fonty (žádné Google Fonts)
- [ ] Tailwind CSS purge (jen použité třídy)
- [ ] Hashed assety s dlouhou cache (`app.[hash].js`)
- [ ] Žádné CDN, žádné externí zdroje
- [ ] Bundle analýza (`vite-bundle-visualizer`)

### 3.2 Backend rychlost

- [ ] PHP OPcache zapnutý
- [ ] `opcache.memory_consumption = 128`
- [ ] `opcache.max_accelerated_files = 10000`
- [ ] PHP-FPM tuning (pm = dynamic, pm.max_children)
- [ ] PDO persistent connections (volitelné)

### 3.3 API rychlost

- [ ] **`SELECT *` zakázáno pro seznamy** (jen výčet sloupců)
- [ ] `SELECT *` OK pro detail (jeden záznam)
- [ ] Paginace (LIMIT/OFFSET) pro všechny seznamy
- [ ] Indexy na každém WHERE/ORDER BY
- [ ] Žádné N+1 dotazy (JOIN místo smyčky)
- [ ] Minimalizovaný JSON payload
- [ ] Gzip/DEFLATE komprese pro textové odpovědi

### 3.4 React render rychlost

- [ ] `React.memo` pro komponenty s častým re-renderem
- [ ] `useCallback` pro handlery předávané dětem
- [ ] `useMemo` pro drahé výpočty
- [ ] Stabilní keys v seznamech (ne index)
- [ ] Virtualizace dlouhých seznamů (>100 položek)
- [ ] Debounced vyhledávání (300ms)

### 3.5 Stale-while-revalidate

- [ ] TanStack Query s `staleTime` a `gcTime`
- [ ] Stará data hned, nová na pozadí
- [ ] Background refetch na focus (volitelné)

### 3.6 Skeleton loading

- [ ] Skeleton komponenty pro DataTable
- [ ] Skeleton pro detail stránky
- [ ] Skeleton pro formuláře
- [ ] Žádné prázdné obrazovky během načítání

### 3.7 Prefetching

- [ ] Prefetch routy **jen při hover** na odkaz v sidebaru
- [ ] Žádný prefetch všech rout po prvním paintu
- [ ] Import chunku na hover, ne na click

### 3.8 Optimistic updates

- [ ] Optimistic update **jen pro delete a update**
- [ ] Pro create: čekat na server response (reálné ID)
- [ ] Rollback při chybě

### 3.9 Optimalizace obrázků

- [ ] Thumbnail 200x200 WebP generován při uploadu
- [ ] Medium 800x800 WebP generován při uploadu
- [ ] JPG konvertován na WebP při uložení
- [ ] Povolené vstupní: PNG, WebP, JPG
- [ ] Uložené: PNG, WebP (JPG konvertován)
- [ ] `loading="lazy"` pro obrázky mimo viewport
- [ ] `width`/`height` atributy (zamezení layout shift)

### 3.10 Preload kritických resource

- [ ] Preload fontů (Inter, JetBrains Mono)
- [ ] Preload hlavního CSS
- [ ] Preload kritického JS

### 3.11 MySQL konfigurace

- [ ] `innodb_buffer_pool_size` (50-70% RAM)
- [ ] `innodb_log_file_size` dostatečné
- [ ] `query_cache_size = 0` (vypnuto, InnoDB buffer pool)
- [ ] `innodb_flush_log_at_trx_commit = 2` (rychlejší, přijatelné riziko)

### 3.12 Měření rychlosti

- [ ] Lighthouse audit (frontend)
- [ ] MySQL EXPLAIN pro pomalé dotazy
- [ ] PHP profiling (Xdebug, volitelně)
- [ ] Bundle analýza po buildu

### 3.13 Pravidla rychlosti (18 bodů)

- [ ] 1. Lokální assety (žádné CDN)
- [ ] 2. Minimální bundle (tree-shaking, code splitting, purge)
- [ ] 3. Indexy (každý WHERE/ORDER BY)
- [ ] 4. Žádné N+1 (JOIN)
- [ ] 5. Paginace (žádné 1000 záznamů najednou)
- [ ] 6. Cache (statické assety dlouhá cache, dynamická bez cache)
- [ ] 7. Komprese (gzip pro text)
- [ ] 8. OPcache (PHP bytecache)
- [ ] 9. Lazy loading (stránky a komponenty)
- [ ] 10. Debounce (vyhledávání, API volání)
- [ ] 11. SELECT jen potřebných sloupců (`SELECT *` zakázáno pro seznamy, OK pro detail)
- [ ] 12. Optimistic updates (jen delete/update, ne create)
- [ ] 13. Stale-while-revalidate (stará hned, nová na pozadí)
- [ ] 14. Skeleton loading (šedé tvary)
- [ ] 15. Prefetching (jen hover, ne všechny routy)
- [ ] 16. Preload kritických resource (fonty, CSS)
- [ ] 17. Lazy obrázky (`loading="lazy"`)
- [ ] 18. Stabilní reference (keys, memo, useCallback)

---

## 4. Funkční checklist (per modul)

### 4.1 Klienti

- [ ] CRUD (nový, upravit, smazat, zobrazit)
- [ ] Typ: Osoba (first_name, last_name) / Firma (company_name, ico, dic, bank_account)
- [ ] Zobrazování: `first_name last_name` nebo `company_name`
- [ ] Řazení podle `last_name, first_name` (osoby) nebo `company_name` (firmy)
- [ ] Validace IČO (8 číslic), DIČ (CZ + 8-10 číslic)
- [ ] Český formát: datum `26.3.2026`, měna `1 500 Kč`
- [ ] Dropdown akce: Zobrazit, Upravit, Smazat (confirm)
- [ ] Detail: projekty, faktury, transakce

### 4.2 Projekty

- [ ] CRUD + archivace + obnova
- [ ] Auto-sync ze složek (cron každou minutu)
- [ ] `PROJECTS_WATCH_DIR` v config
- [ ] Nová složka → projekt (status: active, folder_path)
- [ ] Smazaná složka → status: archived (data zůstanou)
- [ ] Přejmenování → starý archived, nový vytvořen
- [ ] Skryté složky (`.`) ignorovány
- [ ] Symlinky zakázány
- [ ] Status: active, on_hold, completed, cancelled, archived
- [ ] Badge "Ze složky" pro projekty s `folder_path`
- [ ] Dropdown akce: Zobrazit, Upravit, Archivovat, Obnovit, Smazat
- [ ] `POST /api/projects/{id}/archive`
- [ ] `POST /api/projects/{id}/restore`

### 4.3 Úkoly

- [ ] CRUD (nový, upravit, smazat, zobrazit)
- [ ] `project_id` NOT NULL (úkol musí mít projekt)
- [ ] Status: todo, in_progress, done, cancelled
- [ ] Priorita: low, medium, high, urgent
- [ ] `estimated_minutes` (odhadovaný čas)
- [ ] `spent_minutes` (strávený čas)
- [ ] Zobrazení času: `4h 0m` (fmtMinutes)
- [ ] Inline editace statusu
- [ ] Dropdown akce: Zobrazit, Upravit, Smazat

### 4.4 Faktury

- [ ] CRUD + PDF generování (mPDF)
- [ ] `subtotal_cents` (bez DPH)
- [ ] `vat_rate_percent` (sazba DPH, výchozí 21%)
- [ ] `vat_amount_cents` (vypočítáno)
- [ ] `amount_cents` (celková s DPH)
- [ ] `variable_symbol`, `constant_symbol`, `iban`
- [ ] Číslování: `{year}{seq:03d}` (2026001)
- [ ] Reset sekvence při změně roku
- [ ] Výchozí splatnost: 14 dní
- [ ] Status: draft, sent, paid, overdue, cancelled
- [ ] `paid_cents` přepočítáno při smazání platby
- [ ] PDF: údaje prodávajícího z `company_profile`
- [ ] Dropdown akce: Zobrazit, Upravit, Smazat (kaskáda s platbami)
- [ ] `GET /api/invoices/{id}/pdf` (mPDF)

### 4.5 Platby faktur

- [ ] CRUD (nový, upravit, smazat)
- [ ] `amount_cents`, `payment_date`, `method`
- [ ] Metoda: cash, bank_transfer, card, other
- [ ] Po smazání: přepočet `invoices.paid_cents`
- [ ] Dropdown akce: Upravit, Smazat (confirm, přepočet)

### 4.6 Transakce

- [ ] CRUD (nový, upravit, smazat, zobrazit)
- [ ] Typ: income, expense
- [ ] Kategorie: office, software, travel, marketing, hardware, services, income_project, income_consulting, other
- [ ] `amount_cents`, `description`, `transaction_date`
- [ ] Vazba na projekt i klienta (obojjí nullable)
- [ ] Filtr: typ, kategorie, datum (from/to)
- [ ] Dropdown akce: Zobrazit, Upravit, Smazat

### 4.7 Poznámky

- [ ] CRUD (nový, upravit, smazat, zobrazit)
- [ ] Polymorfní vazby (noteables): client, project, task, invoice
- [ ] Multi-select entit v dialogu
- [ ] `title` (volitelný), `content` (povinný)
- [ ] `user_id` (kdo vytvořil)
- [ ] Zobrazení vazeb jako badge (ikona + název)
- [ ] Dropdown akce: Zobrazit (modal), Upravit, Smazat

### 4.8 Soubory

- [ ] Upload (drag & drop, multi-upload)
- [ ] `is_image` příznak (PNG, WebP, JPG)
- [ ] `thumbnail_path` (200x200 WebP)
- [ ] `medium_path` (800x800 WebP)
- [ ] JPG konvertován na WebP při uložení
- [ ] Stahování přes PHP endpoint (autorizace)
- [ ] Polymorfní vazby (fileables): client, project, task, invoice
- [ ] Grid náhledů pro obrázky, ikony pro ne-obrázky
- [ ] Smazání: DB záznam + fyzický soubor + thumbnail + medium
- [ ] Dropdown akce: Stáhnout, Upravit, Smazat (confirm, fyzický soubor)
- [ ] `storage/YYYY/MM/uuid.ext` struktura
- [ ] Apache blokuje přímý přístup k `storage/`

### 4.9 Dashboard

- [ ] 4 KPI karty (Klienti, Projekty, Aktivní projekty, Úkoly)
- [ ] Finanční přehled (zaplaceno, otevřené, po splatnosti)
- [ ] Stav úkolů (progress bary)
- [ ] Projekty dle statusu
- [ ] Rychlé akce
- [ ] Poslední projekty (seznam)

### 4.10 Nastavení

- [ ] **Profil:** jméno, email, změna hesla, password_hint, preference
- [ ] **Firma:** company_profile (prodávající pro faktury)
- [ ] **Aplikace:** settings (DPH, splatnost, formát faktur, měna, timezone)
- [ ] **Vzhled:** téma (světlý/tmavý), sidebar, per_page
- [ ] `GET/PUT /api/settings`
- [ ] `GET/PUT /api/company-profile`
- [ ] `GET/PUT /api/users/me/preferences`

### 4.11 Login + Reset

- [ ] Centrovaný formulář
- [ ] Logo + nadpis "Dev App Pro"
- [ ] Přepínač světlý/tmavý režim (ikona v rohu)
- [ ] Odkaz "Zapomněli jste heslo?"
- [ ] Reset: Krok 1 (username → hint), Krok 2 (nové heslo)
- [ ] Po úspěchu: redirect na Dashboard

### 4.12 Lokalizace

- [ ] Datum: `26.3.2026` (fmtDate)
- [ ] Datum + čas: `26.3.2026 14:30` (fmtDateTime)
- [ ] Měna: `1 500 Kč` (fmtMoney, bez desetinných míst)
- [ ] Čas: `4h 0m` (fmtMinutes)
- [ ] Jméno klienta: `first_name last_name` nebo `company_name`
- [ ] Timezone: Europe/Prague
- [ ] První den týdne: pondělí
- [ ] Fiskální rok: kalendářní (01-01)

### 4.13 Téma

- [ ] Výchozí: tmavý
- [ ] `localStorage('devapppro-theme')` (před i po přihlášení)
- [ ] Po přihlášení: synchronizace s `users.theme` z DB
- [ ] Přepínač v Topbaru (ikona slunce/měsíce)
- [ ] Přepínač na Login stránce
- [ ] Po přepnutí: UI okamžitě, localStorage okamžitě, DB asynchronně
- [ ] Žádné sledování systémové preference

### 4.14 Sidebar

- [ ] 8 položek: Dashboard, Klienti, Projekty, Úkoly, Finance, Poznámky, Soubory, Nastavení
- [ ] Collapsible (ikony-only when collapsed)
- [ ] Výchozí: rozbalený (`sidebar_collapsed = 0`)
- [ ] Preference uložena v `users.sidebar_collapsed`
- [ ] Prefetch routy při hover na odkaz

---

## 5. Infrastruktura

### 5.1 Apache

- [ ] Bind na `127.0.0.1:80`
- [ ] `<Directory>` s `Require local`
- [ ] `.htaccess` blokuje `storage/`, `config/`, `src/`
- [ ] Mod rewrite pro SPA (fallback na `index.html`)
- [ ] Gzip/DEFLATE komprese

### 5.2 PHP

- [ ] PHP 8.3+
- [ ] `session.save_handler = files`
- [ ] `session.cookie_httponly = 1`
- [ ] `session.cookie_samesite = Lax`
- [ ] `session.use_strict_mode = 1`
- [ ] `session.use_only_cookies = 1`
- [ ] `session.gc_maxlifetime = 7200`
- [ ] `session.cookie_lifetime = 0`
- [ ] `opcache.enable = 1`
- [ ] `display_errors = Off` (produkce)
- [ ] `log_errors = On`
- [ ] `post_max_size = 10M`
- [ ] `upload_max_filesize = 10M`
- [ ] `date.timezone = Europe/Prague`

### 5.3 MySQL/MariaDB

- [ ] InnoDB engine
- [ ] `innodb_buffer_pool_size` (50-70% RAM)
- [ ] UTF-8mb4 charset
- [ ] 14 tabulek (users, company_profile, settings, login_attempts, clients, projects, tasks, invoices, invoice_payments, transactions, notes, noteables, files, fileables)
- [ ] Indexy na všech WHERE/ORDER BY
- [ ] Cizí klíče s ON DELETE CASCADE / SET NULL

### 5.4 Cron

- [ ] Auto-sync projektů (každou minutu): `php cli/sync-projects.php`
- [ ] Mazání starých login_attempts (denně)
- [ ] Mazání osiřelých souborů v storage/ (týdně)
- [ ] PHP session GC automaticky (gc_maxlifetime)

### 5.5 Zálohy

- [ ] `BACKUP_DIR` mimo webroot
- [ ] Zálohy `600` oprávnění
- [ ] `BACKUP_RETENTION_DAYS = 30`
- [ ] Cron pro zálohy DB

### 5.6 PDF

- [ ] mPDF přes Composer (`composer require mpdf/mpdf`)
- [ ] `InvoicePdfService.php` v `src/Services/`
- [ ] `GET /api/invoices/{id}/pdf`
- [ ] PDF obsahuje: prodávající, kupující, položky, DPH, VS, KS, IBAN, datumy

---

## 6. Dokumentace

- [ ] `00-overview.md` - přehled, zásady, moduly
- [ ] `01-security.md` - 19 sekcí bezpečnosti
- [ ] `02-modularity.md` - pravidla modularity
- [ ] `03-performance.md` - 17 sekcí rychlosti
- [ ] `04-architecture.md` - architektura, adresáře, bootstrap
- [ ] `05-database.md` - 14 tabulek, konvence, údržba
- [ ] `06-api.md` - 12 sekcí API endpointů
- [ ] `07-frontend.md` - stránky, hooks, lokalizace, téma
- [ ] `08-deployment.md` - konfigurace, cron, zálohy
- [ ] `09-checklisty.md` - tento soubor
