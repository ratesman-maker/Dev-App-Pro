<?php
declare(strict_types=1);

// Router pro PHP built-in server (testování)
// Používá se: php -S 127.0.0.1:8080 tests/test-router.php

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

// Statické soubory
$fullPath = __DIR__ . '/../' . ltrim($uri, '/');
if ($uri !== '/' && file_exists($fullPath) && !is_dir($fullPath)) {
    return false; // PHP built-in server servíruje soubor
}

// SPA fallback
readfile(__DIR__ . '/../index.html');
return true;
