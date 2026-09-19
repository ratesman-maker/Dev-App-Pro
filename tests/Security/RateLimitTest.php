<?php
declare(strict_types=1);

namespace DevAppPro\Tests\Security;

use DevAppPro\Tests\TestCase;

/**
 * Bezpečnostní testy pro rate limiting.
  * @group security
 */
class RateLimitTest extends TestCase
{
    /**
     * 5 neúspěšných pokusů za hodinu projde (vše 401).
     */
    public function test_5_pokusu_za_hodinu_projde(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $response = $this->http->post('/api/auth/login', [
                'json' => [
                    'username' => 'admin',
                    'password' => 'spatne',
                ],
                'headers' => ['X-CSRF-Token' => $this->csrfToken],
            ]);
            $this->assertEquals(401, $response->getStatusCode(), "Pokus č. {$i} by měl vrátit 401");
        }
    }

    /**
     * 6. pokus je blokován → 429.
     */
    public function test_6_pokus_blokovany(): void
    {
        // 5 neúspěšných pokusů
        for ($i = 0; $i < 5; $i++) {
            $this->http->post('/api/auth/login', [
                'json' => [
                    'username' => 'admin',
                    'password' => 'spatne',
                ],
                'headers' => ['X-CSRF-Token' => $this->csrfToken],
            ]);
        }

        // 6. pokus → 429
        $response = $this->http->post('/api/auth/login', [
            'json' => [
                'username' => 'admin',
                'password' => 'spatne',
            ],
            'headers' => ['X-CSRF-Token' => $this->csrfToken],
        ]);
        $this->assertEquals(429, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }
}
