---
name: security-review
description: Security checklist pro Dev App Pro (PHP 8.5 + React + MariaDB). Použij při práci na API controllerech, autentizaci, uploadu souborů, root workerech (restore/delete/hosting/php-version), práci se secrets, nebo před commitem citlivého kódu.
---

# Security Review — Dev App Pro

Checklist přizpůsobený reálné architektuře projektu. Aplikace běží jen na `127.0.0.1`, ale zpracovává **nedůvěryhodná data** (Duplicator archivy klientů, uploady, JSON body) a **root workery** (delete-project, restore-backup, regenerate-project-hosting, change-php-version). Hlavní rizika: command injection v CLI workerech, path traversal v restore/upload, unescape ve frontendu, secrets v logech.

## Kdy aktivovat

- Nový API endpoint nebo controller metoda
- Změna v autentizaci, session, CSRF
- Práce s uživatelským vstupem, uploady, cestami na disku
- Změny v `cli/*-worker` skriptech běžících jako root
- Zpracování externích dat (ZIP/DAF archivy, wp-config.php, SSH operace)
- Práce se secrets, šifrováním, hesly
- Před commitem citlivého kódu (`bin/test.sh security` + tento checklist)

## 1. Secrets a konfigurace

### Kontrola
- [ ] Žádné hardcoded secrets (hesla, klíče, tokeny) v kódu — ani v testech (testy používají dummy hodnoty z `TestCase`)
- [ ] Nové secrets přes `getenv('DEVAPPPRO_*')` — fail-fast bez fallbacku (vzor: `WP_AUTOLOGIN_SECRET`, `CREDENTIALS_ENCRYPTION_KEY` v `config/config.php`)
- [ ] `config/*.php` zůstává v `.gitignore`; nové tajné config soubory přidat do `.gitignore` a do `.htaccess` deny listu
- [ ] `.env` nikdy necommitovat; nové env proměnné zdokumentovat v AGENTS.md
- [ ] DB hesla jen v `config/database.php` (perm 640) nebo `/root/.my.cnf` (root workery čtou přes `parse_ini_file`)
- [ ] `git log` a `git diff` před pushem — žádné secrets v historii
- [ ] SSH klíče (`id_ed25519_deploy`) nikdy nekopírovat do repa ani projektových docrootů

## 2. SQL a databáze

### Vzor (stávající standard — udržet)
```php
// Prepared statement vždy
$stmt = $pdo->prepare('SELECT * FROM clients WHERE id = ?');
$stmt->execute([$id]);

// LIMIT/OFFSET jako bind parametry (ne string concat)
$stmt = $pdo->prepare('SELECT * FROM clients LIMIT ? OFFSET ?');
$stmt->bindValue(1, $limit, PDO::PARAM_INT);
```

### Kontrola
- [ ] Žádné `query()`/`exec()` s interpolací uživatelských dat — `$pdo->query()` jen s interními fixními řetězci nebo přes whitelist (vzor: DashboardApiController whitelist tabulek/sloupců)
- [ ] Dynamické názvy sloupců/služeb/ORDER BY jen přes whitelist mapování, nikdy přímo z inputu
- [ ] V CLI workerech: názvy DB a uživatelů escapované backtickem + validované regexem (vzor: `cli/restore-backup.php`, `cli/delete-project.php` — `$dbNameEsc`, `$safeDbName`)
- [ ] MariaDB identifikátory (DB/user v CREATE/DROP) NELZE parametrizovat — nutná striktní validace `^[a-zA-Z0-9_]+$` před interpolací
- [ ] `LIKE` hodnoty escapované (`addcslashes($v, '%_')`)
- [ ] Search/replace v restore: serialization-aware, počítané délky `s:XX:` (viz restore worker)

## 3. Validace vstupu (API)

### Vzor
```php
// Controller, na začátku metody — fail fast, 422
$data = json_input(); // aplikuje enforce_input_limits() automaticky
if (empty($data['name']) || !is_string($data['name']) || mb_strlen($data['name']) > 100) {
    $this->jsonError('Neplatný název.', 422);
}
```

