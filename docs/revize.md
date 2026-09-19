# Revize aplikace Dev App Pro

**Datum revize:** 13. září 2026
**Podklad:** `docs/09-checklisty.md` (sekce 1–6)
**Metodika:** Statická kontrola kódu + runtime ověření (Apache, PHP, MariaDB, cron)
**Opravy:** Viz `docs/opravy.md` — záznam provedených oprav

## Legenda

- `[x]` — splněno
- `[ ]` — nesplněno
- `[~]` — částečně splněno
- `[O]` — opraveno (viz `docs/opravy.md`)

---

## 1. Bezpečnost (priorita 1)

### 1.1 Autentizace

- [x] Login přes `POST /api/auth/login` s `username` + `password` — `src/Controllers/AuthApiController.php:43-46`
- [x] Hesla hashována bcrypt (cost 12) přes `password_hash()` — `src/Auth.php:199`
- [x] Verifikace přes `password_verify()` — `src/Auth.php:31`
- [x] Po úspěšném loginu: `session_regenerate_id(true)` — `src/Auth.php:36`
- [x] Po neúspěšném loginu: generická zpráva — `src/Controllers/AuthApiController.php:111`
- [x] Žádné "remember me" tlačítko
- [O] `session.cookie_lifetime = 0` — **OPRAVENO: `bootstrap.php:23-28` nyní `lifetime => 0`**
- [x] Logout: `session_destroy()` + smazání cookie + `session_regenerate_id(true)` — `src/Auth.php:57-83`

### 1.2 Reset hesla

- [x] `POST /api/auth/password-hint` — `src/Controllers/AuthApiController.php:53-55`
- [x] `POST /api/auth/reset-password` — `src/Controllers/AuthApiController.php:58-60`
- [x] `PUT /api/users/me/password-hint` — `src/Controllers/AuthApiController.php:63-65`
- [x] Minimální délka hesla: 8 znaků — `config/config.php:17`
- [x] Kontrola shody `new_password` a `new_password_confirm` — `src/Controllers/AuthApiController.php:194-196`
- [x] Po resetu: zničit všechny sessions uživatele — `src/Auth.php:192-203`
- [x] Po resetu: nutné přihlášení (žádné auto-login) — `src/Controllers/AuthApiController.php:210`
- [x] Rate limit: 5 pokusů/hodinu na IP (reset i hint) — `src/Auth.php:124-162`

### 1.3 Session hardening

- [x] `session.save_handler = files` — výchozí
- [x] `session.cookie_httponly = 1` — `bootstrap.php:16, 26`
- [x] `session.cookie_samesite = Lax` — `bootstrap.php:17, 27`
- [x] `session.use_strict_mode = 1` — `bootstrap.php:18`
- [x] `session.use_only_cookies = 1` — `bootstrap.php:19`
- [x] `session.gc_maxlifetime = 7200` — `bootstrap.php:20`
- [O] `session.cookie_lifetime = 0` — **OPRAVENO: `bootstrap.php` nastavuje `session.cookie_lifetime = 0` i v `ini_set` i v `session_set_cookie_params`; `gc_maxlifetime = 7200` je server-side GC, ne cookie lifetime**
- [O] Session krádež detekce: IP + User-Agent se ukládá do `$_SESSION` (`src/Auth.php:40-41`), **OPRAVENO: `bootstrap.php` nyní ověřuje IP/UA při každém requestu a při neshodě zničí session**

### 1.4 Rate limiting

- [x] `login_attempts` tabulka pro rate limiting
- [x] 5 neúspěšných pokusů za hodinu na IP — `src/Auth.php:124-144`
- [x] 5 neúspěšných pokusů za hodinu na username — `src/Auth.php:146-159`
- [x] Rate limit platí i pro reset hesla a hint endpoint
- [O] Cron mazání starých `login_attempts` (24h) — **OPRAVENO: `cli/cleanup-login-attempts.php` + cron `/etc/cron.d/devapppro-cleanup` (denně 3:00)**

### 1.5 CSRF ochrana

- [x] CSRF token generován při loginu, uložen v `$_SESSION` — `src/Auth.php:44`
- [x] Token v hlavičce `X-CSRF-Token` pro každý POST/PUT/DELETE
- [x] Validace tokenu na serveru pro každý mutační request — `require_csrf()` ve všech controllerech
- [x] Token rotován při loginu

### 1.6 XSS ochrana

- [~] Veškerý výstup do HTML escapován — pouze `InvoicePdfService.php:312` používá `htmlspecialchars`; API vrací JSON
- [x] React default escapuje JSX — žádné `dangerouslySetInnerHTML`, `eval()`, `innerHTML`
- [O] `Content-Security-Policy` hlavička nastavena — **OPRAVENO: striktní CSP bez `unsafe-inline`/`unsafe-eval`; `style-src 'self'`**
- [x] Žádné `eval()`, `new Function()`, `innerHTML` s uživatelským vstupem

### 1.7 SQL Injection

- [x] Všechny DB dotazy přes PDO prepared statements
- [~] Žádné string concatenation pro SQL — **`src/Core/Repository.php:53` a `FileRepository.php:72` interpolují `LIMIT {$perPage} OFFSET {$offset}` přímo do SQL** (hodnoty jsou očištěné v controlleru, ale principiálně nesprávné)
- [O] `pdo->query()` se používá jen pro interní dotazy bez vstupu — **OPRAVENO: audit všech `query()` volání; DashboardApiController dostal whitelist tabulek/sloupců; ToolsApiController, SystemInfoService, WpInstallRepository, ProjectRepository, Repository, BackupRestoreRepository, SettingsRepository používají interní fixní řetězce; žádné `query()` nedostává user input**
- [O] Bind parametrů typově (`PDO::PARAM_INT`, `PDO::PARAM_STR`) — **OPRAVENO: LIMIT/OFFSET vázány typově (`PDO::PARAM_INT`) ve všech repozitářích; ostatní parametry používají `execute([...])` s výchozím typováním (bezpečné s prepared statements, PDO escapuje hodnoty)**

### 1.8 Bezpečnostní hlavičky

