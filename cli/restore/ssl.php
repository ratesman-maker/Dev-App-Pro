<?php
declare(strict_types=1);

/**
 * Regenerace SSL certifikátu pro localhost a projektové subdomény.
 */

/**
 * Regenerace SSL certifikátu se všemi subdoménami.
 * Musí běžet jako uživatel ratesman (jeho mkcert CA je v trust store).
 */
function regenerateSslCert(\PDO $db): void
{
    $hosts = ['localhost'];
    $stmt = $db->query("SELECT folder_path FROM projects WHERE folder_path IS NOT NULL AND status = 'active'");
    while ($row = $stmt->fetch()) {
        $hosts[] = $row['folder_path'] . '.localhost';
    }
    // Přidat i restore joby
    $stmt2 = $db->query("SELECT project_name FROM backup_restores WHERE status IN ('completed','importing_sql','configuring','replacing_urls','regenerating_ssl')");
    while ($row = $stmt2->fetch()) {
        $hosts[] = $row['project_name'] . '.localhost';
    }

    $hosts = array_unique($hosts);
    $sanArgs = '';
    foreach ($hosts as $h) {
        $sanArgs .= ' ' . escapeshellarg($h);
    }

    // Spustit mkcert jako uživatel ratesman (jeho CA je v trust store)
    // Generovat do tmp (ratesman nemá právo do /etc/apache2/ssl/)
    $caRoot = '/home/ratesman/.local/share/mkcert';
    $tmpCert = '/tmp/devapppro-ssl-cert.pem';
    $tmpKey = '/tmp/devapppro-ssl-key.pem';
    $cmd = "sudo -u ratesman CAROOT={$caRoot} mkcert -cert-file {$tmpCert} -key-file {$tmpKey} {$sanArgs} 2>&1";
    $result = runCmd($cmd);
    if ($result['code'] !== 0) {
        logMsg("Varování: mkcert: {$result['output']}");
    } else {
        // Přesunout do /etc/apache2/ssl/
        copy($tmpCert, '/etc/apache2/ssl/localhost.crt');
        copy($tmpKey, '/etc/apache2/ssl/localhost.key');
        chmod('/etc/apache2/ssl/localhost.key', 0600);
        @unlink($tmpCert);
        @unlink($tmpKey);
    }
    runCmd('systemctl reload apache2 2>&1');
}
