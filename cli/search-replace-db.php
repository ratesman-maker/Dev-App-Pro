<?php
declare(strict_types=1);

/**
 * Serialization-aware search/replace pro WordPress databáze.
 *
 * Na rozdíl od SQL REPLACE() tato funkce:
 * 1. Nahrazuje jak URL, tak cesty na disku
 * 2. Opravuje délky v PHP serializovaných řetězcích (s:XX:"...")
 * 3. Respektuje JSON data
 *
 * Použití:
 *   php search-replace-db.php --db=dbname --user=user --pass=pass \
 *     --old-url=https://example.com --new-url=https://example.localhost \
 *     --old-path=/home/www/example.com --new-path=/home/ratesman/projekty/example
 */

$options = getopt('', ['db:', 'user:', 'pass:', 'old-url::', 'new-url::', 'old-path::', 'new-path::', 'dry-run']);

$dbName = $options['db'] ?? '';
$dbUser = $options['user'] ?? '';
$dbPass = $options['pass'] ?? '';
$oldUrl = $options['old-url'] ?? '';
$newUrl = $options['new-url'] ?? '';
$oldPath = $options['old-path'] ?? '';
$newPath = $options['new-path'] ?? '';
$dryRun = isset($options['dry-run']);

if (!$dbName || !$dbUser) {
    echo "Použití: php search-replace-db.php --db=DB --user=USER --pass=PASS --old-url=URL --new-url=URL --old-path=PATH --new-path=PATH [--dry-run]\n";
    exit(1);
}