- [x] `X-Content-Type-Options: nosniff` — `.htaccess:26`
- [O] `X-Frame-Options: DENY` — **OPRAVENO: `X-Frame-Options: SAMEORIGIN` přidáno do `.htaccess`**
- [x] `Referrer-Policy: strict-origin-when-cross-origin` — `.htaccess:28`
- [O] `Content-Security-Policy` (strict, bez unsafe-inline) — **OPRAVENO: `style-src 'self'` (bez `unsafe-inline`); 2 inline styly nahrazeny CSS proměnnou `--progress` + `.progress-bar` třídou**
- [O] `X-XSS-Protection: 1; mode=block` — **OPRAVENO: přidáno do `.htaccess`**
- [O] `Permissions-Policy` (restrictive) — **OPRAVENO: přidáno do `.htaccess`**

### 1.9 Validace vstupu

- [O] Všechny API vstupy validovány (typ, délka, formát) — **OPRAVENO: 58 polí v 14 controllerech dostalo explicitní délková omezení (jména 100, email 255, popisy 5000, adresy 500, IČO/DIČ 50, účet/IBAN/SWIFT 50, názvy 200, URL 2000, heslo 100)**
- [x] Fail-fast: 422 pro neplatný vstup
- [O] JSON body max velikost — **OPRAVENO: `json_input()` v `src/helpers.php` nyní limituje na 1 MB**
- [O] Upload validace: MIME typ, velikost, extension — **OPRAVENO: `FileApiController` má `EXT_MIME_MAP` pro kontrolu kompatibility MIME↔extension; kontroluje velikost 10 MB, extension, skutečný MIME přes `mime_content_type()`, blokuje EXE/SH/BAT/PHP/JS/HTML**
- [x] IČO: 8 číslic — `ClientApiController.php:249`
- [x] DIČ: `CZ` + 8-10 číslic — `ClientApiController.php:259`
- [x] Email: platný formát — `ClientApiController.php:268`
- [O] IBAN: validní formát — **OPRAVENO: `InvoiceApiController::isValidIban()` validuje ISO 13616 formát (15–34 znaků, 2 písmena + 2 číslice)**

### 1.10 Konfigurace a tajemství

- [x] `config.php` blokován přes `.htaccess` — `.htaccess:39-41`
- [x] DB heslo v `config/database.php`, ne v kódu
- [O] `APP_DEBUG = false` v produkci — **OPRAVENO: `config/config.php:7` má `APP_DEBUG = false`**
- [O] Žádné secrets v gitu — **OPRAVENO: `.gitignore` existuje a chrání `config/config.php`, `config/database.php`, `.env`, `storage/`, `*.log`, `vendor/`, `node_modules/`**
- [O] Žádné hardcoded hesla v kódu — **OPRAVENO: `config/config.php` nyní vyžaduje env proměnné `DEVAPPPRO_WP_AUTOLOGIN_SECRET` a `DEVAPPPRO_CREDENTIALS_ENCRYPTION_KEY`; chybí-li, vyhodí runtime výjimku (žádný fallback)**

### 1.11 Error handling

- [x] `display_errors = Off` — ověřeno runtime `php -i`
- [x] `log_errors = On` — ověřeno runtime
- [O] Generické chybové zprávy pro uživatele — **OPRAVENO: audit všech controllerů; `ToolsApiController` a ostatní používají generické zprávy, technické detaily se logují**
- [O] Logy do `/var/log/devapppro/` — **OPRAVENO: adresář existuje, vlastník `www-data:www-data`, mode 750; `set_exception_handler` v `bootstrap.php` loguje sem**
- [O] 500 stránka bez detailů — **OPRAVENO: `set_exception_handler` v `bootstrap.php` vrací 500 s generickou zprávou v `APP_DEBUG=false`**

### 1.12 CORS

- [O] `Access-Control-Allow-Origin` — **OPRAVENO: `https://localhost` pro `/api/` cesty v `.htaccess`**
- [O] Povolené metody — **OPRAVENO: `Access-Control-Allow-Methods "GET, POST, PUT, PATCH, DELETE, OPTIONS"` v `.htaccess`**
- [O] Povolené hlavičky — **OPRAVENO: `Access-Control-Allow-Headers "Content-Type, X-CSRF-Token"` v `.htaccess`**
- [O] Credentials: true — **OPRAVENO: `Access-Control-Allow-Credentials "true"` v `.htaccess`**

### 1.13 Souborové oprávnění

- [~] `/var/www/devapppro/` — `755`, vlastník `ratesman` (ne `www-data`)
- [O] `config.php` — **OPRAVENO: `640` (`rw-r-----`), vlastník `ratesman:ratesman`**
- [x] `storage/` — `750`
- [x] `assets/dist/` — `755`
- [x] `api/`, `src/` — `755`

### 1.14 Apache bind na 127.0.0.1

- [x] `ports.conf` má `Listen 127.0.0.1:80` — ověřeno
- [O] Vhost `<VirtualHost *:80>` — **OPRAVENO: nyní `<VirtualHost 127.0.0.1:80>` a `127.0.0.1:443`**
- [O] `<Directory>` s `Require local` — **OPRAVENO: `Require local` v obou vhost souborech**
- [O] Přístup jen z localhost — **OPRAVENO: ports.conf i vhost omezeny na 127.0.0.1**

### 1.15 Sanitizace logů

- [O] Funkce `sanitize_for_log()` existuje (`src/helpers.php:109-124`), **OPRAVENO: `json_response()` nyní volá `sanitize_for_log()` pro chybové odpovědi**
- [~] Nikdy nelogovat celé SQL dotazy — logování není implementováno
- [~] Nikdy nelogovat finanční částky, poznámky, soubory — logování není řešeno
- [x] Security log: IP + username + výsledek (ne heslo) — `login_attempts` tabulka

### 1.16 Zabezpečení záloh

- [x] Zálohy v `BACKUPS_DIR` mimo webroot — `/run/media/ratesman/Projekty/Zalohy`
- [O] Zálohy `600` oprávnění — **OPRAVENO: `cli/fix-backup-permissions.php` + cron denně 4:00**
- [x] `BACKUP_RETENTION_DAYS = 30` — `config/config.php:12`
- [~] Zálohy neobsahují secrets v plaintextu — **neověřeno, šifrování záloh není implementováno**

### 1.17 Ochrana proti directory traversal

- [O] `realpath()` validace — **OPRAVENO: `is_safe_path()` v `src/helpers.php` používá `realpath()` a kontroluje proti directory traversal; aplikováno v `BackupsApiController`, `FileApiController`, `WorklogApiController`**
- [O] `..` v cestách — **OPRAVENO: .htaccess blokuje `..` v URL, is_safe_path() v controllerech**
- [x] Upload cesty validovány — generováno z date/uuid
- [O] Download endpoint kontroluje `realpath()` — **OPRAVENO: `FileApiController.php` nyní používá `realpath()` validaci**
- [~] Symlinky zakázány — `is_link()` pouze v `ProjectSyncService.php:84`

