# Fáze 1 - Infrastruktura a Autentizace

**Fáze:** Fáze 1 (základy)
**Datum vytvoření:** 2026-09-11
**Rozsah:** ~25 nových souborů
**Obtížnost:** Vysoká
**Riziko:** Střední

---

## 1. Cíl

**Co se implementuje:**
- Základní infrastruktura projektu (Composer, adresářová struktura, konfigurace)
- Databázové schema pro `users`, `login_attempts`, `settings`, `company_profile`
- Autentizace (login, logout, session, CSRF)
- Reset hesla (hint, reset)
- PHPUnit test infrastruktura
- První testy (TDD)

**Co NENÍ součástí (scope boundaries):**
- Neimplementuje se frontend (React) - to je Fáze 5
- Neimplementují se CRUD moduly (klienti, projekty, ...) - Fáze 2-3
- Neimplementuje se PDF generování - Fáze 3
- Neimplementuje se auto-sync projektů - Fáze 2
- Neimplementuje se mPDF - Fáze 3

---

## 1b. Reálné riziko a obtížnost

### 1b.1 Obtížnost implementace

**Hodnocení:** Vysoká

**Odůvodnění:**
- Greenfield projekt - vše se vytváří od nuly
- Infrastruktura (Composer, PHPUnit, DB) musí fungovat před implementací
- Autentizace má mnoho bezpečnostních požadavků (session, CSRF, rate limit)
- TDD vyžaduje funkční test infrastrukturu před prvním testem

**Co je snadné (rutinní):**
- DB schema (SQL) - je zdokumentováno v `05-database.md`
- Konfigurační soubory - vzor v `08-deployment.md`
- Session konfigurace - vzor v `01-security.md`

**Co je složité:**
- PHPUnit test infrastruktura s test DB (setUp/tearDown, schema loading)
- CSRF double-submit cookie mechanismus (generování, validace, rotace)
- Rate limiting s `login_attempts` (časové okno, blokace, cleanup)
- Session hardening (všechny cookie flags, strict mode, IP/UA kontrola)
- Reset hesla - zničení sessions po resetu (filesystem sessions - jak najít sessions uživatele?)

**Neznámé (co se musí dohledat):**
- Jak PHPUnit spustit s test DB (Guzzle HTTP klient nebo Symfony HttpClient)
- Jak zničit filesystem sessions konkrétního uživatele po resetu hesla
- Jak přesně nastavit PHP session cookie params v `session_set_cookie_params()`

### 1b.2 Reálné riziko

**Hodnocení:** Střední

**Odůvodnění:**
- Autentizace je kritická bezpečnostní funkce
- Špatná implementace může vést k zranitelnosti
- Ale lokální aplikace na 127.0.0.1 snižuje riziko (přístup jen z localhost)

**Rizika a mitigace:**

| Riziko | Pravděpodobnost | Dopad | Mitigace |
|---|---|---|---|
| CSRF token neplatný po session regeneraci | Střední | Vysoký | Test login → akce, token rotace po loginu |
| Rate limit blokuje legit uživatele | Nízká | Střední | 5 pokusů/hodinu je dost, cleanup starých |
| Session fixation po loginu | Nízká | Vysoký | `session_regenerate_id(true)` v testu |
| Reset hesla nezničí sessions | Střední | Vysoký | Smaž session soubory pro user_id |
| DB migrace smaže data | Nízká | Kritický | Test DB oddělená, seed_test.sql |
| PHPUnit test DB konflikt | Střední | Střední | setUp/tearDown s DROP TABLES |

**Dopad na produkci:**
- Uživatel nemůže přihlásit, pokud autentizace selže
- Neztratí data (jen nemůže přistoupit k aplikaci)

**Rollback plán:**
- `git checkout` před commitem
- Drop test DB (`devapppro_test`)
- Smazat `vendor/` a `composer.lock`, reinstall
- Smazat vytvořené soubory

