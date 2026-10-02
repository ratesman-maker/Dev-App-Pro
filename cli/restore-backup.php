<?php
declare(strict_types=1);

/**
 * CLI script pro obnovu Duplicator Pro záloh jako projekty.
 *
 * Spouští se cronem: php restore-backup.php --process-pending
 *
 * Proces:
 * 1. Extrakce zip do PROJECTS_WATCH_DIR/{name}/
 * 2. Detekce SQL souboru (Duplicator: dup-installer/*.sql)
 * 3. Vytvoření DB + uživatele
 * 4. Import SQL
 * 5. Generování wp-config.php
 * 6. Search/replace staré URL → nová URL
 * 7. Regenerace SSL certifikátu
 * 8. Vytvoření projektu v DB
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/Libs/autoload.php';

use DevAppPro\Repositories\BackupRestoreRepository;
use DevAppPro\Repositories\ProjectRepository;
use DevAppPro\Services\ProjectSyncService;
use Duplicator\Libs\DupArchive\DupArchiveExpandBasicEngine;

function logMsg(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

function updateStatus(\PDO $db, int $id, string $status, ?string $error = null): void
{
    $stmt = $db->prepare('UPDATE backup_restores SET status = ?, error_message = ? WHERE id = ?');
    $errMsg = $error !== null ? substr($error, 0, 65000) : null;
    $stmt->execute([$status, $errMsg, $id]);
}

function runCmd(string $cmd): array
{
    $output = [];
    $code = 0;
    exec($cmd, $output, $code);
    return ['code' => $code, 'output' => implode("\n", $output)];
}

function getRootCredentials(): array
{
    $myCnf = '/root/.my.cnf';
    if (!file_exists($myCnf)) {
        throw new RuntimeException('/root/.my.cnf neexistuje');
    }
    $content = file_get_contents($myCnf);
    $user = 'root';
    $pass = '';
    if (preg_match('/user\s*=\s*(.+)/', $content, $m)) {
        $user = trim($m[1]);
    }
    if (preg_match('/password\s*=\s*(.+)/', $content, $m)) {
        $pass = trim($m[1]);
    }
    return ['user' => $user, 'password' => $pass];
}

/**
 * Najde SQL soubor v rozbalené Duplicator Pro záloze.
 */
function findSqlFile(string $root): ?string
{
    // Duplicator Pro DAF: dup-installer/dup_descriptors_*/db_dumps/*-dump.sql
    $dupDir = $root . '/dup-installer';
    if (is_dir($dupDir)) {
        // Hledat v dup_descriptors
        foreach (glob($dupDir . '/dup_descriptors_*/db_dumps/*.sql') as $sqlFile) {
            return $sqlFile;
        }
        // Fallback: jakýkoliv .sql v dup-installer
        foreach (glob($dupDir . '/*.sql') as $sqlFile) {
            return $sqlFile;
        }
        foreach (glob($dupDir . '/**/*.sql') as $sqlFile) {
            return $sqlFile;
        }
    }

    // Fallback: .sql v rootu
    foreach (glob($root . '/*.sql') as $sqlFile) {
        return $sqlFile;
    }

    // Fallback: rekurzivně
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $file) {
        if ($file->getExtension() === 'sql') {
            return $file->getPathname();
        }
    }

    return null;
}

/**
 * Najde starou URL z Duplicator konfigurace nebo SQL souboru.
 */
