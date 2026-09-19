# 08 - Deployment

Lokální deployment na Apache + PHP + MariaDB. Vše lokálně, žádné externí služby.

---

## 1. Předpoklady

### 1.1 Systém

| Software | Verze |
|---|---|
| Ubuntu | 24.04+ |
| Apache | 2.4+ |
| PHP | 8.3+ |
| MariaDB | 10.11+ / MySQL 8+ |
| Node.js | 22+ |
| npm | 10+ |
| Composer | 2.7+ |

### 1.2 PHP rozšíření

```bash
sudo apt install php8.3 php8.3-cli php8.3-mysql php8.3-mbstring \
  php8.3-xml php8.3-curl php8.3-zip php8.3-opcache php8.3-gd
```

### 1.3 Apache moduly

```bash
sudo a2enmod rewrite headers deflate expires
sudo systemctl restart apache2
```

---

## 2. Instalace

### 2.1 Klonování / vytvoření

```bash
sudo mkdir -p /var/www/devapppro
sudo chown $USER:$USER /var/www/devapppro
```

### 2.2 Backend

```bash
cd /var/www/devapppro

# Composer dependencies
composer install --no-dev --optimize-autoloader
```

### 2.3 Frontend

```bash
cd frontend
npm install
npm run build    # → ../assets/dist/
```

### 2.4 Databáze

```bash
mysql -u root -p < database/schema.sql
mysql -u root -p < database/seed.sql
```

### 2.5 Konfigurace

```bash
cp config/config.example.php config/config.php
cp config/database.example.php config/database.php
# Upravit hodnoty
```

### 2.6 Oprávnění

```bash
chmod 640 config/*.php
chmod 640 database/*.sql
chmod 755 assets/dist
chmod -R 755 assets/dist/*
chmod 750 storage
chmod -R 640 storage/*
```

---

## 3. Apache vhost

### 3.1 Konfigurace

```apache
# /etc/apache2/sites-available/devapppro.conf
<VirtualHost *:80>
    ServerName localhost
    DocumentRoot /var/www/devapppro

    <Directory /var/www/devapppro>
        AllowOverride All
        Require all granted
        Options -Indexes +FollowSymLinks
        DirectoryIndex index.html

        # SPA fallback
        FallbackResource /index.html
    </Directory>

    # API endpointy
    <Directory /var/www/devapppro/api>
        Require all granted
        Options -Indexes
    </Directory>

    # Statické assety
    <Directory /var/www/devapppro/assets>
        Require all granted
        Options -Indexes
    </Directory>

    # Zákaz přístupu ke config a src
    <Directory /var/www/devapppro/config>
        Require all denied
    </Directory>
    <Directory /var/www/devapppro/src>
        Require all denied
    </Directory>
    <Directory /var/www/devapppro/database>
        Require all denied
    </Directory>
    <Directory /var/www/devapppro/docs>
        Require all denied
    </Directory>
    <Directory /var/www/devapppro/frontend>
        Require all denied
    </Directory>
    <Directory /var/www/devapppro/node_modules>
        Require all denied
    </Directory>
    <Directory /var/www/devapppro/vendor>
        Require all denied
    </Directory>
    <Directory /var/www/devapppro/storage>
        Require all denied
    </Directory>

    # Security headers
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "DENY"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Permissions-Policy "geolocation=(), microphone=(), camera=()"
    Header always set Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'"

    # Gzip
    <IfModule mod_deflate.c>
        AddOutputFilterByType DEFLATE application/json text/html text/css application/javascript font/woff2
    </IfModule>

    # Cache statických assetů
    <FilesMatch "\.(js|css|woff2)$">
        Header set Cache-Control "public, max-age=31536000, immutable"
    </FilesMatch>

    ErrorLog ${APACHE_LOG_DIR}/devapppro_error.log
    CustomLog ${APACHE_LOG_DIR}/devapppro_access.log combined
</VirtualHost>
```

### 3.2 Aktivace

```bash
sudo a2dissite 000-default
sudo a2ensite devapppro
sudo systemctl reload apache2
```

---

## 4. .htaccess

```apache
# /var/www/devapppro/.htaccess
RewriteEngine On

# API endpointy: /api/clients → /api/clients.php
RewriteRule ^api/([a-z-]+)/?$ api/$1.php [L,QSA]

# SPA fallback: neexistující routy → index.html
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.html [L]

# Zákaz přístupu ke skrytým souborům
<FilesMatch "^\.(ht|git|env)">
    Require all denied
</FilesMatch>

# Zákaz přístupu ke config
<FilesMatch "^(config|database|composer)">
    Require all denied
</FilesMatch>
```

---

## 5. PHP konfigurace

### 5.1 php.ini (produkce)

```ini
; /etc/php/8.3/apache2/php.ini
display_errors = Off
log_errors = On
error_log = /var/log/devapppro/php_error.log
error_reporting = E_ALL

; Session (filesystem)
session.save_handler = files
session.save_path = "/var/lib/php/sessions"
session.cookie_httponly = 1
session.cookie_samesite = Lax
session.use_strict_mode = 1
session.use_only_cookies = 1
session.gc_maxlifetime = 7200
session.cookie_lifetime = 0

; OPcache
opcache.enable = 1
opcache.memory_consumption = 128
opcache.max_accelerated_files = 10000
opcache.validate_timestamps = 0
```

