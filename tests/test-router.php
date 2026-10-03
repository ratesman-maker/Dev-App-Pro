<?php
declare(strict_types=1);

// Router pro PHP built-in server (testování)
// Používá se: php -S 127.0.0.1:8080 tests/test-router.php

// Pojistka: test server NIKDY nesmí běžet proti produkční databázi.
if (!str_ends_with(getenv('DB_NAME') ?: 'devapppro_test', '_test')) {
    http_response_code(500);
    echo json_encode(['error' => 'Test server vyžaduje testovací DB (název končící _test).']);
    return true;
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// API endpointy - první segment určuje soubor (např. /api/auth/login → api/auth.php)
if (preg_match('#^/api/([a-z-]+)(/.*)?$#', $uri, $m)) {
    $file = __DIR__ . '/../api/' . $m[1] . '.php';
    if (file_exists($file)) {
        require $file;
        return true;
    }
    http_response_code(404);
    echo json_encode(['error' => 'Endpoint nenalezen.']);
    return true;
}

// Servírování souboru s MIME typem (php -S neumí remapovat cesty)
$serveFile = function (string $absPath): bool {
    if (!file_exists($absPath) || is_dir($absPath)) {
        return false;
    }
    static $mimeMap = [
        'js' => 'text/javascript', 'mjs' => 'text/javascript',
        'css' => 'text/css', 'map' => 'application/json',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf',
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg',
        'webp' => 'image/webp', 'ico' => 'image/x-icon', 'html' => 'text/html',
    ];
    $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($mimeMap[$ext] ?? 'application/octet-stream'));
    readfile($absPath);
    return true;
};

// Statické soubory
$fullPath = __DIR__ . '/../' . ltrim($uri, '/');
if ($uri !== '/' && file_exists($fullPath) && !is_dir($fullPath)) {
    return false; // PHP built-in server servíruje soubor
}

// Vite build assety (stejné mapování jako .htaccess): /assets/x → assets/dist/assets/x
if (preg_match('#^/assets/([a-zA-Z0-9._-]+)$#', $uri, $m)) {
    if ($serveFile(__DIR__ . '/../assets/dist/assets/' . $m[1])) {
        return true;
    }
}

// Fonty (stejné mapování jako .htaccess): /fonts/x → frontend/public/fonts/x
if (preg_match('#^/fonts/([a-zA-Z0-9._-]+)$#', $uri, $m)) {
    if ($serveFile(__DIR__ . '/../frontend/public/fonts/' . $m[1])) {
        return true;
    }
}

// SPA fallback - Vite build; placeholder index.html jen když build neexistuje
$index = __DIR__ . '/../assets/dist/index.html';
if (!file_exists($index)) {
    $index = __DIR__ . '/../index.html';
}
$serveFile($index);
return true;
