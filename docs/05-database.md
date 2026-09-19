# 05 - Databáze

MariaDB / MySQL. Všechny částky uloženy jako celá čísla v haléřích (ne float/decimal).

## 0. Zdroj pravdy

**Klienti jsou primárním zdrojem pravdy.**

Všechno začíná klientem. Projekt, faktura, transakce, poznámka i soubor
mohou být navázány na klienta. Projekt je volitelný - umožňuje existenci
faktur, transakcí a poznámek bez projektu (konzultace, záloha, drobný příjem).

Hierarchie:
```
Klient (zdroj pravdy)
  ├── Projekt (volitelný)
  │     └── Úkol
  ├── Faktura (volitelně s projektem)
  │     └── Platba faktury
  ├── Transakce (volitelně s projektem)
  ├── Poznámka (polymorfní)
  └── Soubor (polymorfní)
```

Pravidla:
- Projekt **může** existovat bez klienta (`client_id = NULL`) pro interní projekty
- Faktura **může** existovat bez projektu (`project_id = NULL`)
- Transakce **může** existovat bez projektu (`project_id = NULL`)
- Úkol **vždy** patří projektu (`project_id NOT NULL`)
- Platba faktury **vždy** patří faktuře (`invoice_id NOT NULL`)
- Detail klienta je centrální hub - zobrazuje vše navázané

---

## 1. Schema

### 1.1 users