### 5.2 config/config.php

```php
<?php
declare(strict_types=1);

define('APP_NAME', 'Dev App Pro');
define('APP_VERSION', '1.0.0');
define('APP_ENV', 'production');     // production | development
define('APP_DEBUG', false);

// Timezone
date_default_timezone_set('Europe/Prague');

// Auto-sync projektů ze složek
define('PROJECTS_WATCH_DIR', '/home/user/Projekty');

// Zálohy DB
define('BACKUP_DIR', '/var/backups/devapppro');
define('BACKUP_RETENTION_DAYS', 30);

// PDF generování (mPDF)
define('PDF_LIB', 'mpdf');
// mPDF se načítá přes Composer: composer require mpdf/mpdf

define('PROJECT_STATUSES', [
    'active'    => 'Aktivní',
    'on_hold'   => 'Pozastaveno',
    'completed' => 'Dokončeno',
    'cancelled' => 'Zrušeno',
    'archived'  => 'Archivováno',
]);

define('TASK_STATUSES', [
    'todo'        => 'K dokončení',
    'in_progress' => 'Probíhá',
    'done'        => 'Hotovo',
    'cancelled'   => 'Zrušeno',
]);

define('TASK_PRIORITIES', [
    'low'    => 'Nízká',
    'medium' => 'Střední',
    'high'   => 'Vysoká',
    'urgent' => 'Urgentní',
]);

define('INVOICE_STATUSES', [
    'draft'    => 'Koncept',
    'sent'     => 'Odesláno',
    'paid'     => 'Zaplaceno',
    'overdue'  => 'Po splatnosti',
    'cancelled'=> 'Zrušeno',
]);
```

### 5.3 config/database.php

```php
<?php
declare(strict_types=1);

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'devapppro');
define('DB_USER', 'devapppro');
define('DB_PASS', 'silne-heslo-zde');
define('DB_CHARSET', 'utf8mb4');

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}
```

---

## 6. Logy

```bash
sudo mkdir -p /var/log/devapppro
sudo chown www-data:www-data /var/log/devapppro
```

| Log | Cesta |
|---|---|
| PHP errors | `/var/log/devapppro/php_error.log` |
| Apache access | `/var/log/apache2/devapppro_access.log` |
| Apache error | `/var/log/apache2/devapppro_error.log` |

---

## 7. Cron úlohy

```bash
# /etc/cron.d/devapppro

# Auto-sync projektů ze složek (každou minutu)
* * * * * www-data php /var/www/devapppro/cli/sync-projects.php

# Mazání starých login_attempts (denně)
0 3 * * * www-data mysql -u devapppro -pPASS devapppro -e "DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 24 HOUR"

# Mazání osiřelých souborů v storage/ bez DB záznamu (týdně)
0 4 * * 0 www-data find /var/www/devapppro/storage -type f -mmin +10080 -delete
```

Poznámka: PHP session GC (`session.gc_maxlifetime = 7200`) automaticky maže
expirované filesystem sessions - není potřeba cron.

### 7.1 Oprávnění pro auto-sync

PHP uživatel (www-data) musí mít read přístup k `PROJECTS_WATCH_DIR`:

```bash
# Pokud je složka v /home/user/Projekty
chmod 755 /home/user/Projekty
# nebo přidat www-data do skupiny uživatele
sudo usermod -a -G user www-data
chmod 750 /home/user/Projekty
```

---

## 8. Vývoj vs produkce

### 8.1 Vývoj

```bash
# Terminal 1: Apache (běží)
sudo systemctl start apache2

# Terminal 2: Vite dev server
cd /var/www/devapppro/frontend
npm run dev
# → http://localhost:5173 (React HMR)
# → /api proxy na http://localhost (Apache)
```

### 8.2 Produkce

```bash
# Build frontend
cd /var/www/devapppro/frontend
npm run build    # → /var/www/devapppro/assets/dist/

# Apache servíruje vše
# → http://localhost (React SPA + PHP API)
```

### 8.3 Přepnutí režimu

```php
// config/config.php
define('APP_ENV', 'development');  // zapne detailní chyby
define('APP_DEBUG', true);
```

---

## 9. Kontrolní seznam deploymentu

- [ ] Apache nainstalován a běží
- [ ] PHP 8.3+ s rozšířeními
- [ ] MariaDB běží, databáze `devapppro` vytvořena
- [ ] `database/schema.sql` importován
- [ ] `database/seed.sql` importován (admin účet)
- [ ] `config/config.php` nakonfigurován
- [ ] `config/database.php` nakonfigurován
- [ ] `composer install` proveden
- [ ] `npm install` proveden
- [ ] `npm run build` proveden (assets/dist/)
- [ ] Apache vhost aktivován
- [ ] `.htaccess` povolen (AllowOverride All)
- [ ] Security headers nastaveny
- [ ] OPcache zapnuta
- [ ] Log adresář existuje a je zapisovatelný
- [ ] `storage/` adresář existuje, `chmod 750`, zapisovatelný pro Apache
- [ ] Cron úlohy nastaveny
- [ ] Test: http://localhost/login zobrazí přihlašovací stránku
- [ ] Test: login funguje
- [ ] Test: CRUD pro každý modul funguje
- [ ] Test: CSP neblokuje žádné assety
- [ ] Test: žádné externí requesty (Network tab)
