<?php
declare(strict_types=1);

namespace DevAppPro\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;

/**
 * Základní třída pro všechny testy.
 * Spouští PHP built-in server a poskytuje Guzzle klienta s cookies.
 */
abstract class TestCase extends BaseTestCase
{
    protected \PDO $pdo;
    protected Client $http;
    protected CookieJar $cookies;
    protected string $serverPid;
    protected string $csrfToken;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Připojení k test DB
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $dbname = getenv('DB_NAME') ?: 'devapppro_test';
        $user = getenv('DB_USER') ?: 'devapppro';
        $pass = getenv('DB_PASS') ?: 'devapppro_secret';

        $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
        $this->pdo = new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // 2. Načtení schema.sql (DROP + CREATE)
        $schema = file_get_contents(__DIR__ . '/../database/schema.sql');
        $this->pdo->exec($schema);

        // 3. Načtení seed_test.sql
        $seed = file_get_contents(__DIR__ . '/../database/seed_test.sql');
        $this->pdo->exec($seed);

        // 4. Spuštění PHP built-in serveru
        $this->cookies = new CookieJar();
        $projectRoot = dirname(__DIR__);
        $router = escapeshellarg($projectRoot . '/tests/test-router.php');

        $env = sprintf(
            'DB_HOST=%s DB_NAME=%s DB_USER=%s DB_PASS=%s DEVAPPPRO_WP_AUTOLOGIN_SECRET=%s DEVAPPPRO_CREDENTIALS_ENCRYPTION_KEY=%s',
            escapeshellarg($host),
            escapeshellarg($dbname),
            escapeshellarg($user),
            escapeshellarg($pass),
            escapeshellarg('test_wp_autologin_secret'),
            escapeshellarg('test_credentials_encryption_key')
        );

        $cmd = "{$env} php -S 127.0.0.1:8080 {$router} > /dev/null 2>&1 & echo $!";
        $output = shell_exec($cmd);
        $this->serverPid = trim((string) $output);

        // Počkat na start serveru
        $this->waitForServer();

        // 5. Vytvoření Guzzle klienta s cookies
        $this->http = new Client([
            'base_uri' => 'http://127.0.0.1:8080',
            'cookies' => $this->cookies,
            'http_errors' => false,
        ]);

        // 6. Získání CSRF tokenu
        $this->fetchCsrfToken();
    }

    protected function tearDown(): void
    {
        // 1. Zastavení PHP serveru
        if (!empty($this->serverPid)) {
            shell_exec("kill {$this->serverPid} 2>/dev/null");
            // Počkat na uvolnění portu
            usleep(200000); // 200ms
        }

        // 2. Drop all tables
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $this->pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        parent::tearDown();
    }

    /**
     * Počká na start PHP serveru (max 5 sekund).
     */
    private function waitForServer(int $maxAttempts = 50): void
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            try {
                // Bootstrap error handler převádí i @-potlačená varování na výjimky,
                // takže se nelze spolehnout na @fsockopen.
                $fp = fsockopen('127.0.0.1', 8080, $errno, $errstr, 0.1);
            } catch (\ErrorException) {
                $fp = false;
            }
            if ($fp !== false) {
                fclose($fp);
                return;
            }
            usleep(100000); // 100ms
        }
        throw new \RuntimeException('PHP built-in server se nepodařilo spustit.');
    }

    /**
     * Získá CSRF token z /api/auth/csrf-token.
     */
    protected function fetchCsrfToken(): void
    {
        $response = $this->http->get('/api/auth/csrf-token');
        $body = json_decode((string) $response->getBody(), true);
        $this->csrfToken = $body['csrf_token'] ?? '';
    }

    /**
     * Obnoví CSRF token po úspěšném loginu (session byla regenerována).
     */
    protected function refreshCsrfToken(): void
    {
        $this->fetchCsrfToken();
    }

    /**
     * Přihlásí uživatele a uloží cookies.
     * Po úspěšném loginu obnoví CSRF token.
     */
    protected function login(string $username = 'admin', string $password = 'test123'): void
    {
        $response = $this->http->post('/api/auth/login', [
            'json' => [
                'username' => $username,
                'password' => $password,
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        // Po úspěšném loginu se session regeneruje a CSRF token se rotuje
        if ($response->getStatusCode() === 200) {
            $this->refreshCsrfToken();
        }
    }

    /**
     * Vrátí CSRF token.
     */
    protected function getCsrfToken(): string
    {
        return $this->csrfToken;
    }
}