### Kontrola
- [ ] Vstup jen přes `json_input()` (max 1 MB body, `enforce_input_limits()` — stringy 65535 znaků, pole 100 prvků)
- [ ] Explicitní typ/délka/formát validace v controlleru — nikdy nevěřit frontendu (částky, součty, IBAN počítat na serveru)
- [ ] Délkové limity dle konvence: jména 100, email 255, popisy 5000, adresy 500, IČO/DIČ 50, IBAN/SWIFT 50, názvy 200, URL 2000, heslo 100
- [ ] IBAN: mod-97 kontrola (vzor `InvoiceApiController::isValidIban()`)
- [ ] Email: `filter_var($v, FILTER_VALIDATE_EMAIL)`
- [ ] ID z URL: `getId()` → `ctype_digit` — použít před dotazem
- [ ] Numerické hodnoty přes `is_numeric`/`filter_var(..., FILTER_VALIDATE_INT/FLOAT)`
- [ ] Enum/status hodnoty proti `Constants::*_STATUSES`, nikdy volný string

## 4. Autentizace a session

### Kontrola
- [ ] Každý controller handler začíná `$this->requireAuth()` (vyjma `auth.php` login/reset endpointů)
- [ ] POST/PUT/DELETE chráněné `require_csrf()` — ověří hlavičku `X-CSRF-Token` proti session + double-submit cookie
- [ ] Session: `session_regenerate_id(true)` po loginu (v `Auth::login`), binding na IP+UA (`$_SESSION['ip']`, `$_SESSION['ua']`)
- [ ] Cookie: `httponly` pro session, `samesite=Lax`; `csrf-token` cookie je záměrně `httponly=false` (React ji čte)
- [ ] Změna hesla: `Auth::destroySessions()` — zabije ostatní sessions
- [ ] Rate limit na login/reset: `login_attempts` (max 5/hodina na IP+username) — nové auth endpointy přidat do stejného limitu
- [ ] Heslo: `password_hash(..., PASSWORD_BCRYPT, ['cost' => BCRYPT_COST])`, ověření `password_verify()` — nikdy plaintext/hash porovnání
- [ ] Reset tokeny: náhodné (`random_bytes`), časově omezené, jednorázové, hashed v DB pokud persistentní

## 5. Uploady a soubory

### Vzor (`FileApiController`)
```php
// Whitelist přípon
if (!in_array($ext, self::ALLOWED_EXT, true)) { ... 422 }
// Skutečný MIME (finfo), nehlásit důvěru browseru
$realMime = mime_content_type($tmpPath);
$expectedMimes = self::EXT_MIME_MAP[$ext] ?? [];
if (!in_array($realMime, $expectedMimes, true)) { ... 422 }
```

### Kontrola
- [ ] Přípona i MIME validované proti `ALLOWED_EXT` + `EXT_MIME_MAP` (PDF, PNG, JPEG, GIF, WebP, SVG, TXT, CSV, DOCX, XLSX, ZIP)
- [ ] Blokované spustitelné typy: EXE/SH/BAT/PHP/JS/HTML — žádný bypass přes `.php.jpg`, `file.php%00.png`, double extension
- [ ] Velikost: max 10 MB (konzistentní s vhost limitem)
- [ ] Uložení: `storage/{YYYY}/{MM}/{uuid}.ext` — nikdy pod původním jménem, nikdy do webrootu
- [ ] `storage/.htaccess` = `Require all denied` — download JEN přes `/api/files/{id}/download` (controller hlídá ownership)
- [ ] Download: `is_safe_path()` na cestě k souboru; MIME z DB, ne z přípony
- [ ] SVG: obsahuje markup — servírovat s `Content-Disposition: attachment` nebo CSP sandbox; ne inline render v React
- [ ] Obrázky: reencode přes GD/Imagick (zbaví embedded payload + EXIF)

## 6. Path traversal a symlinky

### Vzor
```php
if (!is_safe_path($requestedPath, STORAGE_DIR)) { ... 403 }
// is_safe_path: odmítne '..', resolvuje realpath, musí být uvnitř baseDir
if (!is_symlink_safe($path)) { ... } // odmítne symlink
```

