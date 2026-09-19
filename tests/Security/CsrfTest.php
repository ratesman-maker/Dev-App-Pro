<?php
declare(strict_types=1);

namespace DevAppPro\Tests\Security;

use DevAppPro\Tests\TestCase;

/**
 * Bezpečnostní testy pro CSRF ochranu.
 */
class CsrfTest extends TestCase
{
    /**
     * POST bez CSRF tokenu → 403.
     */
    public function test_post_bez_csrf_tokenu_vrati_403(): void
    {
        $response = $this->http->post('/api/auth/login', [
            'json' => [
                'username' => 'admin',
                'password' => 'test123',
            ],
            // Záměrně bez X-CSRF-Token hlavičky
        ]);

        $this->assertEquals(403, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    /**
     * POST se špatným CSRF tokenem → 403.
     */
    public function test_post_se_spatnym_csrf_tokenem_vrati_403(): void
    {
        $response = $this->http->post('/api/auth/login', [
            'json' => [
                'username' => 'admin',
                'password' => 'test123',
            ],
            'headers' => [
                'X-CSRF-Token' => 'spatnyToken12345',
            ],
        ]);

        $this->assertEquals(403, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    /**
     * GET bez CSRF tokenu projde (vrátí 401 pro /api/auth/me, ne 403).
     */
    public function test_get_bez_csrf_tokenu_projde(): void
    {
        $response = $this->http->get('/api/auth/me');

        // 401 protože nepřihlášen, ale NE 403 (CSRF se ověřuje jen pro mutační)
        $this->assertEquals(401, $response->getStatusCode());
        $this->assertNotEquals(403, $response->getStatusCode());
    }
}
