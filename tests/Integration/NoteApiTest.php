<?php
declare(strict_types=1);

namespace DevAppPro\Tests\Integration;

use DevAppPro\Tests\TestCase;

/**
 * Integrační testy pro Notes API (přes HTTP).
 */
class NoteApiTest extends TestCase
{
    /**
     * Pomocná metoda - vytvoří klienta přes API a vrátí jeho ID.
     */
    private function createClient(): int
    {
        $response = $this->http->post('/api/clients', [
            'json' => [
                'type'       => 'individual',
                'first_name' => 'Jan',
                'last_name'  => 'Novák',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $body = json_decode((string) $response->getBody(), true);
        return (int) $body['id'];
    }

    /**
     * GET /api/notes bez přihlášení → 401.
     */
    public function test_seznam_bez_prihlaseni_vrati_401(): void
    {
        $response = $this->http->get('/api/notes');

        $this->assertEquals(401, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    /**
     * Login, GET /api/notes → 200, má "data" a "total".
     */
    public function test_seznam_po_prihlaseni_vrati_200(): void
    {
        $this->login();

        $response = $this->http->get('/api/notes');

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('total', $body);
    }

    /**
     * Login, POST {content:"Test"} → 201.
     */
    public function test_vytvoreni_poznamky(): void
    {
        $this->login();

        $response = $this->http->post('/api/notes', [
            'json' => ['content' => 'Test'],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('Test', $body['content']);
    }

    /**
     * Vytvořím klienta, POST {content, attachments} → 201, GET → attachments.
     */
    public function test_vytvoreni_s_attachments(): void
    {
        $this->login();
        $clientId = $this->createClient();

        $response = $this->http->post('/api/notes', [
            'json' => [
                'content'     => 'Poznámka s přílohou',
                'attachments' => [
                    ['entity_type' => 'client', 'entity_id' => $clientId],
                ],
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('attachments', $body);
        $this->assertCount(1, $body['attachments']);
        $this->assertSame('client', $body['attachments'][0]['entity_type']);
        $this->assertSame($clientId, (int) $body['attachments'][0]['entity_id']);

        // GET detail → attachments
        $getResp = $this->http->get('/api/notes/' . $body['id']);
        $getBody = json_decode((string) $getResp->getBody(), true);
        $this->assertCount(1, $getBody['attachments']);
        $this->assertSame('Jan Novák', $getBody['attachments'][0]['entity_name']);
    }

    /**
     * POST bez content → 422.
     */
    public function test_vytvoreni_bez_content_vrati_422(): void
    {
        $this->login();

        $response = $this->http->post('/api/notes', [
            'json' => ['title' => 'Bez obsahu'],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('fields', $body);
        $this->assertArrayHasKey('content', $body['fields']);
    }

    /**
     * GET /api/notes/99999 → 404.
     */
    public function test_detail_vrati_404(): void
    {
        $this->login();

        $response = $this->http->get('/api/notes/99999');

        $this->assertEquals(404, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    /**
     * POST vytvořím, GET → 200, má content.
     */
    public function test_detail_po_vytvoreni(): void
    {
        $this->login();

        $createResp = $this->http->post('/api/notes', [
            'json' => ['content' => 'Detail poznámky'],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $created = json_decode((string) $createResp->getBody(), true);
        $id = $created['id'];

        $response = $this->http->get('/api/notes/' . $id);

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('Detail poznámky', $body['content']);
    }

    /**
     * POST, PUT {content:"Změněno"} → 200, content se změnil.
     */
    public function test_update_poznamky(): void
    {
        $this->login();

        $createResp = $this->http->post('/api/notes', [
            'json' => ['content' => 'Původní'],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $created = json_decode((string) $createResp->getBody(), true);
        $id = $created['id'];

        $response = $this->http->request('PUT', '/api/notes/' . $id, [
            'json' => ['content' => 'Změněno'],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('Změněno', $body['content']);
    }

    /**
     * POST s 2 attachments, PUT s 1 → jen 1.
     */
    public function test_update_nahradi_attachments(): void
    {
        $this->login();
        $clientId1 = $this->createClient();
        $clientId2 = $this->http->post('/api/clients', [
            'json' => [
                'type'       => 'individual',
                'first_name' => 'Petr',
                'last_name'  => 'Svoboda',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $clientId2 = (int) json_decode((string) $clientId2->getBody(), true)['id'];

        $createResp = $this->http->post('/api/notes', [
            'json' => [
                'content'     => 'Poznámka',
                'attachments' => [
                    ['entity_type' => 'client', 'entity_id' => $clientId1],
                    ['entity_type' => 'client', 'entity_id' => $clientId2],
                ],
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $created = json_decode((string) $createResp->getBody(), true);
        $id = $created['id'];

        $this->assertCount(2, $created['attachments']);

        $response = $this->http->request('PUT', '/api/notes/' . $id, [
            'json' => [
                'content'     => 'Poznámka',
                'attachments' => [
                    ['entity_type' => 'client', 'entity_id' => $clientId1],
                ],
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertCount(1, $body['attachments']);
        $this->assertSame($clientId1, (int) $body['attachments'][0]['entity_id']);
    }

    /**
     * POST, DELETE → 204, GET → 404.
     */
    public function test_delete_poznamky(): void
    {
        $this->login();

        $createResp = $this->http->post('/api/notes', [
            'json' => ['content' => 'Smazat'],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $created = json_decode((string) $createResp->getBody(), true);
        $id = $created['id'];

        $deleteResp = $this->http->request('DELETE', '/api/notes/' . $id, [
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(204, $deleteResp->getStatusCode());

        $getResp = $this->http->get('/api/notes/' . $id);
        $this->assertEquals(404, $getResp->getStatusCode());
    }

    /**
     * Login, POST bez X-CSRF-Token → 403.
     */
    public function test_post_bez_csrf_vrati_403(): void
    {
        $this->login();

        $response = $this->http->post('/api/notes', [
            'json' => ['content' => 'Bez CSRF'],
            // Záměrně bez X-CSRF-Token hlavičky
        ]);

        $this->assertEquals(403, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }
}