### 1b.3 Rozdělení (pokud je potřeba)

Obtížnost Vysoká → rozdělit na dílčí plány:

- **Plán 1a:** Infrastruktura (Composer, adresáře, konfigurace, DB schema, seed)
- **Plán 1b:** Autentizace (login, logout, session, CSRF, rate limit)
- **Plán 1c:** Reset hesla (hint, reset, zničení sessions)

Každý dílčí plán má vlastní testy a kriteria dokončení. Začneme 1a.

---

## 2. Předpoklady

**Co musí být hotové předem:**
- Apache nainstalován, PHP 8.3, MariaDB (už existují podle předchozí konverzace)
- Projektový adresář `/var/www/devapppro/` (existuje)
- Dokumentace v `docs/` (existuje)

**Závislosti:**
- **Composer balíčky:** `phpunit/phpunit` (dev), `guzzlehttp/guzzle` (dev, pro testy)
- **npm balíčky:** žádné (frontend je Fáze 5)
- **DB tabulky:** `users`, `login_attempts`, `settings`, `company_profile`
- **Konfigurace:** `config/config.php`, `config/database.php`

---

## 3. Načtení reálného kódu (kontext)

### 3.1 Existující soubory k načtení

| Soubor | Proč načíst | Co hledat |
|---|---|---|
| `docs/04-architecture.md` | Architektura, bootstrap, adresáře | Struktura, bootstrap.php vzor, .htaccess |
| `docs/05-database.md` | DB schema pro users, settings, company_profile | SQL pro tabulky, seed |
| `docs/06-api.md` | API specifikace pro auth endpointy | Login, logout, me, password-hint, reset |
| `docs/01-security.md` | Bezpečnostní požadavky | Session, CSRF, rate limit, hesla |
| `docs/08-deployment.md` | Konfigurace, cron | config.php vzor, DB config |
| `docs/10-testovani.md` | Test strategie, TestCase vzor | PHPUnit setup, test struktura |

### 3.2 Konvence k dodržet

Po načtení kódu **zapsat** zjištěné konvence:

- **Pojmenování tříd:** PascalCase, suffix `Controller`, `Repository`
- **Pojmenování souborů:** `XxxApiController.php`, `XxxRepository.php`
- **Pojmenování metod:** `camelCase` - `index()`, `show()`, `store()`, `update()`, `destroy()`
- **Struktura controlleru:** extends `Core/ApiController`, metoda `handle()` dispatchuje
- **JSON response formát:** `{ "data": [...], "total": N }` pro seznamy, `{ ... }` pro detail
- **Error handling:** `{ "error": "Zpráva" }` s HTTP kódem (400, 401, 403, 404, 422, 500)
- **PSR-4 autoloading:** `src/` → namespace `DevAppPro\`
- **Frontend hook struktura:** N/A (frontend je Fáze 5)

### 3.3 Pravidla modularity k dodržet

- [ ] Max 500 řádků na soubor (520 tolerováno)
- [ ] Max 4 úrovně zanoření logiky (ne JSX)
- [ ] Rule of Three - extrahovat při 3. výskytu
- [ ] Žádné duplikace sdílených funkcí
- [ ] Sdílené funkce v `helpers.php`

---

## 4. Dotčené soubory

### 4.1 Nové soubory (co se vytvoří)

```
/var/www/devapppro/
├── composer.json
├── bootstrap.php
├── .htaccess
├── index.html                    - prázdný placeholder (frontend později)
├── config/
│   ├── config.php
│   └── database.php
├── api/
│   └── auth.php
├── src/
│   ├── Core/
│   │   ├── ApiController.php
│   │   └── Repository.php
│   ├── Controllers/
│   │   └── AuthApiController.php
│   ├── Repositories/
│   │   ├── UserRepository.php
│   │   └── SettingsRepository.php
│   ├── Auth.php
│   └── helpers.php
├── database/
│   ├── schema.sql                - users, login_attempts, settings, company_profile
│   ├── seed.sql                  - admin user, settings, company_profile
│   └── seed_test.sql             - test data
├── tests/
│   ├── TestCase.php
│   ├── phpunit.xml
│   ├── Unit/
│   │   └── AuthTest.php
│   ├── Integration/
│   │   ├── LoginApiTest.php
│   │   └── ResetPasswordApiTest.php
│   └── Security/
│       ├── CsrfTest.php
│       ├── RateLimitTest.php
│       └── AuthorizationTest.php
└── storage/
    └── .gitkeep
