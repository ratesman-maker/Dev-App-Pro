<?php
declare(strict_types=1);

namespace DevAppPro\Tests\Integration;

use DevAppPro\Tests\TestCase;

/**
 * Integrační testy pro Dashboard API (přes HTTP).
 * Nová nástěnka: kpis + klienti + projekty s agregacemi + pás "Co řešit".
 */
class DashboardApiTest extends TestCase
{
    /**
     * Vytvoří klienta přes API a vrátí jeho ID.
     */
    private function createClient(string $firstName = 'Jan', string $lastName = 'Novák'): int
    {
        $response = $this->http->post('/api/clients', [
            'json' => [
                'type'       => 'individual',
                'first_name' => $firstName,
                'last_name'  => $lastName,
            ],
            'headers' => ['X-CSRF-Token' => $this->csrfToken],
        ]);
        $body = json_decode((string) $response->getBody(), true);
        return (int) $body['id'];
    }

    /**
     * Vytvoří projekt přes API a vrátí jeho ID.
     */
    private function createProject(?int $clientId = null, array $extra = []): int
    {
        $data = array_merge(['name' => 'Test projekt'], $extra);
        if ($clientId !== null) {
            $data['client_id'] = $clientId;
        }
        $response = $this->http->post('/api/projects', [
            'json' => $data,
            'headers' => ['X-CSRF-Token' => $this->csrfToken],
        ]);
        $body = json_decode((string) $response->getBody(), true);
        return (int) $body['id'];
    }

    /**
     * Vytvoří fakturu přes API a vrátí její ID.
     */
    private function createInvoice(array $extra = []): int
    {
        $data = array_merge([
            'invoice_number'  => '2026' . random_int(1000, 9999),
            'subtotal_cents'  => 100000,
            'vat_rate_percent' => 0,
            'issue_date'       => date('Y-m-d'),
            'due_date'         => date('Y-m-d', strtotime('+14 days')),
        ], $extra);
        $response = $this->http->post('/api/invoices', [
            'json' => $data,
            'headers' => ['X-CSRF-Token' => $this->csrfToken],
        ]);
        $body = json_decode((string) $response->getBody(), true);
        return (int) $body['id'];
    }

    /**
     * Vytvoří poznámku vázanou na entitu.
     */
    private function createNote(string $entityType, int $entityId, string $title = 'Test poznámka'): int
    {
        $response = $this->http->post('/api/notes', [
            'json' => [
                'title'       => $title,
                'content'     => 'Obsah testovací poznámky',
                'attachments' => [
                    ['entity_type' => $entityType, 'entity_id' => $entityId],
                ],
            ],
            'headers' => ['X-CSRF-Token' => $this->csrfToken],
        ]);
        $body = json_decode((string) $response->getBody(), true);
        return (int) $body['id'];
    }

