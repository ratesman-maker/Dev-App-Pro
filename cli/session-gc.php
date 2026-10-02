<?php
declare(strict_types=1);

/**
 * Cron skript: spustí PHP session garbage collection.
 * PHP konfigurace má gc_probability=0, takže GC se nespouští automaticky.
 * Tento skript spouští GC explicitně přes CLI.
 *
 * Použití v cronu:
 * 30 3 * * * ratesman /usr/bin/php /home/ratesman/projekty/devapppro/cli/session-gc.php >> /var/log/devapppro-cleanup.log 2>&1
 */

// Nastavit GC parametry pro tento běh
ini_set('session.gc_probability', '1');
ini_set('session.gc_divisor', '1'); // 100% pravděpodobnost pro explicitní cron
ini_set('session.gc_maxlifetime', '7200'); // 2 hodiny

// Spustit GC - session_start s gc_probability=1 spustí cleanup
$savePath = session_save_path() ?: sys_get_temp_dir();
if (!is_dir($savePath)) {
    echo date('Y-m-d H:i:s') . " - Session save path neexistuje: {$savePath}" . PHP_EOL;
    exit(0);
}

// Manuální cleanup starých session souborů
// Adresář /var/lib/php/sessions je drwx-wx-wt (write-only), nelze použít DirectoryIterator
// Použijeme glob() který funguje i bez read oprávnění
$count = 0;
$maxLifetime = 7200;
$now = time();

$pattern = rtrim($savePath, '/') . '/sess_*';
$files = glob($pattern);

if ($files !== false) {
    foreach ($files as $file) {
        if (is_file($file)) {
            $mtime = @filemtime($file);
            if ($mtime !== false && ($now - $mtime) > $maxLifetime) {
                if (@unlink($file)) {
                    $count++;
                }
            }
        }
    }
}

echo date('Y-m-d H:i:s') . " - Smazáno {$count} starých session souborů (starších než {$maxLifetime}s)." . PHP_EOL;
