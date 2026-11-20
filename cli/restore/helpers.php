<?php
declare(strict_types=1);

/**
 * Pomocné funkce restore workeru: logování, stav jobu, shell, přístup k root DB.
 */

const RESTORE_LOG = '/var/log/devapppro-restore.log';

function logMsg(string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    echo $line;
    // Worker běží pod cronem (root) — stdout se zahazuje, proto persistovat
    // do souboru. Selhání zápisu nesmí shodit restore.
    try {
        file_put_contents(RESTORE_LOG, $line, FILE_APPEND | LOCK_EX);
    } catch (\Throwable) {
        // bez logu dál
    }
}

function updateStatus(\PDO $db, int $id, string $status, ?string $error = null): void
{
    $stmt = $db->prepare('UPDATE backup_restores SET status = ?, error_message = ? WHERE id = ?');
    $errMsg = $error !== null ? substr($error, 0, 65000) : null;
    $stmt->execute([$status, $errMsg, $id]);
}

function runCmd(string $cmd): array
{
    $output = [];
    $code = 0;
    exec($cmd, $output, $code);
    return ['code' => $code, 'output' => implode("\n", $output)];
}

function getRootCredentials(): array
{
    $myCnf = '/root/.my.cnf';
    if (!file_exists($myCnf)) {
        throw new RuntimeException('/root/.my.cnf neexistuje');
    }
    $content = file_get_contents($myCnf);
    $user = 'root';
    $pass = '';
    if (preg_match('/user\s*=\s*(.+)/', $content, $m)) {
        $user = trim($m[1]);
    }
    if (preg_match('/password\s*=\s*(.+)/', $content, $m)) {
        $pass = trim($m[1]);
    }
    return ['user' => $user, 'password' => $pass];
}