```sql
CREATE TABLE users (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(100) NOT NULL UNIQUE,
    name            VARCHAR(100) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,          -- bcrypt cost 12
    password_hint   VARCHAR(255),                   -- bezpečnostní nápověda pro reset hesla
    email           VARCHAR(255),
    theme           ENUM('light', 'dark') DEFAULT 'dark',  -- uživatelská preference
    sidebar_collapsed TINYINT(1) DEFAULT 0,        -- uživatelská preference (0 = rozbalený)
    per_page        INT UNSIGNED DEFAULT 20,        -- uživatelská preference (počet položek na stránku)
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

**Uživatelské preference:**
- `theme` - 'dark' (výchozí) nebo 'light'
- `sidebar_collapsed` - 0 = rozbalený (výchozí), 1 = sbalený
- `per_page` - 20 (výchozí), počet položek na stránku v DataTable

**Reset hesla:**
- `password_hint` - osobní nápověda (např. "Jméno mého prvního psa")
- Zobrazí se na login stránce po zadání username při resetu
- Po zobrazení hintu lze zadat nové heslo
- **Bezpečnost:** lokální app na 127.0.0.1, přístup má jen uživatel
- Hint není ověření - je připomínka. Reset je možný i bez hintu (prázdný hint = rovnou nové heslo)

### 1.2 company_profile

Údaje prodávajícího (vaše firma) pro faktury. Jednořádková tabulka.

```sql
CREATE TABLE company_profile (
    id              INT AUTO_INCREMENT PRIMARY KEY,  -- vždy 1
    type            ENUM('individual', 'company') NOT NULL DEFAULT 'individual',
    first_name      VARCHAR(100),                    -- křestní jméno (osoba)
    last_name       VARCHAR(100),                    -- příjmení (osoba)
    company_name    VARCHAR(200),                    -- název firmy (firma)
    ico             VARCHAR(20),                     -- IČO
    dic             VARCHAR(30),                     -- DIČ
    email           VARCHAR(255),
    phone           VARCHAR(50),
    address         VARCHAR(500),
    bank_account    VARCHAR(50),                     -- bankovní účet (číslo/předčíslo)
    iban            VARCHAR(50),                      -- IBAN
    swift           VARCHAR(20),                      -- SWIFT/BIC kód banky
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

**Použití:** Na faktuře se zobrazují údaje prodávajícího z této tabulky.
Jednořádková - vždy `id = 1`. Lze upravit přes Nastavení → Profil firmy.

### 1.3 settings

Aplikační nastavení (key-value). Flexibilní úložiště pro konfiguraci.

```sql
CREATE TABLE settings (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    `key`           VARCHAR(100) NOT NULL UNIQUE,
    value           TEXT,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_key (`key`)
);
```

**Výchozí nastavení (seed):**
```sql
INSERT INTO settings (`key`, value) VALUES
('invoice_number_format', '{year}{seq:03d}'),     -- 2026001, 2026002...
('invoice_seq_year', '2026'),                     -- aktuální rok pro sekvenci
('invoice_seq', '0'),                             -- počítadlo faktur v roce
('default_vat_rate', '21'),                      -- výchozí DPH %
('default_due_days', '14'),                       -- výchozí splatnost (dní)
('timezone', 'Europe/Prague'),                    -- timezone
('first_day_of_week', '1'),                       -- 1 = pondělí
('fiscal_year_start', '01-01'),                   -- kalendářní rok
('currency', 'CZK'),                              -- měna
('currency_decimals', '0');                       -- bez desetinných míst
```

**Číslování faktur:**
- Formát `{year}{seq:03d}` → `2026001`, `2026002`, atd.
- Při změně roku se `invoice_seq` resetuje na 0
- `invoice_seq` se inkrementuje při vytvoření faktury (status != draft)
- Při přepnutí z `draft` na `sent` se přidělí číslo (pokud bylo prázdné)

### 1.4 login_attempts

```sql
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

### 1.5 clients

```sql
CREATE TABLE clients (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    type            ENUM('individual', 'company') NOT NULL DEFAULT 'individual',
    first_name      VARCHAR(100),                   -- křestní jméno (NULL pro firmu)
    last_name       VARCHAR(100),                    -- příjmení (NULL pro firmu)
    company_name    VARCHAR(200),                    -- název firmy (NULL pro osobu)
    ico             VARCHAR(20),                     -- IČO (pouze firma)
    dic             VARCHAR(30),                     -- DIČ (pouze firma)
    bank_account    VARCHAR(50),                     -- bankovní účet (pro platby)
    email           VARCHAR(255),
    phone           VARCHAR(50),
    address         VARCHAR(500),
    note            TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_last_name (last_name, first_name),
    INDEX idx_company_name (company_name),
    INDEX idx_email (email),
    INDEX idx_ico (ico)
);
```

**Typ klienta:**
- `individual` - fyzická osoba (first_name + last_name vyžadováno, company_name NULL)
- `company` - firma (company_name vyžadováno, first_name/last_name NULL)

**Zobrazování:**
- `individual`: `first_name last_name` (např. "Jan Novák")
- `company`: `company_name` (např. "Novák s.r.o.")

**Řazení:** podle `last_name, first_name` (osoby) nebo `company_name` (firmy).

### 1.6 projects

```sql
CREATE TABLE projects (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    client_id       INT,
    name            VARCHAR(200) NOT NULL,
    description     TEXT,
    status          ENUM('active', 'on_hold', 'completed', 'cancelled', 'archived')
                    DEFAULT 'active',
    folder_path     VARCHAR(500),                    -- název sledované složky (NULL = ručně vytvořeno)
    budget_cents    INT UNSIGNED DEFAULT 0,         -- rozpočet v haléřích
    started_at      DATE,
    deadline        DATE,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    INDEX idx_client_id (client_id),
    INDEX idx_status (status),
    INDEX idx_deadline (deadline),
    INDEX idx_folder_path (folder_path)
);
```

`folder_path` - název složky v `PROJECTS_WATCH_DIR` (např. "Web redesign").
NULL znamená, že projekt byl vytvořen ručně v aplikaci (ne ze složky).
Při smazání složky z disku se projekt označí jako `archived` (data zůstanou).

### 1.7 tasks

```sql
CREATE TABLE tasks (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    project_id      INT NOT NULL,
    title           VARCHAR(255) NOT NULL,
    description     TEXT,
    status          ENUM('todo', 'in_progress', 'done', 'cancelled')
                    DEFAULT 'todo',
    priority        ENUM('low', 'medium', 'high', 'urgent')
                    DEFAULT 'medium',
    due_date        DATE,
    assigned_to     VARCHAR(100),
    estimated_minutes INT UNSIGNED DEFAULT 0,        -- odhadovaný čas v minutách
    spent_minutes   INT UNSIGNED DEFAULT 0,          -- strávený čas v minutách
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    INDEX idx_project_id (project_id),
    INDEX idx_status (status),
    INDEX idx_priority (priority),
    INDEX idx_due_date (due_date)
);
```

**Čas v minutách** - pro flexibilitu (1h 30m = 90 minut). Frontend převádí
na `1h 30m` nebo `1.5h` podle kontextu.

### 1.8 invoices

```sql
CREATE TABLE invoices (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    client_id       INT,
    project_id      INT,
    invoice_number  VARCHAR(50) NOT NULL UNIQUE,
    status          ENUM('draft', 'sent', 'paid', 'overdue', 'cancelled')
                    DEFAULT 'draft',
    subtotal_cents  INT UNSIGNED NOT NULL,          -- částka bez DPH v haléřích
    vat_rate_percent DECIMAL(5,2) DEFAULT 0,        -- sazba DPH v % (0, 21, 15...)
    vat_amount_cents INT UNSIGNED DEFAULT 0,        -- částka DPH v haléřích
    amount_cents    INT UNSIGNED NOT NULL,          -- celková částka s DPH v haléřích
    paid_cents      INT UNSIGNED DEFAULT 0,         -- zaplaceno v haléřích
    currency        VARCHAR(3) DEFAULT 'CZK',
    variable_symbol VARCHAR(20),                    -- variabilní symbol (max 10 číslic)
    constant_symbol VARCHAR(10),                    -- konstantní symbol (max 4 číslice)
    iban            VARCHAR(50),                     -- IBAN pro platbu
    issue_date      DATE NOT NULL,
    due_date        DATE NOT NULL,
    note            TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    INDEX idx_client_id (client_id),
    INDEX idx_project_id (project_id),
    INDEX idx_status (status),
    INDEX idx_due_date (due_date),
    INDEX idx_invoice_number (invoice_number),
    INDEX idx_variable_symbol (variable_symbol)
);
```

**DPH výpočet:**
- `subtotal_cents` - částka bez DPH
- `vat_rate_percent` - sazba (0, 15, 21)
- `vat_amount_cents` = `subtotal_cents * vat_rate_percent / 100`
- `amount_cents` = `subtotal_cents + vat_amount_cents` (celková s DPH)
- NeDPH plátci: `vat_rate_percent = 0`, `vat_amount_cents = 0`,
  `amount_cents = subtotal_cents`

### 1.9 invoice_payments

```sql
CREATE TABLE invoice_payments (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id      INT NOT NULL,
    amount_cents    INT UNSIGNED NOT NULL,          -- platba v haléřích
    payment_date    DATE NOT NULL,
    method          ENUM('cash', 'bank_transfer', 'card', 'other')
                    DEFAULT 'bank_transfer',
    note            TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    INDEX idx_invoice_id (invoice_id),
    INDEX idx_payment_date (payment_date)
);
```

### 1.10 transactions

```sql
CREATE TABLE transactions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    project_id      INT,
    client_id       INT,
    type            ENUM('income', 'expense') NOT NULL,
    amount_cents    INT UNSIGNED NOT NULL,          -- částka v haléřích
    category        ENUM('office', 'software', 'travel', 'marketing',
                        'hardware', 'services', 'income_project',
                        'income_consulting', 'other')
                    DEFAULT 'other',
    description     VARCHAR(500),
    transaction_date DATE NOT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    INDEX idx_project_id (project_id),
    INDEX idx_client_id (client_id),
    INDEX idx_type (type),
    INDEX idx_category (category),
    INDEX idx_transaction_date (transaction_date)
);
```

**Kategorie:**
Výdaje (`type = 'expense'`):
- `office` - kancelářské potřeby, nájem
- `software` - licence, SaaS
- `travel` - cestovné
- `marketing` - reklama, PR
- `hardware` - technika, vybavení
- `services` - externí služby
- `other` - ostatní výdaje

Příjmy (`type = 'income'`):
- `income_project` - příjem z projektu
- `income_consulting` - konzultace
- `other` - ostatní příjmy

### 1.11 notes

Poznámky s polymorfní vazbou - jedna poznámka může být navázána na více entit
(klient, projekt, úkol, faktura) přes junction tabulku `noteables`.

```sql
CREATE TABLE notes (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT,                            -- kdo poznámku vytvořil
    title           VARCHAR(200),                   -- volitelný nadpis
    content         TEXT NOT NULL,                  -- obsah poznámky
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at)
);
```

### 1.12 noteables

Junction tabulka - propojení poznámky s entitou (polymorfní).

```sql
CREATE TABLE noteables (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    note_id         INT NOT NULL,
    entity_type     ENUM('client', 'project', 'task', 'invoice') NOT NULL,
    entity_id       INT NOT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE,
    INDEX idx_note_id (note_id),
    INDEX idx_entity (entity_type, entity_id)
);
```

### 1.13 files

Metadata souborů. Soubory fyzicky uloženy na lokálním disku
(`/var/www/devapppro/storage/`). V databázi pouze metadata.

```sql
CREATE TABLE files (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT,                            -- kdo soubor nahrál
    original_name   VARCHAR(255) NOT NULL,          -- původní název souboru
    stored_name     VARCHAR(255) NOT NULL,          -- název na disku (UUID + ext)
    mime_type       VARCHAR(100) NOT NULL,
    size_bytes      INT UNSIGNED NOT NULL,          -- velikost v bytech
    storage_path    VARCHAR(500) NOT NULL,          -- relativní cesta v storage/
    is_image        TINYINT(1) DEFAULT 0,           -- příznak obrázku (PNG, WebP, JPG)
    thumbnail_path  VARCHAR(500),                   -- relativní cesta k thumbnailu (200x200 WebP)
    medium_path     VARCHAR(500),                   -- relativní cesta ke střední variantě (800x800 WebP)
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_mime_type (mime_type),
    INDEX idx_is_image (is_image),
    INDEX idx_created_at (created_at)
);
```

**Obrázky (`is_image = 1`):**
- `thumbnail_path` - 200x200 WebP (generováno při uploadu)
- `medium_path` - 800x800 WebP (generováno při uploadu)
- `storage_path` - originál (PNG/WebP, JPG konvertován na WebP)

**Ne-obrázky (`is_image = 0`):**
- `thumbnail_path` = NULL
- `medium_path` = NULL
- `storage_path` - originál (PDF, DOCX, ZIP, atd.)

### 1.14 fileables

Junction tabulka - propojení souboru s entitou (polymorfní).

```sql
CREATE TABLE fileables (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    file_id         INT NOT NULL,
    entity_type     ENUM('client', 'project', 'task', 'invoice') NOT NULL,
    entity_id       INT NOT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    INDEX idx_file_id (file_id),
    INDEX idx_entity (entity_type, entity_id)
);
```

---

## 2. Konvence

### 2.1 Peníze

- **Všechny částky v haléřích** (INT, ne FLOAT/DECIMAL)
- 100 Kč = 10000 haléřů
- Převod na displej: `amount_cents / 100` s formátováním
- Převod z inputu: `(float) input * 100` zaokrouhleno na int

```php
// Uložení
$cents = (int) round((float) $input * 100);

