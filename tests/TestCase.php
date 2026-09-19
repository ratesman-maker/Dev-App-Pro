<?php
declare(strict_types=1);

namespace DevAppPro\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;

/**
 * Základní třída pro HTTP/API testy (Integration, Security).
 * Rozšiřuje UnitTestCase (test DB) o PHP built-in server a Guzzle klienta s cookies.
 */
abstract class TestCase extends UnitTestCase
{
    protected Client $http;
    protected CookieJar $cookies;
    protected string $serverPid;
    protected string $csrfToken;

    protected function setUp(): void
    {
        parent::setUp();

        // Spuštění PHP built-in serveru
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $dbname = getenv('DB_NAME') ?: 'devapppro_test';
        $user = getenv('DB_USER') ?: 'devapppro';
        $pass = getenv('DB_PASS') ?: 'devapppro_secret';

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
        // Zastavení PHP serveru (tabulky uklidí UnitTestCase::tearDown)
        if (!empty($this->serverPid)) {
            shell_exec("kill {$this->serverPid} 2>/dev/null");
            // Počkat na uvolnění portu
            usleep(200000); // 200ms
        }

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