### Kontrola
- [ ] Každá cesta z uživatelského vstupu nebo z DB (folder_path, file paths, backup paths) přes `is_safe_path($path, $baseDir)`
- [ ] Dodatečně `is_symlink_safe()` kde hrozí symlink útok (projektové složky, restore cíle)
- [ ] `.htaccess` blokuje `..` v URL (již nastaveno — nové vhosty přes `generate-vhosts.php` musí filtrovat názvy domén)
- [ ] `folder_path` projektu validován proti `PROJECTS_WATCH_DIR` — nikdy absolutní cesta z inputu

## 7. Root CLI workery (KRITICKÉ)

Běží jako root z cronu/systemd: `delete-project.php`, `restore-backup.php`, `change-php-version.php`, `regenerate-project-hosting.php`, `backup-db.php`. Zdroj dat = DB job fronta, kterou plní web API → **DB data jsou untrusted**.

### Kontrola
- [ ] Každá hodnota z DB jobu validovaná před použitím v shellu/filesystemu
- [ ] `escapeshellarg()` na KAŽDÉM dynamickém argumentu shellu (`rm -rf`, `unzip`, `mkcert`, `mysqldump`) — vzor: `cli/delete-project.php`, `cli/backup-db.php`, `cli/regenerate-project-hosting.php`
- [ ] Před `rm -rf`: `is_safe_path($docRoot, PROJECTS_WATCH_DIR)` + kontrola že cesta není `/`, `PROJECTS_WATCH_DIR` sám, nebo symlink
- [ ] Názvy domén pro mkcert/vhosty: regex `^[a-z0-9.-]+$` + odmítnutí `..`, kontrolní znaků, space (vzor `$sanDomains` v regenerate-project-hosting)
- [ ] `mysqldump`/`mariadb-dump` heslo na příkazové řádce — OK pro localhost dev; nikdy do logu/echo (log jen exit code)
- [ ] `apache2ctl configtest` před každým reload; selhání = nechat starý config, zalogovat
- [ ] Job status transitions atomické; `error_message` do DB zkrácen (substr 65000), bez stack trace
- [ ] Logy do `/var/log/devapppro-*.log` — bez hesel/tokenů (sanitize před zápisem)

## 8. Restore z archivů (NEJVYŠŠÍ RIZIKO)

`cli/restore-backup.php` rozbaluje cizí ZIP/DAF jako root. Obsah archivu je plně pod kontrolou útočníka.

### Kontrola
- [ ] Extrakce přes `unzip -o -q` s `escapeshellarg` na obou cestách (vzor stávající implementace)
- [ ] Po extrakci: validovat, že žádný soubor nesměřuje mimo `$targetRoot` (zip-slip: názvy s `../`, absolutní cesty) — `is_safe_path` na každý entry
- [ ] Symlinky v archivu: `is_link()` kontrola na každý extrahovaný soubor — symlink může vést mimo docroot (zapsat kamkoliv jako root)
- [ ] Po extrakci smazat `installer.php`, `dup-installer/` (obsahuje dump DB — již implementováno)
- [ ] `wp-config.php` je cizí PHP — NEincludovat; číst jen textově (regexy pro `DB_NAME`, `WP_HOME`...). Hodnoty z něj (DB kredence, table prefix) sanitizovat před použitím v SQL/shellu
- [ ] `DEVAPPPRO_SECRET` do wp-config zapisovat pečlivě (escapovaný string literal, jeden zápis, ne duplikovat)
- [ ] URL v search/replace: validovat formát, escapovat pro LIKE/prepared statements
- [ ] Import SQL: jméno DB/user z jobu striktně `[a-zA-Z0-9_]+` (CREATE/DROP nelze parametrizovat)
- [ ] Deaktivace pluginů: pole pluginů do shellu jen přes `escapeshellarg` (vzor `$toDisable` + `array_map('escapeshellarg')`)

## 9. XSS a výstup

### Kontrola
- [ ] Frontend: React escapuje defaultně — NIKDY `dangerouslySetInnerHTML` s uživatelskými daty
- [ ] PHP výstup HTML (PDF, e-mail těla, chybové stránky): `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')`
- [ ] `json_response()` používá `JSON_UNESCAPED_UNICODE` — OK; data z API se v Reactu renderují escapovaně
- [ ] CSP: `script-src 'self'` strict, `style-src 'self'` — žádné inline styly/skripty/události (`onclick=`) ve výstupu
- [ ] URL z DB (project_url, file download): validovat schéma `https?://` před použitím jako `href`/`Location` — odmítnout `javascript:`, `data:`
- [ ] Redirecty: jen interní cesty začínající `/` — žádný open redirect z parametru

