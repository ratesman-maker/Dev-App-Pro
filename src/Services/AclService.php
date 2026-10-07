<?php
declare(strict_types=1);

namespace DevAppPro\Services;

/**
 * Normalizace ACL na projektových stromech.
 *
 * Problém: root workery (restore, WP instalace) vytvářejí soubory s umask 022
 * (mkdir 0755, chmod 0644). Rodičovský adresář ~/projekty má default ACL
 * udělující zápis www-data/ratesman — ale maska ACL se u nových objektů počítá
 * z požadovaného módu → ořízne se na r-x/r-- a pojmenovaní uživatelé ztratí
 * zápis. Následek: WP core/plugin updaty přes www-data selhávají.
 *
 * Poznámky k implementaci (vše ověřeno empiricky na tomto stroji):
 * - `setfacl -R -m u:x:rwX` NElze použít — uvnitř -R se X vyhodnotí jako x
 *   i na obyčejných souborech → všechny soubory by dostaly execute.
 * - `find -perm /111` jako detekce spustitelných selhává: group:: na souborech
 *   se dědí z default ACL rodiče (~projekty má group rwx) a s sebou nese x,
 *   které ale maska ořezává — mode pak x obsahuje i u obyčejných souborů.
 *   Proto se detekuje jen owner execute bit (-perm /u=x).
 * - group:: na souborech se normalizuje spolu se zbytkem, jinak by maska
 *   zůstala rwx a soubory se jevily jako group-executable.
 */
class AclService
{
    /**
     * Uživatelé, kteří potřebují zápis do projektových stromů:
     * www-data = Apache/PHP-FPM (WP updaty, uploady), ratesman = wp-cli/IDE.
     */
    private const ACL_USERS = ['www-data', 'ratesman'];

    /**
     * Normalizuje ACL rekurzivně na celém stromu.
     * @return array{ok: bool, message: string}
     */
    public static function normalizeProjectTree(string $path): array
    {
        if (!is_dir($path)) {
            return ['ok' => false, 'message' => "Adresář neexistuje: {$path}"];
        }

        // Bezpečnost: uživatelé nemusí na jiném stroji existovat → getpwnam
        $users = [];
        foreach (self::ACL_USERS as $user) {
            if (!function_exists('posix_getpwnam') || posix_getpwnam($user) !== false) {
                $users[] = $user;
            }
        }
        if ($users === []) {
            return ['ok' => false, 'message' => 'Žádný známý uživatel pro ACL (www-data/ratesman)'];
        }

        // Adresáře: rwx + default ACL (nové soubory v nich pak zápis zdědí).
        // Soubory bez owner-x: rw- (vč. sjednocení group:: — jinak maska zůstane rwx).
        // Soubory s owner-x (vendor/bin a pod.): rwx pro uživatele, rx pro group.
        $dirSpec = implode(',', array_map(static fn ($u) => "u:{$u}:rwx", $users))
            . ',' . implode(',', array_map(static fn ($u) => "d:u:{$u}:rwx", $users));
        $fileSpec = implode(',', array_map(static fn ($u) => "u:{$u}:rw", $users)) . ',g::rw';
        $execSpec = implode(',', array_map(static fn ($u) => "u:{$u}:rwx", $users)) . ',g::rx';

        $p = escapeshellarg($path);
        $cmds = [
            "find {$p} -type d -exec setfacl -m {$dirSpec} {} +",
            "find {$p} -type f ! -perm /u=x -exec setfacl -m {$fileSpec} {} +",
            "find {$p} -type f -perm /u=x -exec setfacl -m {$execSpec} {} +",
        ];

        $errors = [];
        foreach ($cmds as $cmd) {
            exec($cmd . ' 2>&1', $output, $code);
            if ($code !== 0) {
                $errors[] = trim(implode("\n", $output)) ?: "exit {$code}: {$cmd}";
            }
            $output = [];
        }

        if ($errors !== []) {
            return ['ok' => false, 'message' => implode('; ', $errors)];
        }
        return ['ok' => true, 'message' => 'OK'];
    }
}
