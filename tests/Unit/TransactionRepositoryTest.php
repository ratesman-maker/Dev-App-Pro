<?php
declare(strict_types=1);

namespace DevAppPro\Tests\Unit;

use DevAppPro\Tests\TestCase;
use DevAppPro\Repositories\TransactionRepository;
use DevAppPro\Repositories\ClientRepository;
use DevAppPro\Repositories\ProjectRepository;

/**
 * Unit testy pro TransactionRepository (přímo nad DB přes TestCase).
 */
class TransactionRepositoryTest extends TestCase
{
    private TransactionRepository $repo;
    private ClientRepository $clients;
    private ProjectRepository $projects;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new TransactionRepository();
        $this->clients = new ClientRepository();
        $this->projects = new ProjectRepository();
    }

    /**
     * Pomocná metoda - vytvoří klienta a vrátí jeho ID.
     */
    private function createClient(): int
    {
        return $this->clients->create([
            'type'       => 'individual',
            'first_name' => 'Jan',
            'last_name'  => 'Novák',
            'email'      => 'jan.novak@example.com',
        ]);
    }

    /**
     * Pomocná metoda - vytvoří projekt a vrátí jeho ID.
     */
    private function createProject(): int
    {
        return $this->projects->create(['name' => 'Test projekt']);
    }

    /**
     * Vytvoří income transakci a ověří type a category.
     */
    public function test_create_income_transakce(): void
    {
        $id = $this->repo->create([
            'type'             => 'income',
            'amount_cents'     => 50000,
            'category'         => 'income_project',
            'transaction_date' => '2026-09-11',
        ]);

        $this->assertGreaterThan(0, $id);

        $transaction = $this->repo->find($id);
        $this->assertNotNull($transaction);
        $this->assertSame('income', $transaction['type']);
        $this->assertSame(50000, (int) $transaction['amount_cents']);
        $this->assertSame('income_project', $transaction['category']);
    }

    /**
     * Vytvoří expense transakci a ověří type a category.
     */
    public function test_create_expense_transakce(): void
    {
        $id = $this->repo->create([
            'type'             => 'expense',
            'amount_cents'     => 10000,
            'category'         => 'software',
            'transaction_date' => '2026-09-11',
        ]);

        $this->assertGreaterThan(0, $id);

        $transaction = $this->repo->find($id);
        $this->assertNotNull($transaction);
        $this->assertSame('expense', $transaction['type']);
        $this->assertSame(10000, (int) $transaction['amount_cents']);
        $this->assertSame('software', $transaction['category']);
    }

    /**
     * find() vrátí client_name a project_name z JOIN s clients a projects.
     */
    public function test_find_vrati_client_name_a_project_name(): void
    {
        $clientId = $this->createClient();
        $projectId = $this->createProject();

        $id = $this->repo->create([
            'project_id'       => $projectId,
            'client_id'        => $clientId,
            'type'             => 'income',
            'amount_cents'     => 50000,
            'category'         => 'income_project',
            'transaction_date' => '2026-09-11',
        ]);

        $transaction = $this->repo->find($id);
        $this->assertNotNull($transaction);
        $this->assertSame('Jan Novák', $transaction['client_name']);
        $this->assertSame('Test projekt', $transaction['project_name']);
    }

    /**
     * update() změní description transakce.
     */
    public function test_update_transakce(): void
    {
        $id = $this->repo->create([
            'type'             => 'expense',
            'amount_cents'     => 10000,
            'category'         => 'software',
            'transaction_date' => '2026-09-11',
        ]);

        $this->repo->update($id, ['description' => 'Nákup licence']);

        $transaction = $this->repo->find($id);
        $this->assertSame('Nákup licence', $transaction['description']);
    }

    /**
     * delete() smaže transakci.
     */
    public function test_delete_transakce(): void
    {
        $id = $this->repo->create([
            'type'             => 'expense',
            'amount_cents'     => 10000,
            'category'         => 'software',
            'transaction_date' => '2026-09-11',
        ]);

        $this->assertTrue($this->repo->delete($id));
        $this->assertNull($this->repo->find($id));
    }

    /**
     * all() vrátí seznam transakcí s paginací.
     */
    public function test_all_seznam_s_paginaci(): void
    {
        $this->repo->create([
            'type'             => 'income',
            'amount_cents'     => 50000,
            'category'         => 'income_project',
            'transaction_date' => '2026-09-11',
        ]);
        $this->repo->create([
            'type'             => 'expense',
            'amount_cents'     => 10000,
            'category'         => 'software',
            'transaction_date' => '2026-09-12',
        ]);
        $this->repo->create([
            'type'             => 'expense',
            'amount_cents'     => 5000,
            'category'         => 'office',
            'transaction_date' => '2026-09-13',
        ]);

        $result = $this->repo->all(1, 50);

        $this->assertSame(3, $result['total']);
        $this->assertCount(3, $result['data']);
    }

    /**
     * all() filtruje podle type.
     */
    public function test_all_filtrovat_podle_type(): void
    {
        $this->repo->create([
            'type'             => 'income',
            'amount_cents'     => 50000,
            'category'         => 'income_project',
            'transaction_date' => '2026-09-11',
        ]);
        $this->repo->create([
            'type'             => 'expense',
            'amount_cents'     => 10000,
            'category'         => 'software',
            'transaction_date' => '2026-09-12',
        ]);
        $this->repo->create([
            'type'             => 'income',
            'amount_cents'     => 30000,
            'category'         => 'income_consulting',
            'transaction_date' => '2026-09-13',
        ]);

        $result = $this->repo->all(1, 50, '', null, null, 'income');

        $this->assertSame(2, $result['total']);
        $this->assertCount(2, $result['data']);
        foreach ($result['data'] as $transaction) {
            $this->assertSame('income', $transaction['type']);
        }
    }

    /**
     * all() filtruje podle category.
     */
    public function test_all_filtrovat_podle_category(): void
    {
        $this->repo->create([
            'type'             => 'expense',
            'amount_cents'     => 10000,
            'category'         => 'software',
            'transaction_date' => '2026-09-11',
        ]);
        $this->repo->create([
            'type'             => 'expense',
            'amount_cents'     => 5000,
            'category'         => 'office',
            'transaction_date' => '2026-09-12',
        ]);
        $this->repo->create([
            'type'             => 'expense',
            'amount_cents'     => 8000,
            'category'         => 'software',
            'transaction_date' => '2026-09-13',
        ]);

        $result = $this->repo->all(1, 50, '', null, null, null, 'software');

        $this->assertSame(2, $result['total']);
        $this->assertCount(2, $result['data']);
        foreach ($result['data'] as $transaction) {
            $this->assertSame('software', $transaction['category']);
        }
    }

    /**
     * all() filtruje podle project_id.
     */
    public function test_all_filtrovat_podle_project_id(): void
    {
        $projectId1 = $this->createProject();
        $projectId2 = $this->projects->create(['name' => 'Projekt 2']);

        $this->repo->create([
            'project_id'       => $projectId1,
            'type'             => 'income',
            'amount_cents'     => 50000,
            'category'         => 'income_project',
            'transaction_date' => '2026-09-11',
        ]);
        $this->repo->create([
            'project_id'       => $projectId2,
            'type'             => 'income',
            'amount_cents'     => 30000,
            'category'         => 'income_project',
            'transaction_date' => '2026-09-12',
        ]);
        $this->repo->create([
            'project_id'       => $projectId1,
            'type'             => 'expense',
            'amount_cents'     => 10000,
            'category'         => 'software',
            'transaction_date' => '2026-09-13',
        ]);

        $result = $this->repo->all(1, 50, '', $projectId1);

        $this->assertSame(2, $result['total']);
        $this->assertCount(2, $result['data']);
        foreach ($result['data'] as $transaction) {
            $this->assertSame($projectId1, (int) $transaction['project_id']);
        }
    }

    /**
     * all() filtruje podle client_id.
     */
    public function test_all_filtrovat_podle_client_id(): void
    {
        $clientId1 = $this->createClient();
        $clientId2 = $this->clients->create([
            'type'       => 'individual',
            'first_name' => 'Petra',
            'last_name'  => 'Svobodová',
            'email'      => 'petra.svobodova@example.com',
        ]);

        $this->repo->create([
            'client_id'        => $clientId1,
            'type'             => 'income',
            'amount_cents'     => 50000,
            'category'         => 'income_project',
            'transaction_date' => '2026-09-11',
        ]);
        $this->repo->create([
            'client_id'        => $clientId2,
            'type'             => 'income',
            'amount_cents'     => 30000,
            'category'         => 'income_project',
            'transaction_date' => '2026-09-12',
        ]);
        $this->repo->create([
            'client_id'        => $clientId1,
            'type'             => 'expense',
            'amount_cents'     => 10000,
            'category'         => 'software',
            'transaction_date' => '2026-09-13',
        ]);

        $result = $this->repo->all(1, 50, '', null, $clientId1);

        $this->assertSame(2, $result['total']);
        $this->assertCount(2, $result['data']);
        foreach ($result['data'] as $transaction) {
            $this->assertSame($clientId1, (int) $transaction['client_id']);
        }
    }

    /**
     * all() filtruje podle from a to (datumový rozsah).
     */
    public function test_all_filtrovat_podle_from_to(): void
    {
        $this->repo->create([
            'type'             => 'expense',
            'amount_cents'     => 10000,
            'category'         => 'software',
            'transaction_date' => '2026-09-01',
        ]);
        $this->repo->create([
            'type'             => 'expense',
            'amount_cents'     => 5000,
            'category'         => 'office',
            'transaction_date' => '2026-09-15',
        ]);
        $this->repo->create([
            'type'             => 'expense',
            'amount_cents'     => 8000,
            'category'         => 'travel',
            'transaction_date' => '2026-09-30',
        ]);

        // Filtr od 2026-09-10 do 2026-09-20 → pouze 15.9.
        $result = $this->repo->all(1, 50, '', null, null, null, null, '2026-09-10', '2026-09-20');

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['data']);
        $this->assertSame('2026-09-15', $result['data'][0]['transaction_date']);
    }
}