## 10. Chyby, logy, odpovědi

### Kontrola
- [ ] `json_response()` loguje 4xx/5xx přes `sanitize_for_log()` automaticky — vlastní `error_log()` volání taky sanitizovat
- [ ] Chyby uživateli: generická zpráva, NIKDY `$e->getMessage()`, stack trace, cesty na disku, SQL
- [ ] `APP_DEBUG=false` — detail jen v `/var/log/devapppro/error.log` přes `set_exception_handler`
- [ ] Logy: žádná hesla, tokeny, session ID, plné karty/účty — `sanitize_for_log()` na vše user-derived
- [ ] DB error messages v `backup_restores.error_message`: zkrácené, bez credentials

## 11. Dependency a prostředí

- [ ] `composer audit` — žádné známé CVE (mPDF, Guzzle, PHPUnit)
- [ ] `composer.lock` commitnutý, CI používá `composer install` (ne `update`)
- [ ] `npm audit` ve `frontend/` — kritické/high vyřešit
- [ ] PHP: systémové 8.5 chráněno APT pinningem — nepřepínat na sury build pro devapppro
- [ ] `.htaccess` deny list aktuální: `config/`, `src/`, `vendor/`, `cli/`, `docs/`, `.devin/`, `.env`, `storage/`

## Pre-commit / pre-deploy checklist

```bash
# Minimální sada před commitem citlivého kódu
php -l <zmeneny_soubor>
bin/test.sh security
bin/security-scan.sh           # statický scan: secrets, SQL, shell, unserialize, audit deps

# Plná sada před merge/push
bin/test.sh full
```

- [ ] Žádné secrets v diffu (`git diff | grep -iE 'password|secret|token|key'`)
- [ ] Nové endpoints: auth + CSRF + rate limit kde patří
- [ ] Nové DB queries: prepared statements, whitelist pro identifikátory
- [ ] Nové shell/exec: escapeshellarg na všem dynamickém
- [ ] Nové cesty/filesystem operace: `is_safe_path`/`is_symlink_safe`
- [ ] Nové logy: `sanitize_for_log`
- [ ] Nové security-relevantní testy v `tests/Security/` (`@group security`)
- [ ] `schema.sql` sync pokud se měnila DB struktura
- [ ] Dokumentace: AGENTS.md sekce Bezpečnost doplněna o nové mechanismy

## Statická analýza (doporučené nástroje)

```bash
# Psalm taint analysis — sleduje $_GET/$_POST → SQL/output (nejlepší na SQLi/XSS v PHP)
# Po přidání psalm.xml do repa:
psalm --taint-analysis --no-cache src/ api/ cli/

# PHPStan — type/logic errors
phpstan analyse src/ api/ cli/ --level=5

# Rychlý grep pattern scan (automatizováno v bin/security-scan.sh)
grep -rn '\$pdo->query(\|->exec(' src/ api/ | grep -v 'escapeshell\|prepare'
grep -rn 'shell_exec\|exec(' cli/ src/ | grep -v escapeshellarg
grep -rn '\$e->getMessage()' src/ api/ | grep -v 'log\|error'
grep -rn 'unserialize(' src/ cli/ | grep -v '@unserialize\|allowed_classes'
```

## Hlavní zásady

1. **Untrusted data všude** — DB job fronty, uploady, archivy, wp-config z cizího webu, JSON body. Všechno validovat na hranici systému.
2. **Root workery = nejvyšší riziko** — chyba v `delete-project`/`restore-backup` = RCE nebo smazání čehokoliv. Escapovat, validovat, `is_safe_path`.
3. **Fail securely** — chyby nepropouštět detaily, `APP_DEBUG=false`, generické zprávy uživateli.
4. **Least privilege** — web běží pod `www-data`/`ratesman`, root jen přes CLI workery s minimal scope (jen job queue).
5. **Defense in depth** — i když je localhost: `.htaccess` deny, `Require local`, `is_safe_path`, CSRF — žádná vrstva nesmí být jediná.
