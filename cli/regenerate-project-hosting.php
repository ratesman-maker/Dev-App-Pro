<?php
declare(strict_types=1);

/**
 * CLI worker pro automatickou regeneraci SSL certifikátu a vhostů.
 * Běží jako root přes systemd timer, zpracovává pending jobs z project_hosting_jobs.
 *
 * Použití: php regenerate-project-hosting.php --process-pending
 *
 * Kroky:
 *   1. regenerating_ssl  — mkcert s všechny doménami projektů
 *   2. generating_vhosts — generate-vhosts.php
 *   3. reloading         — systemctl reload apache2
 */

require_once __DIR__ . '/../bootstrap.php';

$pdo = db();

// Najít pending job
$stmt = $pdo->query('SELECT * FROM project_hosting_jobs WHERE status = "pending" ORDER BY id ASC LIMIT 1');
$job = $stmt->fetch();

if ($job === false) {
    echo "Žádné pending hosting jobs.\n";
    exit(0);
}

$jobId = (int) $job['id'];
$projectId = (int) $job['project_id'];
$action = $job['action'];
$folderPath = $job['folder_path'];

echo "=== Zpracovávám job #{$jobId}: projekt {$projectId}, akce {$action}, složka {$folderPath} ===\n";

function updateJobStatus(PDO $pdo, int $jobId, string $status, ?string $error = null): void
{
    $stmt = $pdo->prepare('UPDATE project_hosting_jobs SET status = ?, error_message = ? WHERE id = ?');
    $stmt->execute([$status, $error, $jobId]);
}

try {
    // 1. Regenerovat SSL certifikát
    updateJobStatus($pdo, $jobId, 'regenerating_ssl');
    echo "  Regeneruji SSL certifikát...\n";

    // Základní domény
    $sanDomains = ['localhost', '*.localhost', '127.0.0.1'];

    // Přidat domény všech aktivních projektů s folder_path
    $stmt = $pdo->query("SELECT folder_path FROM projects WHERE status = 'active' AND folder_path IS NOT NULL AND folder_path != ''");
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $fp) {
        $domain = $fp . '.localhost';
        if (!in_array($domain, $sanDomains, true)) {
            $sanDomains[] = $domain;
        }
    }

    // Najít mkcert
    $mkcert = trim(shell_exec('which mkcert 2>/dev/null') ?? '');
    if ($mkcert === '') {
        throw new RuntimeException('mkcert nenalezen v PATH');
    }

    $caRoot = '/home/ratesman/.local/share/mkcert';
    $tmpCert = '/tmp/devapppro-ssl-cert.pem';
    $tmpKey = '/tmp/devapppro-ssl-key.pem';

    $args = array_map('escapeshellarg', $sanDomains);
    $cmd = sprintf(
        'CAROOT=%s %s -cert-file %s -key-file %s %s 2>&1',
        escapeshellarg($caRoot),
        escapeshellarg($mkcert),
        escapeshellarg($tmpCert),
        escapeshellarg($tmpKey),
        implode(' ', $args)
    );
    $output = shell_exec($cmd);
    if (!file_exists($tmpCert) || !file_exists($tmpKey)) {
        throw new RuntimeException('mkcert selhal: ' . $output);
    }
    echo "  Certifikát vygenerován (" . count($sanDomains) . " domén)\n";

    // Spojit cert + CA chain
    $caPem = $caRoot . '/rootCA.pem';
    $certContent = file_get_contents($tmpCert);
    if (file_exists($caPem)) {
        $certContent .= "\n" . file_get_contents($caPem);
    }
    file_put_contents('/etc/apache2/ssl/localhost.crt', $certContent);
    copy($tmpKey, '/etc/apache2/ssl/localhost.key');
    chmod('/etc/apache2/ssl/localhost.key', 0600);
    @unlink($tmpCert);
    @unlink($tmpKey);
    echo "  Certifikát a klíč nainstalovány\n";

    // 2. Generovat vhosty
    updateJobStatus($pdo, $jobId, 'generating_vhosts');
    echo "  Generuji vhosty...\n";

    $vhostScript = __DIR__ . '/generate-vhosts.php';
    $output = shell_exec('php ' . escapeshellarg($vhostScript) . ' 2>&1');
    echo "  Vhosty vygenerovány\n";

    // 3. Reload Apache
    updateJobStatus($pdo, $jobId, 'reloading');
    echo "  Reloaduji Apache...\n";

    $output = shell_exec('apache2ctl configtest 2>&1');
    if (strpos($output, 'Syntax OK') === false) {
        throw new RuntimeException('Apache config test selhal: ' . $output);
    }

    $output = shell_exec('systemctl reload apache2 2>&1');
    echo "  Apache reloadován\n";

    // Hotovo
    updateJobStatus($pdo, $jobId, 'completed');
    echo "=== Job #{$jobId} dokončen ===\n";

} catch (Throwable $e) {
    updateJobStatus($pdo, $jobId, 'failed', $e->getMessage());
    echo "=== Job #{$jobId} SELHAL: " . $e->getMessage() . " ===\n";
    exit(1);
}