```

### 4.2 Upravované soubory

Žádné (greenfield projekt).

### 4.3 Mazané soubory

Žádné.

---

## 5. Database změny

### 5.1 Nové tabulky

```sql
-- users (1.1)
CREATE TABLE users (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(100) NOT NULL UNIQUE,
    name            VARCHAR(100) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    password_hint   VARCHAR(255),
    email           VARCHAR(255),
    theme           ENUM('light', 'dark') DEFAULT 'dark',
    sidebar_collapsed TINYINT(1) DEFAULT 0,
    per_page        INT UNSIGNED DEFAULT 20,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- company_profile (1.2)
CREATE TABLE company_profile (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    type            ENUM('individual', 'company') NOT NULL DEFAULT 'individual',
    first_name      VARCHAR(100),
    last_name       VARCHAR(100),
    company_name    VARCHAR(200),
    ico             VARCHAR(20),
    dic             VARCHAR(30),
    email           VARCHAR(255),
    phone           VARCHAR(50),
    address         VARCHAR(500),
    bank_account    VARCHAR(50),
    iban            VARCHAR(50),
    swift           VARCHAR(20),
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- settings (1.3)
CREATE TABLE settings (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    `key`           VARCHAR(100) NOT NULL UNIQUE,
    value           TEXT,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_key (`key`)
);

-- login_attempts (1.4)
CREATE TABLE login_attempts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    ip_address      VARCHAR(45) NOT NULL,
    username        VARCHAR(100),
    attempted_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    success         TINYINT(1) DEFAULT 0,
    INDEX idx_ip_time (ip_address, attempted_at),
    INDEX idx_username_time (username, attempted_at)
);
```

### 5.2 Upravované tabulky

Žádné (greenfield).

### 5.3 Seed data

```sql
-- Admin user (heslo: admin123 - jen pro dev!)
INSERT INTO users (username, name, password_hash, email, theme)
VALUES ('admin', 'Administrátor', '$2y$12$...', 'admin@devapppro.local', 'dark');

-- Settings
INSERT INTO settings (`key`, value) VALUES
('invoice_number_format', '{year}{seq:03d}'),
('invoice_seq_year', '2026'),
('invoice_seq', '0'),
('default_vat_rate', '21'),
('default_due_days', '14'),
('timezone', 'Europe/Prague'),
('first_day_of_week', '1'),
('fiscal_year_start', '01-01'),
('currency', 'CZK'),
('currency_decimals', '0');

-- Company profile (prázdný, uživatel vyplní)
INSERT INTO company_profile (id, type) VALUES (1, 'individual');
```

### 5.4 Migrace

- [ ] `database/schema.sql` vytvořeno
- [ ] `database/seed.sql` vytvořeno
- [ ] `database/seed_test.sql` vytvořeno (admin s heslem `test123`)
- [ ] Změna idempotentní (DROP TABLE IF EXISTS před CREATE)

---

## 6. API změny

### 6.1 Nové endpointy

| Metoda | Cesta | Popis | Request | Response |
|---|---|---|---|---|
| POST | `/api/auth/login` | Přihlášení | `{username, password}` | `200 {user}` / `401` / `429` |
| POST | `/api/auth/logout` | Odhlášení | - | `200 {success: true}` |
| GET | `/api/auth/me` | Aktuální uživatel | - | `200 {user}` / `401` |
| POST | `/api/auth/password-hint` | Hint pro username | `{username}` | `200 {hint}` / `404` |
| POST | `/api/auth/reset-password` | Reset hesla | `{username, new_password, new_password_confirm}` | `200` / `422` |
| PUT | `/api/users/me/password-hint` | Nastavení hintu | `{password_hint}` | `200` |

### 6.2 Upravované endpointy

Žádné (greenfield).

### 6.3 Příklady request/response

```json
// POST /api/auth/login
// Request
{ "username": "admin", "password": "test123" }

// Response 200
{
  "user": {
    "id": 1,
    "name": "Administrátor",
    "username": "admin",
    "theme": "dark",
    "sidebar_collapsed": false,
    "per_page": 20
  }
}

// Response 401
{ "error": "Neplatné přihlašovací údaje." }

// Response 429
{ "error": "Příliš mnoho pokusů. Zkuste to znovu za 5 min 30 s." }
```

---

## 7. Frontend změny

Žádné (frontend je Fáze 5).

---

## 8. Testy (TDD - píšou se PŘED implementací)

### 8.1 Seznam testů k napsání

| Test soubor | Co ověřuje | Stav |
|---|---|---|
| `tests/TestCase.php` | Základní třída, DB setup/teardown | [ ] napsán |
| `tests/Unit/AuthTest.php` | password_verify, session, hash | [ ] napsán |
| `tests/Integration/LoginApiTest.php` | Login endpoint, rate limit | [ ] napsán |
| `tests/Integration/ResetPasswordApiTest.php` | Hint, reset, validace | [ ] napsán |
| `tests/Security/CsrfTest.php` | CSRF token pro POST | [ ] napsán |
| `tests/Security/RateLimitTest.php` | 5 pokusů/hodinu | [ ] napsán |
| `tests/Security/AuthorizationTest.php` | 401 pro nepřihlášené | [ ] napsán |

### 8.2 Co každý test ověřuje

```
TestCase:
  - setUp: připojit test DB, načíst schema, načíst seed
  - tearDown: drop all tables
  - login() helper

AuthTest:
  - test_password_hash_bcrypt
  - test_password_verify_spravne_heslo
  - test_password_verify_spatne_heslo
  - test_session_regenerate_id_pri_login

LoginApiTest:
  - test_uspesne_prihlaseni
  - test_spatne_heslo_vrati_401
  - test_neexistujici_uzivatel_vrati_401
  - test_prihlaseni_regeneruje_session_id
  - test_rate_limit_blokuje_po_5_pokusech
  - test_login_vrati_user_preferences

ResetPasswordApiTest:
  - test_hint_vrati_napovedu
  - test_hint_pro_neexistujiciho_vrati_404
  - test_reset_zmeni_heslo
  - test_reset_neshoda_hesel_vrati_422
  - test_reset_kratke_heslo_vrati_422

CsrfTest:
  - test_post_bez_csrf_tokenu_vrati_403
  - test_post_se_spatnym_csrf_tokenem_vrati_403
  - test_get_bez_csrf_tokenu_projde

RateLimitTest:
  - test_5_pokusu_za_hodinu_projde
  - test_6_pokus_blokovany
  - test_rate_limit_reset_po_hodine

AuthorizationTest:
  - test_get_auth_me_bez_prihlaseni_vrati_401
  - test_public_endpointy_nevyaduji_prihlaseni
```

### 8.3 Test data (seed pro testy)

```sql
-- database/seed_test.sql
INSERT INTO users (username, name, password_hash, email, theme, password_hint)
VALUES ('admin', 'Administrátor', '$2y$12$...hash_pro_test123...', 'admin@devapppro.local', 'dark', 'Jméno mého prvního psa');

INSERT INTO settings (`key`, value) VALUES
('invoice_number_format', '{year}{seq:03d}'),
('invoice_seq_year', '2026'),
('invoice_seq', '0'),
('default_vat_rate', '21'),
('default_due_days', '14'),
('timezone', 'Europe/Prague'),
('first_day_of_week', '1'),
('fiscal_year_start', '01-01'),
('currency', 'CZK'),
('currency_decimals', '0');

INSERT INTO company_profile (id, type) VALUES (1, 'individual');
```

---

## 9. Kontrolní body (odkaz na `09-checklisty.md`)

### 9.1 Bezpečnost (priorita 1)

- [ ] 1.1 Autentizace - login, session_regenerate_id, bcrypt cost 12
- [ ] 1.2 Reset hesla - hint, reset, zničení sessions, validace
- [ ] 1.3 Session hardening - httponly, samesite, strict_mode, gc_maxlifetime
- [ ] 1.4 Rate limiting - 5 pokusů/hodinu, login_attempts
- [ ] 1.5 CSRF ochrana - double-submit cookie, X-CSRF-Token header
- [ ] 1.7 SQL Injection - PDO prepared statements
- [ ] 1.8 Bezpečnostní hlavičky - X-Content-Type-Options, X-Frame-Options, CSP
- [ ] 1.10 Konfigurace - config.php mimo webroot, žádné secrets v kódu
- [ ] 1.11 Error handling - display_errors Off, generické zprávy
- [ ] 1.13 Souborové oprávnění - 755/640
- [ ] 1.14 Apache bind - 127.0.0.1
- [ ] 1.15 Sanitizace logů - žádné hesla v logu

### 9.2 Modularita (priorita 2)

- [ ] 2.1 Vrstvená architektura - Controller → Repository → DB
- [ ] 2.2 Backend moduly - PSR-4, Repository pattern, Core třídy
- [ ] 2.4 Pravidla - max 500 řádků, 4 úrovně, Rule of Three
- [ ] 2.5 Konfigurace - konstanty v config.php

### 9.3 Rychlost (priorita 3)

- [ ] 3.2 Backend - OPcache (ověřit v php.ini)
- [ ] 3.3 API - prepared statements, indexy

---

## 10. Pořadí kroků

### Krok 1: Infrastruktura (Plán 1a)

- [ ] **Green:** Vytvořit `composer.json` s PSR-4 autoloading
- [ ] **Green:** Vytvořit adresářovou strukturu (`src/`, `api/`, `config/`, `database/`, `tests/`)
- [ ] **Green:** Vytvořit `config/config.php` a `config/database.php`
- [ ] **Green:** Vytvořit `bootstrap.php`
- [ ] **Green:** Vytvořit `.htaccess`
- [ ] **Green:** Vytvořit `database/schema.sql` (4 tabulky)
- [ ] **Green:** Vytvořit `database/seed.sql` a `seed_test.sql`
- [ ] **Verify:** `composer install` projde
- [ ] **Verify:** PHP syntax check všech souborů
- [ ] **Verify:** Schema se načte do `devapppro_test`

### Krok 2: PHPUnit infrastruktura

- [ ] **Red:** Napsat `tests/TestCase.php` (selže - chybí závislosti)
- [ ] **Green:** Napsat `tests/phpunit.xml`
- [ ] **Green:** Nainstalovat PHPUnit + Guzzle přes Composer
- [ ] **Green:** Napsat `tests/TestCase.php` (setUp/tearDown, login helper)
- [ ] **Verify:** `./vendor/bin/phpunit` projde (prázdný test)

### Krok 3: Core třídy

- [ ] **Green:** Vytvořit `src/Core/ApiController.php` (abstraktní, handle(), JSON response)
- [ ] **Green:** Vytvořit `src/Core/Repository.php` (abstraktní, PDO, prepared statements)
- [ ] **Green:** Vytvořit `src/helpers.php` (json_response(), json_input(), csrf funkce)
- [ ] **Verify:** PHP syntax check

### Krok 4: Auth - Login (TDD)

- [ ] **Red:** Napsat `tests/Integration/LoginApiTest.php` (selže - endpoint neexistuje)
- [ ] **Green:** Vytvořit `src/Auth.php` (login, logout, session, CSRF)
- [ ] **Green:** Vytvořit `src/Repositories/UserRepository.php`
- [ ] **Green:** Vytvořit `src/Controllers/AuthApiController.php`
- [ ] **Green:** Vytvořit `api/auth.php`
- [ ] **Verify:** `./vendor/bin/phpunit tests/Integration/LoginApiTest.php`

### Krok 5: Auth - Rate Limiting (TDD)

- [ ] **Red:** Napsat `tests/Security/RateLimitTest.php` (selže)
- [ ] **Green:** Implementovat rate limiting v `Auth.php` (login_attempts)
- [ ] **Verify:** `./vendor/bin/phpunit tests/Security/RateLimitTest.php`

### Krok 6: Auth - CSRF (TDD)

- [ ] **Red:** Napsat `tests/Security/CsrfTest.php` (selže)
- [ ] **Green:** Implementovat CSRF v `helpers.php` (double-submit cookie)
- [ ] **Verify:** `./vendor/bin/phpunit tests/Security/CsrfTest.php`

### Krok 7: Auth - Authorization (TDD)

- [ ] **Red:** Napsat `tests/Security/AuthorizationTest.php` (selže)
- [ ] **Green:** Implementovat `requireAuth()` v `Auth.php`
- [ ] **Verify:** `./vendor/bin/phpunit tests/Security/AuthorizationTest.php`

### Krok 8: Reset hesla (TDD)

- [ ] **Red:** Napsat `tests/Integration/ResetPasswordApiTest.php` (selže)
- [ ] **Green:** Implementovat hint a reset v `AuthApiController.php`
- [ ] **Green:** Implementovat zničení sessions po resetu
- [ ] **Verify:** `./vendor/bin/phpunit tests/Integration/ResetPasswordApiTest.php`

### Krok 9: Settings a Company Profile

- [ ] **Green:** Vytvořit `src/Repositories/SettingsRepository.php`
- [ ] **Green:** Přidat endpointy pro settings a company_profile do `api/auth.php` (nebo nové `api/settings.php`)
- [ ] **Verify:** PHP syntax check

### Krok 10: Finální ověření

- [ ] **Spustit všechny testy:** `./vendor/bin/phpunit`
- [ ] **PHPStan:** `./vendor/bin/phpstan analyse src/ --level=6` (pokud nainstalováno)
- [ ] **Odškrtnout checklisty** v `09-checklisty.md`
- [ ] **Ověřit session konfiguraci** (phpinfo nebo test)

---

## 11. Kriteria dokončení

Plán je dokončen, když **všechny** podmínky platí:

- [ ] Všechny testy procházejí (`./vendor/bin/phpunit`)
- [ ] Login endpoint funguje (200, 401, 429)
- [ ] Logout endpoint funguje (200)
- [ ] GET /api/auth/me funguje (200, 401)
- [ ] Reset hesla funguje (hint, reset, validace)
- [ ] CSRF ochrana funguje (403 bez tokenu)
- [ ] Rate limiting funguje (429 po 5 pokusech)
- [ ] Session hardening (httponly, samesite, strict_mode)
- [ ] PDO prepared statements všude
- [ ] Žádný soubor > 500 řádků
- [ ] Checklist položky odškrtnuty v `09-checklisty.md`
- [ ] Database schema idempotentní
- [ ] `composer install` projde čistě

---

## 12. Poznámky

- Heslo pro test admina: `test123` (jen pro test DB, nikdy produkční)
- Bcrypt hash pro `test123` se generuje při seedu (nebo hardcoded v seed_test.sql)
- Test DB: `devapppro_test` (oddělená od `devapppro`)
- Frontend se neimplementuje - testy volají API přes HTTP klienta

---

## 13. Změny oproti plánu

| Datum | Změna | Důvod |
|---|---|---|
| | | |
