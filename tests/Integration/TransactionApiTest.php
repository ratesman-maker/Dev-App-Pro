<?php
declare(strict_types=1);

namespace DevAppPro\Tests\Integration;

use DevAppPro\Tests\TestCase;

/**
 * Integrační testy pro Transactions API (přes HTTP).
  * @group finance
 */
class TransactionApiTest extends TestCase
{
    /**
     * GET /api/transactions bez přihlášení → 401.
     */
    public function test_seznam_bez_prihlaseni_vrati_401(): void
    {
        $response = $this->http->get('/api/transactions');

        $this->assertEquals(401, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    /**
     * Login, GET /api/transactions → 200, má "data" a "total".
     */
    public function test_seznam_po_prihlaseni_vrati_200(): void
    {
        $this->login();

        $response = $this->http->get('/api/transactions');

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('total', $body);
    }

    /**
     * POST {type:"income", amount_cents:50000, category:"income_project", transaction_date:"2026-09-11"} → 201.
     */
    /**
     * @group smoke
     */
    public function test_vytvoreni_income(): void
    {
        $this->login();

        $response = $this->http->post('/api/transactions', [
            'json' => [
                'type'             => 'income',
                'amount_cents'     => 50000,
                'category'         => 'income_project',
                'transaction_date' => '2026-09-11',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('id', $body);
        $this->assertGreaterThan(0, $body['id']);
        $this->assertSame('income', $body['type']);
        $this->assertSame(50000, (int) $body['amount_cents']);
        $this->assertSame('income_project', $body['category']);
    }

    /**
     * POST {type:"expense", amount_cents:10000, category:"software", transaction_date:"2026-09-11"} → 201.
     */
    public function test_vytvoreni_expense(): void
    {
        $this->login();

        $response = $this->http->post('/api/transactions', [
            'json' => [
                'type'             => 'expense',
                'amount_cents'     => 10000,
                'category'         => 'software',
                'transaction_date' => '2026-09-11',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('id', $body);
        $this->assertGreaterThan(0, $body['id']);
        $this->assertSame('expense', $body['type']);
        $this->assertSame(10000, (int) $body['amount_cents']);
        $this->assertSame('software', $body['category']);
    }

    /**
     * POST bez type → 422.
     */
    public function test_vytvoreni_bez_type_vrati_422(): void
    {
        $this->login();

        $response = $this->http->post('/api/transactions', [
            'json' => [
                'amount_cents'     => 50000,
                'transaction_date' => '2026-09-11',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('fields', $body);
        $this->assertArrayHasKey('type', $body['fields']);
    }

    /**
     * POST bez amount_cents → 422.
     */
    public function test_vytvoreni_bez_amount_vrati_422(): void
    {
        $this->login();

        $response = $this->http->post('/api/transactions', [
            'json' => [
                'type'             => 'income',
                'transaction_date' => '2026-09-11',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('fields', $body);
        $this->assertArrayHasKey('amount_cents', $body['fields']);
    }

    /**
     * POST s amount_cents:0 → 422.
     */
    public function test_vytvoreni_s_nulovou_castkou_vrati_422(): void
    {
        $this->login();

        $response = $this->http->post('/api/transactions', [
            'json' => [
                'type'             => 'income',
                'amount_cents'     => 0,
                'transaction_date' => '2026-09-11',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('fields', $body);
        $this->assertArrayHasKey('amount_cents', $body['fields']);
    }

    /**
     * POST bez transaction_date → 422.
     */
    public function test_vytvoreni_bez_transaction_date_vrati_422(): void
    {
        $this->login();

        $response = $this->http->post('/api/transactions', [
            'json' => [
                'type'         => 'income',
                'amount_cents' => 50000,
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('fields', $body);
        $this->assertArrayHasKey('transaction_date', $body['fields']);
    }

    /**
     * POST {type:"income", category:"software"} → 422 (software je pro expense).
     */
    public function test_vytvoreni_s_neplatnou_kategorii_pro_typ_vrati_422(): void
    {
        $this->login();

        $response = $this->http->post('/api/transactions', [
            'json' => [
                'type'             => 'income',
                'amount_cents'     => 50000,
                'category'         => 'software',
                'transaction_date' => '2026-09-11',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('fields', $body);
        $this->assertArrayHasKey('category', $body['fields']);
    }

    /**
     * POST s neplatným project_id → 422.
     */
    public function test_vytvoreni_s_neplatnym_project_id_vrati_422(): void
    {
        $this->login();

        $response = $this->http->post('/api/transactions', [
            'json' => [
                'type'             => 'income',
                'amount_cents'     => 50000,
                'category'         => 'income_project',
                'transaction_date' => '2026-09-11',
                'project_id'        => 99999,
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
     * GET /api/transactions/99999 → 404.
     */
    public function test_detail_vrati_404(): void
    {
        $this->login();

        $response = $this->http->get('/api/transactions/99999');

        $this->assertEquals(404, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    /**
     * POST, GET /api/transactions/{id} → 200.
     */
    public function test_detail_po_vytvoreni(): void
    {
        $this->login();

        $createResponse = $this->http->post('/api/transactions', [
            'json' => [
                'type'             => 'income',
                'amount_cents'     => 50000,
                'category'         => 'income_project',
                'transaction_date' => '2026-09-11',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $created = json_decode((string) $createResponse->getBody(), true);
        $id = $created['id'];

        $response = $this->http->get('/api/transactions/' . $id);

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame($id, (int) $body['id']);
        $this->assertSame('income', $body['type']);
    }

    /**
     * POST, PUT s novým description → 200.
     */
    public function test_update_transakce(): void
    {
        $this->login();

        $createResponse = $this->http->post('/api/transactions', [
            'json' => [
                'type'             => 'expense',
                'amount_cents'     => 10000,
                'category'         => 'software',
                'transaction_date' => '2026-09-11',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $created = json_decode((string) $createResponse->getBody(), true);
        $id = $created['id'];

        $response = $this->http->request('PUT', '/api/transactions/' . $id, [
            'json' => [
                'description' => 'Nákup licence JetBrains',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('Nákup licence JetBrains', $body['description']);
    }

    /**
     * POST, DELETE → 204, GET → 404.
     */
    public function test_delete_transakce(): void
    {
        $this->login();

        $createResponse = $this->http->post('/api/transactions', [
            'json' => [
                'type'             => 'expense',
                'amount_cents'     => 10000,
                'category'         => 'software',
                'transaction_date' => '2026-09-11',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $created = json_decode((string) $createResponse->getBody(), true);
        $id = $created['id'];

        $deleteResponse = $this->http->request('DELETE', '/api/transactions/' . $id, [
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(204, $deleteResponse->getStatusCode());

        $getResponse = $this->http->get('/api/transactions/' . $id);
        $this->assertEquals(404, $getResponse->getStatusCode());
    }

    /**
     * Login, POST bez X-CSRF-Token → 403.
     */
    public function test_post_bez_csrf_vrati_403(): void
    {
        $this->login();

        $response = $this->http->post('/api/transactions', [
            'json' => [
                'type'             => 'income',
                'amount_cents'     => 50000,
                'category'         => 'income_project',
                'transaction_date' => '2026-09-11',
            ],
            // Záměrně bez X-CSRF-Token hlavičky
        ]);

        $this->assertEquals(403, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }
}
