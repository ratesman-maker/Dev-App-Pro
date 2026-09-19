<?php
declare(strict_types=1);

namespace DevAppPro\Tests\Integration;

use DevAppPro\Tests\TestCase;

/**
 * Integrační testy pro Projects API (přes HTTP).
 */
class ProjectApiTest extends TestCase
{
    /**
     * GET /api/projects bez přihlášení → 401.
     */
    public function test_seznam_bez_prihlaseni_vrati_401(): void
    {
        $response = $this->http->get('/api/projects');

        $this->assertEquals(401, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    /**
     * Login, GET /api/projects → 200, má "data" a "total".
     */
    public function test_seznam_po_prihlaseni_vrati_200(): void
    {
        $this->login();

        $response = $this->http->get('/api/projects');

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('total', $body);
    }

    /**
     * Login, POST {name:"Web redesign"} → 201, má id, status='active'.
     */
    public function test_vytvoreni_projektu(): void
    {
        $this->login();

        $response = $this->http->post('/api/projects', [
            'json' => [
                'name' => 'Web redesign',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('id', $body);
        $this->assertGreaterThan(0, $body['id']);
        $this->assertSame('active', $body['status']);
        $this->assertSame('Web redesign', $body['name']);
    }

    /**
     * Vytvořím klienta, POST {client_id: X, name:"X"} → 201, GET → client_name.
     */
    public function test_vytvoreni_s_clientem(): void
    {
        $this->login();

        // Vytvoření klienta
        $clientResponse = $this->http->post('/api/clients', [
            'json' => [
                'type'       => 'individual',
                'first_name' => 'Jan',
                'last_name'  => 'Novák',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $client = json_decode((string) $clientResponse->getBody(), true);
        $clientId = $client['id'];

        // Vytvoření projektu s client_id
        $response = $this->http->post('/api/projects', [
            'json' => [
                'name'      => 'Web redesign',
                'client_id' => $clientId,
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame($clientId, (int) $body['client_id']);

        // GET detail → client_name
        $detailResponse = $this->http->get('/api/projects/' . $body['id']);
        $detail = json_decode((string) $detailResponse->getBody(), true);
        $this->assertSame('Jan Novák', $detail['client_name']);
    }

    /**
     * POST {} → 422, fields.name.
     */
    public function test_vytvoreni_bez_jmena_vrati_422(): void
    {
        $this->login();

        $response = $this->http->post('/api/projects', [
            'json' => [],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('fields', $body);
        $this->assertArrayHasKey('name', $body['fields']);
    }

    /**
     * POST {name:"X", client_id: 99999} → 422.
     */
    public function test_vytvoreni_s_neplatnym_client_id_vrati_422(): void
    {
        $this->login();

        $response = $this->http->post('/api/projects', [
            'json' => [
                'name'      => 'Test projekt',
                'client_id' => 99999,
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('fields', $body);
        $this->assertArrayHasKey('client_id', $body['fields']);
    }

    /**
     * GET /api/projects/99999 → 404.
     */
    public function test_detail_vrati_404(): void
    {
        $this->login();

        $response = $this->http->get('/api/projects/99999');

        $this->assertEquals(404, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    /**
     * POST, GET /api/projects/{id} → 200.
     */
    public function test_detail_po_vytvoreni(): void
    {
        $this->login();

        $createResponse = $this->http->post('/api/projects', [
            'json' => [
                'name' => 'Web redesign',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $created = json_decode((string) $createResponse->getBody(), true);
        $id = $created['id'];

        $response = $this->http->get('/api/projects/' . $id);

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('Web redesign', $body['name']);
        $this->assertSame($id, (int) $body['id']);
    }

    /**
     * POST, PUT s novým name → 200, ověřím změnu.
     */
    public function test_update_projektu(): void
    {
        $this->login();

        $createResponse = $this->http->post('/api/projects', [
            'json' => [
                'name' => 'Web redesign',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $created = json_decode((string) $createResponse->getBody(), true);
        $id = $created['id'];

        $response = $this->http->request('PUT', '/api/projects/' . $id, [
            'json' => [
                'name' => 'Web redesign v2',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('Web redesign v2', $body['name']);
    }

    /**
     * POST, POST /api/projects/{id}/archive → 200, status='archived'.
     */
    public function test_archive_projektu(): void
    {
        $this->login();

        $createResponse = $this->http->post('/api/projects', [
            'json' => [
                'name' => 'Web redesign',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $created = json_decode((string) $createResponse->getBody(), true);
        $id = $created['id'];

        $response = $this->http->post('/api/projects/' . $id . '/archive', [
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame($id, (int) $body['id']);
        $this->assertSame('archived', $body['status']);
    }

    /**
     * POST, archive, POST /api/projects/{id}/restore → 200, status='active'.
     */
    public function test_restore_projektu(): void
    {
        $this->login();

        $createResponse = $this->http->post('/api/projects', [
            'json' => [
                'name' => 'Web redesign',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $created = json_decode((string) $createResponse->getBody(), true);
        $id = $created['id'];

        // Archivace
        $this->http->post('/api/projects/' . $id . '/archive', [
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        // Obnova
        $response = $this->http->post('/api/projects/' . $id . '/restore', [
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame($id, (int) $body['id']);
        $this->assertSame('active', $body['status']);
    }

    /**
     * POST, DELETE → 202 (async job), projekt smazán z DB, GET → 404.
     */
    public function test_delete_projektu(): void
    {
        $this->login();

        $createResponse = $this->http->post('/api/projects', [
            'json' => [
                'name' => 'Web redesign',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $created = json_decode((string) $createResponse->getBody(), true);
        $id = $created['id'];

        $deleteResponse = $this->http->request('DELETE', '/api/projects/' . $id, [
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(202, $deleteResponse->getStatusCode());
        $body = json_decode((string) $deleteResponse->getBody(), true);
        $this->assertArrayHasKey('job_id', $body);

        $getResponse = $this->http->get('/api/projects/' . $id);
        $this->assertEquals(404, $getResponse->getStatusCode());
    }

    /**
     * Login, POST bez X-CSRF-Token → 403.
     */
    public function test_post_bez_csrf_vrati_403(): void
    {
        $this->login();

        $response = $this->http->post('/api/projects', [
            'json' => [
                'name' => 'Web redesign',
            ],
            // Záměrně bez X-CSRF-Token hlavičky
        ]);

        $this->assertEquals(403, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    /**
     * Login, POST archive bez X-CSRF-Token → 403.
     */
    public function test_archive_bez_csrf_vrati_403(): void
    {
        $this->login();

        // Nejprve vytvořit projekt (s CSRF)
        $createResponse = $this->http->post('/api/projects', [
            'json' => [
                'name' => 'Web redesign',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $created = json_decode((string) $createResponse->getBody(), true);
        $id = $created['id'];

        // Archive bez CSRF
        $response = $this->http->post('/api/projects/' . $id . '/archive', [
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

        // Nejprve vytvořit projekt (s CSRF)
        $createResponse = $this->http->post('/api/projects', [
            'json' => [
                'name' => 'Web redesign',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $created = json_decode((string) $createResponse->getBody(), true);
        $id = $created['id'];

        // DELETE bez CSRF
        $response = $this->http->request('DELETE', '/api/projects/' . $id, [
            // Záměrně bez X-CSRF-Token hlavičky
        ]);

        $this->assertEquals(403, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }
}