// Zobrazení
function money_int(int $cents): string {
    return number_format($cents / 100, 0, ',', ' ') . ' Kč';
}
```

### 2.2 Timestamps

- `created_at` - automaticky při INSERT
- `updated_at` - automaticky při UPDATE
- `TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### 2.3 Soft delete

- **Žádný soft delete** - mazání je trvalé
- Kaskádové mazání pouze u závislých dat (tasks → project, payments → invoice)
- U nezávislých dat `ON DELETE SET NULL` (projects → client)

### 2.4 Statusy

| Entita | Statusy |
|---|---|
| Project | active, on_hold, completed, cancelled, archived |
| Task | todo, in_progress, done, cancelled |
| Invoice | draft, sent, paid, overdue, cancelled |
| Transaction | income, expense |

### 2.5 Priorities (úkoly)

| Priorita | Význam |
|---|---|
| low | Nízká - může počkat |
| medium | Střední - normální priorita |
| high | Vysoká - důležité |
| urgent | Urgentní - okamžitě |

### 2.6 Polymorfní vazby (poznámky, soubory)

Poznámky a soubory používají polymorfní vazbu přes junction tabulky
(`noteables`, `fileables`). Každá junction tabulka obsahuje:

- `entity_type` - typ entity (`client`, `project`, `task`, `invoice`)
- `entity_id` - ID entity

