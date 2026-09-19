<?php
declare(strict_types=1);

namespace DevAppPro\Tests\Integration;

use DevAppPro\Tests\TestCase;

/**
 * Integrační testy pro login API.
 */
class LoginApiTest extends TestCase
{
    /**
     * Úspěšné přihlášení s admin/test123 → 200, user data.
     */
    public function test_uspesne_prihlaseni(): void
    {
        $response = $this->http->post('/api/auth/login', [
            'json' => [
                'username' => 'admin',
                'password' => 'test123',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('user', $body);
        $this->assertEquals('admin', $body['user']['username']);
        $this->assertEquals('Administrátor', $body['user']['name']);
    }

    /**
     * Špatné heslo → 401.
     */
    public function test_spatne_heslo_vrati_401(): void
    {
        $response = $this->http->post('/api/auth/login', [
            'json' => [
                'username' => 'admin',
                'password' => 'spatneHeslo',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(401, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    /**
     * Neexistující uživatel → 401.
     */
    public function test_neexistujici_uzivatel_vrati_401(): void
    {
        $response = $this->http->post('/api/auth/login', [
            'json' => [
                'username' => 'neexistuje',
                'password' => 'cokoliv',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(401, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    /**
     * Přihlášení vrátí user preferences (theme, sidebar_collapsed, per_page).
     */
    public function test_prihlaseni_vrati_user_preferences(): void
    {
        $response = $this->http->post('/api/auth/login', [
            'json' => [
                'username' => 'admin',
                'password' => 'test123',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('user', $body);
        $this->assertArrayHasKey('theme', $body['user']);
        $this->assertArrayHasKey('sidebar_collapsed', $body['user']);
        $this->assertArrayHasKey('per_page', $body['user']);
        $this->assertEquals('dark', $body['user']['theme']);
        $this->assertFalse($body['user']['sidebar_collapsed']);
        $this->assertEquals(20, $body['user']['per_page']);
    }

    /**
     * Rate limit blokuje po 5 neúspěšných pokusech.
     */
    public function test_rate_limit_blokuje_po_5_pokusech(): void
    {
        // 5 neúspěšných pokusů → vše 401
        for ($i = 0; $i < 5; $i++) {
            $response = $this->http->post('/api/auth/login', [
                'json' => [
                    'username' => 'admin',
                    'password' => 'spatne',
                ],
                'headers' => [
                    'X-CSRF-Token' => $this->csrfToken,
                ],
            ]);
            $this->assertEquals(401, $response->getStatusCode(), "Pokus č. {$i} by měl vrátit 401");
        }

        // 6. pokus → 429
        $response = $this->http->post('/api/auth/login', [
            'json' => [
                'username' => 'admin',
                'password' => 'spatne',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $this->assertEquals(429, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    /**
     * Login vyžaduje CSRF token - POST bez X-CSRF-Token → 403.
     */
    public function test_login_vyzaduje_csrf_token(): void
    {
        $response = $this->http->post('/api/auth/login', [
            'json' => [
                'username' => 'admin',
                'password' => 'test123',
            ],
            // Bez X-CSRF-Token hlavičky
        ]);

        $this->assertEquals(403, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }
}
