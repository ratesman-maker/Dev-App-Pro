# Dev App Pro

Lokální SaaS aplikace pro správu podnikání — klienti, projekty, faktury, finance, poznámky, soubory a pracovní deník. Běží pouze na localhostu (Apache + PHP + MariaDB), frontend je moderní React aplikace ve stylu shadcn/ui.

> **Lokální aplikace** — vše běží na `https://localhost`, data zůstávají u vás. Není určena pro veřejný hosting.

---

## Obsah

- [Funkce](#funkce)
- [Technologie](#technologie)
- [Architektura](#architektura)
- [Instalace](#instalace)
- [Konfigurace](#konfigurace)
- [Databáze](#databáze)
- [API](#api)
- [CLI skripty a cron](#cli-skripty-a-cron)
- [Integrace s WordPress](#integrace-s-wordpress)
- [Testování](#testování)
- [Bezpečnost](#bezpečnost)
- [Struktura projektu](#struktura-projektu)
- [Dokumentace](#dokumentace)

---

## Funkce

### Nástěnka (Dashboard)
Moderní nástěnka ve stylu shadcn dashboard-01:
- **4 metriky** — klienti, aktivní projekty, otevřené faktury, po splatnosti
- **Graf příjmů a výdajů** (area chart, recharts) za 12M/6M/3M z transakcí
- **Tabulka klientů** — projekty, faktury, příjmy, poslední poznámka, aktivita (řazení, 5 záznamů, klik → detail)
- **Tabulka aktivních projektů** — rozpočet vs. zaplaceno (progress bar), faktury, termín
- **Pás „Co řešit"** — faktury po splatnosti, termíny projektů do 14 dní, poslední poznámky

### Klienti
- Typy: **Osoba** (vč. OSVČ s IČO/DIČ pro fakturaci), **Firma**, **Neziskový**, **Státní správa**
- Kontakty (e-mail, telefon), adresa, bankovní účet, poznámka
- Detail klienta: projekty, faktury (s akcemi — úprava, status, PDF, smazání), transakce, poznámky, soubory
- Tlačítko **„Vystavit fakturu"** přímo z detailu klienta

### Projekty
- Vazba na klienta, interní projekty bez klienta
- Rozpočet, termín, status (aktivní / pozastaveno / dokončeno / zrušeno / archivováno)
- Typ projektu: `static` / `php` / `wordpress` (autodetekce při sync ze složky; statické vhosty běží bez FPM)
- **Per-projekt PHP verze** (7.4–8.5, přepnutí přes FPM)
- **Automatická synchronizace ze složek** — složky v `PROJECTS_WATCH_DIR` se promítají do projektů
- **Automatický hosting** — generování Apache vhostů + SSL (mkcert) pro každý projekt
- Mazání projektů přes asynchronní job (soubory, DB, vhost, SSL)

### Úkoly
- Vazba na projekt, priorita, status, odhad/strávený čas, termín
- Filtry, inline změna statusu

### Faktury
- **Položky faktury** (popis, množství, MJ, cena) — mezisoučet se počítá ze serveru
- **Datum uskutečnění zdanitelného plnění (DZP)** — povinná náležitost dle ZDPH §28
- **Automatické číslování** — formát `{year}{seq:03d}` (nastavitelný), reset na rok
- Statusy: koncept / odesláno / zaplaceno / po splatnosti / zrušeno (rychlá změna z detailu)
- **PDF s QR platbou** — zákonné náležitosti (prodávající/kupující, IČO/DIČ, DZP, rozpad DPH, platební údaje, prohlášení plátce/neplátce DPH, podpis)
- **QR Platba** (standard ČBA, SPD 1.0) — IBAN (fallback na profil firmy, přepočet čísla účtu na IBAN), částka, variabilní symbol
- Název PDF souboru: `faktura-{číslo}-{klient}.pdf`
- **Platby faktur** — přepočet zaplaceno, automatický status `paid`
- **Archivní kopie PDF** — při přechodu na „odesláno/zaplaceno" se PDF zmrazí do `storage/`; stažení vydané faktury vždy vrátí verzi, která byla vydána

### Finance
- **Transakce** — příjmy/výdaje, kategorie, vazba na projekt/klienta/fakturu
- Widgety souhrnu (celkem zaplaceno, příjmy, výdaje, otevřené, po splatnosti)
- 3 taby: Faktury / Platby / Transakce

### Poznámky a soubory
- **Polymorfní vazby** — poznámka/soubor může patřit klientovi, projektu, úkolu i faktuře
- Upload souborů (PDF, ZIP, obrázky, DOCX…), thumbnaily, download
- Fyzické soubory ve `storage/` (blokováno z webu, jen přes API)

### Pracovní deník (Worklog)
- Záznamy práce (kategorie: projekt / bezpečnost / údržba / schůzka / jiné, závažnost, hodiny, hotovo)
- Vazba na projekt/klienta, přílohy (obrázky, soubory)

### Notifikace
- Upozornění na akce (nová faktura, úkol…) a termínové připomínky (deadline úkolů, splatnost faktur)
- Zvoník v horní liště, nastavení typů a předstihu

### Nastavení
- **Profil** — jméno, e-mail, změna hesla
- **Firma** — údaje prodávajícího (jméno, IČO, DIČ, adresa, bankovní účet, IBAN, SWIFT) — používá se na fakturách a QR platbě
- **Aplikace** — formát číslování faktur, splatnost, měna, desetinná místa
- **Vzhled** — světlý/tmavý režim

---

## Technologie

| Vrstva | Technologie |
|---|---|
| Backend | PHP 8.5 (per-projekt 7.4–8.5 přes FPM), Apache 2.4 |
| Databáze | MariaDB 11.x |
| Frontend | React 19, TypeScript, Vite 7, Tailwind CSS 4 |
| UI | shadcn/ui (new-york, zinc), Lucide icons |
| Data | TanStack Query 5, TanStack Table 8 |
| Grafy | recharts 3 |
| PDF | mPDF 8.3 + mpdf/qrcode |
| Testy | PHPUnit 11 (Unit + Integration přes HTTP) |
| SSL | mkcert (wildcard `*.localhost`) |

---

## Architektura

```
┌─────────────────────────────────────────────────────────────┐
│  Frontend (React SPA, Vite build → assets/dist)              │
│  /login · / · /clients · /projects · /tasks · /finance       │
│  /notes · /files · /worklog · /backups · /tools · /settings  │
└──────────────────────────┬──────────────────────────────────┘
                           │ fetch /api/* (JSON, CSRF token)
┌──────────────────────────▼──────────────────────────────────┐
│  Apache → .htaccess → api/{module}.php (tenký vstupní bod)   │
│  → Controller (validace, auth) → Repository (PDO) → JSON     │
│  Služby: InvoicePdfService, ProjectSyncService, …            │
└──────────────────────────┬──────────────────────────────────┘
                           │
┌──────────────────────────▼──────────────────────────────────┐
│  MariaDB (schema.sql + migrace)                              │
└─────────────────────────────────────────────────────────────┘
```

- **api/{module}.php** — tenké vstupní body, pouze volají kontrolery
- **Controllers** — validace vstupů (422), auth, CSRF ochrana, JSON odpovědi
- **Repositories** — PDO s prepared statements, whitelist sloupců
- **Services** — PDF generování, synchronizace projektů, šifrování, notifikace
- **Frontend** — stránky + hooks (useClients, useInvoices…) + sdílené komponenty (DataTable, DetailModal, dialogy)

---

## Instalace

### Předpoklady
- Apache 2.4 (vhost pro localhost, mod_rewrite), PHP 8.5 + FPM, MariaDB, Composer, Node.js 22
- Mailpit (lokální SMTP catcher — reset hesla apod.; `sendmail_path` ve FPM conf.d), systemd workery + cron (viz CLI skripty a cron)

### Kroky

```bash
# 1. Naklonovat do webrootu
git clone https://github.com/ratesman-maker/Dev-App-Pro.git /var/www/devapppro
cd /var/www/devapppro

# 2. Backend závislosti
composer install

# 3. Frontend závislosti + build
cd frontend
npm install
npm run build

# 4. Konfigurace (viz níže)
#    - config/config.php, config/database.php + secrets (SetEnv ve vhostu)

# 5. Databáze
mysql -u root -p -e "CREATE DATABASE devapppro CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p devapppro < database/schema.sql
mysql -u root -p devapppro < database/seed.sql

# 6. Apache vhost pro https://localhost (+ SSL přes mkcert)
```

### Frontend (dev režim)
```bash
cd frontend
npm run dev        # http://localhost:5173, proxy /api → localhost
```

---

## Konfigurace

| Soubor | Účel |
|---|---|
| `config/config.php` | Konstanty aplikace (APP_NAME, RATE_LIMIT, PROJECTS_WATCH_DIR, BACKUPS_DIR…) |
| `config/database.php` | PDO připojení (host, dbname, user, pass, options) |
| Apache vhost | Tajemství `DEVAPPPRO_WP_AUTOLOGIN_SECRET`, `DEVAPPPRO_CREDENTIALS_ENCRYPTION_KEY` přes `SetEnv` direktivy (PHP je čte přes `getenv()`) |

> `config/database.php` a `config/config.php` jsou v `.gitignore` — necommitují se.

- `PROJECTS_WATCH_DIR` — složka s projekty (synchronizace, vhosty)
- `BACKUPS_DIR` — složka se zálohami pro Duplicator import

---

## Databáze

- **schema.sql** — kompletní schéma (DROP + CREATE všeho)
- **migrace** — `database/migration_*.sql` (inkrementální změny; po migraci vždy doplnit do schema.sql)
- **seed.sql** / **seed_test.sql** — výchozí data (admin uživatel, nastavení, profil firmy)

Hlavní tabulky:

```
users, company_profile, settings, login_attempts
clients, projects, tasks
invoices, invoice_items, invoice_payments, transactions
notes, noteables, files, fileables        (polymorfní vazby)
wp_installs, project_credentials
project_hosting_jobs, php_version_jobs, project_delete_jobs, backup_restores
worklog_entries, worklog_attachments
notifications
```

---

## API

Všechny endpointy pod `/api/*`, JSON, vyžadují přihlášení (session), mutace vyžadují CSRF hlavičku `X-CSRF-Token`.

| Modul | Endpointy |
|---|---|
| auth | `POST /api/auth/login`, `logout`, `csrf-token`, `reset-password` |
| users | `GET/PUT /api/users/me/*` (profil, heslo) |
| clients | `GET/POST/PUT/DELETE /api/clients` |
| projects | `GET/POST/PUT/DELETE /api/projects`, `archive/restore`, `{id}/php-version`, `{id}/credentials` |
| tasks | `GET/POST/PUT/DELETE /api/tasks` |
| invoices | `GET/POST/PUT/DELETE /api/invoices`, `GET /api/invoices/{id}/pdf` |
| invoice-payments | `GET/POST/PUT/DELETE /api/invoice-payments` |
| transactions | `GET/POST/PUT/DELETE /api/transactions` |
| finance-overview | `GET /api/finance-overview` (sjednocený seznam příjmů/výdajů) |
| notes | `GET/POST/PUT/DELETE /api/notes` (+ polymorfní attachments) |
| files | `GET/POST/DELETE /api/files`, `GET /api/files/{id}/download|thumbnail` |
| worklog | `GET/POST/PUT/DELETE /api/worklog`, přílohy |
| notifications | `GET/PUT/DELETE /api/notifications`, `unread-count`, `read-all` |
| dashboard | `GET /api/dashboard` (kpis, finance, klienti, projekty, finance_series, attention) |
| settings | `GET/PUT /api/settings` |
| company-profile | `GET/PUT /api/company-profile` |
| backups | `GET /api/backups`, `POST /api/backups/restore`, `GET /api/backups/restores` |
| tools | `GET /api/tools/php-versions`, `php-version-jobs` |

---

## CLI skripty a cron

| Skript | Účel |
|---|---|
| `sync-projects.php` | Synchronizace projektů ze složek (každou minutu) |
| `generate-vhosts.php` | Generování Apache vhostů + SSL pro projekty (maže zastaralé) |
| `restore-backup.php --process-pending` | Worker pro obnovu Duplicator záloh (každou minutu) |
| `import-duplicator.php <archiv> <slug> [--wait]` | CLI wrapper pro import Duplicator zálohy |
| `install-wordpress.php` | Instalace čistého WordPress projektu |
| `delete-project.php --process-pending` | Worker pro mazání projektů (timer 5 s) |
| `change-php-version.php --process-pending` | Worker pro změnu PHP verze (timer 5 s) |
| `regenerate-project-hosting.php` | Regenerace hostingu projektu (vhost, SSL) |
| `search-replace-db.php` | Serialization-aware náhrada URL/cest v DB |
| `backup-db.php` | Denní záloha databáze (2:00, retence 30 dní) |
| `check-deadlines.php` | Termínové notifikace (úkoly, faktury) |
| `cleanup-login-attempts.php` | Čištění login_attempts (3:00) |
| `session-gc.php` | GC PHP sessions (3:30) |
| `cleanup-orphaned-files.php` | Mazání osiřelých souborů ve storage/ (5:00 neděle) |
| `fix-backup-permissions.php` | Oprávnění záloh (4:00) |
| `rollback-php-versions.sh` | Rollback PHP verzí po chybě |

Cron: `/etc/cron.d/devapppro-cleanup`, `/etc/cron.d/devapppro-sync` + systemd timery (`devapppro-php-version`, `devapppro-delete-project`, `devapppro-hosting` — každých 5 s).

---

## Integrace s WordPress

Aplikace spravuje lokální WordPress projekty:

- **Auto-login do wp-admin** — tlačítko v projektu vygeneruje HMAC token (`DEVAPPPRO_SECRET` v `wp-config.php` projektu), mu-plugin `devapppro-autologin.php` ověří a přihlásí (5 min platnost, bez hesel)
- **Duplicator import** — zálohy `.zip`/`.daf` v `BACKUPS_DIR` → UI (Projekty → Zálohy → „Vytvořit projekt") nebo CLI. Worker: extrakce → DB → wp-config → URL replace (serialization-safe) → cache cleanup (et-cache, jinak Divi ikony = číslice) → deaktivace security/cache pluginů → vhost + SSL
- **Search/replace** — `cli/search-replace-db.php` opravuje délky `s:NN:` v serializovaných datech
- **Per-projekt PHP verze** — `projects.php_version` + FPM pool

Podrobný postup: [docs/duplicator-import.md](docs/duplicator-import.md)

---

## Testování

Testy jsou kategorizované podle modulů a profilů spouštění:

```bash
bin/test.sh smoke                # kritická cesta (8 testů, ~10 s) — po každé změně
bin/test.sh unit                 # unit testy (repositáře, služby) — bez HTTP serveru
bin/test.sh integration          # všechny API testy
bin/test.sh integration invoices # jen modul (auth, clients, projects, tasks, invoices,
                                 # finance, notes, files, settings, dashboard)
bin/test.sh security             # bezpečnostní testy
bin/test.sh e2e                  # Playwright E2E (build + specy frontend/e2e/)
bin/test.sh full                 # kompletní sada — běží v CI na PR
```

- **Skupiny modulů** — PHPUnit `@group` anotace, modul lze spustit i přímo: `vendor/bin/phpunit --group invoices`
- **Base třídy** — `UnitTestCase` (test DB bez serveru) vs `TestCase` (DB + PHP built-in server na 8080)
- Test DB se resetuje před každým testem (`devapppro_test`, schema.sql + seed_test.sql)
- **CI** — GitHub Actions (`.github/workflows/tests.yml`): MariaDB service + `bin/test.sh full` při pushi na main / PR
- **Bezpečnost testů** — testy běží výhradně na `devapppro_test`; pojistka (UnitTestCase + test-router) odmítne spustit testy proti jiné než `_test` databázi
- 220+ testů: Unit + Integration (API přes HTTP) + Security

## Vývoj

### Pravidla (zkráceně — kompletní v AGENTS.md)

**Databáze**
- Změna DB = migrační soubor `database/migration_XXX.sql` + ihned synchronizovat `schema.sql` (testy na něm stojí)
- Migrace idempotentní, `schema.sql` je zdroj pravdy (porovnávat s live DB)

**Backend**
- Validace v kontrolerech (422), částky počítat na serveru, PDO prepared statements
- Po změně: `php -l` + `bin/test.sh smoke` + `bin/test.sh integration <modul>`

**Frontend**
- Po změně: `tsc` + `npm run build` + vizuální kontrola (build se ztrácí v cache prohlížeče)
- Bez stínů a inline stylů (CSP), UI ve stylu shadcn, texty česky

**Testy**
- Nová funkce = nové testy s `@group` modulu
- **Pre-push gate:** `bin/hooks/pre-push` (aktivace `git config core.hooksPath bin/hooks`) spustí `unit` + `smoke`; push při selhání padne. `full` sada běží v CI na PR.

**Git**
- Feature větve (`feat/`, `fix/`, `docs/`, `chore/`) → **Pull Request** → CI zelená → merge přes GitHub UI (do main se nepushuje přímo)
- Commity česky „co a proč", žádný force-push, `pull` před pushem větve
- Každý PR doplní položku do `CHANGELOG.md` → `[Unreleased]` (Keep a Changelog + SemVer)
- Tajemství (`config/*.php`, secrets ve vhostu) se necommitují

**Proces**
- Komunikace česky, ověřovat reálné chování (ne jen syntax), záloha před systémovými změnami

---

## Bezpečnost

- **Lokální pouze** — Apache vhost `Require local`, bind 127.0.0.1
- **Session** — HttpOnly, SameSite=Lax, detekce krádeže (IP/UA)
- **CSRF** — token pro všechny mutující operace
- **CSP** — striktní `script-src 'self'`, `style-src 'self'` (bez unsafe-inline)
- **Uploady** — whitelist MIME↔extenze, max 10 MB, blokace EXE/SH/PHP/JS
- **SQL injection** — PDO prepared statements všude, whitelisty sloupců
- **Path traversal** — `is_safe_path()`, `.htaccess` blokuje `..`
- **Tajemství** — env proměnné přes Apache SetEnv, fail-fast pokud chybí klíče
- **Error handling** — generické 500 zprávy, logy se sanitizací hesel

---

## Struktura projektu

```
api/           tenké HTTP vstupní body
src/           PHP kód (PSR-4: DevAppPro\)
  Controllers/  validace + HTTP vrstva
  Repositories/ PDO přístup k datům
  Services/     PDF, sync, crypto, notifikace
  Core/         ApiController, Constants, Container
  Libs/DupArchive/  Duplicator DAF extrakce
cli/           skripty pro cron/terminál
config/        konfigurace (database.php, config.php — v .gitignore)
database/      schema.sql, seed, migrace
docs/          dokumentace (00-10, revize, opravy, duplicator-import)
frontend/      React SPA (Vite)
  src/pages/     stránky (Dashboard, Clients, Projects, Finance, …)
  src/components/  ui/ (shadcn) · shared/ · modulové komponenty
  src/hooks/     TanStack Query hooks
  src/lib/       api klient, utils, constants
storage/       nahrané soubory (v .gitignore)
tests/         PHPUnit (Unit, Integration, Security)
assets/dist/   build výstup frontendu (v .gitignore)
```

---

## Dokumentace

- [docs/duplicator-import.md](docs/duplicator-import.md) — obnova webů z Duplicator záloh
- [AGENTS.md](AGENTS.md) — vývojová pravidla a provozní konvence pro agenty
- [docs/](docs/) — dokumentace, revize, opravy a post-mortemy incidentů