Výhody:
- Jedna poznámka/soubor může být navázána na více entit najednou
- Sdílení stejné poznámky napříč entitami
- Smazání entity nezpůsobí kaskádové mazání poznámky (jen junction záznam)

Aplikační vrstva musí validovat, že `entity_id` skutečně existuje pro daný
`entity_type` (databáze nemůže zajistit FK pro polymorfní vazbu).

### 2.7 Soubory - úložiště

- **Fyzické úložiště:** `/var/www/devapppro/storage/`
- **Struktura:** `storage/{YYYY}/{MM}/{uuid}.{ext}` (např. `storage/2026/09/a1b2c3d4.pdf`)
- **Název na disku:** UUID + původní přípona (zabraňuje kolizi, skrývá původní název)
- **Původní název:** uložen v `files.original_name` (zobrazuje se uživateli)
- **Povolené typy:** PDF, PNG, JPG, GIF, WEBP, SVG, TXT, CSV, DOCX, XLSX, ZIP
- **Maximální velikost:** 10 MB
- **Zákaz:** spustitelné soubory (.exe, .sh, .php, .bat, .cmd)
- **Oprávnění:** `storage/` adresář `chmod 750`, soubory `chmod 640`
- **Apache:** zákaz přístupu k `storage/` přes HTTP (přes .htaccess)
- **Stahování:** přes PHP endpoint (kontrola oprávnění, ne přímý přístup)

