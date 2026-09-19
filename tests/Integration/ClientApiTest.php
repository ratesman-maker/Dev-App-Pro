<?php
declare(strict_types=1);

namespace DevAppPro\Tests\Integration;

use DevAppPro\Tests\TestCase;

/**
 * Integrační testy pro Clients API (přes HTTP).
  * @group clients
 */
class ClientApiTest extends TestCase
{
    /**
     * GET /api/clients bez přihlášení → 401.
     */
    public function test_seznam_bez_prihlaseni_vrati_401(): void
    {
        $response = $this->http->get('/api/clients');

        $this->assertEquals(401, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    /**
     * Login, GET /api/clients → 200, má "data" a "total".
     */
    public function test_seznam_po_prihlaseni_vrati_200(): void
    {
        $this->login();

        $response = $this->http->get('/api/clients');

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('total', $body);
    }

    /**
     * Login, POST osoba → 201, má full_name="Jan Novák".
     */
    /**
     * @group smoke
     */
    public function test_vytvoreni_osoby(): void
    {
        $this->login();

        $response = $this->http->post('/api/clients', [
            'json' => [
                'type'       => 'individual',
                'first_name' => 'Jan',
                'last_name'  => 'Novák',
                'email'      => 'jan.novak@example.cz',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('Jan Novák', $body['full_name']);
        $this->assertSame('individual', $body['type']);
    }

    /**
     * Login, POST firma → 201, má full_name="Novák s.r.o.".
     */
    public function test_vytvoreni_firmy(): void
    {
        $this->login();

        $response = $this->http->post('/api/clients', [
            'json' => [
                'type'         => 'company',
                'company_name' => 'Novák s.r.o.',
                'ico'          => '12345678',
                'dic'          => 'CZ12345678',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('Novák s.r.o.', $body['full_name']);
        $this->assertSame('company', $body['type']);
    }

    /**
     * POST osoba bez příjmení → 422, fields obsahuje last_name.
     */
    public function test_osoba_bez_prijmeni_vrati_422(): void
    {
        $this->login();

        $response = $this->http->post('/api/clients', [
            'json' => [
                'type'       => 'individual',
                'first_name' => 'Jan',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('fields', $body);
        $this->assertArrayHasKey('last_name', $body['fields']);
    }

    /**
     * POST firma bez názvu → 422.
     */
    public function test_firma_bez_nazvu_vrati_422(): void
    {
        $this->login();

        $response = $this->http->post('/api/clients', [
            'json' => [
                'type' => 'company',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('fields', $body);
        $this->assertArrayHasKey('company_name', $body['fields']);
    }

    /**
     * POST firma s neplatným IČO → 422.
     */
    public function test_neplatne_ico_vrati_422(): void
    {
        $this->login();

        $response = $this->http->post('/api/clients', [
            'json' => [
                'type'         => 'company',
                'company_name' => 'X',
                'ico'          => '123',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('fields', $body);
        $this->assertArrayHasKey('ico', $body['fields']);
    }

    /**
     * POST osoba s neplatným e-mailem → 422.
     */
    public function test_neplatny_email_vrati_422(): void
    {
        $this->login();

        $response = $this->http->post('/api/clients', [
            'json' => [
                'type'       => 'individual',
                'first_name' => 'A',
                'last_name'  => 'B',
                'email'      => 'neplatny',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('fields', $body);
        $this->assertArrayHasKey('email', $body['fields']);
    }

    /**
     * GET /api/clients/99999 → 404.
     */
    public function test_detail_vrati_404_pro_neexistujici(): void
    {
        $this->login();

        $response = $this->http->get('/api/clients/99999');

        $this->assertEquals(404, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    /**
     * POST vytvořím, GET /api/clients/{id} → 200, má full_name.
     */
    public function test_detail_po_vytvoreni(): void
    {
        $this->login();

        $createResponse = $this->http->post('/api/clients', [
            'json' => [
                'type'       => 'individual',
                'first_name' => 'Jan',
                'last_name'  => 'Novák',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $created = json_decode((string) $createResponse->getBody(), true);
        $id = $created['id'];

        $response = $this->http->get('/api/clients/' . $id);

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('Jan Novák', $body['full_name']);
    }

    /**
     * POST vytvořím, PUT s novým last_name → 200, ověřím změnu.
     */
    public function test_update_klienta(): void
    {
        $this->login();

        $createResponse = $this->http->post('/api/clients', [
            'json' => [
                'type'       => 'individual',
                'first_name' => 'Jan',
                'last_name'  => 'Novák',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $created = json_decode((string) $createResponse->getBody(), true);
        $id = $created['id'];

        $response = $this->http->request('PUT', '/api/clients/' . $id, [
            'json' => [
                'type'       => 'individual',
                'first_name' => 'Jan',
                'last_name'  => 'Svoboda',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('Svoboda', $body['last_name']);
        $this->assertSame('Jan Svoboda', $body['full_name']);
    }

    /**
     * POST vytvořím, DELETE → 204, GET → 404.
     */
    public function test_delete_klienta(): void
    {
        $this->login();

        $createResponse = $this->http->post('/api/clients', [
            'json' => [
                'type'       => 'individual',
                'first_name' => 'Jan',
                'last_name'  => 'Novák',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $created = json_decode((string) $createResponse->getBody(), true);
        $id = $created['id'];

        $deleteResponse = $this->http->request('DELETE', '/api/clients/' . $id, [
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(204, $deleteResponse->getStatusCode());

        $getResponse = $this->http->get('/api/clients/' . $id);
        $this->assertEquals(404, $getResponse->getStatusCode());
    }

    /**
     * Login, POST bez X-CSRF-Token → 403.
     */
    public function test_post_bez_csrf_vrati_403(): void
    {
        $this->login();

        $response = $this->http->post('/api/clients', [
            'json' => [
                'type'       => 'individual',
                'first_name' => 'Jan',
                'last_name'  => 'Novák',
            ],
            // Záměrně bez X-CSRF-Token hlavičky
        ]);

        $this->assertEquals(403, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    /**
     * Login, PUT bez X-CSRF-Token → 403.
     */
    public function test_put_bez_csrf_vrati_403(): void
    {
        $this->login();

        $response = $this->http->request('PUT', '/api/clients/1', [
            'json' => [
                'type'       => 'individual',
                'first_name' => 'Jan',
                'last_name'  => 'Novák',
            ],
            // Záměrně bez X-CSRF-Token hlavičky
        ]);

        $this->assertEquals(403, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    /**
     * Login, DELETE bez X-CSRF-Token → 403.
     */
    public function test_delete_bez_csrf_vrati_403(): void
    {
        $this->login();

        $response = $this->http->request('DELETE', '/api/clients/1', [
            // Záměrně bez X-CSRF-Token hlavičky
        ]);

        $this->assertEquals(403, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }
}
