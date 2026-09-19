<?php
declare(strict_types=1);

namespace DevAppPro\Tests\Integration;

use DevAppPro\Tests\TestCase;

/**
 * Integrační testy pro Company Profile API.
  * @group settings
 */
class CompanyProfileApiTest extends TestCase
{
    /**
     * GET /api/company-profile bez přihlášení → 401.
     */
    public function test_get_company_profile_bez_prihlaseni_vrati_401(): void
    {
        $response = $this->http->get('/api/company-profile');

        $this->assertEquals(401, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    /**
     * Login, GET /api/company-profile → 200, obsahuje type, first_name, last_name.
     */
    public function test_get_company_profile_po_prihlaseni_vrati_200(): void
    {
        $this->login();

        $response = $this->http->get('/api/company-profile');

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('type', $body);
        $this->assertArrayHasKey('first_name', $body);
        $this->assertArrayHasKey('last_name', $body);
    }

    /**
     * Login, PUT /api/company-profile bez X-CSRF-Token → 403.
     */
    public function test_put_company_profile_bez_csrf_vrati_403(): void
    {
        $this->login();

        $response = $this->http->request('PUT', '/api/company-profile', [
            'json' => ['company_name' => 'Test s.r.o.'],
            // Záměrně bez X-CSRF-Token hlavičky
        ]);

        $this->assertEquals(403, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    /**
     * Login, PUT s {company_name: "Test s.r.o.", ico: "12345678"} → 200,
     * následný GET obsahuje nové hodnoty.
     */
    public function test_put_company_profile_aktualizuje_udaje(): void
    {
        $this->login();

        $response = $this->http->request('PUT', '/api/company-profile', [
            'json' => [
                'company_name' => 'Test s.r.o.',
                'ico' => '12345678',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertEquals('Test s.r.o.', $body['company_name']);
        $this->assertEquals('12345678', $body['ico']);

        // Ověření přes GET
        $getResponse = $this->http->get('/api/company-profile');
        $this->assertEquals(200, $getResponse->getStatusCode());
        $getBody = json_decode((string) $getResponse->getBody(), true);
        $this->assertEquals('Test s.r.o.', $getBody['company_name']);
        $this->assertEquals('12345678', $getBody['ico']);
    }
}
