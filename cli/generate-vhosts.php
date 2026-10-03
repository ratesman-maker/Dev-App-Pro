<?php
declare(strict_types=1);

/**
 * CLI skript pro generování per-project Apache vhostů.
 *
 * Použití: php generate-vhosts.php [--dry-run]
 *
 * Generuje vhost pro každý projekt v /etc/apache2/sites-available/devapppro-projects/
 * Každý vhost ukazuje na příslušný PHP-FPM socket podle php_version v DB.
 * Pokud projekt nemá php_version, použije se výchozí (8.5).
 *
 * Wildcard vhost zůstává jako fallback pro projekty bez vlastního vhostu.
 */

require_once __DIR__ . '/../bootstrap.php';

use DevAppPro\Repositories\ProjectRepository;

$dryRun = in_array('--dry-run', $argv ?? [], true);

$projectsDir = '/etc/apache2/sites-available/devapppro-projects';
$projectsConf = '/etc/apache2/sites-available/devapppro-projects.conf';

// Dostupné PHP verze a jejich FPM sockety
$phpVersions = [
    '7.4' => '/run/php/php7.4-fpm.sock',
    '8.0' => '/run/php/php8.0-fpm.sock',
    '8.1' => '/run/php/php8.1-fpm.sock',
    '8.2' => '/run/php/php8.2-fpm.sock',
    '8.3' => '/run/php/php8.3-fpm.sock',
    '8.4' => '/run/php/php8.4-fpm.sock',
    '8.5' => '/run/php/php8.5-fpm.sock',
];

$defaultPhp = '8.5';

echo "=== Generuji per-project vhosty ===\n";

if (!$dryRun) {
    if (!is_dir($projectsDir)) {
        mkdir($projectsDir, 0755, true);
    }
}

$repo = new ProjectRepository();
$pdo = db();

// Načíst projekty s php_version
$stmt = $pdo->query("SELECT id, name, folder_path, type, php_version FROM projects WHERE status = 'active' AND folder_path IS NOT NULL ORDER BY folder_path");
$projects = $stmt->fetchAll();

if (empty($projects)) {
    echo "Žádné aktivní projekty s folder_path.\n";
    // Přepsat hlavní konfig prázdným (odstranit staré Include odkazy)
    $mainConf = "# Per-project vhosty - auto-generováno Dev App Pro\n";
    $mainConf .= "# NEUPRAVOVAT RUČNĚ\n\n";
    if (!$dryRun) {
        file_put_contents($projectsConf, $mainConf);
        echo "Hlavní konfig vymazán: {$projectsConf}\n";
    }
    echo "\nHotovo.\n";
    exit(0);
}

$includes = [];

foreach ($projects as $project) {
    $folder = $project['folder_path'];
    $name = $project['name'];

    // Obrana: nevalidní hostname by rozbil celý Apache config pro všechny projekty
    if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $folder)) {
        echo "Přeskočeno: {$folder} - název složky není validní hostname\n";
        continue;
    }

    $phpVersion = $project['php_version'] ?: $defaultPhp;
    $docRoot = PROJECTS_WATCH_DIR . '/' . $folder;
    $serverName = $folder . '.localhost';

    if (!isset($phpVersions[$phpVersion])) {
        echo "Varování: Projekt {$folder} má neplatnou PHP verzi {$phpVersion}, používám {$defaultPhp}\n";
        $phpVersion = $defaultPhp;
    }

    $fpmSocket = $phpVersions[$phpVersion];
    $vhostFile = $projectsDir . '/' . $folder . '.conf';

    // Pro statické projekty dát index.html přednost, pro WordPress index.php
    $directoryIndex = ($project['type'] === 'wordpress')
        ? 'index.php index.html'
        : 'index.html index.php';

    // FPM direktivy jen pro PHP-projekty (wordpress/php) - statické weby PHP nepotřebují
    $phpBlock = '';
    if ($project['type'] !== 'static') {
        $phpBlock = <<<FPM

    <FilesMatch \.php$>
        SetHandler "proxy:unix:{$fpmSocket}|fcgi://localhost"
    </FilesMatch>
    ProxyPassMatch "^/(.*\.php(/.*)?)$" "unix:{$fpmSocket}|fcgi://localhost{$docRoot}/\$1"
FPM;
    }

    $phpInfo = $project['type'] === 'static'
        ? '# Staticky web (bez PHP-FPM)'
        : "# PHP: {$phpVersion} ({$fpmSocket})";

    $vhost = <<<VHOST
# Auto-generováno Dev App Pro - projekt: {$name}
{$phpInfo}
# NEUPRAVOVAT RUČNĚ - změny se přepíší

<VirtualHost *:80>
    ServerName {$serverName}
    DocumentRoot {$docRoot}

    <Directory {$docRoot}>
        AllowOverride All
        Require all granted
        Options Indexes FollowSymLinks
        DirectoryIndex {$directoryIndex}
    </Directory>

{$phpBlock}

</VirtualHost>

<VirtualHost *:443>
    ServerName {$serverName}
    DocumentRoot {$docRoot}

    SSLEngine on
    SSLCertificateFile /etc/apache2/ssl/localhost.crt
    SSLCertificateKeyFile /etc/apache2/ssl/localhost.key

    <Directory {$docRoot}>
        AllowOverride All
        Require all granted
        Options Indexes FollowSymLinks
        DirectoryIndex {$directoryIndex}
    </Directory>

{$phpBlock}

</VirtualHost>
VHOST;

    if ($dryRun) {
        echo "--- {$serverName} (PHP {$phpVersion}) ---\n";
        echo $vhost . "\n\n";
    } else {
        file_put_contents($vhostFile, $vhost);

        // PHP limity přes .user.ini (FPM nepodporuje php_admin_value ve vhostu)
        // Auto-generováno - ruční změny se při regeneraci přepíší
        $userIni = "upload_max_filesize = 128M\npost_max_size = 128M\nmemory_limit = 512M\nmax_execution_time = 300\n";
        file_put_contents($docRoot . '/.user.ini', $userIni);
        echo "Vygenerováno: {$serverName} (PHP {$phpVersion})\n";
    }

    $includes[] = "Include \"{$projectsDir}/{$folder}.conf\"";
}

// Smazat zastaralé .conf soubory projektů, které se už negenerují
// (archivované/smazané projekty, projekty bez folder_path)
$expectedFiles = array_map(
    fn($p) => $p['folder_path'] . '.conf',
    $projects
);
foreach (glob($projectsDir . '/*.conf') ?: [] as $existingFile) {
    if (!in_array(basename($existingFile), $expectedFiles, true)) {
        if ($dryRun) {
            echo "Smazal bych zastaralý vhost: " . basename($existingFile) . "\n";
        } else {
            unlink($existingFile);
            echo "Smazán zastaralý vhost: " . basename($existingFile) . "\n";
        }
    }
}

// Hlavní konfigurační soubor
$mainConf = "# Per-project vhosty - auto-generováno Dev App Pro\n";
$mainConf .= "# NEUPRAVOVAT RUČNĚ\n\n";
foreach ($includes as $inc) {
    $mainConf .= $inc . "\n";
}

if (!$dryRun) {
    file_put_contents($projectsConf, $mainConf);
    echo "\nHlavní konfig: {$projectsConf}\n";
    echo "Počet vhostů: " . count($includes) . "\n";
} else {
    echo "\n--- Hlavní konfig ({$projectsConf}) ---\n";
    echo $mainConf . "\n";
}

echo "\nHotovo.\n";
