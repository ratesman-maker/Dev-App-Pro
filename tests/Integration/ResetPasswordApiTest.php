<?php
declare(strict_types=1);

namespace DevAppPro\Tests\Integration;

use DevAppPro\Tests\TestCase;

/**
 * Integrační testy pro reset hesla API.
  * @group auth
 */
class ResetPasswordApiTest extends TestCase
{
    /**
     * Hint vrátí nápovědu pro existujícího uživatele.
     */
    public function test_hint_vrati_napovedu(): void
    {
        $response = $this->http->post('/api/auth/password-hint', [
            'json' => ['username' => 'admin'],
            'headers' => ['X-CSRF-Token' => $this->csrfToken],
        ]);

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertEquals('admin', $body['username']);
        $this->assertEquals('Jméno mého prvního psa', $body['hint']);
    }

    /**
     * Hint pro neexistujícího uživatele → 404.
     */
    public function test_hint_pro_neexistujiciho_vrati_404(): void
    {
        $response = $this->http->post('/api/auth/password-hint', [
            'json' => ['username' => 'neexistuje'],
            'headers' => ['X-CSRF-Token' => $this->csrfToken],
        ]);

        $this->assertEquals(404, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    /**
     * Reset změní heslo - pak login s novým heslem → 200, staré → 401.
     */
    public function test_reset_zmeni_heslo(): void
    {
        // Reset hesla
        $response = $this->http->post('/api/auth/reset-password', [
            'json' => [
                'username' => 'admin',
                'new_password' => 'NoveHeslo123',
                'new_password_confirm' => 'NoveHeslo123',
            ],
            'headers' => ['X-CSRF-Token' => $this->csrfToken],
        ]);
        $this->assertEquals(200, $response->getStatusCode());

        // Login s novým heslem → 200
        $response = $this->http->post('/api/auth/login', [
            'json' => [
                'username' => 'admin',
                'password' => 'NoveHeslo123',
            ],
            'headers' => ['X-CSRF-Token' => $this->csrfToken],
        ]);
        $this->assertEquals(200, $response->getStatusCode());

        // Po úspěšném loginu se session regeneruje a CSRF token se rotuje
        $this->refreshCsrfToken();

        // Login se starým heslem → 401
        $response = $this->http->post('/api/auth/login', [
            'json' => [
                'username' => 'admin',
                'password' => 'test123',
            ],
            'headers' => ['X-CSRF-Token' => $this->csrfToken],
        ]);
        $this->assertEquals(401, $response->getStatusCode());
    }

    /**
     * Reset s neshodou hesel → 422.
     */
    public function test_reset_neshoda_hesel_vrati_422(): void
    {
        $response = $this->http->post('/api/auth/reset-password', [
            'json' => [
                'username' => 'admin',
                'new_password' => 'NoveHeslo123',
                'new_password_confirm' => 'JineHeslo456',
            ],
            'headers' => ['X-CSRF-Token' => $this->csrfToken],
        ]);

        $this->assertEquals(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    /**
     * Reset s krátkým heslem ('123') → 422.
     */
    public function test_reset_kratke_heslo_vrati_422(): void
    {
        $response = $this->http->post('/api/auth/reset-password', [
            'json' => [
                'username' => 'admin',
                'new_password' => '123',
                'new_password_confirm' => '123',
            ],
            'headers' => ['X-CSRF-Token' => $this->csrfToken],
        ]);

        $this->assertEquals(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }
}
