# 01 - Zabezpečení

Zabezpečení je priorita číslo 1. Každé rozhodnutí v aplikaci musí nejprve projít bezpečnostní analýzou.

---

## 1. Autentizace

### 1.1 Mechanismus

**Session-based auth** s HTTP-only cookies (ne JWT).

Důvody:
- Lokální aplikace = jeden server, není potřeba stateless token
- Session lze okamžitě zrušit (ztráta/krádež)
- JWT je těžké revokovat bez blacklistu
- HTTP-only cookie není dostupný z JS (ochrana proti XSS)

### 1.2 Session konfigurace

```ini
; php.ini / session_set_cookie_params()
session.cookie_httponly = 1      ; cookie není dostupný z JS
session.cookie_samesite = "Lax"  ; ochrana proti CSRF
session.cookie_secure = 0        ; lokálně HTTP, produkce = 1 (HTTPS)
session.use_strict_mode = 1      ; odmítne neinicializované session ID
session.use_only_cookies = 1     ; žádné session ID v URL
session.gc_maxlifetime = 7200    ; 2 hodiny neaktivnosti
session.sid_length = 48          ; dlouhé session ID
session.sid_bits_per_character = 6
```

### 1.3 Session úložiště

Session data uložena na **filesystemu** (standardní PHP sessions).

```ini
; php.ini
session.save_handler = files
session.save_path = "/var/lib/php/sessions"
```

- Rychlejší než databázové sessions (žádný SQL dotaz navíc)
- Pro lokální aplikaci dostačující (jeden server)
- IP a User-Agent kontrolu řeší aplikace (v `$_SESSION`, ne v DB tabulce)

### 1.4 Login flow

```
1. Klient: POST /api/auth/login { username, password, csrf_token }
2. Server:
   a. Ověřit CSRF token
   b. Zkontrolovat rate limit (login_attempts)
   c. Načíst uživatele z DB (username)
   d. Ověřit heslo: password_verify($input, $hash)
   e. Pokud OK:
      - Regenerovat session ID (session_regenerate_id(true))
      - Nastavit session data (user_id, login_time, ip, user_agent)
      - Vrátat { success: true, user: { id, name, username } }
   f. Pokud NE:
      - Zaznamenat pokus do login_attempts
      - Vrátit 401 { error: "Neplatné přihlašovací údaje." }
```

### 1.5 Hesla

- **Hash:** bcrypt (cost 12) nebo argon2id
- **Nikdy** neukládat plaintext hesla
- **Nikdy** nelogovat hesla
- Minimální délka: 8 znaků
- Změna hesla: vyžadovat aktuální heslo

```php
// Hashování
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

// Ověření
if (password_verify($input, $hash)) { /* OK */ }
```

### 1.6 Logout

```
1. Klient: POST /api/auth/logout { csrf_token }
2. Server:
   a. Ověřit CSRF token
   b. session_destroy() - smazat session data ze souboru
   c. Smazat session cookie (setcookie s minulou expirací)
   d. Vrátit { success: true }
```

---

## 2. CSRF ochrana

### 2.1 Mechanismus

**Double-submit cookie** + **custom header** requirement.

SPA + API architektura vyžaduje specifický přístup:
1. Server vydá CSRF token v cookie (`csrf-token`)
2. React čte cookie a posílá token v hlavičce `X-CSRF-Token`
3. Server ověří, že cookie token == header token
4. Všechny POST/PUT/PATCH/DELETE musí mít hlavičku `X-CSRF-Token`

### 2.2 Implementace

```php
// Generování CSRF tokenu (při loginu nebo prvním GET)
$csrfToken = bin2hex(random_bytes(32));
setcookie('csrf-token', $csrfToken, [
    'httponly' => false,  // React musí číst
    'samesite' => 'Lax',
    'path' => '/',
]);

// Ověření na serveru
function csrf_verify(): bool {
    $cookie = $_COOKIE['csrf-token'] ?? null;
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!$cookie || !$header) return false;
    return hash_equals($cookie, $header);
}
```

```javascript
// React - automatické přidání CSRF hlavičky
const csrfToken = document.cookie.match(/csrf-token=([^;]+)/)?.[1];
fetch('/api/clients', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken,
    },
    credentials: 'same-origin',
    body: JSON.stringify(data),
});
```

