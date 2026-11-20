<?php
declare(strict_types=1);

/**
 * Serialization-aware search/replace URL a cest v databázi.
 */

/**
 * Serialization-aware search/replace URL a cest v databázi.
 * Na rozdíl od SQL REPLACE() tato funkce:
 * 1. Nahrazuje URL i cesty na disku
 * 2. Opravuje délky v PHP serializovaných řetězcích (s:XX:"...")
 * 3. Respektuje JSON data
 */
function replaceUrlsInDb(string $dbName, string $dbUser, string $dbPass, string $oldUrl, string $newUrl, ?string $oldPath = null, ?string $newPath = null): void
{
    if ($oldUrl === $newUrl && ($oldPath === null || $oldPath === $newPath)) {
        return;
    }

    $pdo = new \PDO(
        "mysql:host=127.0.0.1;port=3306;dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
    );

    // Sestavit seznam nahrazení (od nejdelšího po nejkratší)
    $replacements = [];

    if ($oldUrl && $newUrl && $oldUrl !== $newUrl) {
        $parsed = parse_url($oldUrl);
        if (!empty($parsed['host'])) {
            $host = $parsed['host'];
            $path = $parsed['path'] ?? '';
            $newBase = rtrim($newUrl, '/') . $path;
            // Obě staré varianty (http i https) mapovat na novou URL — nová je vždy https,
            // jinak se do DB propíše http:// <doména> a web má mixed content.
            // Plus escapované varianty pro JSON v serializovaných datech (\/\/)
            $esc = fn(string $u): string => str_replace('/', '\\/', $u);
            $replacements['https://' . $host . $path] = $newBase;
            $replacements['http://' . $host . $path] = $newBase;
            $replacements[$esc('https://' . $host . $path)] = $esc($newBase);
            $replacements[$esc('http://' . $host . $path)] = $esc($newBase);
        } else {
            $replacements[$oldUrl] = $newUrl;
        }
    }

    if ($oldPath && $newPath && $oldPath !== $newPath) {
        $replacements[$oldPath] = $newPath;
        // Escapovaná varianta pro JSON (\/ → /)
        $replacements[str_replace('/', '\\/', $oldPath)] = str_replace('/', '\\/', $newPath);
    }

    // Seřadit od nejdelšího (aby se nejdřív nahradily delší matche)
    uksort($replacements, fn($a, $b) => strlen($b) - strlen($a));

    logMsg("Nahrazení: " . implode(' → ', array_keys($replacements)) . " → " . implode(' → ', array_values($replacements)));

    // Najít všechny tabulky
    $tables = $pdo->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);
    $totalReplaced = 0;

    foreach ($tables as $table) {
        $cols = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(\PDO::FETCH_ASSOC);
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
                $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $old);
                $whereParts[] = "`{$col}` LIKE " . $pdo->quote('%' . $escaped . '%');
            }
            $where = implode(' OR ', $whereParts);

            $stmt = $pdo->query("SELECT {$pkList}, `{$col}` FROM `{$table}` WHERE {$where}");
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $value = $row[$col];
                $newValue = serializeAwareReplace($value, $replacements);

                if ($newValue !== $value) {
                    $totalReplaced++;

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

    logMsg("Search/replace dokončeno: {$totalReplaced} řádků aktualizováno");
}

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
    // Pozor: globální error handler v bootstrap.php převádí i @-potlačená
    // varování na ErrorException, proto try/catch místo pouhého @.
    // allowed_classes=false: data pocházejí z cizích WP dumpů (object injection)
    try {
        $unserialized = @unserialize($data, ['allowed_classes' => false]);
    } catch (\Throwable) {
        $unserialized = false;
    }
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
