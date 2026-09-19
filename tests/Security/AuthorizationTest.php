<?php
declare(strict_types=1);

namespace DevAppPro\Tests\Security;

use DevAppPro\Tests\TestCase;

/**
 * Bezpečnostní testy pro autorizaci.
 */
class AuthorizationTest extends TestCase
{
    /**
     * GET /api/auth/me bez přihlášení → 401.
     */
    public function test_get_auth_me_bez_prihlaseni_vrati_401(): void
    {
        $response = $this->http->get('/api/auth/me');
        $this->assertEquals(401, $response->getStatusCode());
    }

    /**
     * Veřejné endpointy nevyžadují přihlášení (nevrátí 401).
     * POST /api/auth/login, POST /api/auth/password-hint, POST /api/auth/reset-password.
     */
    public function test_public_endpointy_nevyaduji_prihlaseni(): void
    {
        // Login endpoint - ne 401 (špatné heslo dá 401, ale to je kvůli špatným údajům,
        // ne kvůli chybějící autentizaci). Použijeme prázdné tělo pro 422.
        $response = $this->http->post('/api/auth/login', [
            'json' => [],
            'headers' => ['X-CSRF-Token' => $this->csrfToken],
        ]);
        $this->assertNotEquals(401, $response->getStatusCode());

        // Password-hint endpoint - ne 401 (prázdné username → 422)
        $response = $this->http->post('/api/auth/password-hint', [
            'json' => [],
            'headers' => ['X-CSRF-Token' => $this->csrfToken],
        ]);
        $this->assertNotEquals(401, $response->getStatusCode());

        // Reset-password endpoint - ne 401 (prázdné username → 422)
        $response = $this->http->post('/api/auth/reset-password', [
            'json' => [],
            'headers' => ['X-CSRF-Token' => $this->csrfToken],
        ]);
        $this->assertNotEquals(401, $response->getStatusCode());
    }
}