### 2.3 Pravidla

- GET, HEAD, OPTIONS requesty nevyžadují CSRF token
- Všechny mutující metody (POST, PUT, PATCH, DELETE) vyžadují CSRF token
- CSRF token se rotuje při loginu
- Při neplatném CSRF: 403 + žádná akce se neprovede

---

## 3. XSS ochrana

### 3.1 React ochrana

React automaticky escapuje JSX výrazy. Riziko je pouze při:
- `dangerouslySetInnerHTML` - **zakázáno** pokud není striktně nutné
- Uživatelský vstup v atributech (href, src) - **sanitizovat**

### 3.2 Content Security Policy (CSP)

```
Content-Security-Policy:
  default-src 'self';
  script-src 'self';
  style-src 'self';
  img-src 'self' data:;
  font-src 'self';
  connect-src 'self';
  frame-ancestors 'none';
  base-uri 'self';
  form-action 'self';
  object-src 'none';
```

- **Žádné 'unsafe-inline'** - veškerý JS v externích souborech
- **Žádné 'unsafe-eval'** - žádný eval(), new Function()
- **Žádné externí domény** - vše lokální
- **frame-ancestors 'none'** - ochrana proti clickjacking

### 3.3 Pravidla

- Veškerý uživatelský vstup se renderuje přes React (automatický escape)
- Pokud je nutné vložit HTML (např. popisky), použít DOMPurify (lokální)
- `dangerouslySetInnerHTML` pouze po sanitizaci
- Neklikatelné odkazy v uživatelském vstupu (pouze text)
- Validace vstupu na serveru (délka, typ, formát)

---

## 4. SQL Injection ochrana

### 4.1 Pravidla

- **Vždy** PDO prepared statements
- **Nikdy** konkatenovat SQL řetězce s uživatelským vstupem
- **Nikdy** nepoužívat `query()` s proměnnými
- Repository pattern centralizuje DB přístup

```php
// SPRÁVNĚ
$stmt = $pdo->prepare('SELECT * FROM clients WHERE id = ?');
$stmt->execute([$id]);

// ŠPATNĚ - ZAKÁZÁNO
$pdo->query("SELECT * FROM clients WHERE id = " . $id);
```

### 4.2 Whitelist pro ORDER BY / LIMIT

```php
$allowedSort = ['name', 'created_at', 'email'];
$sort = in_array($_GET['sort'] ?? 'name', $allowedSort) ? $_GET['sort'] : 'name';
$stmt = $pdo->prepare("SELECT * FROM clients ORDER BY $sort DESC LIMIT ?");
$stmt->execute([$limit]);
```

---

## 5. Rate Limiting

### 5.1 Login rate limiting

Databázové sledování pokusů o přihlášení:

```sql
CREATE TABLE login_attempts (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    ip_address  VARCHAR(45) NOT NULL,
    username    VARCHAR(100),
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    success     TINYINT(1) DEFAULT 0,
    INDEX idx_ip_time (ip_address, attempted_at),
    INDEX idx_username_time (username, attempted_at)
);
```

Pravidla:
- **5 neúspěšných pokusů** z jedné IP za 15 minut → zablokováno
- **10 neúspěšných pokusů** pro jeden username za 15 minut → zablokováno
- Po 5 neúspěších: **exponenciální backoff** (30s, 60s, 120s, 300s)
- Úspěšný login: smazat záznamy pro danou IP + username

### 5.2 API rate limiting

- Obecné API: **60 requestů/minut** per session
- Mutující operace: **20 requestů/minut** per session
- Implementováno v ApiController middleware

---

## 6. Bezpečnostní hlavičky

Apache `.htaccess` nebo vhost konfigurace:

```apache
# Security headers
Header always set X-Content-Type-Options "nosniff"
Header always set X-Frame-Options "DENY"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
Header always set Permissions-Policy "geolocation=(), microphone=(), camera=()"
Header always set Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'"
```

---

## 7. Validace vstupu

### 7.1 Pravidla

- **Vždy** validovat na serveru (React validace je jen UX)
- Validovat typ, délku, formát, rozsah
- Whitelist povolených hodnot (status, priorita)
- Finanční částky: celá čísla v haléřích (ne float)

### 7.2 Validátory

