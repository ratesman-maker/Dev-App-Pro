<?php
declare(strict_types=1);

namespace DevAppPro\Tests\Unit;

use DevAppPro\Tests\TestCase;
use DevAppPro\Repositories\InvoiceRepository;
use DevAppPro\Repositories\InvoicePaymentRepository;
use DevAppPro\Repositories\ClientRepository;
use DevAppPro\Repositories\ProjectRepository;

/**
 * Unit testy pro InvoiceRepository (přímo nad DB přes TestCase).
 */
class InvoiceRepositoryTest extends TestCase
{
    private InvoiceRepository $repo;
    private InvoicePaymentRepository $payments;
    private ClientRepository $clients;
    private ProjectRepository $projects;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new InvoiceRepository();
        $this->payments = new InvoicePaymentRepository();
        $this->clients = new ClientRepository();
        $this->projects = new ProjectRepository();
    }

    /**
     * Pomocná metoda - vytvoří fakturu se zadaným DPH a vrátí její ID.
     */
    private function createInvoice(int $subtotal, float $vatRate, string $invoiceNumber = '2026001'): int
    {
        $vatAmount = (int) round($subtotal * $vatRate / 100);
        $amount = $subtotal + $vatAmount;

        return $this->repo->create([
            'invoice_number'   => $invoiceNumber,
            'subtotal_cents'    => $subtotal,
            'vat_rate_percent'  => $vatRate,
            'vat_amount_cents'  => $vatAmount,
            'amount_cents'      => $amount,
            'issue_date'        => '2026-09-11',
            'due_date'          => '2026-09-25',
        ]);
    }

    /**
     * Vytvoří fakturu a ověří invoice_number a amount_cents (výpočet DPH).
     */
    public function test_create_faktura(): void
    {
        $id = $this->createInvoice(100000, 21);

        $this->assertGreaterThan(0, $id);

        $invoice = $this->repo->find($id);
        $this->assertNotNull($invoice);
        $this->assertSame('2026001', $invoice['invoice_number']);
        $this->assertSame(121000, (int) $invoice['amount_cents']);
    }

    /**
     * Výpočet DPH 21%: subtotal=100000, vat=21 → vat_amount=21000, amount=121000.
     */
    public function test_vypocet_dph_21(): void
    {
        $id = $this->createInvoice(100000, 21, '2026002');

        $invoice = $this->repo->find($id);
        $this->assertNotNull($invoice);
        $this->assertSame(21000, (int) $invoice['vat_amount_cents']);
        $this->assertSame(121000, (int) $invoice['amount_cents']);
    }

    /**
     * Výpočet DPH 0%: subtotal=100000, vat=0 → vat_amount=0, amount=100000.
     */
    public function test_vypocet_dph_0(): void
    {
        $id = $this->createInvoice(100000, 0, '2026003');

        $invoice = $this->repo->find($id);
        $this->assertNotNull($invoice);
        $this->assertSame(0, (int) $invoice['vat_amount_cents']);
        $this->assertSame(100000, (int) $invoice['amount_cents']);
    }

    /**
     * find() vrátí client_name a project_name z JOIN.
     */
    public function test_find_vrati_client_name_a_project_name(): void
    {
        $clientId = $this->clients->create([
            'type'       => 'individual',
            'first_name' => 'Jan',
            'last_name'  => 'Novák',
        ]);
        $projectId = $this->projects->create(['name' => 'Web redesign', 'client_id' => $clientId]);

        $id = $this->repo->create([
            'invoice_number'   => '2026004',
            'client_id'         => $clientId,
            'project_id'        => $projectId,
            'subtotal_cents'    => 100000,
            'vat_rate_percent'  => 21,
            'vat_amount_cents'  => 21000,
            'amount_cents'      => 121000,
            'issue_date'        => '2026-09-11',
            'due_date'          => '2026-09-25',
        ]);

        $invoice = $this->repo->find($id);
        $this->assertNotNull($invoice);
        $this->assertSame('Jan Novák', $invoice['client_name']);
        $this->assertSame('Web redesign', $invoice['project_name']);
    }

    /**
     * update() změní fakturu.
     */
    public function test_update_faktura(): void
    {
        $id = $this->createInvoice(100000, 21, '2026005');

        $this->repo->update($id, ['status' => 'sent']);

        $invoice = $this->repo->find($id);
        $this->assertSame('sent', $invoice['status']);
    }

    /**
     * delete() smaže fakturu a kaskádově smaže i její platby.
     */
    public function test_delete_faktura_kaskadne_smaze_platby(): void
    {
        $id = $this->createInvoice(100000, 21, '2026006');

        $paymentId = $this->payments->create([
            'invoice_id'   => $id,
            'amount_cents' => 50000,
            'payment_date' => '2026-09-11',
        ]);

        $this->assertNotNull($this->payments->find($paymentId));

        $this->repo->delete($id);

        $this->assertNull($this->repo->find($id));
        $this->assertNull($this->payments->find($paymentId));
    }

    /**
     * recalculatePaid() přepočítá paid_cents z invoice_payments.
     */
    public function test_recalculate_paid_cents(): void
    {
        $id = $this->createInvoice(100000, 21, '2026007');

        $this->payments->create([
            'invoice_id'   => $id,
            'amount_cents' => 30000,
            'payment_date' => '2026-09-11',
        ]);
        $this->payments->create([
            'invoice_id'   => $id,
            'amount_cents' => 20000,
            'payment_date' => '2026-09-12',
        ]);

        $this->repo->recalculatePaid($id);

        $invoice = $this->repo->find($id);
        $this->assertSame(50000, (int) $invoice['paid_cents']);
    }

    /**
     * recalculatePaid() nastaví status='paid' pokud paid_cents >= amount_cents.
     */
    public function test_recalculate_status_paid(): void
    {
        $id = $this->createInvoice(100000, 21, '2026008'); // amount = 121000

        $this->payments->create([
            'invoice_id'   => $id,
            'amount_cents' => 121000,
            'payment_date' => '2026-09-11',
        ]);

        $this->repo->recalculatePaid($id);

        $invoice = $this->repo->find($id);
        $this->assertSame('paid', $invoice['status']);
        $this->assertSame(121000, (int) $invoice['paid_cents']);
    }

    /**
     * all() vrátí seznam faktur s paginací.
     */
    public function test_all_seznam_s_paginaci(): void
    {
        $this->createInvoice(100000, 21, '2026010');
        $this->createInvoice(100000, 21, '2026011');
        $this->createInvoice(100000, 21, '2026012');

        $result = $this->repo->all(1, 50);

        $this->assertSame(3, $result['total']);
        $this->assertCount(3, $result['data']);
    }

    /**
     * all() filtruje podle client_id.
     */
    public function test_all_filtrovat_podle_client_id(): void
    {
        $clientId1 = $this->clients->create([
            'type'       => 'individual',
            'first_name' => 'Jan',
            'last_name'  => 'Novák',
        ]);
        $clientId2 = $this->clients->create([
            'type'       => 'individual',
            'first_name' => 'Petr',
            'last_name'  => 'Svoboda',
        ]);

        $this->repo->create([
            'invoice_number'   => '2026020',
            'client_id'         => $clientId1,
            'subtotal_cents'    => 100000,
            'vat_rate_percent'  => 21,
            'vat_amount_cents'  => 21000,
            'amount_cents'      => 121000,
            'issue_date'        => '2026-09-11',
            'due_date'          => '2026-09-25',
        ]);
        $this->repo->create([
            'invoice_number'   => '2026021',
            'client_id'         => $clientId2,
            'subtotal_cents'    => 100000,
            'vat_rate_percent'  => 21,
            'vat_amount_cents'  => 21000,
            'amount_cents'      => 121000,
            'issue_date'        => '2026-09-11',
            'due_date'          => '2026-09-25',
        ]);

        $result = $this->repo->all(1, 50, '', $clientId1);

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['data']);
        $this->assertSame($clientId1, (int) $result['data'][0]['client_id']);
    }

    /**
     * all() filtruje podle status.
     */
    public function test_all_filtrovat_podle_status(): void
    {
        $this->repo->create([
            'invoice_number'   => '2026030',
            'status'            => 'draft',
            'subtotal_cents'    => 100000,
            'vat_rate_percent'  => 21,
            'vat_amount_cents'  => 21000,
            'amount_cents'      => 121000,
            'issue_date'        => '2026-09-11',
            'due_date'          => '2026-09-25',
        ]);
        $this->repo->create([
            'invoice_number'   => '2026031',
            'status'            => 'sent',
            'subtotal_cents'    => 100000,
            'vat_rate_percent'  => 21,
            'vat_amount_cents'  => 21000,
            'amount_cents'      => 121000,
            'issue_date'        => '2026-09-11',
            'due_date'          => '2026-09-25',
        ]);
        $this->repo->create([
            'invoice_number'   => '2026032',
            'status'            => 'paid',
            'subtotal_cents'    => 100000,
            'vat_rate_percent'  => 21,
            'vat_amount_cents'  => 21000,
            'amount_cents'      => 121000,
            'issue_date'        => '2026-09-11',
            'due_date'          => '2026-09-25',
        ]);

        $result = $this->repo->all(1, 50, '', null, 'sent');

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['data']);
        $this->assertSame('sent', $result['data'][0]['status']);
    }

    /**
     * all() overdue filtr - faktura s due_date v minulosti a status='sent' → nalezen;
     * faktura se status='paid' → nenalezena.
     */
    public function test_all_overdue_filtr(): void
    {
        // Faktura po termínu (due_date v minulosti) se status='sent' → overdue
        $this->repo->create([
            'invoice_number'   => '2026040',
            'status'            => 'sent',
            'subtotal_cents'    => 100000,
            'vat_rate_percent'  => 21,
            'vat_amount_cents'  => 21000,
            'amount_cents'      => 121000,
            'issue_date'        => '2026-09-11',
            'due_date'          => date('Y-m-d', strtotime('-5 days')),
        ]);

        // Faktura po termínu ale se status='paid' → není overdue
        $this->repo->create([
            'invoice_number'   => '2026041',
            'status'            => 'paid',
            'subtotal_cents'    => 100000,
            'vat_rate_percent'  => 21,
            'vat_amount_cents'  => 21000,
            'amount_cents'      => 121000,
            'issue_date'        => '2026-09-11',
            'due_date'          => date('Y-m-d', strtotime('-5 days')),
        ]);

        $result = $this->repo->all(1, 50, '', null, null, true);

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['data']);
        $this->assertSame('sent', $result['data'][0]['status']);
    }
}