    /**
     * GET /api/dashboard bez přihlášení → 401.
     */
    public function test_dashboard_bez_prihlaseni_vrati_401(): void
    {
        $response = $this->http->get('/api/dashboard');

        $this->assertEquals(401, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    /**
     * Login, GET /api/dashboard → 200, má kpis/clients/projects/attention.
     */
    public function test_dashboard_po_prihlaseni_vrati_200(): void
    {
        $this->login();

        $response = $this->http->get('/api/dashboard');

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('kpis', $body);
        $this->assertArrayHasKey('finance', $body);
        $this->assertArrayHasKey('total_paid_cents', $body['finance']);
        $this->assertArrayHasKey('total_income_cents', $body['finance']);
        $this->assertArrayHasKey('total_expense_cents', $body['finance']);
        $this->assertArrayHasKey('total_open_cents', $body['finance']);
        $this->assertArrayHasKey('overdue_count', $body['finance']);
        $this->assertArrayHasKey('overdue_cents', $body['finance']);
        $this->assertArrayHasKey('clients', $body);
        $this->assertArrayHasKey('projects', $body);
        $this->assertArrayHasKey('finance_series', $body);
        $this->assertCount(12, $body['finance_series']);
        $this->assertArrayHasKey('attention', $body);
        $this->assertArrayHasKey('overdue_invoices', $body['attention']);
        $this->assertArrayHasKey('upcoming_deadlines', $body['attention']);
        $this->assertArrayHasKey('recent_notes', $body['attention']);
    }

    /**
     * Transakce a faktury → finance souhrn (widgety Finance sekce).
     */
    public function test_dashboard_finance_souhrn(): void
    {
        $this->login();

        $clientId = $this->createClient();

        // Příjem
        $this->http->post('/api/transactions', [
            'json' => [
                'type'             => 'income',
                'amount_cents'     => 70000,
                'client_id'        => $clientId,
                'transaction_date' => date('Y-m-d'),
            ],
            'headers' => ['X-CSRF-Token' => $this->csrfToken],
        ]);
        // Výdaj
        $this->http->post('/api/transactions', [
            'json' => [
                'type'             => 'expense',
                'amount_cents'     => 30000,
                'client_id'        => $clientId,
                'transaction_date' => date('Y-m-d'),
            ],
            'headers' => ['X-CSRF-Token' => $this->csrfToken],
        ]);
        // Otevřená faktura (sent)
        $this->createInvoice([
            'status'     => 'sent',
            'client_id'  => $clientId,
            'due_date'   => date('Y-m-d', strtotime('+14 days')),
        ]);

        $response = $this->http->get('/api/dashboard');

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $finance = $body['finance'];

        $this->assertGreaterThanOrEqual(70000, (int) $finance['total_income_cents']);
        $this->assertGreaterThanOrEqual(30000, (int) $finance['total_expense_cents']);
        $this->assertGreaterThanOrEqual(100000, (int) $finance['total_open_cents']);
    }

    /**
     * Klient + aktivní projekt + otevřená a po splatnosti faktura → kpis.
     */
    public function test_dashboard_kpis(): void
    {
        $this->login();

        $clientId = $this->createClient();
        $this->createProject($clientId);

        // Otevřená (sent)
        $this->createInvoice([
            'status'     => 'sent',
            'due_date'   => date('Y-m-d', strtotime('+14 days')),
            'client_id'  => $clientId,
        ]);
        // Po splatnosti
        $this->createInvoice([
            'status'     => 'sent',
            'due_date'   => date('Y-m-d', strtotime('-10 days')),
            'client_id'  => $clientId,
        ]);

        $response = $this->http->get('/api/dashboard');

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $kpis = $body['kpis'];

        $this->assertGreaterThanOrEqual(1, $kpis['clients']);
        $this->assertGreaterThanOrEqual(1, $kpis['active_projects']);
        $this->assertGreaterThanOrEqual(2, $kpis['open_invoices_count']);
        $this->assertGreaterThanOrEqual(200000, $kpis['open_cents']);
        $this->assertGreaterThanOrEqual(1, $kpis['overdue_count']);
        $this->assertGreaterThanOrEqual(100000, $kpis['overdue_cents']);
    }

    /**
     * Klient s projektem, fakturou, příjmem a poznámkou → agregace v clients.
     */
    public function test_dashboard_klienti_agregace(): void
    {
        $this->login();

        $clientId = $this->createClient('Petra', 'Testová');
        $projectId = $this->createProject($clientId);

        // Faktura (sent, otevřená)
        $this->createInvoice([
            'status'    => 'sent',
            'client_id' => $clientId,
            'project_id'=> $projectId,
        ]);

        // Příjem (income transakce)
        $this->http->post('/api/transactions', [
            'json' => [
                'type'              => 'income',
                'amount_cents'      => 50000,
                'client_id'         => $clientId,
                'project_id'        => $projectId,
                'transaction_date'  => date('Y-m-d'),
            ],
            'headers' => ['X-CSRF-Token' => $this->csrfToken],
        ]);

        // Poznámka
        $this->createNote('client', $clientId, 'Klientova poznámka');

        $response = $this->http->get('/api/dashboard');

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);

        $found = null;
        foreach ($body['clients'] as $c) {
            if ((int) $c['id'] === $clientId) {
                $found = $c;
                break;
            }
        }

        $this->assertNotNull($found, 'Klient nenalezen v agregaci.');
        $this->assertSame('Petra Testová', $found['name']);
        $this->assertSame(1, (int) $found['projects_count']);
        $this->assertSame(1, (int) $found['active_projects_count']);
        $this->assertSame(1, (int) $found['invoices_count']);
        $this->assertSame(100000, (int) $found['open_cents']);
        $this->assertSame(0, (int) $found['overdue_count']);
        $this->assertSame(50000, (int) $found['income_cents']);
        $this->assertNotNull($found['last_note']);
        $this->assertSame('Klientova poznámka', $found['last_note']['title']);
        $this->assertNotEmpty($found['last_activity_at']);
    }

    /**
     * Projekt s rozpočtem, příjmem a fakturou → agregace v projects.
     */
    public function test_dashboard_projekty_agregace(): void
    {
        $this->login();

        $clientId = $this->createClient();
        $projectId = $this->createProject($clientId, [
            'budget_cents' => 200000,
        ]);

        $this->http->post('/api/transactions', [
            'json' => [
                'type'              => 'income',
                'amount_cents'      => 80000,
                'client_id'         => $clientId,
                'project_id'        => $projectId,
                'transaction_date'  => date('Y-m-d'),
            ],
            'headers' => ['X-CSRF-Token' => $this->csrfToken],
        ]);

        $this->createInvoice([
            'status'     => 'sent',
            'client_id'  => $clientId,
            'project_id' => $projectId,
        ]);

        $response = $this->http->get('/api/dashboard');

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);

        $found = null;
        foreach ($body['projects'] as $p) {
            if ((int) $p['id'] === $projectId) {
                $found = $p;
                break;
            }
        }

        $this->assertNotNull($found, 'Projekt nenalezen v agregaci.');
        $this->assertSame(200000, (int) $found['budget_cents']);
        $this->assertSame(80000, (int) $found['income_cents']);
        $this->assertSame(1, (int) $found['invoices_count']);
        $this->assertSame(100000, (int) $found['open_cents']);
        $this->assertSame('Jan Novák', $found['client_name']);
    }

