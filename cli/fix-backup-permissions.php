<?php
declare(strict_types=1);

/**
 * Cron skript: nastaví bezpečná oprávnění (600) pro všechny zálohy v BACKUPS_DIR.
 * Spouští se denně přes cron.
 *
 * Použití v cronu:
 * 0 4 * * * ratesman /usr/bin/php /home/ratesman/projekty/devapppro/cli/fix-backup-permissions.php >> /var/log/devapppro-cleanup.log 2>&1
 */

require_once __DIR__ . '/../bootstrap.php';

$backupsDir = BACKUPS_DIR;

if (!is_dir($backupsDir)) {
    echo date('Y-m-d H:i:s') . " - Zálohový adresář neexistuje: {$backupsDir}" . PHP_EOL;
    exit(0);
}

$count = 0;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($backupsDir, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if ($file->isFile()) {
        $path = $file->getPathname();
        $currentPerms = fileperms($path) & 0777;
        if ($currentPerms !== 0600) {
            if (chmod($path, 0600)) {
                $count++;
            }
        }
    }
}

echo date('Y-m-d H:i:s') . " - Opraveno oprávnění pro {$count} zálohových souborů na 600." . PHP_EOL;