```php
// String
function validate_string($value, int $min = 1, int $max = 255): ?string {
    $value = trim($value);
    $len = mb_strlen($value);
    if ($len < $min || $len > $max) return null;
    return $value;
}

// Email
function validate_email($value): ?string {
    $value = trim($value);
    return filter_var($value, FILTER_VALIDATE_EMAIL) ?: null;
}

// Částka (celá čísla v haléřích)
function validate_amount($value, int $min = 0, int $max = 999999999): ?int {
    if (!is_numeric($value)) return null;
    $cents = (int) round((float) $value * 100);
    if ($cents < $min || $cents > $max) return null;
    return $cents;
}

// Enum
function validate_enum($value, array $allowed): ?string {
    return in_array($value, $allowed, true) ? $value : null;
}
```

---

## 8. Konfigurace a tajemství

### 8.1 Databázové přihlašovací údaje

- Uloženy v `config/database.php`
- Soubor **mimo webroot** nebo chráněn `.htaccess`:
  ```apache
  # Zákaz přístupu ke config souborům
  <FilesMatch "\.(php)$">
      Order Allow,Deny
      Deny from all
  </FilesMatch>
  # Výjimka pro index.php
  <Files "index.php">
      Order Allow,Deny
      Allow from all
  </Files>
  ```
- Ideálně: `/etc/devapppro/config.php` (mimo webroot)

### 8.2 Produkční režim

```php
// config/config.php
define('APP_ENV', 'production');  // nebo 'development'
define('APP_DEBUG', false);        // žádné detailní chyby

if (APP_ENV === 'production') {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', '/var/log/devapppro/error.log');
}
```

- **display_errors = Off** v produkci
- Chyby logovány do souboru, ne zobrazovány klientovi
- Generické chybové zprávy: "Došlo k chybě." (ne stack trace)

---

## 9. Error handling

### 9.1 API chyby

```php
// Vždy JSON, nikdy HTML
http_response_code(400);
echo json_encode([
    'error' => 'Neplatný vstup.',
    'fields' => ['email' => 'Vyžadováno platný email.'],  // pouze validace
]);
```

### 9.2 Pravidla

- **Nikdy** nevracet stack trace klientovi
- **Nikdy** nevracet SQL dotaz v chybové zprávě
- **Nikdy** nevracet cesty k souborům v chybové zprávě
- Logovat detaily na server, klientovi generická zpráva
- HTTP status kódy: 200, 201, 400, 401, 403, 404, 422, 429, 500

---

## 10. Session krádež - detekce

### 10.1 IP a User-Agent kontrola

Při každém requestu ověřit, že IP a User-Agent odpovídá hodnotám zapsaným
při loginu (uloženy v `$_SESSION`):

```php
function verify_session(): bool {
    if (empty($_SESSION['user_id'])) return false;

    $currentIp = $_SERVER['REMOTE_ADDR'];
    $currentUa = $_SERVER['HTTP_USER_AGENT'] ?? '';

    // IP se změnila - podezřelé
    if ($_SESSION['ip'] !== $currentIp) {
        log_security_event('session_ip_mismatch', $_SESSION['user_id']);
        session_destroy();
        return false;
    }

    // User-Agent se změnil - podezřelé
    if ($_SESSION['ua'] !== $currentUa) {
        session_destroy();
        return false;
    }

    return true;
}
```

### 10.2 Session timeout

- Neaktivita 2 hodiny → automatický logout (`session.gc_maxlifetime = 7200`)
- PHP session GC automaticky maže expirované filesystem sessions
- Při každém requestu: session se prodlužuje (PHP default chování)

---

## 11. CORS

```apache
# Pouze stejný origin - žádné wildcard
Header always set Access-Control-Allow-Origin ""
# Nebo vůbec nenastavovat - same-origin je default
```

- **Nevystavovat** CORS hlavičky pro externí domény
- React SPA a PHP API na stejném originu (`localhost`)
- Vite dev server proxy na `localhost/api` → PHP

---

## 12. Souborové oprávnění

```
/var/www/devapppro/
├── config/          chmod 750  (čtení jen pro PHP)
├── src/             chmod 755
├── api/             chmod 755
├── assets/dist/     chmod 755  (veřejné)
├── storage/         chmod 750  (nahrané soubory - citlivé)
├── storage/*        chmod 640  (nikdo jiný nečte)
├── database/        chmod 750  (schema + seed s hesly)
├── database/*.sql   chmod 640
├── docs/            chmod 750  (interní dokumentace)
├── index.php        chmod 644
└── .htaccess        chmod 644
```

