<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

require_once __DIR__ . '/config/config.php';
$dbConfig = require __DIR__ . '/config/database.php';

// Override pro testy (env vars z phpunit.xml nebo test serveru)
if (getenv('DB_HOST')) $dbConfig['host'] = getenv('DB_HOST');
if (getenv('DB_NAME')) $dbConfig['dbname'] = getenv('DB_NAME');
if (getenv('DB_USER')) $dbConfig['username'] = getenv('DB_USER');
if (getenv('DB_PASS')) $dbConfig['password'] = getenv('DB_PASS');

// Error handling - 500 stránka bez detailů pro produkci
// V debug módu se zobrazí detaily, jinak jen generická zpráva
set_exception_handler(function (\Throwable $e): void {
    // Logovat do /var/log/devapppro/error.log
    $logMsg = sprintf(
        "[%s] %s: %s in %s:%d\nStack trace:\n%s\n",
        date('Y-m-d H:i:s'),
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    );
    // Logger nesmí shodit exception handler - vypnout error handler,
    // aby @ skutečně potlačilo warning (jinak ErrorException uvnitř handleru = fatal)
    $logFile = '/var/log/devapppro/error.log';
    restore_error_handler();
    if (@file_put_contents($logFile, $logMsg, FILE_APPEND | LOCK_EX) === false && PHP_SAPI === 'cli') {
        fwrite(STDERR, $logMsg);
    }

    // Pokud ještě nebyl odeslán HTTP hlavičky, odešli 500
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        if (defined('APP_DEBUG') && APP_DEBUG) {
            echo json_encode([
                'error' => 'Interní chyba serveru.',
                'debug' => [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            echo json_encode(['error' => 'Interní chyba serveru. Zkuste to prosím později.'], JSON_UNESCAPED_UNICODE);
        }
    }
});

set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
    // Převést na exception pro exception handler
    throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// Session konfigurace (před session_start)
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime', '7200');
ini_set('session.cookie_lifetime', '0');

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

// Detekce krádeže session: ověření IP + User-Agent proti hodnotám z doby přihlášení.
// Pokud se změní, session je zneplatněna (regenerována).
if (isset($_SESSION['ip']) || isset($_SESSION['ua'])) {
    $currentIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $currentUa = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if ($_SESSION['ip'] !== $currentIp || $_SESSION['ua'] !== $currentUa) {
        // Session možná ukradena - zničit a začít čistou
        session_destroy();
        session_regenerate_id(true);
        $_SESSION = [];
    }
}

// Globální PDO připojení
// Self-contained: načítá konfiguraci samostatně, aby fungovalo i při
// načtení bootstrapu přes include_once uvnitř metody (např. PHPUnit).
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $cfg = require __DIR__ . '/config/database.php';
        if (getenv('DB_HOST')) $cfg['host'] = getenv('DB_HOST');
        if (getenv('DB_NAME')) $cfg['dbname'] = getenv('DB_NAME');
        if (getenv('DB_USER')) $cfg['username'] = getenv('DB_USER');
        if (getenv('DB_PASS')) $cfg['password'] = getenv('DB_PASS');
        $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']};charset={$cfg['charset']}";
        $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], $cfg['options']);
    }
    return $pdo;
}