    /**
     * Transakce → finance_series má 12 měsíců, income/expense se sčítají.
     */
    public function test_dashboard_finance_series(): void
    {
        $this->login();

        $clientId = $this->createClient();
        $projectId = $this->createProject($clientId);

        // Příjem + výdaj v aktuálním měsíci
        $this->http->post('/api/transactions', [
            'json' => [
                'type'             => 'income',
                'amount_cents'     => 60000,
                'client_id'        => $clientId,
                'project_id'       => $projectId,
                'transaction_date' => date('Y-m-d'),
            ],
            'headers' => ['X-CSRF-Token' => $this->csrfToken],
        ]);
        $this->http->post('/api/transactions', [
            'json' => [
                'type'             => 'expense',
                'amount_cents'     => 25000,
                'client_id'        => $clientId,
                'project_id'       => $projectId,
                'transaction_date' => date('Y-m-d'),
            ],
            'headers' => ['X-CSRF-Token' => $this->csrfToken],
        ]);

        $response = $this->http->get('/api/dashboard');

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);

        $series = $body['finance_series'];
        $this->assertCount(12, $series);
        $last = $series[count($series) - 1];
        $this->assertSame(date('Y-m'), $last['month']);
        $this->assertGreaterThanOrEqual(60000, (int) $last['income_cents']);
        $this->assertGreaterThanOrEqual(25000, (int) $last['expense_cents']);
    }

    /**
     * Overdue faktura + termín projektu ≤ 14 dní + poznámka → attention.
     */
    public function test_dashboard_attention(): void
    {
        $this->login();

        $clientId = $this->createClient();

        // Overdue faktura
        $this->createInvoice([
            'status'     => 'sent',
            'due_date'   => date('Y-m-d', strtotime('-10 days')),
            'client_id'  => $clientId,
        ]);

        // Projekt s termínem za 5 dní
        $this->createProject($clientId, [
            'deadline' => date('Y-m-d', strtotime('+5 days')),
        ]);

        // Poznámka na klienta
        $this->createNote('client', $clientId, 'Poznámka do pásu');

        $response = $this->http->get('/api/dashboard');

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $attention = $body['attention'];

        $this->assertGreaterThanOrEqual(1, count($attention['overdue_invoices']));
        foreach ($attention['overdue_invoices'] as $inv) {
            $this->assertLessThan(date('Y-m-d'), $inv['due_date']);
        }

        $this->assertGreaterThanOrEqual(1, count($attention['upcoming_deadlines']));
        foreach ($attention['upcoming_deadlines'] as $d) {
            $this->assertLessThanOrEqual(14, (int) $d['days_left']);
        }

        $this->assertGreaterThanOrEqual(1, count($attention['recent_notes']));
        $this->assertStringContainsString('Jan Novák', $attention['recent_notes'][0]['entity_label']);
    }
}