- Config soubory: `chmod 640` (čtení jen pro vlastník + Apache)
- Databázové migrace: `chmod 640`
- Log soubory: `chmod 600`
- Storage soubory: `chmod 640` (smlouvy, faktury - citlivé)
- **Nikdy** `chmod 777`

---

## 13. Apache bind na 127.0.0.1

Lokální aplikace nesmí být dostupná z LAN nebo internetu.

```apache
# /etc/apache2/ports.conf
Listen 127.0.0.1:80
```

- **Pouze localhost** - žádné zařízení v síti nemá přístup
- Vhost `ServerName localhost` - bind na 127.0.0.1
- Pokud je potřeba přístup z LAN (např. testování na telefonu), použít SSH tunel:
  ```bash
  ssh -L 80:127.0.0.1:80 user@server
  ```
- **Nikdy** nebindovat na `0.0.0.0` nebo veřejnou IP

---

## 14. Sanitizace logů

Logy nesmí obsahovat citlivá data klientů.

### 14.1 Zakázané v logu

- **Nikdy** nelogovat hesla (ani hash)
- **Nikdy** nelogovat celé SQL dotazy (mohou obsahovat data)
- **Nikdy** nelogovat emaily, telefony, adresy klientů
- **Nikdy** nelogovat finanční částky
- **Nikdy** nelogovat obsah poznámek nebo souborů

### 14.2 Co logovat

- **Error log:** generická zpráva + timestamp + kód chyby (ne stack trace s daty)
- **Security log:** IP + username + výsledek (ne heslo) - login_attempts tabulka

### 14.3 Implementace

```php
// SPRÁVNĚ - logovat jen akci, ne obsah
error_log("Login failed for user: $username from IP: $ip");

// ŠPATNĚ - únik dat
error_log("Client created: " . json_encode([
    'first_name' => 'Jan',
    'last_name' => 'Novák',
    'email' => 'jan.novak@example.cz',
    'phone' => '+420 123 456 789',
]));
```

---

## 15. Zabezpečení záloh

Záloha databáze = kompletní citlivá data klientů.

### 16.1 Úložiště

- Zálohy **mimo webroot** (`/var/backups/devapppro/`)
- Oprávnění `chmod 600` (jen vlastník)
- Ideálně šifrované (gpg, age)

### 16.2 Skript

```bash
#!/bin/bash
# /usr/local/bin/devapppro-backup.sh
BACKUP_DIR=/var/backups/devapppro
DATE=$(date +%Y%m%d_%H%M%S)

# DB záloha
mysqldump -u devapppro -pPASS devapppro | gzip > "$BACKUP_DIR/db_$DATE.sql.gz"

# Storage záloha
tar czf "$BACKUP_DIR/storage_$DATE.tar.gz" /var/www/devapppro/storage/

# Šifrování (volitelné)
gpg --encrypt --recipient backup@example.com "$BACKUP_DIR/db_$DATE.sql.gz"
rm "$BACKUP_DIR/db_$DATE.sql.gz"  # smazat nešifrovanou

# Rotace (denní 7, týdenní 4, měsíční 3)
find "$BACKUP_DIR" -name "db_*" -mtime +7 -delete
find "$BACKUP_DIR" -name "storage_*" -mtime +7 -delete
```

### 16.3 Cron

```cron
# Denní záloha ve 3:00
0 3 * * * /usr/local/bin/devapppro-backup.sh
```

---

## 16. Session hardening pro lokální

Lokální aplikace s citlivými daty = striktnější session pravidla.

### 17.1 Pravidla

- **Krátký timeout** - 2 hodiny neaktivity (ne 30 dní)
- **Žádný "remember me"** - lokální aplikace, pokaždé se přihlásit
- **Session zrušena při zavření prohlížeče** - `cookie_lifetime = 0`
- **Single session** - jedno aktivní přihlášení najednou

### 17.2 Single session

Při novém loginu se zruší předchozí session uživatele (session_regenerate_id
s `delete_old_session = true`):

