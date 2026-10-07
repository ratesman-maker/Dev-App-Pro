<?php
declare(strict_types=1);

/**
 * Denní normalizace ACL projektových stromů (cron).
 *
 * Proč: default ACL na ~/projekty uděluje zápis www-data/ratesman, ale maska
 * ACL se u nových objektů počítá z módu vytvářejícího procesu (mkdir 0755 /
 * chmod 0644 s umask 022) → maska se ořízne na r-x/r-- a pojmenovaní
 * uživatelé ztratí zápis. Děje se opakovaně: root restore/install workery,
 * ale i www-data při WP updatech (nové plugin adresáře s mkdir 0755).
 *
 * Skript normalizuje všechny projekty s folder_path — masky se samy domažou.
 *
 * Spouštění: php normalize-project-acls.php (cron 1× denně, root)
 */

require_once __DIR__ . '/../bootstrap.php';

use DevAppPro\Repositories\ProjectRepository;
use DevAppPro\Services\AclService;

function logMsg(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

$repo = new ProjectRepository();
$projects = $repo->allWithFolderPath();

$ok = 0;
$fail = 0;
foreach ($projects as $project) {
    $path = rtrim(PROJECTS_WATCH_DIR, '/') . '/' . $project['folder_path'];
    if (!is_dir($path)) {
        continue;
    }
    $result = AclService::normalizeProjectTree($path);
    if ($result['ok']) {
        $ok++;
    } else {
        $fail++;
        logMsg("VAROVÁNÍ [{$project['folder_path']}]: {$result['message']}");
    }
}

logMsg("ACL normalizace dokončena: {$ok} OK, {$fail} selhalo");
exit($fail > 0 ? 1 : 0);