---

## 3. Migrace

### 3.1 Init

```bash
mysql -u root -p < database/schema.sql
mysql -u root -p < database/seed.sql
```

### 3.2 Seed (výchozí admin)

```sql
INSERT INTO users (username, name, password_hash) VALUES (
    'admin',
    'Administrátor',
    '$2y$12$...'  -- password_hash('admin', PASSWORD_BCRYPT, ['cost' => 12])
);
```

### 3.3 Údržba

- Cron: mazat staré login_attempts (`attempted_at < NOW() - 24h`)
- Cron: mazat osiřelé junction záznamy v `noteables`/`fileables` kde entita neexistuje
- Cron: mazat fyzické soubory v `storage/` bez DB záznamu (osiřelé soubory)
- PHP session GC: `session.gc_maxlifetime = 7200` (automaticky mazí filesystem sessions)

### 3.4 Auto-sync projektů ze složek

Aplikace automaticky sleduje složku v home uživatele a vytváří projekty
z nových složek. Soubory ve složce se neimportují - složka = pouze projekt.

**Konfigurace:**
```php
// config/config.php
define('PROJECTS_WATCH_DIR', '/home/user/Projekty');
```

**Pravidla:**
- Cron jede každou minutu, skenuje `PROJECTS_WATCH_DIR`
- Nová složka → INSERT projekt (name = název složky, status = active, folder_path = název)
- Smazaná složka → projekt se označí jako `archived` (data zůstanou)
- Přejmenování složky → starý projekt `archived`, nový vytvořen (data starého zůstanou)
- Soubory ve složce se **neimportují** do `files` tabulky
- Klienta, rozpočet, termín doplní uživatel v aplikaci
- Projekty vytvořené ručně v app mají `folder_path = NULL` (nesledují se)

**Bezpečnost:**
- Cesta konfigurovatelná v `config.php` (mimo webroot)
- Žádné symlinky (`is_link()` check)
- Název složky validován (max 200 znaků, žádné `..`, žádné `/`)
- PHP uživatel (www-data) musí mít read přístup k `PROJECTS_WATCH_DIR`
- Skryté složky (začínající `.`) se ignorují