```php
function attempt_login(string $username, string $password): bool
{
    // ... ověření hesla ...

    // Regenerovat session ID a smazat stará data
    session_regenerate_id(true);

    // Nastavit nová session data
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['login_time'] = time();
    $_SESSION['ip'] = $_SERVER['REMOTE_ADDR'];
    $_SESSION['ua'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
}
```

Poznámka: filesystem sessions neumožňují snadno najít a smazat sessions
jiného uživatele. Pro striktní single session by se musela vést tabulka
aktivních session ID v DB. Pro lokální aplikaci je `session_regenerate_id(true)`
dostatečné - stará session data jsou smazána.

### 17.3 Konfigurace

```ini
session.cookie_lifetime = 0       # zrušit při zavření prohlížeče
session.gc_maxlifetime = 7200     # 2 hodiny neaktivity
```

---

## 17. Ochrana proti directory traversal

Při stahování souborů z `storage/`:

```php
function download_file(int $fileId): void
{
    $file = getFileById($fileId);
    if (!$file) {
        http_response_code(404);
        exit;
    }

    $storagePath = realpath(__DIR__ . '/../storage');
    $filePath = realpath($storagePath . '/' . $file['storage_path']);

    // Kontrola, že soubor je uvnitř storage/
    if ($filePath === false || strpos($filePath, $storagePath) !== 0) {
        // Directory traversal pokus - odmítnout
        log_security_event('directory_traversal_attempt', $fileId);
        http_response_code(403);
        exit;
    }

    // Odeslat soubor
    header('Content-Type: ' . $file['mime_type']);
    header('Content-Disposition: attachment; filename="' . $file['original_name'] . '"');
    header('Content-Length: ' . $file['size_bytes']);
    readfile($filePath);
}
```

### Pravidla

- **Nikdy** nepoužívat uživatelský vstup přímo v cestě
- **Vždy** `realpath()` kontrola, že soubor je uvnitř `storage/`
- **Žádné** `..` v cestě
- `storage_path` v DB je relativní (např. `2026/09/uuid.pdf`), nikdy absolutní

---

## 18. Limity velikosti vstupu

Ochrrana proti nechtěnému DoS i na lokální aplikaci.

### 19.1 PHP konfigurace

```ini
post_max_size = 10M
upload_max_filesize = 10M
max_execution_time = 30
max_input_time = 30
memory_limit = 128M
```

### 19.2 API limity

```php
// JSON API - max 1MB
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 1048576) {
    http_response_code(413);
    echo json_encode(['error' => 'Příliš velký požadavek.']);
    exit;
}

// Upload - max 10MB, max 10 souborů najednou
if ($_SERVER['CONTENT_LENGTH'] > 10485760) {
    http_response_code(413);
    echo json_encode(['error' => 'Soubor je příliš velký. Maximum 10 MB.']);
    exit;
}
```

### 19.3 Délka stringů

Všechny textové vstupy validovány na maximální délku:
- Jméno klienta: max 200 znaků
- Email: max 255 znaků
- Poznámka: max 5000 znaků
- Popis projektu: max 5000 znaků

---

## 19. Sanitizace chybových zpráv

Chybové zprávy klientovi nesmí prozradit citlivé informace.

### 20.1 Zakázané v chybové zprávě

- **Nikdy** SQL dotaz v chybové zprávě
- **Nikdy** cesty k souborům v chybové zprávě
- **Nikdy** stack trace v chybové zprávě
- **Nikdy** DB strukturu v chybové zprávě
- **Nikdy** jména tabulek nebo sloupců

### 20.2 Implementace

```php
// SPRÁVNĚ
try {
    $stmt->execute([$id]);
} catch (PDOException $e) {
    // Log detail na server
    error_log("DB error: " . $e->getMessage() . " in " . __FILE__);
    // Klientovi generická zpráva
    http_response_code(500);
    echo json_encode(['error' => 'Došlo k chybě. Zkuste to znovu.']);
    exit;
}

// ŠPATNĚ - únik informací
catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    // nebo: echo json_encode(['error' => "SQL: $sql"]);
}
```

### 20.3 Produkční režim

```php
if (APP_ENV === 'production') {
    // Vždy generická zpráva
    $errorMessage = 'Došlo k chybě. Zkuste to znovu.';
} else {
    // Vývoj - detailní pro debugging
    $errorMessage = $e->getMessage();
}
```
