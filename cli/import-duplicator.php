<?php
declare(strict_types=1);

/**
 * CLI wrapper pro import WordPress webu z Duplicator archivu (.zip / .daf).
 *
 * Veškerou práci dělá cli/restore-backup.php (cron worker) - tento skript
 * pouze ověří archiv a zařadí restore job do fronty backup_restores.
 * Stejný pipeline jako tlačítko "Vytvořit projekt" na /projects/backups.
 *
 * Použití:
 *   php import-duplicator.php <archive.zip|daf> <slug> [--wait]
 *
 *     <slug>   → složka PROJECTS_WATCH_DIR/<slug>,
 *                doména https://<slug>.localhost, DB wp_<slug_s_podtržítky>
 *     --wait   → čekej na dokončení jobu a vypiš průběh statusů
 *
 * Příklad:
 *   php cli/import-duplicator.php \
 *     PROJECTS_WATCH_DIR/Zalohy/20260911_obereggenannacom_..._archive.zip \
 *     obereggen-anna --wait
 */

require_once __DIR__ . '/../config/config.php';
$dbCfg = require __DIR__ . '/../config/database.php';

// --- Argumenty (ruční parsování - getopt končí na prvním pozičním arg) ---
$pos = [];
$wait = false;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--wait') {
        $wait = true;
    } elseif (!str_starts_with($a, '-')) {
        $pos[] = $a;
    }
}

if (count($pos) < 2) {
    echo "Použití: php import-duplicator.php <archive.zip|daf> <slug> [--wait]\n";
    exit(1);
}
[$archivePath, $slug] = $pos;

// --- Validace ---
if (!preg_match('/^[a-z0-9][a-z0-9-]{1,48}[a-z0-9]$/', $slug)) {
    fwrite(STDERR, "Neplatný slug '{$slug}' - 3-50 znaků: a-z, 0-9, pomlčka.\n");
    exit(1);
}
if (!is_file($archivePath)) {
    fwrite(STDERR, "Archiv nenalezen: {$archivePath}\n");
    exit(1);
}
$ext = strtolower(pathinfo($archivePath, PATHINFO_EXTENSION));
if (!in_array($ext, ['zip', 'daf'], true)) {
    fwrite(STDERR, "Nepodporovaný formát '.{$ext}' - jen .zip nebo .daf.\n");
    exit(1);
}

$targetRoot = rtrim(PROJECTS_WATCH_DIR, '/') . '/' . $slug;
if (is_dir($targetRoot)) {
    fwrite(STDERR, "Složka '{$slug}' již existuje: {$targetRoot}\n");
    exit(1);
}

// --- Ověření, že jde o Duplicator archiv ---
if ($ext === 'zip') {
    $listing = shell_exec('unzip -l ' . escapeshellarg($archivePath) . ' 2>/dev/null');
    if (!str_contains((string) $listing, 'dup-installer/')
        && !str_contains((string) $listing, 'installer.php')
        && !preg_match('/dup_descriptors_.*db_dumps.*\.sql/', (string) $listing)) {
        echo "VAROVÁNÍ: archiv nevypadá jako Duplicator balíček, pokračuji...\n";
    }
}

// --- Vytvoření restore jobu ---
$pdo = new PDO(
    "mysql:host={$dbCfg['host']};port={$dbCfg['port']};dbname={$dbCfg['dbname']};charset={$dbCfg['charset']}",
    $dbCfg['username'],
    $dbCfg['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$dbName = 'wp_' . str_replace('-', '_', $slug);
$dbPassword = bin2hex(random_bytes(12)); // 24 hex znaků

$stmt = $pdo->prepare(
    'INSERT INTO backup_restores
     (backup_path, project_name, site_url, document_root, db_name, db_user, db_password, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, "pending")'
);
$stmt->execute([
    $archivePath,
    $slug,
    $slug . '.localhost',
    $targetRoot,
    $dbName,
    $dbName,
    $dbPassword,
]);
$jobId = (int) $pdo->lastInsertId();

echo "Restore job #{$jobId} zařazen pro '{$slug}'.\n";
echo "Worker (cron, každou minutu) provede: extrakci → DB → wp-config → URL replace → SSL + vhost.\n";

if (!str_starts_with(realpath($archivePath) ?: $archivePath, rtrim(BACKUPS_DIR, '/') . '/')) {
    echo "Pozn.: archiv je mimo " . BACKUPS_DIR . " - v seznamu záloh v UI se nezobrazí.\n";
}

// --- --wait: sledovat stav jobu ---
if ($wait) {
    $lastStatus = '';
    $deadline = time() + 900; // max 15 min
    while (time() < $deadline) {
        $row = $pdo->query("SELECT status, error_message FROM backup_restores WHERE id = {$jobId}")
            ->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo "Job #{$jobId} zmizel z DB.\n";
            exit(1);
        }
        if ($row['status'] !== $lastStatus) {
            echo '[' . date('H:i:s') . "] {$row['status']}\n";
            $lastStatus = $row['status'];
        }
        if ($row['status'] === 'completed') {
            echo "\nHotovo: https://{$slug}.localhost/  (admin: /wp-admin/)\n";
            exit(0);
        }
        if ($row['status'] === 'failed') {
            echo "\nSELHALO: {$row['error_message']}\n";
            exit(1);
        }
        sleep(3);
    }
    echo "Timeout - job stále běží, sleduj /var/log/devapppro-restore.log\n";
    exit(2);
}