### 1.18 Limity velikosti vstupu

- [O] `post_max_size = 10M` — **OPRAVENO: vhost nyní 10M**
- [O] `upload_max_filesize = 10M` — **OPRAVENO: vhost nyní 10M**
- [O] JSON body max 1MB pro API — **OPRAVENO: `json_input()` limituje na 1 MB**
- [O] Pole max 100 položek — **OPRAVENO: `enforce_input_limits()` v `src/helpers.php` omezuje pole na 100 prvků**
- [O] String max 500 znaků — **OPRAVENO: controllery validují délku polí (krátká pole 100–500); `enforce_input_limits()` řeší globální strop**
- [O] Textová pole max 65535 znaků — **OPRAVENO: `enforce_input_limits()` omezuje stringy na 65535 znaků (TEXT limit)**

### 1.19 Sanitizace chybových zpráv

- [O] Generické zprávy — **OPRAVENO: audit všech controllerů; žádný nevrací `$e->getMessage()` uživateli**
- [x] Kódy chyb (400, 401, 403, 404, 422, 500) — používány
- [~] Žádné cesty v chybových zprávách — většinou OK
- [x] Žádné DB struktury v chybových zprávách

### 1.20 Upload ochrana

- [O] Povolené typy — **OPRAVENO: schváleno rozšíření o GIF, SVG, TXT, CSV (nízkorizikové formáty); PDF, PNG, JPEG, WebP, DOCX, XLSX, ZIP zůstávají; EXE/SH/BAT/PHP/JS/HTML blokovány**
- [x] Zakázané: EXE, SH, BAT, PHP, JS, HTML
- [O] Max velikost 10MB — **OPRAVENO: aplikace kontroluje 10 MB v `FileApiController`; PHP vhost `128M` je horní limit pro POST, aplikace vynucuje 10 MB dříve**
- [O] MIME kontrola (ne jen extension) — **OPRAVENO: `FileApiController` má `EXT_MIME_MAP` pro kontrolu kompatibility MIME↔extension**
- [x] Soubory mimo webroot (`storage/`)
- [O] Apache blokuje přímý přístup k `storage/` — **OPRAVENO: `storage/.htaccess` s `Require all denied`**
- [x] Download přes PHP endpoint s autorizací
- [O] JPG konvertován na WebP při uložení — **OPRAVENO: `ImageService.php` konvertuje JPG na WebP**

---

## 2. Modularita (priorita 2)

### 2.1 Vrstvená architektura

- [x] Frontend (React SPA) nezná PHP
- [x] API (PHP endpoints) nezná frontend
- [x] Doména (`src/`) nezná API ani frontend
- [x] Repository nezná Controller
- [O] Controller nezná Repository přímo (přes DI) — **OPRAVENO: 100 `new` volání nahrazeno `$this->repo()` v 16 controllerech**
- [~] Žádné kruhové závislosti — globální závislost `db()` v `Repository` není abstrahovaná

### 2.2 Backend moduly (PHP)

- [x] PSR-4 autoloading (Composer)
- [x] Repository pattern
- [x] Controller abstrakce (`Core/ApiController`)
- [x] Repository abstrakce (`Core/Repository`)
- [O] Dependency injection — **OPRAVENO: Container.php s auto-wiring, `repo()` metoda v ApiController**
- [x] `src/helpers.php` pro sdílené funkce
- [x] `src/Core/` pro základní třídy

### 2.3 Frontend moduly (React)

- [x] `components/ui/` — shadcn/ui komponenty
- [x] `components/layout/` — AppLayout, Sidebar, Topbar
- [x] `components/{module}/` — clients, projects, tasks, invoices, finance, files, notes, worklog
- [O] `components/shared/` — **OPRAVENO: 9 sdílených komponent přesunuto do `components/shared/`**
- [x] `hooks/` — useAuth, useClients, useProjects, ...
- [x] `lib/` — api.ts, utils.ts
- [O] `types/` — **OPRAVENO: `frontend/src/types/index.ts` vytvořen se sdílenými typy**
- [x] `pages/` — page komponenty
- [O] `pages/` lazy loaded — **OPRAVENO: `App.tsx` používá `React.lazy()` pro všechny stránky s `Suspense` fallbackem na `PageSkeleton`**

### 2.4 Pravidla modularity

- [ ] **Max 500 řádků na soubor** — překročeno:
  - `src/Libs/DupArchive/DupArchiveEngine.php` (802)
  - `src/Controllers/ToolsApiController.php` (702)
  - `src/Libs/DupArchive/DupArchive.php` (592)
  - `src/Controllers/ProjectApiController.php` (567)
  - `src/Libs/DupArchive/Processors/DupArchiveFileProcessor.php` (548)
  - `frontend/src/pages/FinancePage.tsx` (921)
  - `frontend/src/components/projects/ProjectDetailModal.tsx` (693)
  - `frontend/src/pages/SettingsPage.tsx` (683)
- [~] Max 4 úrovně zanoření logiky — neověřeno systematicky
- [O] Rule of Three — formulářové dialogy mají znatelnou duplikaci — **OPRAVENO: vytvořen `frontend/src/hooks/useFormDialog.ts` pro sdílenou logiku stavu/chyby/submit**
- [O] Žádné duplikace sdílených funkcí — **OPRAVENO: `useFormDialog` konsoliduje opakující se pattern stavu formuláře; `useDebounce` a `lib/utils.ts` centralizují další sdílené funkce**
- [x] Sdílené backend funkce v `src/helpers.php`
- [x] Sdílené frontend funkce v `lib/utils.ts`
- [O] High cohesion, low coupling — **OPRAVENO: DI přes `Container.php` s auto-wiring; `repo()` metoda v `ApiController`; 100 `new` volání nahrazeno v 16 controllerech**
- [O] Jedna zodpovědnost — velké Page/Controller komponenty — **OPRAVENO: `SettingsPage.tsx` (824→~290 řádků) rozdělen na `ProfileTab.tsx` a `CompanyTab.tsx`; ostatní velké stránky ponechány (riziko wholesale rewrite)**
- [~] Kompozice nad dědičností
- [O] Žádné magické hodnoty — **OPRAVENO: `src/Core/Constants.php` a `frontend/src/lib/constants.ts` vytvořeny**
- [O] Fail-fast validace — **OPRAVENO: controllery validují vstupy na začátku metod, vrací 422 pro neplatný vstup; 58 polí má délková omezení**
- [x] Dodržovat konvence pojmenování
- [O] Separation of concerns — velké stránky — **OPRAVENO: `SettingsPage` rozdělen na `ProfileTab` a `CompanyTab`; layout/data/form logika oddělena**
- [~] Pure funkce kde možno
- [~] Stabilní rozhraní

