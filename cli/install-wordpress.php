<?php
declare(strict_types=1);

/**
 * CLI script pro instalaci a odinstalaci WordPressu.
 * Běží jako root (přes cron nebo sudo).
 *
 * Použití:
 *   php install-wordpress.php {id}              → instalace
 *   php install-wordpress.php {id} --uninstall   → odinstalace
 *   php install-wordpress.php --process-pending  → zpracuje všechny pending jobs (cron)
 *
 * Logování: /var/log/devapppro-wp-install.log
 */

// Načtení konfigurace (bez bootstrapu - nepotřebujeme session)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Services/AclService.php';
$dbConfig = require __DIR__ . '/../config/database.php';

const LOG_FILE = '/var/log/devapppro-wp-install.log';

/**
 * PDO připojení k MariaDB (devapppro databáze - pro čtení/zápis wp_installs).
 */
function getDb(): PDO
{
    $cfg = require __DIR__ . '/../config/database.php';
    $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']};charset={$cfg['charset']}";
    return new PDO($dsn, $cfg['username'], $cfg['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

/**
 * PDO připojení k MariaDB jako root (čte heslo z /root/.my.cnf).
 */
function getRootDb(): PDO
{
    $pass = '';
    $mycnf = '/root/.my.cnf';
    if (is_readable($mycnf)) {
        $cfg = parse_ini_file($mycnf, true);
        $pass = $cfg['client']['password'] ?? '';
    }
    $dsn = 'mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4';
    return new PDO($dsn, 'root', $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

/**
 * Zápis do logu.
 */
function logMsg(string $msg): void
{
    $timestamp = date('Y-m-d H:i:s');
    $line = "[{$timestamp}] {$msg}" . PHP_EOL;
    @file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Najde záznam instalace podle ID.
 */
function findInstall(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM wp_installs WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}

/**
 * Aktualizuje status instalace.
 */
function updateStatus(PDO $db, int $id, string $status, ?string $errorMessage = null): void
{
    if ($errorMessage !== null) {
        // Zkrácení na 1000 znaků (prevence přetečení)
        $errorMessage = mb_substr($errorMessage, 0, 1000);
        $stmt = $db->prepare('UPDATE wp_installs SET status = ?, error_message = ? WHERE id = ?');
        $stmt->execute([$status, $errorMessage, $id]);
    } else {
        $stmt = $db->prepare('UPDATE wp_installs SET status = ?, error_message = NULL WHERE id = ?');
        $stmt->execute([$status, $id]);
    }
}

/**
 * Bezpečné ověření, že cesta je uvnitř base adresáře (ochrana proti path traversal).
 */
function safePath(string $base, string $sub): string
{
    $realBase = realpath($base);
    if ($realBase === false) {
        // Base ještě neexistuje - ověříme lexikálně
        $realBase = rtrim($base, '/');
    }
    $full = rtrim($base, '/') . '/' . ltrim($sub, '/');
    $realFull = realpath($full);
    if ($realFull !== false) {
        if (strpos($realFull, $realBase) !== 0) {
            throw new RuntimeException("Path traversal detekován: {$sub}");
        }
        return $realFull;
    }
    // Cesta ještě neexistuje - ověříme lexikálně
    $normalized = rtrim($base, '/') . '/' . ltrim($sub, '/');
    if (strpos($normalized, $realBase) !== 0) {
        throw new RuntimeException("Path traversal detekován: {$sub}");
    }
    return $normalized;
}

/**
 * Vygeneruje náhodné salts pro wp-config.php.
 */
function generateSalts(): array
{
    $keys = [
        'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
        'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT',
    ];
    $salts = [];
    foreach ($keys as $key) {
        $salts[$key] = bin2hex(random_bytes(32));
    }
    return $salts;
}

/**
 * Vygeneruje obsah wp-config.php.
 */
function generateWpConfig(array $install): string
{
    $salts = generateSalts();
    $dbName = addslashes($install['db_name']);
    $dbUser = addslashes($install['db_user']);
    $dbPass = addslashes($install['db_password']);
    $dbHost = WP_DB_HOST;
    $prefix = WP_DB_PREFIX;
    $secret = WP_AUTOLOGIN_SECRET;

    $saltLines = '';
    foreach ($salts as $key => $value) {
        $saltLines .= "define('{$key}', '{$value}');\n";
    }

    return <<<WPCONFIG
<?php
/**
 * WordPress konfigurace vygenerována Dev App Pro.
 * Web: {$install['site_name']}
 */

define('DB_NAME', '{$dbName}');
define('DB_USER', '{$dbUser}');
define('DB_PASSWORD', '{$dbPass}');
define('DB_HOST', '{$dbHost}');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

{$saltLines}

\$table_prefix = '{$prefix}';

define('WP_DEBUG', false);

// Dev App Pro auto-login secret
define('DEVAPPPRO_SECRET', '{$secret}');

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require_once ABSPATH . 'wp-settings.php';
WPCONFIG;
}

/**
 * Regeneruje SSL certifikát s SAN pro všechny WordPress instalace + základní domény.
 * Používá mkcert (CA v /home/ratesman/.local/share/mkcert/).
 */
function regenerateSslCert(PDO $db): void
{
    // Základní domény, které certifikát vždy obsahuje
    $sanDomains = ['localhost', '*.localhost', 'wp-dev.localhost', '127.0.0.1'];

    // Přidat všechny aktivní WordPress instalace (completed + configuring)
    $stmt = $db->query("SELECT site_url FROM wp_installs WHERE status IN ('completed', 'configuring')");
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $siteUrl) {
        if (!in_array($siteUrl, $sanDomains, true)) {
            $sanDomains[] = $siteUrl;
        }
    }

    // Najít mkcert binary
    $mkcert = trim(shell_exec('which mkcert 2>/dev/null') ?? '');
    if ($mkcert === '') {
        throw new RuntimeException('mkcert nenalezen v PATH');
    }

    // Nastavit CA path pro root (mkcert hledá v $HOME/.local/share/mkcert)
    $caRoot = '/home/ratesman/.local/share/mkcert';
    $env = 'CAROOT=' . escapeshellarg($caRoot);

    // Vygenerovat cert do dočasného souboru
    $tmpCert = '/tmp/wp-ssl-cert.pem';
    $tmpKey = '/tmp/wp-ssl-key.pem';
    $args = array_map('escapeshellarg', $sanDomains);
    $cmd = sprintf(
        '%s %s -cert-file %s -key-file %s %s',
        $env,
        escapeshellarg($mkcert),
        escapeshellarg($tmpCert),
        escapeshellarg($tmpKey),
        implode(' ', $args)
    );
    $result = runCmd($cmd);
    if ($result['code'] !== 0) {
        throw new RuntimeException('mkcert selhal: ' . $result['output']);
    }

    // Kopírovat cert na místo
    if (!copy($tmpCert, '/etc/apache2/ssl/localhost.crt')) {
        throw new RuntimeException('Nelze kopírovat certifikát');
    }
    if (!copy($tmpKey, '/etc/apache2/ssl/localhost.key')) {
        throw new RuntimeException('Nelze kopírovat klíč');
    }
    chmod('/etc/apache2/ssl/localhost.key', 0600);

    // Úklid
    @unlink($tmpCert);
    @unlink($tmpKey);

    // Reload Apache
    $result = runCmd('systemctl reload apache2');
    if ($result['code'] !== 0) {
        throw new RuntimeException('Reload Apache selhal: ' . $result['output']);
    }
}

/**
 * Spustí systémový příkaz a vrátí výstup + návratový kód.
 */
function runCmd(string $cmd): array
{
    $output = [];
    $rc = 0;
    exec($cmd . ' 2>&1', $output, $rc);
    return ['output' => implode("\n", $output), 'code' => $rc];
}

/**
 * Provede instalaci WordPressu.
 */
function doInstall(int $id): int
{
    $db = getDb();

    try {
        $install = findInstall($db, $id);
        if ($install === null) {
            logMsg("Instalace ID {$id} nenalezena v DB.");
            return 1;
        }

        // Ověření statusu
        if ($install['status'] !== 'pending') {
            logMsg("Instalace ID {$id} má status '{$install['status']}', očekáván 'pending'. Přeskakuji.");
            return 0;
        }

        $siteName = $install['site_name'];
        $docRoot = $install['document_root'];
        $dbName = $install['db_name'];
        $dbUser = $install['db_user'];
        $dbPass = $install['db_password'];
        $siteUrl = $install['site_url'];

        logMsg("Začínám instalaci WordPressu pro '{$siteName}' (ID {$id})");

        // Krok 1: downloading
        updateStatus($db, $id, 'downloading');
        $tarPath = '/tmp/wp-' . $id . '.tar.gz';
        $downloadCmd = sprintf('curl -fsSL -o %s %s', escapeshellarg($tarPath), escapeshellarg(WP_DOWNLOAD_URL));
        $result = runCmd($downloadCmd);
        if ($result['code'] !== 0) {
            throw new RuntimeException("Stažení WordPress selhalo: {$result['output']}");
        }
        logMsg("WordPress stažen do {$tarPath}");

        // Krok 2: extracting
        updateStatus($db, $id, 'extracting');

        // Ověření bezpečnosti cesty
        $validatedRoot = safePath(WP_INSTALL_BASE, $siteName);

        // Vytvoření document_root adresáře
        if (!is_dir($validatedRoot)) {
            if (!mkdir($validatedRoot, 0755, true)) {
                throw new RuntimeException("Nelze vytvořit adresář: {$validatedRoot}");
            }
        }

        // Rozbalení WordPress - tar obsahuje složku wordpress/
        $tmpExtract = '/tmp/wp-extract-' . $id;
        if (is_dir($tmpExtract)) {
            runCmd('rm -rf ' . escapeshellarg($tmpExtract));
        }
        mkdir($tmpExtract, 0755, true);

        $extractCmd = sprintf('tar -xzf %s -C %s', escapeshellarg($tarPath), escapeshellarg($tmpExtract));
        $result = runCmd($extractCmd);
        if ($result['code'] !== 0) {
            throw new RuntimeException("Rozbalení WordPress selhalo: {$result['output']}");
        }

        // Přesun obsahu wordpress/ do document_root
        $wpDir = $tmpExtract . '/wordpress';
        if (!is_dir($wpDir)) {
            throw new RuntimeException("Adresář wordpress/ nenalezen po rozbalení.");
        }

        // Přesun všech souborů (včetně skrytých) z wordpress/ do docRoot
        // -r rekurzivně, bez -a (exFAT nepodporuje zachování ownership/permissions)
        $moveCmd = sprintf(
            'cp -r %s/. %s/',
            escapeshellarg($wpDir),
            escapeshellarg($validatedRoot)
        );
        $result = runCmd($moveCmd);
        if ($result['code'] !== 0) {
            throw new RuntimeException("Přesun souborů WordPress selhal: {$result['output']}");
        }

        // Úklid dočasných souborů
        @unlink($tarPath);
        runCmd('rm -rf ' . escapeshellarg($tmpExtract));

        logMsg("WordPress rozbalen do {$validatedRoot}");

        // Krok 3: creating_db
        updateStatus($db, $id, 'creating_db');
        $rootDb = getRootDb();

        // Vytvoření databáze
        $rootDb->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        // Vytvoření uživatele a grant (bez prepared statements - MariaDB nepodporuje pro CREATE USER)
        $rootDb->exec("CREATE USER IF NOT EXISTS `{$dbUser}`@'localhost' IDENTIFIED BY '{$dbPass}'");
        $rootDb->exec("GRANT ALL PRIVILEGES ON `{$dbName}`.* TO `{$dbUser}`@'localhost'");
        $rootDb->exec("FLUSH PRIVILEGES");

        logMsg("Databáze '{$dbName}' a uživatel '{$dbUser}' vytvořeny");

        // Krok 4: configuring
        updateStatus($db, $id, 'configuring');

        // Vygenerování wp-config.php
        $wpConfig = generateWpConfig($install);
        $configPath = $validatedRoot . '/wp-config.php';
        if (file_put_contents($configPath, $wpConfig) === false) {
            throw new RuntimeException("Nelze zapsat wp-config.php do {$configPath}");
        }
        chmod($configPath, 0640);

        logMsg("wp-config.php vygenerován");

        // Kopírování mu-pluginu pro auto-login
        $muDir = $validatedRoot . '/wp-content/mu-plugins';
        if (!is_dir($muDir)) {
            mkdir($muDir, 0755, true);
        }
        $muSource = __DIR__ . '/wp-mu-plugin.php';
        $muDest = $muDir . '/devapppro-autologin.php';
        if (!copy($muSource, $muDest)) {
            throw new RuntimeException("Nelze kopírovat mu-plugin do {$muDest}");
        }
        logMsg("mu-plugin pro auto-login nainstalován");

        // Krok 5: Regenerace SSL certifikátu s novým subdoménovým SAN
        updateStatus($db, $id, 'configuring');
        regenerateSslCert($db);
        logMsg("SSL certifikát regenerován s novým subdoménovým SAN");

        // Krok 6: Spuštění WordPress instalace přes wp-cli (vytvoří DB tabulky + admin účet)
        $adminUser = $install['admin_user'] ?? 'admin';
        $adminPass = $install['admin_password'] ?? bin2hex(random_bytes(8));
        $adminEmail = $install['admin_email'];
        $siteTitle = ucfirst($siteName);

        $wpCmd = sprintf(
            'sudo -u ratesman wp core install --path=%s --url=%s --title=%s --admin_user=%s --admin_password=%s --admin_email=%s --skip-email 2>&1',
            escapeshellarg($validatedRoot),
            escapeshellarg('https://' . $install['site_url']),
            escapeshellarg($siteTitle),
            escapeshellarg($adminUser),
            escapeshellarg($adminPass),
            escapeshellarg($adminEmail)
        );
        $result = runCmd($wpCmd);
        if ($result['code'] !== 0) {
            throw new RuntimeException("wp core install selhal: {$result['output']}");
        }
        logMsg("WordPress instalace dokončena přes wp-cli (admin: {$adminUser})");

        // Krok 6b: normalizace ACL - soubory vytvořené root workerem (mkdir 0755,
        // umask 022) mají oříznutou ACL masku → www-data/ratesman ztratí zápis
        // a WP updaty selžou. Selhání jen logovat, instalaci neshodit.
        $acl = \DevAppPro\Services\AclService::normalizeProjectTree($validatedRoot);
        if ($acl['ok']) {
            logMsg("ACL normalizováno (www-data, ratesman rwX)");
        } else {
            logMsg("VAROVÁNÍ: ACL normalizace selhala: {$acl['message']}");
        }

        // Krok 7: completed - zjištění verze WordPress
        $versionFile = $validatedRoot . '/wp-includes/version.php';
        $wpVersion = null;
        if (is_file($versionFile)) {
            $versionContent = file_get_contents($versionFile);
            if ($versionContent !== false && preg_match("/\\\$wp_version\s*=\s*'([^']+)'/", $versionContent, $m)) {
                $wpVersion = $m[1];
            }
        }

        // Update status na completed + wp_version
        $stmt = $db->prepare('UPDATE wp_installs SET status = ?, wp_version = ?, error_message = NULL WHERE id = ?');
        $stmt->execute(['completed', $wpVersion, $id]);

        logMsg("Instalace WordPressu pro '{$siteName}' (ID {$id}) dokončena. Verze: {$wpVersion}");
        return 0;
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        logMsg("CHYBA při instalaci ID {$id}: {$msg}");
        updateStatus($db, $id, 'failed', $msg);
        return 1;
    }
}

/**
 * Provede odinstalaci WordPressu.
 */
function doUninstall(int $id): int
{
    $db = getDb();

    try {
        $install = findInstall($db, $id);
        if ($install === null) {
            logMsg("Odinstalace: instalace ID {$id} nenalezena v DB.");
            return 1;
        }

        $siteName = $install['site_name'];
        $docRoot = $install['document_root'];
        $dbName = $install['db_name'];
        $dbUser = $install['db_user'];

        logMsg("Začínám odinstalaci WordPressu pro '{$siteName}' (ID {$id})");

        // 1. Drop databáze a uživatele (vhost neřešíme - wildcard)
        $rootDb = getRootDb();
        $rootDb->exec("DROP DATABASE IF EXISTS `{$dbName}`");
        $rootDb->exec("DROP USER IF EXISTS `{$dbUser}`@'localhost'");
        $rootDb->exec("FLUSH PRIVILEGES");

        logMsg("Databáze '{$dbName}' a uživatel '{$dbUser}' smazány");

        // 3. Smazání document_root
        $validatedRoot = safePath(WP_INSTALL_BASE, $siteName);
        if (is_dir($validatedRoot)) {
            runCmd('rm -rf ' . escapeshellarg($validatedRoot));
            logMsg("Adresář smazán: {$validatedRoot}");
        }

        logMsg("Odinstalace WordPressu pro '{$siteName}' (ID {$id}) dokončena");

        // 4. Smazání záznamu z DB (před regenerací cert - aby se SAN odstranil)
        $stmt = $db->prepare('DELETE FROM wp_installs WHERE id = ?');
        $stmt->execute([$id]);
        logMsg("Záznam ID {$id} smazán z DB");

        // 5. Regenerace SSL certifikátu (bez smazaného subdoménového SAN)
        regenerateSslCert($db);
        logMsg("SSL certifikát regenerován po odinstalaci");

        return 0;
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        logMsg("CHYBA při odinstalaci ID {$id}: {$msg}");
        return 1;
    }
}

// ── Hlavní logika ──────────────────────────────────────────────

$argv = $_SERVER['argv'] ?? [];

// Mód: --process-pending (pro cron) - zpracuje všechny pending jobs
if (isset($argv[1]) && $argv[1] === '--process-pending') {
    $db = getDb();
    $stmt = $db->query("SELECT id FROM wp_installs WHERE status = 'pending' ORDER BY id ASC");
    $jobs = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($jobs)) {
        exit(0);
    }
    logMsg("Cron: zpracovávám " . count($jobs) . " pending job(s)");
    foreach ($jobs as $jobId) {
        $exitCode = doInstall((int) $jobId);
        logMsg("Cron: job $jobId dokončen s exit code $exitCode");
    }
    exit(0);
}

// Mód: --process-uninstalls (pro cron) - zpracuje odinstalace
if (isset($argv[1]) && $argv[1] === '--process-uninstalls') {
    $db = getDb();
    $stmt = $db->query("SELECT id FROM wp_installs WHERE status = 'pending_uninstall' ORDER BY id ASC");
    $jobs = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($jobs)) {
        exit(0);
    }
    logMsg("Cron: zpracovávám " . count($jobs) . " uninstall job(s)");
    foreach ($jobs as $jobId) {
        $exitCode = doUninstall((int) $jobId);
        logMsg("Cron: uninstall job $jobId dokončen s exit code $exitCode");
    }
    exit(0);
}

if (count($argv) < 2) {
    fwrite(STDERR, "Použití: php install-wordpress.php {id} [--uninstall] | --process-pending | --process-uninstalls\n");
    exit(1);
}

$id = (int) $argv[1];
if ($id <= 0) {
    fwrite(STDERR, "ID musí být kladné celé číslo.\n");
    exit(1);
}

$uninstall = isset($argv[2]) && $argv[2] === '--uninstall';

if ($uninstall) {
    $exitCode = doUninstall($id);
} else {
    $exitCode = doInstall($id);
}

exit($exitCode);
