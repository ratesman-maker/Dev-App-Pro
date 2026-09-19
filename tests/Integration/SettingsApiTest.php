<?php
declare(strict_types=1);

namespace DevAppPro\Tests\Integration;

use DevAppPro\Tests\TestCase;

/**
 * Integrační testy pro Settings API.
  * @group settings
 */
class SettingsApiTest extends TestCase
{
    /**
     * GET /api/settings bez přihlášení → 401.
     */
    public function test_get_settings_bez_prihlaseni_vrati_401(): void
    {
        $response = $this->http->get('/api/settings');

        $this->assertEquals(401, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    /**
     * Login, GET /api/settings → 200, obsahuje default_vat_rate, timezone, currency.
     */
    public function test_get_settings_po_prihlaseni_vrati_200(): void
    {
        $this->login();

        $response = $this->http->get('/api/settings');

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('default_vat_rate', $body);
        $this->assertArrayHasKey('timezone', $body);
        $this->assertArrayHasKey('currency', $body);
    }

    /**
     * Login, PUT /api/settings bez X-CSRF-Token → 403.
     */
    public function test_put_settings_bez_csrf_vrati_403(): void
    {
        $this->login();

        $response = $this->http->request('PUT', '/api/settings', [
            'json' => ['default_due_days' => '30'],
            // Záměrně bez X-CSRF-Token hlavičky
        ]);

        $this->assertEquals(403, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    /**
     * Login, PUT /api/settings s {default_due_days: "30"} → 200,
     * následný GET obsahuje "30".
     */
    public function test_put_settings_aktualizuje_hodnotu(): void
    {
        $this->login();

        $response = $this->http->request('PUT', '/api/settings', [
            'json' => ['default_due_days' => '30'],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertEquals('30', $body['default_due_days']);

        // Ověření přes GET
        $getResponse = $this->http->get('/api/settings');
        $this->assertEquals(200, $getResponse->getStatusCode());
        $getBody = json_decode((string) $getResponse->getBody(), true);
        $this->assertEquals('30', $getBody['default_due_days']);
    }
}
