<?php
declare(strict_types=1);

namespace DevAppPro\Tests\Integration;

use DevAppPro\Tests\TestCase;

/**
 * Integrační testy pro Tasks API (přes HTTP).
 */
class TaskApiTest extends TestCase
{
    /**
     * Pomocná metoda - vytvoří projekt přes API a vrátí jeho ID.
     */
    private function createProject(): int
    {
        $response = $this->http->post('/api/projects', [
            'json' => [
                'name' => 'Test projekt',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $body = json_decode((string) $response->getBody(), true);
        return (int) $body['id'];
    }

    /**
     * GET /api/tasks bez přihlášení → 401.
     */
    public function test_seznam_bez_prihlaseni_vrati_401(): void
    {
        $response = $this->http->get('/api/tasks');

        $this->assertEquals(401, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    /**
     * Login, GET /api/tasks → 200, má "data" a "total".
     */
    public function test_seznam_po_prihlaseni_vrati_200(): void
    {
        $this->login();

        $response = $this->http->get('/api/tasks');

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('total', $body);
    }

    /**
     * Vytvořím projekt, POST {project_id, title} → 201.
     */
    public function test_vytvoreni_ukolu(): void
    {
        $this->login();
        $projectId = $this->createProject();

        $response = $this->http->post('/api/tasks', [
            'json' => [
                'project_id' => $projectId,
                'title'      => 'Napsat testy',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('id', $body);
        $this->assertGreaterThan(0, $body['id']);
        $this->assertSame('Napsat testy', $body['title']);
        $this->assertSame('todo', $body['status']);
    }

    /**
     * POST bez project_id → 422.
     */
    public function test_vytvoreni_bez_project_id_vrati_422(): void
    {
        $this->login();

        $response = $this->http->post('/api/tasks', [
            'json' => [
                'title' => 'Napsat testy',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('fields', $body);
        $this->assertArrayHasKey('project_id', $body['fields']);
    }

    /**
     * POST bez title → 422.
     */
    public function test_vytvoreni_bez_titlu_vrati_422(): void
    {
        $this->login();
        $projectId = $this->createProject();

        $response = $this->http->post('/api/tasks', [
            'json' => [
                'project_id' => $projectId,
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('fields', $body);
        $this->assertArrayHasKey('title', $body['fields']);
    }

    /**
     * POST s project_id=99999 → 422.
     */
    public function test_vytvoreni_s_neplatnym_project_id_vrati_422(): void
    {
        $this->login();

        $response = $this->http->post('/api/tasks', [
            'json' => [
                'project_id' => 99999,
                'title'      => 'Napsat testy',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('fields', $body);
        $this->assertArrayHasKey('project_id', $body['fields']);
    }

    /**
     * GET /api/tasks/99999 → 404.
     */
    public function test_detail_vrati_404(): void
    {
        $this->login();

        $response = $this->http->get('/api/tasks/99999');

        $this->assertEquals(404, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    /**
     * POST, GET /api/tasks/{id} → 200.
     */
    public function test_detail_po_vytvoreni(): void
    {
        $this->login();
        $projectId = $this->createProject();

        $createResponse = $this->http->post('/api/tasks', [
            'json' => [
                'project_id' => $projectId,
                'title'      => 'Napsat testy',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $created = json_decode((string) $createResponse->getBody(), true);
        $id = $created['id'];

        $response = $this->http->get('/api/tasks/' . $id);

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('Napsat testy', $body['title']);
        $this->assertSame($id, (int) $body['id']);
    }

    /**
     * POST, PUT s novým title → 200.
     */
    public function test_update_ukolu(): void
    {
        $this->login();
        $projectId = $this->createProject();

        $createResponse = $this->http->post('/api/tasks', [
            'json' => [
                'project_id' => $projectId,
                'title'      => 'Napsat testy',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $created = json_decode((string) $createResponse->getBody(), true);
        $id = $created['id'];

        $response = $this->http->request('PUT', '/api/tasks/' . $id, [
            'json' => [
                'title' => 'Napsat testy v2',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('Napsat testy v2', $body['title']);
    }

    /**
     * POST, PUT {status:"done"} → 200, status='done'.
     */
    public function test_update_status_ukolu(): void
    {
        $this->login();
        $projectId = $this->createProject();

        $createResponse = $this->http->post('/api/tasks', [
            'json' => [
                'project_id' => $projectId,
                'title'      => 'Napsat testy',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $created = json_decode((string) $createResponse->getBody(), true);
        $id = $created['id'];

        $response = $this->http->request('PUT', '/api/tasks/' . $id, [
            'json' => [
                'status' => 'done',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('done', $body['status']);
    }

    /**
     * POST, DELETE → 204, GET → 404.
     */
    public function test_delete_ukolu(): void
    {
        $this->login();
        $projectId = $this->createProject();

        $createResponse = $this->http->post('/api/tasks', [
            'json' => [
                'project_id' => $projectId,
                'title'      => 'Napsat testy',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $created = json_decode((string) $createResponse->getBody(), true);
        $id = $created['id'];

        $deleteResponse = $this->http->request('DELETE', '/api/tasks/' . $id, [
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(204, $deleteResponse->getStatusCode());

        $getResponse = $this->http->get('/api/tasks/' . $id);
        $this->assertEquals(404, $getResponse->getStatusCode());
    }

    /**
     * Login, POST bez X-CSRF-Token → 403.
     */
    public function test_post_bez_csrf_vrati_403(): void
    {
        $this->login();
        $projectId = $this->createProject();

        $response = $this->http->post('/api/tasks', [
            'json' => [
                'project_id' => $projectId,
                'title'      => 'Napsat testy',
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
        $projectId = $this->createProject();

        // Nejprve vytvořit úkol (s CSRF)
        $createResponse = $this->http->post('/api/tasks', [
            'json' => [
                'project_id' => $projectId,
                'title'      => 'Napsat testy',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $created = json_decode((string) $createResponse->getBody(), true);
        $id = $created['id'];

        // DELETE bez CSRF
        $response = $this->http->request('DELETE', '/api/tasks/' . $id, [
            // Záměrně bez X-CSRF-Token hlavičky
        ]);

        $this->assertEquals(403, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }
}
