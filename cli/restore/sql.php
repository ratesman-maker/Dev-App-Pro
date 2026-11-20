<?php
declare(strict_types=1);

/**
 * Hledání SQL dumpu v rozbalené Duplicator záloze a zjištění staré URL.
 */

/**
 * Složí rozdělené Duplicator dumpy (db_dumps/*.sql.gz) do jednoho SQL souboru.
 * Nejdřív schema.sql.gz (CREATE TABLE), pak data po tabulkách.
 * Výstup leží v dup-installer/, který se po importu stejně maže (krok 6d).
 */
function combineGzDumps(string $root): ?string
{
    $parts = glob($root . '/dup-installer/dup_descriptors_*/db_dumps/*.sql.gz') ?: [];
    if ($parts === []) {
        return null;
    }
    usort($parts, static function (string $a, string $b): int {
        $keyA = [!str_contains(basename($a), 'schema.'), $a];
        $keyB = [!str_contains(basename($b), 'schema.'), $b];
        return $keyA <=> $keyB;
    });

    $out = $root . '/dup-installer/combined-dump.sql';
    $fh = fopen($out, 'wb');
    if ($fh === false) {
        throw new RuntimeException("Nelze vytvořit {$out}");
    }
    try {
        foreach ($parts as $part) {
            $gz = gzopen($part, 'rb');
            if ($gz === false) {
                throw new RuntimeException("Nelze číst {$part}");
            }
            while (!gzeof($gz)) {
                $chunk = gzread($gz, 65536);
                if ($chunk === false) {
                    break;
                }
                fwrite($fh, $chunk);
            }
            gzclose($gz);
            fwrite($fh, "\n");
        }
    } finally {
        fclose($fh);
    }
    return $out;
}

/**
 * Najde SQL soubor v rozbalené Duplicator Pro záloze.
 */
function findSqlFile(string $root): ?string
{
    // Duplicator Pro DAF: dup-installer/dup_descriptors_*/db_dumps/*-dump.sql
    $dupDir = $root . '/dup-installer';
    if (is_dir($dupDir)) {
        // Hledat v dup_descriptors
        foreach (glob($dupDir . '/dup_descriptors_*/db_dumps/*.sql') as $sqlFile) {
            return $sqlFile;
        }
        // Fallback: jakýkoliv .sql v dup-installer
        foreach (glob($dupDir . '/*.sql') as $sqlFile) {
            return $sqlFile;
        }
        foreach (glob($dupDir . '/**/*.sql') as $sqlFile) {
            return $sqlFile;
        }
        // Duplicator Pro může DB rozdělit po tabulkách do db_dumps/*.sql.gz
        $combined = combineGzDumps($root);
        if ($combined !== null) {
            return $combined;
        }
    }

    // Fallback: .sql v rootu
    foreach (glob($root . '/*.sql') as $sqlFile) {
        return $sqlFile;
    }

    // Fallback: rekurzivně
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $file) {
        if ($file->getExtension() === 'sql') {
            return $file->getPathname();
        }
    }

    return null;
}

/**
 * Najde starou URL z Duplicator konfigurace nebo SQL souboru.
 */
function findOldUrl(string $root, ?string $sqlFile): ?string
{
    // Duplicator Pro DAF: dup-installer/dup_descriptors_*/archive.txt (JSON)
    $archiveTxt = glob($root . '/dup-installer/dup_descriptors_*/archive.txt');
    if (!empty($archiveTxt) && file_exists($archiveTxt[0])) {
        $cfg = json_decode(file_get_contents($archiveTxt[0]), true);
        if ($cfg) {
            // url_old (starší formát)
            if (!empty($cfg['url_old'])) {
                return $cfg['url_old'];
            }
            // subsites[0].domain (DAF formát)
            if (!empty($cfg['subsites'][0]['domain'])) {
                $domain = $cfg['subsites'][0]['domain'];
                $scheme = (!empty($cfg['subsites'][0]['secure_on']) || !empty($cfg['secure_on'])) ? 'https' : 'http';
                return $scheme . '://' . $domain;
            }
            // blogname jako fallback (často obsahuje doménu)
            if (!empty($cfg['blogname']) && preg_match('/\./', $cfg['blogname'])) {
                return 'https://' . $cfg['blogname'];
            }
        }
    }

    // Duplicator Pro: dup-installer/dup-installer.cfg (JSON)
    $cfgFile = $root . '/dup-installer/dup-installer.cfg';
    if (file_exists($cfgFile)) {
        $cfg = json_decode(file_get_contents($cfgFile), true);
        if ($cfg && !empty($cfg['urlOld'])) {
            return $cfg['urlOld'];
        }
    }

    // Fallback: hledat v SQL souboru (prvních 500KB - WordPress sql dump má INSERT na začátku)
    if ($sqlFile && file_exists($sqlFile)) {
        $content = file_get_contents($sqlFile, false, null, 0, 500000);
        if (preg_match('/https?:\/\/[^\s\'"<>]+/i', $content, $m)) {
            // Najít nejčastější URL (často je to siteurl)
            preg_match_all('/https?:\/\/[a-zA-Z0-9._-]+\.[a-zA-Z]{2,}/i', $content, $all);
            if (!empty($all[0])) {
                $counts = array_count_values($all[0]);
                arsort($counts);
                return array_key_first($counts);
            }
            return $m[0];
        }
    }

    return null;
}