function findOldUrl(string $root, ?string $sqlFile): ?string
{
    // Duplicator Pro DAF: dup-installer/dup_descriptors_*/archive.txt (JSON)
    $archiveTxt = glob($root . '/dup-installer/dup_descriptors_*/archive.txt');
    if (!empty($archiveTxt) && file_exists($archiveTxt[0])) {
        $cfg = json_decode(file_get_contents($archiveTxt[0]), true);
        if ($cfg) {
            // url_old (starší formát)
            if (!empty($cfg['url_old'])) {
                return $cfg['url_old'];
            }
            // subsites[0].domain (DAF formát)
            if (!empty($cfg['subsites'][0]['domain'])) {
                $domain = $cfg['subsites'][0]['domain'];
                $scheme = (!empty($cfg['subsites'][0]['secure_on']) || !empty($cfg['secure_on'])) ? 'https' : 'http';
                return $scheme . '://' . $domain;
            }
            // blogname jako fallback (často obsahuje doménu)
            if (!empty($cfg['blogname']) && preg_match('/\./', $cfg['blogname'])) {
                return 'https://' . $cfg['blogname'];
            }
        }
    }

    // Duplicator Pro: dup-installer/dup-installer.cfg (JSON)
    $cfgFile = $root . '/dup-installer/dup-installer.cfg';
    if (file_exists($cfgFile)) {
        $cfg = json_decode(file_get_contents($cfgFile), true);
        if ($cfg && !empty($cfg['urlOld'])) {
            return $cfg['urlOld'];
        }
    }

    // Fallback: hledat v SQL souboru (prvních 500KB - WordPress sql dump má INSERT na začátku)
    if ($sqlFile && file_exists($sqlFile)) {
        $content = file_get_contents($sqlFile, false, null, 0, 500000);
        if (preg_match('/https?:\/\/[^\s\'"<>]+/i', $content, $m)) {
            // Najít nejčastější URL (často je to siteurl)
            preg_match_all('/https?:\/\/[a-zA-Z0-9._-]+\.[a-zA-Z]{2,}/i', $content, $all);
            if (!empty($all[0])) {
                $counts = array_count_values($all[0]);
                arsort($counts);
                return array_key_first($counts);
            }
            return $m[0];
        }
    }

    return null;
}

/**
 * Serialization-aware search/replace URL a cest v databázi.
 * Na rozdíl od SQL REPLACE() tato funkce:
 * 1. Nahrazuje URL i cesty na disku
 * 2. Opravuje délky v PHP serializovaných řetězcích (s:XX:"...")
 * 3. Respektuje JSON data
 */
function replaceUrlsInDb(string $dbName, string $dbUser, string $dbPass, string $oldUrl, string $newUrl, ?string $oldPath = null, ?string $newPath = null): void
{
    if ($oldUrl === $newUrl && ($oldPath === null || $oldPath === $newPath)) {
        return;
    }

    $pdo = new \PDO(
        "mysql:host=127.0.0.1;port=3306;dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
    );

    // Sestavit seznam nahrazení (od nejdelšího po nejkratší)
    $replacements = [];

    if ($oldUrl && $newUrl && $oldUrl !== $newUrl) {
        $parsed = parse_url($oldUrl);
        if (!empty($parsed['host'])) {
            $host = $parsed['host'];
            $path = $parsed['path'] ?? '';
            $newBase = rtrim($newUrl, '/') . $path;
            // Obě staré varianty (http i https) mapovat na novou URL — nová je vždy https,
            // jinak se do DB propíše http:// <doména> a web má mixed content
            $replacements['https://' . $host . $path] = $newBase;
            $replacements['http://' . $host . $path] = $newBase;
        } else {
            $replacements[$oldUrl] = $newUrl;
        }
    }

    if ($oldPath && $newPath && $oldPath !== $newPath) {
        $replacements[$oldPath] = $newPath;
        // Escapovaná varianta pro JSON (\/ → /)
        $replacements[str_replace('/', '\\/', $oldPath)] = str_replace('/', '\\/', $newPath);
    }

    // Seřadit od nejdelšího (aby se nejdřív nahradily delší matche)
    uksort($replacements, fn($a, $b) => strlen($b) - strlen($a));

    logMsg("Nahrazení: " . implode(' → ', array_keys($replacements)) . " → " . implode(' → ', array_values($replacements)));

    // Najít všechny tabulky
    $tables = $pdo->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);
    $totalReplaced = 0;

    foreach ($tables as $table) {
        $cols = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(\PDO::FETCH_ASSOC);
        $textCols = [];
        $pkCols = [];
        foreach ($cols as $col) {
            $type = strtolower($col['Type']);
            if (str_contains($type, 'text') || str_contains($type, 'varchar') || str_contains($type, 'char')) {
                if ($col['Field'] === 'guid') continue; // WordPress konvence
                $textCols[] = $col['Field'];
            }
            if ($col['Key'] === 'PRI') {
                $pkCols[] = $col['Field'];
            }
        }

        if (empty($textCols) || empty($pkCols)) {
            continue;
        }

        $pkList = implode(', ', array_map(fn($c) => "`{$c}`", $pkCols));

        foreach ($textCols as $col) {
            // Najít řádky obsahující nějaký ze starých řetězců
            $whereParts = [];
            foreach ($replacements as $old => $new) {
                $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $old);
                $whereParts[] = "`{$col}` LIKE " . $pdo->quote('%' . $escaped . '%');
            }
            $where = implode(' OR ', $whereParts);

            $stmt = $pdo->query("SELECT {$pkList}, `{$col}` FROM `{$table}` WHERE {$where}");
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $value = $row[$col];
                $newValue = serializeAwareReplace($value, $replacements);

                if ($newValue !== $value) {
                    $totalReplaced++;

                    $setParts = [];
                    $params = [];
                    foreach ($pkCols as $pk) {
                        $setParts[] = "`{$pk}` = ?";
                        $params[] = $row[$pk];
                    }
                    $whereClause = implode(' AND ', $setParts);

                    $update = $pdo->prepare("UPDATE `{$table}` SET `{$col}` = ? WHERE {$whereClause}");
                    $update->execute(array_merge([$newValue], $params));
                }
            }
        }
    }

    logMsg("Search/replace dokončeno: {$totalReplaced} řádků aktualizováno");
}

