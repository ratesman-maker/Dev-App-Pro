<?php
declare(strict_types=1);

namespace DevAppPro\Tests\Integration;

use DevAppPro\Tests\TestCase;

/**
 * Integrační testy pro Invoice Payments API (přes HTTP).
 */
class InvoicePaymentApiTest extends TestCase
{
    /**
     * Pomocná metoda - vytvoří fakturu přes API a vrátí její ID.
     */
    private function createInvoice(): int
    {
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
        $body = json_decode((string) $response->getBody(), true);
        return (int) $body['id'];
    }

    /**
     * GET /api/invoice-payments bez přihlášení → 401.
     */
    public function test_seznam_plateb_bez_prihlaseni_vrati_401(): void
    {
        $response = $this->http->get('/api/invoice-payments?invoice_id=1');

        $this->assertEquals(401, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    /**
     * Vytvořím fakturu, přidám 2 platby, GET ?invoice_id=X → 200, data má 2.
     */
    public function test_seznam_plateb_pro_fakturu(): void
    {
        $this->login();
        $invoiceId = $this->createInvoice();

        // První platba
        $this->http->post('/api/invoice-payments', [
            'json' => [
                'invoice_id'   => $invoiceId,
                'amount_cents' => 30000,
                'payment_date' => '2026-09-11',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        // Druhá platba
        $this->http->post('/api/invoice-payments', [
            'json' => [
                'invoice_id'   => $invoiceId,
                'amount_cents' => 20000,
                'payment_date' => '2026-09-12',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $response = $this->http->get('/api/invoice-payments?invoice_id=' . $invoiceId);

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertCount(2, $body['data']);
    }

    /**
     * Vytvořím fakturu, POST platba → 201.
     */
    public function test_vytvoreni_platby(): void
    {
        $this->login();
        $invoiceId = $this->createInvoice();

        $response = $this->http->post('/api/invoice-payments', [
            'json' => [
                'invoice_id'   => $invoiceId,
                'amount_cents' => 50000,
                'payment_date' => '2026-09-11',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame($invoiceId, (int) $body['invoice_id']);
        $this->assertSame(50000, (int) $body['amount_cents']);
    }

    /**
     * Po vytvoření platby faktura.paid_cents = 50000.
     */
    public function test_vytvoreni_platby_zmeni_paid_cents(): void
    {
        $this->login();
        $invoiceId = $this->createInvoice();

        $this->http->post('/api/invoice-payments', [
            'json' => [
                'invoice_id'   => $invoiceId,
                'amount_cents' => 50000,
                'payment_date' => '2026-09-11',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $response = $this->http->get('/api/invoices/' . $invoiceId);
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(50000, (int) $body['paid_cents']);
    }

    /**
     * Platba ve výši amount_cents → status='paid'.
     */
    public function test_vytvoreni_platby_nastavi_status_paid(): void
    {
        $this->login();
        $invoiceId = $this->createInvoice(); // amount = 121000

        $this->http->post('/api/invoice-payments', [
            'json' => [
                'invoice_id'   => $invoiceId,
                'amount_cents' => 121000,
                'payment_date' => '2026-09-11',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $response = $this->http->get('/api/invoices/' . $invoiceId);
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('paid', $body['status']);
        $this->assertSame(121000, (int) $body['paid_cents']);
    }

    /**
     * Vytvořím platbu, smažu → paid_cents=0.
     */
    public function test_smazani_platby_prepocita_paid_cents(): void
    {
        $this->login();
        $invoiceId = $this->createInvoice();

        $createResponse = $this->http->post('/api/invoice-payments', [
            'json' => [
                'invoice_id'   => $invoiceId,
                'amount_cents' => 50000,
                'payment_date' => '2026-09-11',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $payment = json_decode((string) $createResponse->getBody(), true);
        $paymentId = (int) $payment['id'];

        $deleteResponse = $this->http->request('DELETE', '/api/invoice-payments/' . $paymentId, [
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(204, $deleteResponse->getStatusCode());

        $response = $this->http->get('/api/invoices/' . $invoiceId);
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(0, (int) $body['paid_cents']);
    }

    /**
     * Plná platba (status=paid), smažu platbu → status='sent'.
     */
    public function test_smazani_platby_status_sent(): void
    {
        $this->login();
        $invoiceId = $this->createInvoice(); // amount = 121000

        $createResponse = $this->http->post('/api/invoice-payments', [
            'json' => [
                'invoice_id'   => $invoiceId,
                'amount_cents' => 121000,
                'payment_date' => '2026-09-11',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $payment = json_decode((string) $createResponse->getBody(), true);
        $paymentId = (int) $payment['id'];

        // Ověříme, že status je 'paid'
        $invoiceResponse = $this->http->get('/api/invoices/' . $invoiceId);
        $invoiceBody = json_decode((string) $invoiceResponse->getBody(), true);
        $this->assertSame('paid', $invoiceBody['status']);

        // Smažeme platbu
        $this->http->request('DELETE', '/api/invoice-payments/' . $paymentId, [
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        // Status by měl být 'sent'
        $response = $this->http->get('/api/invoices/' . $invoiceId);
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('sent', $body['status']);
        $this->assertSame(0, (int) $body['paid_cents']);
    }

    /**
     * POST bez invoice_id → 422.
     */
    public function test_vytvoreni_bez_invoice_id_vrati_422(): void
    {
        $this->login();

        $response = $this->http->post('/api/invoice-payments', [
            'json' => [
                'amount_cents' => 50000,
                'payment_date' => '2026-09-11',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('fields', $body);
        $this->assertArrayHasKey('invoice_id', $body['fields']);
    }

    /**
     * POST s neplatným invoice_id → 422.
     */
    public function test_vytvoreni_s_neplatnym_invoice_id_vrati_422(): void
    {
        $this->login();

        $response = $this->http->post('/api/invoice-payments', [
            'json' => [
                'invoice_id'   => 99999,
                'amount_cents' => 50000,
                'payment_date' => '2026-09-11',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('fields', $body);
        $this->assertArrayHasKey('invoice_id', $body['fields']);
    }

    /**
     * Login, POST bez X-CSRF-Token → 403.
     */
    public function test_post_bez_csrf_vrati_403(): void
    {
        $this->login();
        $invoiceId = $this->createInvoice();

        $response = $this->http->post('/api/invoice-payments', [
            'json' => [
                'invoice_id'   => $invoiceId,
                'amount_cents' => 50000,
                'payment_date' => '2026-09-11',
            ],
            // Záměrně bez X-CSRF-Token hlavičky
        ]);

        $this->assertEquals(403, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }
}
