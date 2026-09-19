<?php
/**
 * Dev App Pro - DB záloha
 *
 * Spouští se přes cron: denně v 2:00
 * Vytvoří SQL dump databáze devapppro do /var/backups/devapppro/
 * Ponechá zálohy za posledních 30 dní, starší smaže.
 *
 * Použití: php cli/backup-db.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
$dbConfig = require __DIR__ . '/../config/database.php';

$backupDir = BACKUP_DIR;
$date = date('Y-m-d_H-i-s');
$backupFile = $backupDir . '/db-' . $date . '.sql.gz';

// Vytvořit adresář pokud neexistuje
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0750, true);
}

// Sestavit mysqldump příkaz
$host = $dbConfig['host'];
$port = $dbConfig['port'];
$dbname = $dbConfig['dbname'];
$user = $dbConfig['username'];
$pass = $dbConfig['password'];

// Escapovat heslo pro shell (použít --password= s uvozovkami)
$cmd = sprintf(
    'mysqldump --host=%s --port=%d --user=%s --password=%s --single-transaction --routines --triggers --events %s 2>/dev/null | gzip > %s',
    escapeshellarg($host),
    (int) $port,
    escapeshellarg($user),
    escapeshellarg($pass),
    escapeshellarg($dbname),
    escapeshellarg($backupFile)
);

// Spustit zálohu
exec($cmd, $output, $exitCode);

if ($exitCode !== 0 || !file_exists($backupFile) || filesize($backupFile) === 0) {
    error_log("[devapppro backup-db] Záloha selhala (exit=$exitCode): $backupFile");
    echo "Záloha selhala.\n";
    exit(1);
}

// Nastavit oprávnění 600 (jen vlastník může číst)
chmod($backupFile, 0600);

$size = filesize($backupFile);
$sizeMB = round($size / 1024 / 1024, 2);
echo "Záloha vytvořena: $backupFile ($sizeMB MB)\n";

// Smazat zálohy starší než BACKUP_RETENTION_DAYS dní
$retentionDays = defined('BACKUP_RETENTION_DAYS') ? BACKUP_RETENTION_DAYS : 30;
$deleted = 0;
$cutoff = time() - ($retentionDays * 86400);

foreach (glob($backupDir . '/db-*.sql.gz') as $oldFile) {
    if (filemtime($oldFile) < $cutoff) {
        unlink($oldFile);
        $deleted++;
    }
}

if ($deleted > 0) {
    echo "Smazáno $deleted starých záloh (starších než $retentionDays dní).\n";
}

// Logovat
error_log("[devapppro backup-db] Záloha OK: $backupFile ($sizeMB MB), smazáno $deleted starých");
exit(0);