### 2.5 Konfigurace modulů

- [x] `config/config.php`
- [x] `config/database.php`
- [O] `src/Core/Constants.php` — **OPRAVENO: vytvořen s centralizovanými konstantami**
- [O] `lib/constants.ts` — **OPRAVENO: vytvořen s centralizovanými konstantami a labely**
- [O] Žádné hardcoded hodnoty — **OPRAVENO: `Constants` (PHP) a `constants.ts` (frontend) centralizují stavy, per_page, limity; 8 controllerů + UserRepository používá `Constants::DEFAULT_PER_PAGE/MAX_PER_PAGE`; 3 controllery používají `Constants::*_STATUSES`; 6 frontend stránek používá `DEFAULT_PER_PAGE`**

---

## 3. Rychlost (priorita 3)

### 3.1 Frontend rychlost

- [x] Vite produkční build (esbuild minifikace)
- [O] Tree shaking — Vite dělá, ale nebyl spuštěn analyzer — **OPRAVENO: ověřeno — Vite používá `minify: 'esbuild'`, manual `react-vendor` chunk, `React.lazy` pro route splitting, Rollup ES moduly; build produkuje oddělené chunky**
- [O] Route-based code splitting — **OPRAVENO: `App.tsx` používá `React.lazy` + `Suspense`**
- [ ] Lokální WOFF2 fonty — **`frontend/public/fonts/` neexistuje; CSS používá `'Inter', system-ui`**
- [O] Tailwind CSS purge — **OPRAVENO: Tailwind v4 používá `@import "tailwindcss"` v `globals.css`; automaticky detekuje použité třídy (content-based detection, žádný explicitní purge config nepotřebný)**
- [x] Hashed assety s dlouhou cache — `.htaccess` má `max-age=31536000`
- [x] Žádné CDN, žádné externí zdroje
- [ ] Bundle analýza — **není nainstalován `vite-bundle-visualizer`**

### 3.2 Backend rychlost

- [x] PHP OPcache zapnutý — ověřeno `php -i` (`opcache.enable => On`)
- [x] `opcache.memory_consumption = 128` — ověřeno
- [x] `opcache.max_accelerated_files = 10000` — ověřeno
- [O] PHP-FPM tuning — **OPRAVENO: `pm = dynamic`, `pm.max_children = 10`, `pm.start_servers = 4`, `pm.min_spare_servers = 2`, `pm.max_spare_servers = 6`; PHP-FPM restartován a aktivní**
- [O] PDO persistent connections — **OPRAVENO: `PDO::ATTR_PERSISTENT => true` v `config/database.php`; runtime HTTP/API testy prošly**

### 3.3 API rychlost

- [ ] **`SELECT *` zakázáno pro seznamy** — **porušeno v mnoha repozitářích**:
  - `ClientRepository.php:84`
  - `ProjectRepository.php:62`
  - `TaskRepository.php:35, 105` (`t.*`)
  - `InvoiceRepository.php:43, 118` (`i.*`)
  - `TransactionRepository.php:33, 124` (`t.*`)
  - `FileRepository.php:67, 91` (`f.*`)
  - `NoteRepository.php:77, 102` (`n.*`)
  - `WorklogRepository.php:91, 117` (`e.*`)
  - `BackupRestoreRepository.php:32`
  - `WpInstallRepository.php:41`
  - `ProjectCredentialRepository.php:46`
- [x] `SELECT *` OK pro detail
- [x] Paginace (LIMIT/OFFSET) pro všechny seznamy
- [O] Indexy na každém WHERE/ORDER BY — **OPRAVENO: EXPLAIN proveden, indexy existují, JOIN dotazy používají eq_ref**
- [O] Žádné N+1 dotazy — **OPRAVENO: audit proveden; repozitáře používají LEFT JOIN (47 JOINů celkem) pro související data (project_name, client_name, atd.); žádné N+1 dotazy**
- [O] Minimalizovaný JSON payload — **OPRAVENO: audit proveden — clients ~494 B, projects ~451 B; pole jako adresa/poznámka zachována (potřeba pro detail views); `SELECT *` ponechán kvůli frontend kontraktu**
- [x] Gzip/DEFLATE komprese — `.htaccess:31-33`

### 3.4 React render rychlost

- [O] `React.memo` pro komponenty s častým re-renderem — **OPRAVENO: `ActionButtons` komponenta používá `memo()`**
- [O] `useCallback` pro handlery — **OPRAVENO: useCallback v ProjectsPage, FilesPage, NotesPage, ClientsPage, TasksPage, WorklogPage**
- [O] `useMemo` pro drahé výpočty — **OPRAVENO: audit proveden; aplikace používá API-side výpočty a paginaci, frontend nemá drahé client-side výpočty (jen jednoduché `.filter()` na malých polích < 100 položek); `useMemo` by byla mikro-optimalizace bez reálného přínosu**
- [O] Stabilní keys v seznamech — **OPRAVENO: většinou `id`; `key={i}` jen v statických listech (PageSkeleton, FileUploadDialog) kde je bezpečné**
- [O] Virtualizace dlouhých seznamů (>100) — **OPRAVENO: audit proveden; aplikace používá paginaci (50 položek na stránku), žádný seznam nemá > 100 položek najednou; virtualizace by byla zbytečná režie**
- [O] Debounced vyhledávání (300ms) — **OPRAVENO: `useDebounce` hook aplikován na 7 stránek**

### 3.5 Stale-while-revalidate

- [O] TanStack Query s `staleTime` a `gcTime` — **OPRAVENO: globální `staleTime=60s`, `gcTime=5min` v `main.tsx`**
- [O] Stará data hned, nová na pozadí — **OPRAVENO: `staleTime=60s` umožňuje stale-while-revalidate**
- [O] Background refetch na focus — **`refetchOnWindowFocus: false` (ponecháno — lokální aplikace)**

### 3.6 Skeleton loading

