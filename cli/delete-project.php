<?php
declare(strict_types=1);

/**
 * CLI worker pro mazání projektů.
 * Běží jako root přes systemd timer, zpracovává pending jobs z project_delete_jobs.
 * Maže: soubory, DB, DB uživatele, Apache vhost, SSL certifikát.
 *
 * Použití: php delete-project.php --process-pending
 */

require_once __DIR__ . '/../bootstrap.php';

$pdo = db();

$stmt = $pdo->query('SELECT * FROM project_delete_jobs WHERE status = "pending" ORDER BY id ASC LIMIT 1');
$job = $stmt->fetch();

if ($job === false) {
    echo "Žádné pending delete jobs.\n";
    exit(0);
}

$jobId = (int) $job['id'];
$folderPath = $job['folder_path'];
$siteUrl = $job['site_url'];
$dbName = $job['db_name'];
$dbUser = $job['db_user'];
$projectName = $job['project_name'];

echo "=== Mazání projektu: {$projectName} (job #{$jobId}) ===\n";

function updateJobStatus(PDO $pdo, int $jobId, string $status, ?string $error = null): void
{
    $stmt = $pdo->prepare('UPDATE project_delete_jobs SET status = ?, error_message = ? WHERE id = ?');
    $stmt->execute([$status, $error, $jobId]);
}

try {
    // 1. Smazat soubory na disku
    if ($folderPath) {
        updateJobStatus($pdo, $jobId, 'deleting_files');
        $docRoot = PROJECTS_WATCH_DIR . '/' . $folderPath;
        if (is_dir($docRoot)) {
            $output = shell_exec('rm -rf ' . escapeshellarg($docRoot) . ' 2>&1');
            if (is_dir($docRoot)) {
                throw new RuntimeException("Nepodařilo se smazat složku {$docRoot}: {$output}");
            }
            echo "  Soubory smazány: {$docRoot}\n";
        } else {
            echo "  Složka neexistuje: {$docRoot}\n";
        }
    }

    // 2. Smazat databázi a uživatele
    if ($dbName) {
        updateJobStatus($pdo, $jobId, 'dropping_db');
        // Escapovat - povolit jen alfanumerické a podtržítko
        $safeDbName = preg_replace('/[^a-zA-Z0-9_]/', '', $dbName);
        $safeDbUser = $dbUser ? preg_replace('/[^a-zA-Z0-9_]/', '', $dbUser) : null;

        try {
            // Root přístup - načíst heslo z /root/.my.cnf
            $myCnf = parse_ini_file('/root/.my.cnf', true);
            $rootPass = $myCnf['client']['password'] ?? '';
            $rootPdo = new PDO('mysql:host=localhost', 'root', $rootPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $rootPdo->exec("DROP DATABASE IF EXISTS `{$safeDbName}`");
            echo "  Databáze smazána: {$safeDbName}\n";

            if ($safeDbUser) {
                // Restore vytváří usera pro localhost i 127.0.0.1 - smazat oba
                $rootPdo->exec("DROP USER IF EXISTS '{$safeDbUser}'@'localhost'");
                $rootPdo->exec("DROP USER IF EXISTS '{$safeDbUser}'@'127.0.0.1'");
                echo "  DB uživatel smazán: {$safeDbUser} (@localhost + @127.0.0.1)\n";
            }
            $rootPdo->exec("FLUSH PRIVILEGES");
        } catch (PDOException $e) {
            echo "  Varování DB: " . $e->getMessage() . "\n";
        }
    }

    // 3. Smazat Apache vhost
    if ($folderPath) {
        updateJobStatus($pdo, $jobId, 'removing_vhost');
        $vhostFile = '/etc/apache2/sites-available/devapppro-projects/' . $folderPath . '.conf';
        if (file_exists($vhostFile)) {
            unlink($vhostFile);
            echo "  Vhost smazán: {$vhostFile}\n";
        }

        // Regenerovat hlavní konfig
        $genOutput = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/generate-vhosts.php') . ' 2>&1');
        if ($genOutput && trim($genOutput) !== '') {
            echo "  generate-vhosts: " . trim($genOutput) . "\n";
        }

        // Test a reload
        $test = shell_exec('apache2ctl configtest 2>&1');
        if (str_contains($test, 'Syntax OK')) {
            shell_exec('systemctl reload apache2 2>&1');
            echo "  Apache reloaded\n";
        } else {
            echo "  Varování: Apache configtest: {$test}\n";
        }
    }

    // 4. Smazat SSL certifikát pro tento projekt (pokud existuje vlastní)
    if ($siteUrl) {
        updateJobStatus($pdo, $jobId, 'removing_ssl');
        $sslCrt = '/etc/apache2/ssl/' . $siteUrl . '.crt';
        $sslKey = '/etc/apache2/ssl/' . $siteUrl . '.key';
        if (file_exists($sslCrt)) {
            unlink($sslCrt);
            echo "  SSL cert smazán: {$sslCrt}\n";
        }
        if (file_exists($sslKey)) {
            unlink($sslKey);
            echo "  SSL key smazán: {$sslKey}\n";
        }
    }

    // 5. Hotovo
    updateJobStatus($pdo, $jobId, 'completed');
    echo "  Job #{$jobId} dokončen.\n";

} catch (Throwable $e) {
    updateJobStatus($pdo, $jobId, 'failed', $e->getMessage());
    echo "  CHYBA: " . $e->getMessage() . "\n";
    exit(1);
}