$pdo = new PDO(
    "mysql:host=127.0.0.1;port=3306;dbname={$dbName};charset=utf8mb4",
    $dbUser,
    $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

/**
 * Rekurzivně nahradí hodnoty v serializovaných datech s opravou délek.
 * Funguje pro: serializované PHP řetězce, JSON, a plain text.
 */
function serializeAwareReplace(string $data, array $replacements): string
{
    if (empty($replacements)) {
        return $data;
    }

    // Zkusit PHP unserialize - pokud je serializované, rekurzivně nahradit
    $unserialized = @unserialize($data);
    if ($unserialized !== false || $data === 'b:0;') {
        $replaced = recursiveReplace($unserialized, $replacements);
        $result = serialize($replaced);
        if ($result !== $data) {
            return $result;
        }
    }

    // Pokud to není serializované, zkusit JSON
    $jsonDecoded = json_decode($data, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($jsonDecoded)) {
        $replaced = recursiveReplace($jsonDecoded, $replacements);
        $result = json_encode($replaced, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($result !== $data) {
            return $result;
        }
    }

    // Jinak prostý string replace
    $result = $data;
    foreach ($replacements as $old => $new) {
        $result = str_replace($old, $new, $result);
    }
    return $result;
}

/**
 * Rekurzivně projde pole/hodnotu a nahradí všechny výskyty.
 */
function recursiveReplace(mixed $data, array $replacements): mixed
{
    if (is_string($data)) {
        foreach ($replacements as $old => $new) {
            $data = str_replace($old, $new, $data);
        }
        return $data;
    }

    if (is_array($data)) {
        $result = [];
        foreach ($data as $key => $value) {
            // Nahradit i v klíči (může obsahovat URL/cestu)
            $newKey = $key;
            if (is_string($key)) {
                foreach ($replacements as $old => $new) {
                    $newKey = str_replace($old, $new, $newKey);
                }
            }
            $result[$newKey] = recursiveReplace($value, $replacements);
        }
        return $result;
    }

    return $data;
}

// Sestavit seznam nahrazení (od nejdelšího po nejkratší - důležité pro správné pořadí)
$replacements = [];

// URL nahrazení - přidat http i https varianty
if ($oldUrl && $newUrl) {
    $parsed = parse_url($oldUrl);
    if (!empty($parsed['host'])) {
        $host = $parsed['host'];
        $path = $parsed['path'] ?? '';
        $newParsed = parse_url($newUrl);
        // new-url bez cesty → zachovat cestu z old-url (pouze změna hostu).
        // new-url s cestou → použít ji doslova (změna hostu i cesty).
        $newPath = $newParsed['path'] ?? '';
        $newBase = rtrim($newUrl, '/');
        if ($newPath === '') {
            $newBase .= $path;
        }
        if (($newParsed['host'] ?? '') === $host && ($parsed['scheme'] ?? 'http') !== ($newParsed['scheme'] ?? '')) {
            // Čistá změna schématu na stejném hostu (např. http→https) — nahradit jen dané schéma
            $replacements[($parsed['scheme'] ?? 'http') . '://' . $host . $path] = $newBase;
        } else {
            // Obě staré varianty mapovat na newBase — nové URL je autoritativní,
            // jinak se při přesunu na https propíše http:// a vznikne mixed content
            $replacements['https://' . $host . $path] = $newBase;
            $replacements['http://' . $host . $path] = $newBase;
        }
    } else {
        $replacements[$oldUrl] = $newUrl;
    }
}

// Cesta nahrazení
if ($oldPath && $newPath) {
    $replacements[$oldPath] = $newPath;
    // Také escapovaná varianta pro JSON (\/ → /)
    $replacements[str_replace('/', '\\/', $oldPath)] = str_replace('/', '\\/', $newPath);
}

// Seřadit od nejdelšího (aby se nejdřív nahradily delší matche)
uksort($replacements, fn($a, $b) => strlen($b) - strlen($a));

echo "Nahrazení:\n";
foreach ($replacements as $old => $new) {
    echo "  {$old} → {$new}\n";
}

// Najít všechny tabulky
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$totalReplaced = 0;

foreach ($tables as $table) {
    // Najít sloupce typu text/varchar/char
    $cols = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
    $textCols = [];
    $pkCols = [];
    foreach ($cols as $col) {
        $type = strtolower($col['Type']);
        if (str_contains($type, 'text') || str_contains($type, 'varchar') || str_contains($type, 'char')) {
            if ($col['Field'] === 'guid') continue; // WordPress konvence
            $textCols[] = $col['Field'];
        }
        if ($col['Key'] === 'PRI') {
            $pkCols[] = $col['Field'];
        }
    }

    if (empty($textCols) || empty($pkCols)) {
        continue;
    }

    $pkList = implode(', ', array_map(fn($c) => "`{$c}`", $pkCols));

    foreach ($textCols as $col) {
        // Najít řádky obsahující nějaký ze starých řetězců
        $whereParts = [];
        foreach ($replacements as $old => $new) {
            $whereParts[] = "`{$col}` LIKE " . $pdo->quote('%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $old) . '%');
        }
        $where = implode(' OR ', $whereParts);

        $stmt = $pdo->query("SELECT {$pkList}, `{$col}` FROM `{$table}` WHERE {$where}");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $value = $row[$col];
            $newValue = serializeAwareReplace($value, $replacements);

            if ($newValue !== $value) {
                $totalReplaced++;

                if ($dryRun) {
                    echo "  [DRY] {$table}.{$col}: " . substr($value, 0, 80) . " → " . substr($newValue, 0, 80) . "\n";
                } else {
                    $setParts = [];
                    $params = [];
                    foreach ($pkCols as $pk) {
                        $setParts[] = "`{$pk}` = ?";
                        $params[] = $row[$pk];
                    }
                    $whereClause = implode(' AND ', $setParts);

                    $update = $pdo->prepare("UPDATE `{$table}` SET `{$col}` = ? WHERE {$whereClause}");
                    $update->execute(array_merge([$newValue], $params));
                }
            }
        }
    }
}

echo "\nCelkem nahrazeno: {$totalReplaced} řádků\n";
if ($dryRun) {
    echo "(dry-run - žádné změny nebyly provedeny)\n";
}