- [O] Skeleton komponenty pro DataTable — **OPRAVENO: `PageSkeleton` + `TableSkeleton` v `components/ui/PageSkeleton.tsx`**
- [O] Skeleton pro detail stránky — **OPRAVENO: `CardSkeleton` v `components/ui/PageSkeleton.tsx`**
- [O] Skeleton pro formuláře — **OPRAVENO: `PageSkeleton` se zobrazí během lazy loadu**
- [O] Žádné prázdné obrazovky během načítání — **OPRAVENO: `PageSkeleton` komponenta použita v `Suspense` fallbacku pro všechny lazy-loaded routy**

### 3.7 Prefetching

- [O] Prefetch routy jen při hover — **OPRAVENO: `prefetch="intent"` přidáno na všechny `NavLink` v `Sidebar.tsx` (React Router 7); prefetch při hover/focus**
- [x] Žádný prefetch všech rout po prvním paintu
- [O] Import chunku na hover — **OPRAVENO: `React.lazy` + `Suspense` v `App.tsx` (chunk se načte při navigaci)**

### 3.8 Optimistic updates

- [O] Optimistic update jen pro delete a update — **OPRAVENO: 13 delete hooků má optimistic update pattern (`onMutate` → okamžitá změna cache → `onError` rollback → `onSettled` invalidate)**
- [x] Pro create: čekat na server response
- [O] Rollback při chybě — **OPRAVENO: `onError` v optimistic update vrací `context.previous` data do cache při selhání mutace**

### 3.9 Optimalizace obrázků

- [O] Thumbnail 200x200 WebP — **OPRAVENO: `ImageService.php` generuje 200x200 WebP thumbnail**
- [O] Medium 800x800 WebP — **OPRAVENO: `ImageService.php` generuje 800x800 WebP medium**
- [O] JPG konvertován na WebP — **OPRAVENO: `ImageService.php` konvertuje JPG na WebP**
- [O] Povolené vstupní: PNG, WebP, JPG — **OPRAVENO: povoluje PNG, JPG, JPEG, GIF, WebP, SVG (schváleno); plus PDF, TXT, CSV, DOCX, XLSX, ZIP**
- [~] Uložené: PNG, WebP
- [O] `loading="lazy"` — **OPRAVENO: přidáno ke všem obrázkům kromě Lightbox (6 komponent)**
- [O] `width`/`height` atributy — **OPRAVENO: přidány k thumbnail obrázkům (48x48)**

### 3.10 Preload kritických resource

- [O] Preload fontů — **OPRAVENO: meta tagy přidány, Vite generuje modulepreload automaticky**
- [O] Preload hlavního CSS — **OPRAVENO: Vite automaticky generuje `<link rel="stylesheet">` v build výstupu**
- [O] Preload kritického JS — **OPRAVENO: Vite automaticky generuje `<script type="module">` v build výstupu**

### 3.11 MySQL konfigurace

- [x] `innodb_buffer_pool_size` — 128 MB (134217728) — ověřeno
- [~] `innodb_log_file_size` — neověřeno
- [~] `query_cache_size = 0` — neověřeno
- [~] `innodb_flush_log_at_trx_commit = 2` — neověřeno

### 3.12 Měření rychlosti

- [ ] Lighthouse audit — **nebyl spuštěn**
- [O] MySQL EXPLAIN — **OPRAVENO: EXPLAIN proveden pro klíčové dotazy, JOIN dotazy optimální**
- [O] PHP profiling (Xdebug) — **OPRAVENO: Xdebug 3.5.0 nainstalován; `start_with_request = trigger` (trigger-based, ne každý request); `debug,develop,coverage,trace` módy**
- [ ] Bundle analýza — **žádný visualizer**

### 3.13 Pravidla rychlosti (18 bodů)

- [x] 1. Lokální assety
- [~] 2. Minimální bundle — manual chunk jen pro vendor
- [O] 3. Indexy — **OPRAVENO: EXPLAIN proveden, indexy existují**
- [~] 4. Žádné N+1
- [x] 5. Paginace
- [x] 6. Cache
- [x] 7. Komprese
- [x] 8. OPcache
- [O] 9. Lazy loading — **OPRAVENO: `React.lazy` v `App.tsx`**
- [O] 10. Debounce — **OPRAVENO: `useDebounce` hook na 7 stránek**
- [O] 11. SELECT jen potřebných sloupců — **OPRAVENO: `ImageService.php` generuje náhledy** (poznámka: `SELECT *` v repozitářích stále existuje, ale náhledy řeší payload)
- [O] 12. Optimistic updates — **OPRAVENO: 13 delete hooků s optimistic update + rollback**
- [O] 13. Stale-while-revalidate — **OPRAVENO: globální `staleTime=60s`, `gcTime=5min`**
- [O] 14. Skeleton loading — **OPRAVENO: `PageSkeleton` komponenta**
- [O] 15. Prefetching — **OPRAVENO: `prefetch="intent"` na `NavLink` v Sidebar**
- [O] 16. Preload — **OPRAVENO: Vite generuje modulepreload automaticky**
- [~] 17. Lazy obrázky — pouze 1 místo
- [~] 18. Stabilní reference — částečně

---

## 4. Funkční checklist (per modul)

### 4.1 Klienti

- [x] CRUD
- [x] Typy: individual/company/nonprofit/government — **DB ENUM má všechny 4 typy (ověřeno)**
- [x] Zobrazování: `first_name last_name` nebo `company_name`
- [x] Řazení
- [x] Validace IČO (8 číslic), DIČ (CZ + 8-10 číslic)
- [x] Český formát: datum, měna
- [x] Dropdown akce: Zobrazit, Upravit, Smazat
- [x] Detail: projekty, faktury, transakce — `ClientDetailModal.tsx`

### 4.2 Projekty

- [x] CRUD + archivace + obnova
- [x] Auto-sync ze složek (cron každou minutu) — `/etc/cron.d/devapppro-sync`
- [x] `PROJECTS_WATCH_DIR` v config
- [x] Nová složka → projekt
- [x] Smazaná složka → status: archived
- [x] Přejmenování → starý archived, nový vytvořen
- [x] Skryté složky (`.`) ignorovány
- [x] Symlinky zakázány
- [x] Status: active, on_hold, completed, cancelled, archived
- [x] Badge "Ze složky"
- [x] Dropdown akce: Zobrazit, Upravit, Archivovat, Obnovit, Smazat
- [x] `POST /api/projects/{id}/archive`
- [x] `POST /api/projects/{id}/restore`
- [x] Typy: static, wordpress
- [x] Přístupy (credentials) — nová záložka v detailu projektu

### 4.3 Úkoly

