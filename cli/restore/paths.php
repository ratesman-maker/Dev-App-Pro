<?php
declare(strict_types=1);

/**
 * Detekce staré FS cesty a post-replace sweep zbylých cest.
 */

/**
 * Extrahuje kořen WP instalace z hodnoty obsahující absolutní FS cestu.
 * Vrací prefix před /wp-content|wp-includes|wp-admin (např. /home/html/site/_sub/web).
 */
function extractFsRoot(string $value): ?string
{
    if (preg_match('#(/(?:home|var/www|usr/www|srv|opt|mnt)/[^\s"\'\\\\]+?)/(?:wp-content|wp-includes|wp-admin|wp-login)#', $value, $m)) {
        return rtrim($m[1], '/');
    }
    return null;
}

/**
 * Původní cesta WP rootu z Duplicator descriptoru (archive.txt).
 * Metadata archivu jsou autoritativní zdroj — obsahují cestu, ze které
 * byl snapshot pořízen, nezávisle na tom, co zbylo v options.
 */
function findOldPathFromDescriptor(string $targetRoot): ?string
{
    $archiveTxt = glob($targetRoot . '/dup-installer/dup_descriptors_*/archive.txt');
    if (empty($archiveTxt) || !file_exists($archiveTxt[0])) {
        return null;
    }
    $cfg = json_decode((string) file_get_contents($archiveTxt[0]), true);
    if (!is_array($cfg)) {
        return null;
    }

    $origPaths = $cfg['wpInfo']['configs']['realValues']['originalPaths'] ?? [];
    $candidates = [
        $origPaths['home'] ?? null,
        $origPaths['abs'] ?? null,
        $origPaths['wpconfig'] ?? null,
        $cfg['wpInfo']['targetRoot'] ?? null,
    ];
    // subsites[0].fullUploadPath = <root>/wp-content/uploads → oříznout suffix
    $upload = $cfg['subsites'][0]['fullUploadPath'] ?? null;
    if (is_string($upload) && $upload !== '') {
        $candidates[] = preg_replace('#/wp-content/uploads/?$#', '', $upload);
    }

    foreach ($candidates as $c) {
        if (is_string($c) && str_starts_with($c, '/')) {
            return rtrim($c, '/');
        }
    }
    return null;
}

/**
 * Detekce staré cesty na serveru. Tři úrovně:
 * 1) známé options (recently_edited, upload_path, …)
 * 2) Duplicator descriptor (archive.txt — původní cesta z metadat)
 * 3) frekvenční scan options — nejčastější absolutní prefix před /wp-content
 *    (pokryje weby, kde detekční options jsou prázdné — např. Kadence
 *    downloaded_font_files, dirsize transienty)
 */
function findOldPath(\PDO $sitePdo, string $tablePrefix, ?string $targetRoot = null): ?string
{
    $optionsTable = $tablePrefix . 'options';

    // 1) Známé options
    $keys = ['recently_edited', 'et_images_temp_folder', 'upload_path', 'stylesheet_root', 'template_root'];
    foreach ($keys as $key) {
        $stmt = $sitePdo->prepare("SELECT option_value FROM `{$optionsTable}` WHERE option_name = ? LIMIT 1");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        if ($val && ($root = extractFsRoot((string) $val)) !== null) {
            return $root;
        }
    }

    // 2) Duplicator descriptor (dup-installer existuje — maže se až v kroku 6d)
    if ($targetRoot !== null && ($root = findOldPathFromDescriptor($targetRoot)) !== null) {
        logMsg("Stará cesta z Duplicator descriptoru: {$root}");
        return $root;
    }

    // 3) Frekvenční scan — nejčastější absolutní root v options
    $roots = [];
    $stmt = $sitePdo->query(
        "SELECT option_value FROM `{$optionsTable}` "
        . "WHERE option_value REGEXP '/(home|var|usr|srv|opt|mnt)/[^[:space:]\"'']+/wp-content' LIMIT 200"
    );
    while (($val = $stmt->fetchColumn()) !== false) {
        $root = extractFsRoot((string) $val);
        if ($root !== null) {
            $roots[$root] = ($roots[$root] ?? 0) + substr_count((string) $val, $root);
        }
    }
    if ($roots) {
        arsort($roots);
        $root = array_key_first($roots);
        logMsg("Stará cesta z frekvenčního scanu options: {$root}");
        return $root;
    }

    return null;
}

/**
 * Post-replace sweep: najde absolutní FS rooty, které v DB přežily
 * replace (typicky když detekce staré cesty selhala). Vrací seznam
 * rootů != $newPath seřazený podle frekvence.
 */
function findLeftoverFsRoots(\PDO $sitePdo, string $tablePrefix, string $newPath): array
{
    $found = [];
    $targets = [
        [$tablePrefix . 'options', 'option_value'],
        [$tablePrefix . 'postmeta', 'meta_value'],
        [$tablePrefix . 'posts', 'post_content'],
    ];
    foreach ($targets as [$table, $col]) {
        try {
            $stmt = $sitePdo->query(
                "SELECT `{$col}` FROM `{$table}` "
                . "WHERE `{$col}` REGEXP '/(home|var|usr|srv|opt|mnt)/[^[:space:]\"'']+/wp-content' LIMIT 300"
            );
        } catch (\Throwable $e) {
            continue; // tabulka nemusí existovat
        }
        while (($val = $stmt->fetchColumn()) !== false) {
            $root = extractFsRoot((string) $val);
            if ($root !== null && $root !== $newPath) {
                $found[$root] = ($found[$root] ?? 0) + substr_count((string) $val, $root);
            }
        }
    }
    arsort($found);
    return array_keys($found);
}
