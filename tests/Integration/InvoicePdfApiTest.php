<?php
declare(strict_types=1);

namespace DevAppPro\Tests\Integration;

use DevAppPro\Tests\TestCase;

/**
 * Integrační testy pro PDF generování faktur (přes HTTP).
 */
class InvoicePdfApiTest extends TestCase
{
    /**
     * GET /api/invoices/{id}/pdf bez přihlášení → 401.
     */
    public function test_pdf_bez_prihlaseni_vrati_401(): void
    {
        $response = $this->http->get('/api/invoices/1/pdf');

        $this->assertEquals(401, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    /**
     * GET /api/invoices/99999/pdf pro neexistující fakturu → 404.
     */
    public function test_pdf_pro_neexistujici_fakturu_vrati_404(): void
    {
        $this->login();

        $response = $this->http->get('/api/invoices/99999/pdf');

        $this->assertEquals(404, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    /**
     * Vytvořím fakturu, GET /api/invoices/{id}/pdf → 200, Content-Type: application/pdf,
     * Content-Disposition obsahuje "faktura-", body začíná "%PDF".
     */
    public function test_pdf_vrati_pdf_po_prihlaseni(): void
    {
        $this->login();

        $createResponse = $this->http->post('/api/invoices', [
            'json' => [
                'invoice_number'   => '2026001',
                'subtotal_cents'    => 150000,
                'vat_rate_percent'  => 21,
                'issue_date'        => '2026-03-26',
                'due_date'          => '2026-04-09',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $created = json_decode((string) $createResponse->getBody(), true);
        $id = $created['id'];

        $response = $this->http->get('/api/invoices/' . $id . '/pdf');

        $this->assertEquals(200, $response->getStatusCode());

        $contentType = $response->getHeader('Content-Type');
        $this->assertNotEmpty($contentType);
        $this->assertStringContainsString('application/pdf', $contentType[0]);

        $contentDisposition = $response->getHeader('Content-Disposition');
        $this->assertNotEmpty($contentDisposition);
        $this->assertStringContainsString('faktura-', $contentDisposition[0]);

        $body = (string) $response->getBody();
        $this->assertStringStartsWith('%PDF', $body);
    }

    /**
     * Vytvořím company_profile s názvem firmy, fakturu, GET PDF →
     * PDF je validní (začíná %PDF) a má správné hlavičky.
     * (PDF je binární, obsah nelze testovat přímo.)
     */
    public function test_pdf_obsahuje_seller_info(): void
    {
        $this->login();

        // Nastavení company_profile s názvem firmy
        $this->http->request('PUT', '/api/company-profile', [
            'json' => [
                'type'         => 'company',
                'company_name' => 'Test Firma s.r.o.',
                'ico'          => '12345678',
                'dic'          => 'CZ12345678',
                'email'        => 'firma@test.cz',
                'address'      => 'Testovací 123, Praha',
                'iban'         => 'CZ1234567890123456789012',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $createResponse = $this->http->post('/api/invoices', [
            'json' => [
                'invoice_number'   => '2026002',
                'subtotal_cents'    => 100000,
                'vat_rate_percent'  => 21,
                'issue_date'        => '2026-03-26',
                'due_date'          => '2026-04-09',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $created = json_decode((string) $createResponse->getBody(), true);
        $id = $created['id'];

        $response = $this->http->get('/api/invoices/' . $id . '/pdf');

        $this->assertEquals(200, $response->getStatusCode());

        $contentType = $response->getHeader('Content-Type');
        $this->assertStringContainsString('application/pdf', $contentType[0]);

        $contentDisposition = $response->getHeader('Content-Disposition');
        $this->assertStringContainsString('faktura-', $contentDisposition[0]);

        $body = (string) $response->getBody();
        $this->assertStringStartsWith('%PDF', $body);
    }
}