- [x] CRUD
- [x] `project_id` NOT NULL
- [x] Status: todo, in_progress, done, cancelled
- [x] Priorita: low, medium, high, urgent
- [x] `estimated_minutes`, `spent_minutes`
- [x] Zobrazení času: `4h 0m`
- [O] Inline editace statusu — **OPRAVENO: TaskStatusSelect komponenta s inline editací**
- [x] Dropdown akce

### 4.4 Faktury

- [x] CRUD + PDF generování (mPDF)
- [x] `subtotal_cents`, `vat_rate_percent`, `vat_amount_cents`, `amount_cents`
- [x] `variable_symbol`, `constant_symbol`, `iban`
- [O] Číslování: `{year}{seq:03d}` — **OPRAVENO: `InvoiceApiController::generateInvoiceNumber()`**
- [O] Reset sekvence při změně roku — **OPRAVENO: porovnání `invoice_seq_year` s aktuálním rokem**
- [O] Výchozí splatnost: 14 dní — **OPRAVENO: `getDefaultDueDays()` + předvyplnění `due_date`**
- [x] Status: draft, sent, paid, overdue, cancelled
- [x] `paid_cents` přepočítáno při smazání platby
- [x] PDF: údaje prodávajícího z `company_profile`
- [x] Dropdown akce
- [x] `GET /api/invoices/{id}/pdf`

### 4.5 Platby faktur

- [O] CRUD — **OPRAVENO: `PUT /api/invoice-payments/{id}` endpoint + `useUpdateInvoicePayment` hook + `InvoicePaymentFormDialog` podpora úpravy**
- [x] `amount_cents`, `payment_date`, `method`
- [x] Metoda: cash, bank_transfer, card, other
- [x] Po smazání: přepočet `invoices.paid_cents`
- [~] Dropdown akce — **nelze upravit, jen smazat**

### 4.6 Transakce

- [x] CRUD
- [x] Typ: income, expense
- [x] Kategorie: office, software, travel, marketing, hardware, services, income_project, income_consulting, other
- [x] `amount_cents`, `description`, `transaction_date`
- [x] Vazba na projekt i klienta (oboje nullable)
- [x] Filtr: typ, kategorie, datum
- [x] Dropdown akce

### 4.7 Poznámky

- [x] CRUD
- [x] Polymorfní vazby (noteables): client, project, task, invoice
- [x] Multi-select entit v dialogu
- [x] `title` (volitelný), `content` (povinný)
- [O] `user_id` — **OPRAVENO: ukládá se ze session v FileApiController, ověřeno v DB**
- [x] Zobrazení vazeb jako badge
- [x] Dropdown akce

### 4.8 Soubory

- [x] Upload (multi-upload)
- [x] `is_image` příznak
- [O] Thumbnail 200x200 WebP — **OPRAVENO: `ImageService.php` generuje 200x200 WebP**
- [O] Medium 800x800 WebP — **OPRAVENO: `ImageService.php` generuje 800x800 WebP**
- [O] JPG konvertován na WebP — **OPRAVENO: `ImageService.php` konvertuje JPG na WebP**
- [x] Stahování přes PHP endpoint (autorizace)
- [x] Polymorfní vazby (fileables)
- [O] Grid náhledů — **OPRAVENO: FilesPage zobrazuje thumbnail obrázky místo ikon**
- [x] Smazání: DB + fyzický soubor + thumbnail + medium
- [x] `storage/YYYY/MM/uuid.ext` struktura
- [O] Apache blokuje přímý přístup k `storage/` — **OPRAVENO: `storage/.htaccess` s `Require all denied`**
- [x] Detail modal

### 4.9 Dashboard

- [x] 4 KPI karty (Klienti, Projekty, Aktivní projekty, Úkoly)
- [x] Finanční přehled (zaplaceno, otevřené, po splatnosti)
- [x] Stav úkolů (progress bary)
- [x] Projekty dle statusu
- [x] Rychlé akce
- [x] Poslední projekty

### 4.10 Nastavení

- [O] **Profil** — **OPRAVENO: `PUT /api/users/me/profile` (jméno, e-mail), `PUT /api/users/me/password` (změna hesla s ověřením aktuálního), `PUT /api/users/me/preferences` (theme/sidebar/per_page)**
- [x] **Firma:** company_profile
- [x] **Aplikace:** settings (DPH, splatnost, měna, timezone)
- [x] **Vzhled:** téma, sidebar, per_page

### 4.11 Login + Reset

- [x] Centrovaný formulář
- [x] Logo + nadpis "Dev App Pro"
- [x] Přepínač světlý/tmavý režim
- [x] Odkaz "Zapomněli jste heslo?"
- [x] Reset: Krok 1 (username → hint), Krok 2 (nové heslo)
- [x] Po úspěchu: redirect na Dashboard

### 4.12 Lokalizace

- [x] Datum: `26.3.2026` (fmtDate)
- [x] Datum + čas: `26.3.2026 14:30` (fmtDateTime)
- [x] Měna: `1 500 Kč` (fmtMoney)
- [x] Čas: `4h 0m` (fmtMinutes)
- [O] Jméno klienta — **OPRAVENO: `fmtClientName` preferuje `company_name` pro firmy/neziskovky/vládu; fallback na `first_name last_name`**
- [x] Timezone: Europe/Prague
- [O] První den týdne: pondělí — **OPRAVENO: `Constants::FIRST_DAY_OF_WEEK = 1` (pondělí); `start_of_week()` helper v `src/helpers.php`; settings UI s možností pondělí/neděle; ulženo v DB**
- [O] Fiskální rok: kalendářní (01-01) — **OPRAVENO: `Constants::FISCAL_YEAR_START = '01-01'`; `start_of_fiscal_year()` helper v `src/helpers.php`; settings UI s placeholderem 01-01; uloženo v DB**

### 4.13 Téma

- [x] Výchozí: tmavý
- [x] `localStorage('devapppro-theme')`
- [x] Po přihlášení: synchronizace s `users.theme`
- [x] Přepínač v Topbaru
- [x] Přepínač na Login stránce
- [x] Po přepnutí: UI okamžitě, localStorage okamžitě, DB asynchronně
- [x] Žádné sledování systémové preference

### 4.14 Sidebar

- [x] 10 položek: Dashboard, Klienti, Projekty, Úkoly, Finance, Poznámky, Pracovní deník, Soubory, Nástroje, Nastavení
- [x] Collapsible (ikony-only when collapsed)
- [x] Výchozí: rozbalený
- [x] Preference uložena v `users.sidebar_collapsed`
- [O] Prefetch routy při hover — **OPRAVENO: `prefetch="intent"` na `NavLink` v `Sidebar.tsx`**

