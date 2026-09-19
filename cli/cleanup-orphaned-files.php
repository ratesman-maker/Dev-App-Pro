<?php
declare(strict_types=1);

/**
 * Cron skript: najde a smaže osiřelé soubory v storage/ (soubory na disku, které nejsou v DB).
 * Spouští se týdně přes cron.
 *
 * Použití v cronu:
 * 0 5 * * 0 ratesman /usr/bin/php /var/www/devapppro/cli/cleanup-orphaned-files.php >> /var/log/devapppro-cleanup.log 2>&1
 */

require_once __DIR__ . '/../bootstrap.php';

use DevAppPro\Repositories\FileRepository;

$storageDir = __DIR__ . '/../storage';

if (!is_dir($storageDir)) {
    echo date('Y-m-d H:i:s') . " - Storage adresář neexistuje." . PHP_EOL;
    exit(0);
}

// Načíst všechny storage_path hodnoty z DB
$repo = new FileRepository();
$pdo = db();

// Získat všechny platné cesty z DB (storage_path, thumbnail_path, medium_path)
$stmt = $pdo->query("SELECT `storage_path`, `thumbnail_path`, `medium_path` FROM `files` WHERE `storage_path` IS NOT NULL");
$dbPaths = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (!empty($row['storage_path'])) $dbPaths[] = $row['storage_path'];
    if (!empty($row['thumbnail_path'])) $dbPaths[] = $row['thumbnail_path'];
    if (!empty($row['medium_path'])) $dbPaths[] = $row['medium_path'];
}
$dbPathsSet = array_flip($dbPaths);

// Projít storage adresář
$deleted = 0;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($storageDir, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (!$file->isFile()) continue;

    // Ignorovat .htaccess soubory
    if ($file->getFilename() === '.htaccess') continue;

    $absPath = $file->getPathname();
    $relPath = substr($absPath, strlen($storageDir) + 1);

    // Pokud cesta není v DB, je osiřelá
    if (!isset($dbPathsSet[$relPath])) {
        // Bezpečnost: realpath kontrola
        $realStorage = realpath($storageDir);
        $realPath = realpath($absPath);
        if ($realPath === false || strpos($realPath, $realStorage) !== 0) {
            continue;
        }

        if (@unlink($realPath)) {
            $deleted++;
        }
    }
}

echo date('Y-m-d H:i:s') . " - Smazáno {$deleted} osiřelých souborů ze storage/." . PHP_EOL;