/**
 * Rekurzivně nahradí hodnoty v serializovaných datech s opravou délek.
 * Funguje pro: serializované PHP řetězce, JSON, a plain text.
 */
function serializeAwareReplace(string $data, array $replacements): string
{
    if (empty($replacements)) {
        return $data;
    }

    // Zkusit PHP unserialize - pokud je serializované, rekurzivně nahradit
    // Pozor: globální error handler v bootstrap.php převádí i @-potlačená
    // varování na ErrorException, proto try/catch místo pouhého @.
    try {
        $unserialized = @unserialize($data);
    } catch (\Throwable) {
        $unserialized = false;
    }
    if ($unserialized !== false || $data === 'b:0;') {
        $replaced = recursiveReplace($unserialized, $replacements);
        $result = serialize($replaced);
        if ($result !== $data) {
            return $result;
        }
    }

    // Pokud to není serializované, zkusit JSON
    $jsonDecoded = json_decode($data, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($jsonDecoded)) {
        $replaced = recursiveReplace($jsonDecoded, $replacements);
        $result = json_encode($replaced, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($result !== $data) {
            return $result;
        }
    }

    // Jinak prostý string replace
    $result = $data;
    foreach ($replacements as $old => $new) {
        $result = str_replace($old, $new, $result);
    }
    return $result;
}

/**
 * Rekurzivně projde pole/hodnotu a nahradí všechny výskyty.
 */
function recursiveReplace(mixed $data, array $replacements): mixed
{
    if (is_string($data)) {
        foreach ($replacements as $old => $new) {
            $data = str_replace($old, $new, $data);
        }
        return $data;
    }

    if (is_array($data)) {
        $result = [];
        foreach ($data as $key => $value) {
            $newKey = $key;
            if (is_string($key)) {
                foreach ($replacements as $old => $new) {
                    $newKey = str_replace($old, $new, $newKey);
                }
            }
            $result[$newKey] = recursiveReplace($value, $replacements);
        }
        return $result;
    }

    return $data;
}

/**
 * Detekce staré cesty na serveru z WordPress databáze.
 * Hledá v options jako recently_edited, et_images_temp_folder, upload_path, atd.
 */
function findOldPath(\PDO $sitePdo, string $tablePrefix): ?string
{
    $optionsTable = $tablePrefix . 'options';

    // Zkusit najít cestu v různých options
    $keys = ['recently_edited', 'et_images_temp_folder', 'upload_path', 'stylesheet_root', 'template_root'];
    foreach ($keys as $key) {
        $stmt = $sitePdo->prepare("SELECT option_value FROM `{$optionsTable}` WHERE option_name = ? LIMIT 1");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        if ($val && preg_match('#(/home/[^\s"\']+|/var/www/[^\s"\']+|/srv/[^\s"\']+)#', $val, $m)) {
            // Najít kořenovou cestu (do wp-content nebo wp-includes)
            $path = $m[1];
            // Oříznout na kořen - najít /wp-content nebo /wp-includes nebo /wp-admin
            if (preg_match('#^(.+?)/(wp-content|wp-includes|wp-admin|wp-login)#', $path, $rootMatch)) {
                return rtrim($rootMatch[1], '/');
            }
            return rtrim($path, '/');
        }
    }

    // Fallback: hledat v _transient_dirsize_cache nebo jiných serializovaných datech
    $stmt = $sitePdo->query("SELECT option_value FROM `{$optionsTable}` WHERE option_value LIKE '%/home/www/%' OR option_value LIKE '%/var/www/%' LIMIT 1");
    $val = $stmt->fetchColumn();
    if ($val && preg_match('#(/home/[^\s"\']+|/var/www/[^\s"\']+)#', $val, $m)) {
        $path = $m[1];
        if (preg_match('#^(.+?)/(wp-content|wp-includes|wp-admin|wp-login)#', $path, $rootMatch)) {
            return rtrim($rootMatch[1], '/');
        }
        return rtrim($path, '/');
    }

    return null;
}

/**
 * Regenerace SSL certifikátu se všemi subdoménami.
 * Musí běžet jako uživatel ratesman (jeho mkcert CA je v trust store).
 */
function regenerateSslCert(\PDO $db): void
{
    $hosts = ['localhost'];
    $stmt = $db->query("SELECT folder_path FROM projects WHERE folder_path IS NOT NULL AND status = 'active'");
    while ($row = $stmt->fetch()) {
        $hosts[] = $row['folder_path'] . '.localhost';
    }
    // Přidat i restore joby
    $stmt2 = $db->query("SELECT project_name FROM backup_restores WHERE status IN ('completed','importing_sql','configuring','replacing_urls','regenerating_ssl')");
    while ($row = $stmt2->fetch()) {
        $hosts[] = $row['project_name'] . '.localhost';
    }

    $hosts = array_unique($hosts);
    $sanArgs = '';
    foreach ($hosts as $h) {
        $sanArgs .= ' ' . escapeshellarg($h);
    }

    // Spustit mkcert jako uživatel ratesman (jeho CA je v trust store)
    // Generovat do tmp (ratesman nemá právo do /etc/apache2/ssl/)
    $caRoot = '/home/ratesman/.local/share/mkcert';
    $tmpCert = '/tmp/devapppro-ssl-cert.pem';
    $tmpKey = '/tmp/devapppro-ssl-key.pem';
    $cmd = "sudo -u ratesman CAROOT={$caRoot} mkcert -cert-file {$tmpCert} -key-file {$tmpKey} {$sanArgs} 2>&1";
    $result = runCmd($cmd);
    if ($result['code'] !== 0) {
        logMsg("Varování: mkcert: {$result['output']}");
    } else {
        // Přesunout do /etc/apache2/ssl/
        copy($tmpCert, '/etc/apache2/ssl/localhost.crt');
        copy($tmpKey, '/etc/apache2/ssl/localhost.key');
        chmod('/etc/apache2/ssl/localhost.key', 0600);
        @unlink($tmpCert);
        @unlink($tmpKey);
    }
    runCmd('systemctl reload apache2 2>&1');
}

// === MAIN ===

$mode = $argv[1] ?? '';

if ($mode !== '--process-pending') {
    echo "Použití: php restore-backup.php --process-pending\n";
    exit(1);
}

$repo = new BackupRestoreRepository();
$db = db();

$job = $repo->findPending();
if ($job === null) {
    echo "Žádné restore joby.\n";
    exit(0);
}

$id = (int) $job['id'];
$projectName = $job['project_name'];
$backupPath = $job['backup_path'];
$targetRoot = $job['document_root'];
$dbName = $job['db_name'];
$dbUser = $job['db_user'];
$dbPassword = $job['db_password'];
$newUrl = 'https://' . $job['site_url'];

logMsg("Začínám restore zálohy pro '{$projectName}' (ID {$id})");

try {
    // 1. Extrakce
    updateStatus($db, $id, 'extracting');
    if (!is_dir($targetRoot)) {
        mkdir($targetRoot, 0755, true);
    }

    $ext = strtolower(pathinfo($backupPath, PATHINFO_EXTENSION));
    if ($ext === 'daf') {
        // DAF extrakce přes DupArchive knihovnu
        DupArchiveExpandBasicEngine::setCallbacks(
            function ($s) { logMsg($s); },
            function ($path, $mode) { @chmod($path, 0644); },
            function ($path, $mode, $recursive) {
                if (!is_dir($path)) {
                    @mkdir($path, 0755, true);
                }
                return is_dir($path);
            }
        );
        DupArchiveExpandBasicEngine::expandDirectory($backupPath, '', $targetRoot, '');
    } else {
        // ZIP extrakce
        $cmd = sprintf('unzip -o -q %s -d %s 2>&1', escapeshellarg($backupPath), escapeshellarg($targetRoot));
        $result = runCmd($cmd);
        if ($result['code'] !== 0) {
            throw new RuntimeException("Extrakce selhala: {$result['output']}");
        }
    }
    logMsg("Záloha rozbalena do {$targetRoot}");

    // 2. Najít SQL soubor
    $sqlFile = findSqlFile($targetRoot);
    if ($sqlFile === null) {
        throw new RuntimeException("SQL soubor nebyl nalezen v záloze.");
    }
    logMsg("SQL soubor: {$sqlFile}");

    // Najít starou URL (preliminary - z konfigurace)
    $oldUrl = findOldUrl($targetRoot, $sqlFile);
    if ($oldUrl) {
        $oldUrl = rtrim($oldUrl, '/');
        logMsg("Stará URL (preliminary): {$oldUrl}");
        $stmt = $db->prepare('UPDATE backup_restores SET old_url = ? WHERE id = ?');
        $stmt->execute([$oldUrl, $id]);
    }

    // 3. Vytvoření DB + uživatele
    updateStatus($db, $id, 'creating_db');
    $rootCreds = getRootCredentials();
    $rootPdo = new PDO(
        "mysql:host=127.0.0.1;port=3306",
        $rootCreds['user'],
        $rootCreds['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $dbNameEsc = preg_replace('/[^a-zA-Z0-9_]/', '', $dbName);
    $dbUserEsc = preg_replace('/[^a-zA-Z0-9_]/', '', $dbUser);

    $rootPdo->exec("DROP DATABASE IF EXISTS `{$dbNameEsc}`");
    $rootPdo->exec("CREATE DATABASE `{$dbNameEsc}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $rootPdo->exec("DROP USER IF EXISTS `{$dbUserEsc}`@'localhost'");
    $rootPdo->exec("DROP USER IF EXISTS `{$dbUserEsc}`@'127.0.0.1'");
    $rootPdo->exec("CREATE USER `{$dbUserEsc}`@'localhost' IDENTIFIED BY '{$dbPassword}'");
    $rootPdo->exec("CREATE USER `{$dbUserEsc}`@'127.0.0.1' IDENTIFIED BY '{$dbPassword}'");
    $rootPdo->exec("GRANT ALL PRIVILEGES ON `{$dbNameEsc}`.* TO `{$dbUserEsc}`@'localhost'");
    $rootPdo->exec("GRANT ALL PRIVILEGES ON `{$dbNameEsc}`.* TO `{$dbUserEsc}`@'127.0.0.1'");
    $rootPdo->exec("FLUSH PRIVILEGES");
    logMsg("Databáze {$dbNameEsc} a uživatel {$dbUserEsc} vytvořeny");

    // 4. Import SQL
    updateStatus($db, $id, 'importing_sql');
    $cmd = sprintf(
        'mysql -h 127.0.0.1 -u %s -p%s %s < %s 2>&1',
        escapeshellarg($dbUserEsc),
        escapeshellarg($dbPassword),
        escapeshellarg($dbNameEsc),
        escapeshellarg($sqlFile)
    );
    $result = runCmd($cmd);
    if ($result['code'] !== 0) {
        throw new RuntimeException("Import SQL selhal: {$result['output']}");
    }
    logMsg("SQL importován do {$dbNameEsc}");

    // 4b. Najít skutečnou starou URL z DB (siteurl) - spolehlivější než konfig
    $sitePdo = new PDO(
        "mysql:host=127.0.0.1;port=3306;dbname={$dbNameEsc};charset=utf8mb4",
        $dbUserEsc,
        $dbPassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Najít WordPress tabulku prefix (z archive.txt nebo detekce)
    $tablePrefix = 'wp_';
    $archiveTxt = glob($targetRoot . '/dup-installer/dup_descriptors_*/archive.txt');
    if (!empty($archiveTxt) && file_exists($archiveTxt[0])) {
        $cfg = json_decode(file_get_contents($archiveTxt[0]), true);
        if ($cfg && !empty($cfg['wp_tableprefix'])) {
            $tablePrefix = $cfg['wp_tableprefix'];
        }
    }
    // Fallback: detekce prefixu z tabulek
    if ($tablePrefix === 'wp_') {
        $tables = $sitePdo->query("SHOW TABLES LIKE '%options'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $tbl) {
            if (str_ends_with($tbl, 'options')) {
                $tablePrefix = preg_replace('/options$/', '', $tbl);
                break;
            }
        }
    }
    logMsg("Table prefix: {$tablePrefix}");

    // Číst siteurl z DB
    $optionsTable = $tablePrefix . 'options';
    $stmt = $sitePdo->prepare("SELECT option_value FROM `{$optionsTable}` WHERE option_name = 'siteurl' LIMIT 1");
    $stmt->execute();
    $dbSiteUrl = $stmt->fetchColumn();
    if ($dbSiteUrl) {
        $dbSiteUrl = rtrim($dbSiteUrl, '/');
        if ($oldUrl !== $dbSiteUrl) {
            logMsg("Opravuji starou URL z DB: {$oldUrl} → {$dbSiteUrl}");
            $oldUrl = $dbSiteUrl;
            $stmt = $db->prepare('UPDATE backup_restores SET old_url = ? WHERE id = ?');
            $stmt->execute([$oldUrl, $id]);
        }
    }

    // 5. Generování wp-config.php
    updateStatus($db, $id, 'configuring');
    $wpConfigPath = $targetRoot . '/wp-config.php';
    $salts = [];
    $saltKeys = ['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'];
    foreach ($saltKeys as $key) {
        $salts[$key] = bin2hex(random_bytes(32));
    }
    $saltLines = '';
    foreach ($salts as $key => $value) {
        $saltLines .= "define('{$key}', '{$value}');\n";
    }

    $secret = WP_AUTOLOGIN_SECRET;
    if ($secret === 'cli_placeholder') {
        throw new RuntimeException('WP_AUTOLOGIN_SECRET není nastaven — cron worker potřebuje DEVAPPPRO_WP_AUTOLOGIN_SECRET v env (viz /etc/cron.d/devapppro-sync)');
    }
    $wpConfig = <<<WPCONFIG
<?php
/**
 * WordPress konfigurace - obnoveno ze zálohy Dev App Pro.
 * Projekt: {$projectName}
 */

define('DB_NAME', '{$dbNameEsc}');
define('DB_USER', '{$dbUserEsc}');
define('DB_PASSWORD', '{$dbPassword}');
define('DB_HOST', '127.0.0.1');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

{$saltLines}

\$table_prefix = '{$tablePrefix}';

define('WP_DEBUG', false);
define('FS_METHOD', 'direct');
define('DEVAPPPRO_SECRET', '{$secret}');

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require_once ABSPATH . 'wp-settings.php';
WPCONFIG;

    file_put_contents($wpConfigPath, $wpConfig);
    chmod($wpConfigPath, 0640);
    logMsg("wp-config.php vygenerován");

    // Kopírovat mu-plugin pro auto-login
    $muDir = $targetRoot . '/wp-content/mu-plugins';
    if (!is_dir($muDir)) {
        mkdir($muDir, 0755, true);
    }
    $muSource = __DIR__ . '/wp-mu-plugin.php';
    if (file_exists($muSource)) {
        copy($muSource, $muDir . '/devapppro-autologin.php');
    }

    // 5b. Vytvořit .htaccess pro WordPress (pretty permalinks)
    $htaccessPath = $targetRoot . '/.htaccess';
    if (!file_exists($htaccessPath)) {
        $htaccessContent = <<<HTACCESS
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
HTACCESS;
        file_put_contents($htaccessPath, $htaccessContent);
        chmod($htaccessPath, 0644);
        logMsg(".htaccess vytvořen pro pretty permalinks");
    }

    // 6. Search/replace URL a cest (serialization-aware)
    updateStatus($db, $id, 'replacing_urls');
    if ($oldUrl) {
        // Detekovat starou cestu na serveru z DB
        $oldPath = findOldPath($sitePdo, $tablePrefix);
        $newPath = $targetRoot; // PROJECTS_WATCH_DIR/{name}
        if ($oldPath) {
            logMsg("Detekována stará cesta: {$oldPath} → {$newPath}");
        }
        replaceUrlsInDb($dbNameEsc, $dbUserEsc, $dbPassword, $oldUrl, $newUrl, $oldPath, $newPath);
    } else {
        logMsg("Stará URL nenalezena, přeskakuji search/replace");
    }

    // 6b. Opravit CSS soubory s lokálními fonty (Kadence theme ukládá absolutní cesty)
    $fontsDir = $targetRoot . '/wp-content/fonts';
    if (is_dir($fontsDir)) {
        $cssFiles = glob($fontsDir . '/*.css');
        foreach ($cssFiles as $cssFile) {
            $content = file_get_contents($cssFile);
            if ($content === false) continue;
            $changed = false;
            if ($oldPath && strpos($content, $oldPath) !== false) {
                $content = str_replace($oldPath . '/wp-content//fonts', '/wp-content/fonts', $content);
                $content = str_replace($oldPath . '/wp-content/fonts', '/wp-content/fonts', $content);
                $changed = true;
            }
            if (strpos($content, $newPath . '/wp-content//fonts') !== false) {
                $content = str_replace($newPath . '/wp-content//fonts', '/wp-content/fonts', $content);
                $changed = true;
            }
            if (strpos($content, $newPath . '/wp-content/fonts') !== false) {
                $content = str_replace($newPath . '/wp-content/fonts', '/wp-content/fonts', $content);
                $changed = true;
            }
            if ($changed) {
                file_put_contents($cssFile, $content);
                logMsg("Opraveny cesty k fontům v: " . basename($cssFile));
            }
        }
    }

    // 6c. Vyčistit cache adresáře - obsahují natvrdo starou produkční URL
    // (Divi et-cache drží CSS s url(//stara-domena/...) pro ikonní fonty
    // → prohlížeč je zablokuje CORS → ikony se vykreslí jako číslice)
    foreach (['wp-content/et-cache', 'wp-content/cache'] as $cacheDir) {
        $cachePath = $targetRoot . '/' . $cacheDir;
        if (is_dir($cachePath)) {
            runCmd('rm -rf ' . escapeshellarg($cachePath) . '/*');
            logMsg("Cache vyčištěna: {$cacheDir}");
        }
    }

    // 6c2. Deaktivovat pluginy, které na localhostu škodí:
    // - bezpečnostní (blokují "localhost" v URL, schovávají wp-login)
    // - cache (drží produkční URL, maškují změny)
    // - backup/externí správa (duplicator, ManageWP worker - volá domů)
    // SEO a funkční pluginy zůstávají aktivní (zachovávají markup produkce).
    $disablePlugins = [
        // bezpečnostní
        'block-bad-queries', 'wps-hide-login', 'limit-login-attempts-reloaded',
        'really-simple-ssl', 'really-simple-ssl-pro', 'complianz-gdpr',
        'wordfence', 'ithemes-security-pro', 'better-wp-security', 'sucuri-scanner',
        'all-in-one-wp-security-and-firewall', 'wp-cerber', 'bulletproof-security', 'hide-my-wp',
        // cache
        'wp-super-cache', 'w3-total-cache', 'wp-rocket', 'litespeed-cache', 'cache-enabler',
        'autoptimize', 'wp-fastest-cache', 'breeze', 'hummingbird-performance',
        'sg-cachepress', 'comet-cache', 'flying-press', 'simple-cache-cleaner-2',
        // externí správa (duplicator NECHÁVÁME - uživatel ho používá denně)
        'worker', 'updraftplus', 'backwpup', 'jetpack',
    ];
    $wpCli = '/usr/local/bin/wp';
    if (file_exists($wpCli)) {
        $listCmd = sprintf(
            'sudo -u ratesman %s --path=%s plugin list --status=active --field=name 2>/dev/null',
            escapeshellarg($wpCli),
            escapeshellarg($targetRoot)
        );
        $activePlugins = array_filter(array_map('trim', explode("\n", (string) shell_exec($listCmd))));
        $toDisable = array_values(array_intersect($activePlugins, $disablePlugins));
        if (!empty($toDisable)) {
            $deactCmd = sprintf(
                'sudo -u ratesman %s --path=%s plugin deactivate %s 2>&1',
                escapeshellarg($wpCli),
                escapeshellarg($targetRoot),
                implode(' ', array_map('escapeshellarg', $toDisable))
            );
            shell_exec($deactCmd);
            logMsg('Deaktivovány pluginy: ' . implode(', ', $toDisable));
        }
    }

    // 6d. Odstranit Duplicator pozůstatky - installer.php je bezpečnostní riziko
    // a dup-installer/ obsahuje kompletní SQL dump databáze
    foreach (['installer.php', 'installer-backup.php', 'dup-installer'] as $leftover) {
        $leftoverPath = $targetRoot . '/' . $leftover;
        if (file_exists($leftoverPath)) {
            runCmd('rm -rf ' . escapeshellarg($leftoverPath));
            logMsg("Odstraněno: {$leftover}");
        }
    }
    // Duplicator pojmenovává installery i jako <datum>_<název>_<hash>_installer*.php
    foreach (array_merge(
        glob($targetRoot . '/*_installer.php') ?: [],
        glob($targetRoot . '/*_installer-backup.php') ?: [],
        glob($targetRoot . '/*_archive.zip') ?: []
    ) as $nestedArchive) {
        @unlink($nestedArchive);
        logMsg('Odstraněno: ' . basename($nestedArchive));
    }

    // 7. Regenerace SSL
    updateStatus($db, $id, 'regenerating_ssl');
    regenerateSslCert($db);
    logMsg("SSL certifikát regenerován");

    // 8. Vytvoření projektu v DB (sync to udělá automaticky, ale můžeme urychlit)
    $projectRepo = new ProjectRepository();
    $sync = new ProjectSyncService($projectRepo, PROJECTS_WATCH_DIR);
    $sync->sync();

    // 8b. Nastavit php_version pro nový projekt (default 8.5) a vygenerovat vhost
    $newProject = $projectRepo->findByFolderPath($projectName);
    if ($newProject && $newProject['php_version'] === null) {
        $projectRepo->update((int) $newProject['id'], ['php_version' => '8.5']);
        logMsg("Nastavena PHP 8.5 pro nový projekt {$projectName}");
    }

    // 8c. Vygenerovat Apache vhost pro nový projekt
    shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/generate-vhosts.php') . ' 2>&1');
    $test = shell_exec('apache2ctl configtest 2>&1');
    if (str_contains($test, 'Syntax OK')) {
        shell_exec('systemctl reload apache2 2>&1');
        logMsg("Apache vhost vygenerován a Apache reloaded");
    } else {
        logMsg("Varování: Apache configtest selhal po vygenerování vhostu: {$test}");
    }

    // 8d. Flush WordPress rewrite rules (obnoví permalink strukturu)
    $wpCli = '/usr/local/bin/wp';
    if (file_exists($wpCli)) {
        $flushOutput = shell_exec("sudo -u ratesman {$wpCli} --path=" . escapeshellarg($targetRoot) . " rewrite flush 2>&1");
        logMsg("WP rewrite rules flush: {$flushOutput}");
    }

    // 9. Completed
    updateStatus($db, $id, 'completed');
    logMsg("Restore zálohy pro '{$projectName}' (ID {$id}) dokončen.");

} catch (Throwable $e) {
    updateStatus($db, $id, 'failed', $e->getMessage());
    logMsg("CHYBA: " . $e->getMessage());
    exit(1);
}

exit(0);