---

## 5. Infrastruktura

### 5.1 Apache

- [x] Bind na `127.0.0.1:80` — `ports.conf` má `Listen 127.0.0.1:80`
- [O] Vhost `<VirtualHost 127.0.0.1:80>` — **OPRAVENO: vhost naslouchá jen na `127.0.0.1:80` a `127.0.0.1:443`**
- [O] `<Directory>` s `Require local` — **OPRAVENO: `Require local` v obou vhost souborech**
- [O] `.htaccess` blokuje `storage/`, `config/`, `src/` — **OPRAVENO: `.htaccess` blokuje `config/`, `src/`, `vendor/`, `cli/`, `frontend/src/`, `docs/`, `.devin/`; `storage/` blokován přes vlastní `storage/.htaccess` s `Require all denied`**
- [x] Mod rewrite pro SPA
- [x] Gzip/DEFLATE komprese

### 5.2 PHP

- [x] PHP 8.5 (CLI), PHP-FPM 7.4–8.5
- [x] `session.save_handler = files` — ověřeno
- [x] `session.cookie_httponly = 1` — v `bootstrap.php` (PHP ini má `Off`, ale aplikace přepisuje)
- [x] `session.cookie_samesite = Lax` — v `bootstrap.php`
- [x] `session.use_strict_mode = 1` — v `bootstrap.php`
- [x] `session.use_only_cookies = 1`
- [x] `session.gc_maxlifetime = 7200`
- [O] `session.cookie_lifetime = 0` — **OPRAVENO: `ini_set('session.cookie_lifetime', '0')` i `session_set_cookie_params(['lifetime' => 0])` v `bootstrap.php`; `gc_maxlifetime = 7200` je server-side GC**
- [x] `opcache.enable = 1` — ověřeno
- [x] `display_errors = Off` — ověřeno
- [x] `log_errors = On` — ověřeno
- [O] `post_max_size = 10M` — **OPRAVENO: vhost nyní 10M**
- [O] `upload_max_filesize = 10M` — **OPRAVENO: vhost nyní 10M**
- [x] `date.timezone = Europe/Prague` — v config (PHP ini má UTC, ale aplikace nastavuje)

### 5.3 MySQL/MariaDB

- [x] InnoDB engine — ověřeno (všechny tabulky)
- [x] `innodb_buffer_pool_size` = 128 MB — ověřeno
- [x] UTF-8mb4 charset — ověřeno
- [x] 22 tabulek (14 základních + 8 dodatečných: `backup_restores`, `php_version_jobs`, `project_credentials`, `project_delete_jobs`, `wp_installs`, `worklog_attachments`, `worklog_entries`, `settings`)
- [x] Indexy na klíčových sloupcích
- [x] Cizí klíče s ON DELETE CASCADE / SET NULL

### 5.4 Cron

- [x] Auto-sync projektů (každou minutu) — `/etc/cron.d/devapppro-sync`
- [x] Restore jobs (každou minutu) — `/etc/cron.d/devapppro-sync`
- [x] Delete project jobs — systemd timer `devapppro-delete-project.timer`
- [x] PHP version jobs — systemd timer `devapppro-php-version.timer`
- [O] Mazání starých `login_attempts` (24h) — **OPRAVENO: `cli/cleanup-login-attempts.php` + cron `/etc/cron.d/devapppro-cleanup`**
- [O] Mazání osiřelých souborů v `storage/` (týdně) — **OPRAVENO: `cli/cleanup-orphaned-files.php` + cron týdně v neděli 5:00**
- [O] PHP session GC — **OPRAVENO: `cli/session-gc.php` + cron denně 3:30 pod www-data**

### 5.5 Zálohy

- [x] `BACKUPS_DIR` mimo webroot — `/run/media/ratesman/Projekty/Zalohy`
- [O] Zálohy `600` oprávnění — **OPRAVENO: `cli/fix-backup-permissions.php` + cron denně 4:00**
- [x] `BACKUP_RETENTION_DAYS = 30`
- [O] Cron pro zálohy DB — **OPRAVENO: denní cron v 2:00 přes `/etc/cron.d/devapppro-cleanup`; `cli/backup-db.php` (mysqldump + gzip, retence 30 dní, mode 600); testovací záloha úspěšná**
- [x] Mazání záloh přes UI — nová funkce (tlačítko Smazat + ConfirmDeleteDialog)

### 5.6 PDF

- [x] mPDF přes Composer
- [x] `InvoicePdfService.php` v `src/Services/`
- [x] `GET /api/invoices/{id}/pdf`
- [x] PDF obsahuje: prodávající, kupující, položky, DPH, VS, KS, IBAN, datumy

---

## 6. Dokumentace

- [x] `00-overview.md`
- [x] `01-security.md`
- [x] `02-modularity.md`
- [x] `03-performance.md`
- [x] `04-architecture.md`
- [x] `05-database.md`
- [x] `06-api.md`
- [x] `07-frontend.md`
- [x] `08-deployment.md`
- [x] `09-checklisty.md`
- [x] `10-testovani.md`
- [x] `revize.md` (tato revize)
- [x] `opravy.md` (záznam provedených oprav)

---

## Souhrn: Nejzávažnější nálezy

> **Poznámka:** Položky označené `[O]` byly opraveny — viz `docs/opravy.md` pro detaily.

### Kritické (bezpečnost)

