<?php
declare(strict_types=1);

/**
 * CLI worker pro zpracování přepínání PHP verzí.
 * Běží jako root přes cron, zpracovává pending jobs z php_version_jobs.
 *
 * Použití: php change-php-version.php --process-pending
 */

require_once __DIR__ . '/../bootstrap.php';

$pdo = db();

// Najít pending job
$stmt = $pdo->query('SELECT * FROM php_version_jobs WHERE status = "pending" ORDER BY id ASC LIMIT 1');
$job = $stmt->fetch();

if ($job === false) {
    echo "Žádné pending PHP version jobs.\n";
    exit(0);
}

$jobId = (int) $job['id'];
$projectId = (int) $job['project_id'];
$newVersion = $job['php_version'];
$oldVersion = $job['old_php_version'] ?: '8.5';

echo "=== Zpracovávám job #{$jobId}: projekt {$projectId}, PHP {$oldVersion} → {$newVersion} ===\n";

function updateJobStatus(PDO $pdo, int $jobId, string $status, ?string $error = null): void
{
    $stmt = $pdo->prepare('UPDATE php_version_jobs SET status = ?, error_message = ? WHERE id = ?');
    $stmt->execute([$status, $error, $jobId]);
}

try {
    // 1. Nastartovat FPM pro novou verzi
    updateJobStatus($pdo, $jobId, 'starting_fpm');
    $socket = "/run/php/php{$newVersion}-fpm.sock";
    if (!file_exists($socket)) {
        $cmd = sprintf('systemctl start php%s-fpm 2>&1', $newVersion);
        $output = shell_exec($cmd);
        if (!file_exists($socket)) {
            throw new RuntimeException("FPM {$newVersion} se nepodařilo nastartovat: {$output}");
        }
        echo "  FPM {$newVersion} nastartován\n";
    } else {
        echo "  FPM {$newVersion} už běží\n";
    }

    // 2. Uložit novou verzi do DB
    updateJobStatus($pdo, $jobId, 'regenerating');
    $stmt = $pdo->prepare('UPDATE projects SET php_version = ? WHERE id = ?');
    $stmt->execute([$newVersion, $projectId]);

    // 3. Regenerovat vhosty
    $output = shell_exec('/usr/bin/php /var/www/devapppro/cli/generate-vhosts.php 2>&1');
    echo "  Vhosty regenerovány\n";

    // 4. Test Apache konfigurace
    updateJobStatus($pdo, $jobId, 'reloading');
    $test = shell_exec('apache2ctl configtest 2>&1');
    if (!str_contains($test, 'Syntax OK')) {
        // Rollback
        $stmt = $pdo->prepare('UPDATE projects SET php_version = ? WHERE id = ?');
        $stmt->execute([$oldVersion, $projectId]);
        shell_exec('/usr/bin/php /var/www/devapppro/cli/generate-vhosts.php 2>&1');
        throw new RuntimeException("Apache configtest selhal: {$test}");
    }

    // 5. Reload Apache
    shell_exec('systemctl reload apache2 2>&1');
    echo "  Apache reloaded\n";

    // 6. Hotovo
    updateJobStatus($pdo, $jobId, 'completed');
    echo "  Job #{$jobId} dokončen: PHP {$newVersion}\n";

} catch (Throwable $e) {
    updateJobStatus($pdo, $jobId, 'failed', $e->getMessage());
    echo "  CHYBA: " . $e->getMessage() . "\n";
    exit(1);
}
