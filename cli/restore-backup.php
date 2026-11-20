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
require_once __DIR__ . '/restore/helpers.php';
require_once __DIR__ . '/restore/sql.php';
require_once __DIR__ . '/restore/search-replace.php';
require_once __DIR__ . '/restore/paths.php';
require_once __DIR__ . '/restore/ssl.php';

use DevAppPro\Repositories\BackupRestoreRepository;
use DevAppPro\Repositories\ProjectRepository;
use DevAppPro\Services\AclService;
use DevAppPro\Services\ProjectSyncService;
use Duplicator\Libs\DupArchive\DupArchiveExpandBasicEngine;

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
    $newPath = $targetRoot; // PROJECTS_WATCH_DIR/{name}
    if ($oldUrl) {
        // Detekovat starou cestu: options → Duplicator descriptor → frekvenční scan
        $oldPath = findOldPath($sitePdo, $tablePrefix, $targetRoot);
        if ($oldPath) {
            logMsg("Detekována stará cesta: {$oldPath} → {$newPath}");
        } else {
            logMsg("WARN: Stará FS cesta nedetekována (options, descriptor ani scan) — případné zbytky odchytí post-replace sweep");
        }
        replaceUrlsInDb($dbNameEsc, $dbUserEsc, $dbPassword, $oldUrl, $newUrl, $oldPath, $newPath);
    } else {
        logMsg("Stará URL nenalezena, přeskakuji search/replace");
    }

    // 6b. Cache fix-upy — Kadence/WPTT drží mapu font→FS cesta v option
    // downloaded_font_files a generuje <hash>.css (hash z base_url+base_path
    // → staré CSS jsou po restore stejně mrtvé). Obojí je čistá cache,
    // zregeneruje se na nové cestě sama. Stejně dirsize transient — drží
    // stovky starých FS cest. Smazáním je samooprava i bez dokonalé detekce.
    // Běží PŘED sweepem — tyto options by jinak sweep hlásil jako leftover.
    try {
        $sitePdo->exec(
            "DELETE FROM `{$optionsTable}` WHERE option_name IN "
            . "('downloaded_font_files','_transient_dirsize_cache','_transient_timeout_dirsize_cache')"
        );
    } catch (\Throwable $e) {
        logMsg('WARN: úklid cache options selhal: ' . $e->getMessage());
    }
    $fontsDir = $targetRoot . '/wp-content/fonts';
    if (is_dir($fontsDir)) {
        $cssFiles = glob($fontsDir . '/*.css') ?: [];
        foreach ($cssFiles as $cssFile) {
            try {
                unlink($cssFile);
            } catch (\Throwable $e) {
                logMsg('WARN: nelze smazat ' . basename($cssFile) . ': ' . $e->getMessage());
            }
        }
        if ($cssFiles) {
            logMsg('Smazáno ' . count($cssFiles) . ' starých font CSS (zregenerují se na nové cestě)');
        }
    }

    // 6c1. Post-replace sweep — canary na zbylé FS cesty. Detekce mohla selhat
    // tiše (prázdné options, non-Duplicator archiv) → cesty v options/postmeta
    // by přežily a rozbily např. lokální fonty. Zbylé rooty přidat do replace.
    $leftoverRoots = findLeftoverFsRoots($sitePdo, $tablePrefix, $newPath);
    if ($leftoverRoots) {
        logMsg('WARN: Po replace zůstaly staré FS cesty: ' . implode(', ', $leftoverRoots) . ' — doplňuji náhradu');
        foreach ($leftoverRoots as $leftover) {
            replaceUrlsInDb($dbNameEsc, $dbUserEsc, $dbPassword, $newUrl, $newUrl, $leftover, $newPath);
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
            'sudo -u ratesman %s --path=%s plugin list --status=active --field=name 2>&1',
            escapeshellarg($wpCli),
            escapeshellarg($targetRoot)
        );
        $listResult = runCmd($listCmd);
        if ($listResult['code'] !== 0) {
            logMsg('VAROVÁNÍ: wp-cli plugin list selhal (' . trim($listResult['output']) . ') - deaktivace problematických pluginů přeskočena');
        }
        $activePlugins = array_filter(array_map('trim', explode("\n", (string) $listResult['output'])));
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
    $genResult = runCmd(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/generate-vhosts.php') . ' 2>&1');
    $vhostFile = '/etc/apache2/sites-available/devapppro-projects/' . $projectName . '.conf';
    if ($genResult['code'] !== 0 || !file_exists($vhostFile)) {
        logMsg("VAROVÁNÍ: vhost pro {$projectName} se nepodařilo vygenerovat: " . trim($genResult['output']));
    }
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

    // 8e. Post-restore health check - ověří, že web skutečně odpovídá
    $healthCode = trim((string) shell_exec(
        'curl -s -o /dev/null -w %{http_code} --max-time 15 ' . escapeshellarg("https://{$projectName}.localhost/")
    ));
    if (in_array($healthCode, ['200', '301', '302'], true)) {
        logMsg("Health check OK: https://{$projectName}.localhost/ vrací HTTP {$healthCode}");
    } else {
        logMsg("VAROVÁNÍ: health check selhal - https://{$projectName}.localhost/ vrací HTTP '{$healthCode}'");
    }

    // 8f. Normalizace ACL - soubory vytvořené root workerem s umask 022
    // (mkdir 0755, chmod 0644) mají oříznutou ACL masku na r-x/r-- → www-data
    // a ratesman ztratí zápis a WP updaty selhávají. Selhání jen logovat.
    $acl = AclService::normalizeProjectTree($targetRoot);
    if ($acl['ok']) {
        logMsg("ACL normalizováno (www-data, ratesman rwX)");
    } else {
        logMsg("VAROVÁNÍ: ACL normalizace selhala: {$acl['message']}");
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