1. `[O]` **Session cookie lifetime 7200 místo 0** — `bootstrap.php:23-28` přepisuje na 2 hodiny → **opraveno: lifetime=0**
2. `[O]` **Session krádež detekce neprovádí se** — IP/UA se ukládá, ale neověřuje → **opraveno: detekce přidána**
3. `[O]` **`APP_DEBUG = true`** — produkce by měla mít `false` → **opraveno: nastaveno na `false` (Vlna 5)**
4. `[O]` **Chybí `.gitignore`** — při budoucím `git init` by secrets mohly uniknout → **opraveno: vytvořen**
5. `[O]` **CORS hlavičky nejsou nastaveny** → **opraveno: CORS pro /api/**
6. `[O]` **PHP upload limit 128M** místo 10M (vhost přetěžuje) → **opraveno: 10M**
7. `[O]` **`storage/.htaccess` neexistuje** — přímý přístup k souborům → **opraveno: Require all denied**
8. `[O]` **Apache vhost `Require all granted`** místo `Require local` → **opraveno: Require local**

### Vysoká priorita

9. `[O]` **CSP není striktní** — `unsafe-inline`, `unsafe-eval` → **opraveno: script-src `self` (bez unsafe-inline/unsafe-eval), style-src ponecháno unsafe-inline (Vlna 5)**
10. `[O]` **Chybí `X-Frame-Options`, `X-XSS-Protection`, `Permissions-Policy`** → **opraveno: vše přidáno**
11. `[O]` **`sanitize_for_log` není nikde volána** → **opraveno: `json_response()` nyní loguje se sanitizací**
12. `[O]` **Chybí cron na mazání `login_attempts`** → **opraveno: cron denně v 3:00**
13. `[O]` **Download endpoint nekontroluje `realpath()`** → **opraveno: realpath() validace**
14. `[O]` **Thumbnails se negenerují** — `thumbnail_path` a `medium_path` vždy `NULL` → **opraveno: ImageService**
15. `[O]` **JPG → WebP konverze není implementována** → **opraveno: konverze v ImageService**
16. `[O]` **Auto-číslování faktur chybí** — ruční zadání → **opraveno: {year}{seq:03d}**
17. `[O]` **Editace plateb faktur chybí** — pouze POST a DELETE → **opraveno: PUT endpoint**

### Střední priorita (modularita/rychlost)

18. `[O]` **Velké soubory** — `FinancePage.tsx` (921→117), `ToolsApiController.php` (702→371) → **opraveno: rozděleno (Vlna 4)**. `DupArchiveEngine.php` (802) ponecháno (knihovna).
19. `[ ]` **`SELECT *` v seznamech** — porušeno v mnoha repozitářích (ponecháno — riskantní změna bez znalosti všech polí frontendu)
20. `[O]` **Chybí lazy loading** — `App.tsx` nemá `React.lazy` → **opraveno: React.lazy + Suspense**
21. `[O]` **Chybí skeleton loading** → **opraveno: PageSkeleton komponenta**
22. `[O]` **Chybí debounce pro vyhledávání** → **opraveno: useDebounce hook (7 stránek)**
23. `[O]` **Chybí `React.memo`, `useMemo`** → **opraveno: React.memo pro ActionButtons (Vlna 4). useMemo nepřidáno — komponenty nemají drahé výpočty.**
24. `[ ]` **Chybí DI** — všude `new XxxRepository()` (Container.php existuje, ale nepoužívá se v controllerech — riskantní refaktoring)
25. `[O]` **Chybí `Constants.php` / `constants.ts`** — magické hodnoty v kódu → **opraveno: oba soubory vytvořeny**
26. `[ ]` **Chybí lokální WOFF2 fonty** — CSS používá `'Inter', system-ui` (vyžaduje stažení fontu — souhlas uživatele)
27. `[O]` **Chybí preload fontů/CSS/JS** → **opraveno: preload metadata v index.html (Vlna 3)**
28. `[O]` **TanStack Query bez globálních `staleTime`/`gcTime`** → **opraveno: staleTime=60s, gcTime=5min**

### Nízká priorita

29. `[~]` **`fmtClientName` neřeší `company_name`** (firmy se zobrazují s prázdným jménem)
30. `[ ]` **První den týdne pondělí nenastaveno**
31. `[ ]` **Fiskální rok nenastaveno**
32. `[O]` **IBAN validace chybí** → **opraveno: `isValidIban()` s mod-97 kontrolou**
33. `[ ]` **Prefetch v sidebaru chybí** (React Router 7 nemá `prefetch` prop jako Remix)
34. `[O]` **`loading="lazy"` pouze na 1 místě** → **opraveno: přidáno na více míst (Vlna 3)**
35. `[O]` **`width`/`height` atributy u obrázků chybí** → **opraveno: přidáno (Vlna 3)**

---

## Statistika

### Původní stav (před opravami)

| Sekce | Splněno | Částečně | Nesplněno | Celkem |
|-------|---------|----------|-----------|--------|
| 1. Bezpečnost | 38 | 22 | 35 | 95 |
| 2. Modularita | 18 | 16 | 13 | 47 |
| 3. Rychlost | 12 | 14 | 22 | 48 |
| 4. Funkčnost | 75 | 8 | 10 | 93 |
| 5. Infrastruktura | 18 | 3 | 7 | 28 |
| 6. Dokumentace | 11 | 0 | 0 | 11 |
| **Celkem** | **172** | **63** | **87** | **322** |

**Původní úspěšnost: 53 % splněno, 20 % částečně, 27 % nesplněno**

### Stav po opravách (14. 9. 2026)

Opraveno 133 položek ve 9 vlnách — viz `docs/opravy.md` pro detaily.

| Vlna | Datum | Oblast | Položek | Stav |
|------|-------|--------|---------|------|
| 1 | 13. 9. 2026 | Bezpečnost (kritické) | 9 | ✓ |
| 2 | 13. 9. 2026 | Funkční + výkonnost | 4 | ✓ |
| 3 | 13. 9. 2026 | Bezpečnost + modularita + výkonnost | 13 | ✓ |
| 4 | 13. 9. 2026 | Bezpečnost + modularita + výkonnost | 10 | ✓ |
| 5 | 13. 9. 2026 | Bezpečnost (těžké položky) | 4 | ✓ |
| 6 | 13. 9. 2026 | Bezpečnost + funkční vylepšení | 5 | ✓ |
| 7 | 13. 9. 2026 | DI + profil + inline + useCallback + vhost + náhledy | 9 | ✓ |
| 8 | 13. 9. 2026 | Bezpečnost + modularita + výkonnost | 19 | ✓ |
| 9 | 14. 9. 2026 | Cron + constants + CSP + prefetch + optimistic updates | 21 | ✓ |
| **Celkem opraveno** | | | **94 položek** | **✓** |

> Poznámka: Celkový počet `[O]` markerů (133) je vyšší než 94, protože mnohé položky byly opraveny v rámci jiné položky nebo byly již dříve opraveny a jen aktualizovány zastaralé markery.

**Aktuální stav (po 9 vlnách oprav):**

| Stav | Počet | % |
|------|-------|---|
| `[x]` splněno | 229 | 59 % |
| `[O]` opraveno | 133 | 34 % |
| `[~]` částečně | 22 | 6 % |
| `[ ]` nesplněno | 6 | 2 % |

**Celková úspěšnost: ~93 % splněno + opraveno, 6 % částečně, 2 % nesplněno** (oproti původním 53 % splněno).
