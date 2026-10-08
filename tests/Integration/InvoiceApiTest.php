<?php
declare(strict_types=1);

namespace DevAppPro\Tests\Integration;

use DevAppPro\Tests\TestCase;

/**
 * Integrační testy pro Invoices API (přes HTTP).
  * @group invoices
 */
class InvoiceApiTest extends TestCase
{
    /**
     * GET /api/invoices bez přihlášení → 401.
     */
    public function test_seznam_bez_prihlaseni_vrati_401(): void
    {
        $response = $this->http->get('/api/invoices');

        $this->assertEquals(401, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    /**
     * Login, GET /api/invoices → 200, má "data" a "total".
     */
    public function test_seznam_po_prihlaseni_vrati_200(): void
    {
        $this->login();

        $response = $this->http->get('/api/invoices');

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('total', $body);
    }

    /**
     * POST vytvoření faktury → 201, vat_amount_cents=21000, amount_cents=121000.
     */
    /**
     * @group smoke
     */
    public function test_vytvoreni_faktury(): void
    {
        $this->login();

        $response = $this->http->post('/api/invoices', [
            'json' => [
                'invoice_number'   => '2026001',
                'subtotal_cents'    => 100000,
                'vat_rate_percent'  => 21,
                'issue_date'        => '2026-09-11',
                'due_date'          => '2026-09-25',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('2026001', $body['invoice_number']);
        $this->assertSame(21000, (int) $body['vat_amount_cents']);
        $this->assertSame(121000, (int) $body['amount_cents']);
    }

    /**
     * POST bez invoice_number → 201 s automaticky vygenerovaným číslem.
     */
    public function test_vytvoreni_bez_invoice_number_vygeneruje_cislo(): void
    {
        $this->login();

        $response = $this->http->post('/api/invoices', [
            'json' => [
                'subtotal_cents'    => 100000,
                'vat_rate_percent'  => 21,
                'issue_date'        => '2026-09-11',
                'due_date'          => '2026-09-25',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertNotEmpty($body['invoice_number']);
    }

    /**
     * POST bez subtotal_cents → 422.
     */
    public function test_vytvoreni_bez_subtotal_vrati_422(): void
    {
        $this->login();

        $response = $this->http->post('/api/invoices', [
            'json' => [
                'invoice_number'   => '2026001',
                'vat_rate_percent'  => 21,
                'issue_date'        => '2026-09-11',
                'due_date'          => '2026-09-25',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('fields', $body);
        $this->assertArrayHasKey('subtotal_cents', $body['fields']);
    }

    /**
     * POST s neplatnou sazbou DPH (150) → 422.
     */
    public function test_vytvoreni_s_neplatnou_dph_vrati_422(): void
    {
        $this->login();

        $response = $this->http->post('/api/invoices', [
            'json' => [
                'invoice_number'   => '2026001',
                'subtotal_cents'    => 100000,
                'vat_rate_percent'  => 150,
                'issue_date'        => '2026-09-11',
                'due_date'          => '2026-09-25',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('fields', $body);
        $this->assertArrayHasKey('vat_rate_percent', $body['fields']);
    }

    /**
     * GET /api/invoices/99999 → 404.
     */
    public function test_detail_vrati_404(): void
    {
        $this->login();

        $response = $this->http->get('/api/invoices/99999');

        $this->assertEquals(404, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    /**
     * POST vytvořím, GET /api/invoices/{id} → 200.
     */
    public function test_detail_po_vytvoreni(): void
    {
        $this->login();

        $createResponse = $this->http->post('/api/invoices', [
            'json' => [
                'invoice_number'   => '2026001',
                'subtotal_cents'    => 100000,
                'vat_rate_percent'  => 21,
                'issue_date'        => '2026-09-11',
                'due_date'          => '2026-09-25',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $created = json_decode((string) $createResponse->getBody(), true);
        $id = $created['id'];

        $response = $this->http->get('/api/invoices/' . $id);

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('2026001', $body['invoice_number']);
        $this->assertSame(121000, (int) $body['amount_cents']);
    }

    /**
     * POST vytvořím, PUT s novým subtotal → 200, amount_cents se přepočítá.
     */
    public function test_update_faktury(): void
    {
        $this->login();

        $createResponse = $this->http->post('/api/invoices', [
            'json' => [
                'invoice_number'   => '2026001',
                'subtotal_cents'    => 100000,
                'vat_rate_percent'  => 21,
                'issue_date'        => '2026-09-11',
                'due_date'          => '2026-09-25',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $created = json_decode((string) $createResponse->getBody(), true);
        $id = $created['id'];

        $response = $this->http->request('PUT', '/api/invoices/' . $id, [
            'json' => [
                'invoice_number'   => '2026001',
                'subtotal_cents'    => 200000,
                'vat_rate_percent'  => 21,
                'issue_date'        => '2026-09-11',
                'due_date'          => '2026-09-25',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(200000, (int) $body['subtotal_cents']);
        $this->assertSame(42000, (int) $body['vat_amount_cents']);
        $this->assertSame(242000, (int) $body['amount_cents']);
    }

    /**
     * PUT pouze se statusem (částečný update) → 200, status se změní,
     * ostatní pole zůstanou.
     */
    public function test_castecny_update_statusu(): void
    {
        $this->login();

        $createResponse = $this->http->post('/api/invoices', [
            'json' => [
                'invoice_number'   => '2026007',
                'subtotal_cents'    => 100000,
                'vat_rate_percent'  => 21,
                'issue_date'        => '2026-09-11',
                'due_date'          => '2026-09-25',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $created = json_decode((string) $createResponse->getBody(), true);
        $id = $created['id'];

        // Jen status
        $response = $this->http->request('PUT', '/api/invoices/' . $id, [
            'json' => ['status' => 'paid'],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('paid', $body['status']);
        $this->assertSame('2026007', $body['invoice_number']);
        $this->assertSame(121000, (int) $body['amount_cents']);
    }

    /**
     * GET /api/invoices?project_id={id} vrací jen faktury daného projektu.
     */
    public function test_seznam_filtruje_podle_projektu(): void
    {
        $this->login();

        // Projekt
        $projResponse = $this->http->post('/api/projects', [
            'json' => ['name' => 'Test projekt'],
            'headers' => ['X-CSRF-Token' => $this->csrfToken],
        ]);
        $projectId = json_decode((string) $projResponse->getBody(), true)['id'];

        // Faktura A s projektem
        $this->http->post('/api/invoices', [
            'json' => [
                'invoice_number'   => '2026010',
                'project_id'       => $projectId,
                'subtotal_cents'   => 100000,
                'vat_rate_percent' => 21,
                'issue_date'       => '2026-09-11',
                'due_date'         => '2026-09-25',
            ],
            'headers' => ['X-CSRF-Token' => $this->csrfToken],
        ]);

        // Faktura B bez projektu
        $this->http->post('/api/invoices', [
            'json' => [
                'invoice_number'   => '2026011',
                'subtotal_cents'   => 100000,
                'vat_rate_percent' => 21,
                'issue_date'       => '2026-09-11',
                'due_date'         => '2026-09-25',
            ],
            'headers' => ['X-CSRF-Token' => $this->csrfToken],
        ]);

        $response = $this->http->get('/api/invoices?project_id=' . $projectId);

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(1, (int) $body['total']);
        $this->assertSame('2026010', $body['data'][0]['invoice_number']);
    }

    /**
     * POST vytvořím, DELETE → 204, GET → 404.
     */
    public function test_delete_faktury(): void
    {
        $this->login();

        $createResponse = $this->http->post('/api/invoices', [
            'json' => [
                'invoice_number'   => '2026001',
                'subtotal_cents'    => 100000,
                'vat_rate_percent'  => 21,
                'issue_date'        => '2026-09-11',
                'due_date'          => '2026-09-25',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $created = json_decode((string) $createResponse->getBody(), true);
        $id = $created['id'];

        $deleteResponse = $this->http->request('DELETE', '/api/invoices/' . $id, [
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(204, $deleteResponse->getStatusCode());

        $getResponse = $this->http->get('/api/invoices/' . $id);
        $this->assertEquals(404, $getResponse->getStatusCode());
    }

    /**
     * POST s položkami (items) → 201, subtotal spočítán z položek,
     * taxable_date default = issue_date, položky vráceny v detailu.
     */
    public function test_vytvoreni_s_polozkami(): void
    {
        $this->login();

        $response = $this->http->post('/api/invoices', [
            'json' => [
                'invoice_number'   => '2026002',
                'vat_rate_percent'  => 21,
                'issue_date'        => '2026-09-11',
                'due_date'          => '2026-09-25',
                'items' => [
                    ['description' => 'Tvorba webu', 'quantity' => 1, 'unit' => 'ks', 'unit_price_cents' => 100000],
                    ['description' => 'Správa webu', 'quantity' => 3, 'unit' => 'hod', 'unit_price_cents' => 50000],
                ],
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        // 100000 + 3*50000 = 250000; DPH 21 % = 52500; celkem 302500
        $this->assertSame(250000, (int) $body['subtotal_cents']);
        $this->assertSame(52500, (int) $body['vat_amount_cents']);
        $this->assertSame(302500, (int) $body['amount_cents']);
        $this->assertSame('2026-09-11', $body['taxable_date']);
        $this->assertCount(2, $body['items']);
        $this->assertSame('Správa webu', $body['items'][1]['description']);
        $this->assertSame(50000, (int) $body['items'][1]['unit_price_cents']);

        // Detail vrátí položky
        $detail = $this->http->get('/api/invoices/' . $body['id']);
        $detailBody = json_decode((string) $detail->getBody(), true);
        $this->assertCount(2, $detailBody['items']);
    }

    /**
     * POST s položkou bez popisu → 422.
     */
    public function test_polozka_bez_popisu_vrati_422(): void
    {
        $this->login();

        $response = $this->http->post('/api/invoices', [
            'json' => [
                'invoice_number'   => '2026003',
                'vat_rate_percent'  => 21,
                'issue_date'        => '2026-09-11',
                'due_date'          => '2026-09-25',
                'items' => [
                    ['description' => '', 'quantity' => 1, 'unit' => 'ks', 'unit_price_cents' => 100000],
                ],
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('items', $body['fields']);
    }

    /**
     * POST s nulovým množstvím → 422.
     */
    public function test_polozka_s_nulovym_mnozstvim_vrati_422(): void
    {
        $this->login();

        $response = $this->http->post('/api/invoices', [
            'json' => [
                'invoice_number'   => '2026004',
                'vat_rate_percent'  => 21,
                'issue_date'        => '2026-09-11',
                'due_date'          => '2026-09-25',
                'items' => [
                    ['description' => 'Služba', 'quantity' => 0, 'unit' => 'ks', 'unit_price_cents' => 100000],
                ],
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('items', $body['fields']);
    }

    /**
     * POST s taxable_date → 201, taxable_date persistován; PUT změní položky.
     */
    public function test_taxable_date_a_update_polozek(): void
    {
        $this->login();

        $createResponse = $this->http->post('/api/invoices', [
            'json' => [
                'invoice_number'   => '2026005',
                'vat_rate_percent'  => 15,
                'issue_date'        => '2026-09-11',
                'due_date'          => '2026-09-25',
                'taxable_date'      => '2026-09-15',
                'items' => [
                    ['description' => 'Konzultace', 'quantity' => 2, 'unit' => 'hod', 'unit_price_cents' => 80000],
                ],
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $created = json_decode((string) $createResponse->getBody(), true);
        $id = $created['id'];
        $this->assertSame('2026-09-15', $created['taxable_date']);
        $this->assertSame(160000, (int) $created['subtotal_cents']);

        // PUT: změna položek → přepočet
        $updateResponse = $this->http->request('PUT', '/api/invoices/' . $id, [
            'json' => [
                'invoice_number'   => '2026005',
                'vat_rate_percent'  => 15,
                'issue_date'        => '2026-09-11',
                'due_date'          => '2026-09-25',
                'taxable_date'      => '2026-09-16',
                'items' => [
                    ['description' => 'Konzultace', 'quantity' => 3, 'unit' => 'hod', 'unit_price_cents' => 80000],
                ],
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $this->assertEquals(200, $updateResponse->getStatusCode());
        $updated = json_decode((string) $updateResponse->getBody(), true);
        $this->assertSame(240000, (int) $updated['subtotal_cents']);
        $this->assertSame('2026-09-16', $updated['taxable_date']);
        $this->assertCount(1, $updated['items']);
        $this->assertEquals(3, (float) $updated['items'][0]['quantity']);
    }

    /**
     * GET /api/invoices/{id}/pdf → 200, application/pdf, začíná %PDF.
     */
    public function test_pdf_faktury(): void
    {
        $this->login();

        $createResponse = $this->http->post('/api/invoices', [
            'json' => [
                'invoice_number'   => '2026006',
                'vat_rate_percent'  => 21,
                'issue_date'        => '2026-09-11',
                'due_date'          => '2026-09-25',
                'items' => [
                    ['description' => 'Služba', 'quantity' => 1, 'unit' => 'ks', 'unit_price_cents' => 100000],
                ],
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $created = json_decode((string) $createResponse->getBody(), true);
        $id = $created['id'];

        $response = $this->http->get('/api/invoices/' . $id . '/pdf');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('application/pdf', (string) $response->getHeaderLine('Content-Type'));
        $body = (string) $response->getBody();
        $this->assertStringStartsWith('%PDF', $body);
        $this->assertGreaterThan(10000, strlen($body));
    }

    /**
     * Login, POST bez X-CSRF-Token → 403.
     */
    public function test_post_bez_csrf_vrati_403(): void
    {
        $this->login();

        $response = $this->http->post('/api/invoices', [
            'json' => [
                'invoice_number'   => '2026001',
                'subtotal_cents'    => 100000,
                'vat_rate_percent'  => 21,
                'issue_date'        => '2026-09-11',
                'due_date'          => '2026-09-25',
            ],
            // Záměrně bez X-CSRF-Token hlavičky
        ]);

        $this->assertEquals(403, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    /**
     * PUT status=sent zmrazí PDF do storage/invoices/, GET .../pdf
     * servíruje přesně archivní kopii, DELETE soubor uklidí.
     */
    public function test_odeslani_faktury_zmrazi_pdf(): void
    {
        $this->login();

        $createResponse = $this->http->post('/api/invoices', [
            'json' => [
                'invoice_number'   => '2099001',
                'subtotal_cents'    => 100000,
                'vat_rate_percent'  => 21,
                'issue_date'        => '2026-09-11',
                'due_date'          => '2026-09-25',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $created = json_decode((string) $createResponse->getBody(), true);
        $id = $created['id'];
        $frozenPath = dirname(__DIR__, 2) . '/storage/invoices/faktura-2099001.pdf';

        try {
            $response = $this->http->request('PUT', '/api/invoices/' . $id, [
                'json' => ['status' => 'sent'],
                'headers' => [
                    'X-CSRF-Token' => $this->csrfToken,
                ],
            ]);

            $this->assertEquals(200, $response->getStatusCode());
            $body = json_decode((string) $response->getBody(), true);
            $this->assertSame('sent', $body['status']);
            $this->assertSame('invoices/faktura-2099001.pdf', $body['frozen_pdf']);
            $this->assertFileExists($frozenPath);

            $pdfResponse = $this->http->get('/api/invoices/' . $id . '/pdf');
            $this->assertEquals(200, $pdfResponse->getStatusCode());
            $this->assertSame(
                file_get_contents($frozenPath),
                (string) $pdfResponse->getBody()
            );

            $delResponse = $this->http->request('DELETE', '/api/invoices/' . $id, [
                'headers' => [
                    'X-CSRF-Token' => $this->csrfToken,
                ],
            ]);
            $this->assertEquals(204, $delResponse->getStatusCode());
            $this->assertFileDoesNotExist($frozenPath);
        } finally {
            if (is_file($frozenPath)) {
                unlink($frozenPath);
            }
        }
    }
}
