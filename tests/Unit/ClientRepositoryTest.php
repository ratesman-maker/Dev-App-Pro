<?php
declare(strict_types=1);

namespace DevAppPro\Tests\Unit;

use DevAppPro\Tests\TestCase;
use DevAppPro\Repositories\ClientRepository;

/**
 * Unit testy pro ClientRepository (přímo nad DB přes TestCase).
 */
class ClientRepositoryTest extends TestCase
{
    private ClientRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new ClientRepository();
    }

    /**
     * Vytvoří osobu a ověří first_name, last_name, type.
     */
    public function test_create_osoba(): void
    {
        $id = $this->repo->create([
            'type'       => 'individual',
            'first_name' => 'Jan',
            'last_name'  => 'Novák',
            'email'      => 'jan.novak@example.cz',
        ]);

        $this->assertGreaterThan(0, $id);

        $client = $this->repo->find($id);
        $this->assertNotNull($client);
        $this->assertSame('individual', $client['type']);
        $this->assertSame('Jan', $client['first_name']);
        $this->assertSame('Novák', $client['last_name']);
    }

    /**
     * Vytvoří firmu a ověří company_name, ico, dic.
     */
    public function test_create_firma(): void
    {
        $id = $this->repo->create([
            'type'         => 'company',
            'company_name' => 'Novák s.r.o.',
            'ico'          => '12345678',
            'dic'          => 'CZ12345678',
        ]);

        $this->assertGreaterThan(0, $id);

        $client = $this->repo->find($id);
        $this->assertNotNull($client);
        $this->assertSame('company', $client['type']);
        $this->assertSame('Novák s.r.o.', $client['company_name']);
        $this->assertSame('12345678', $client['ico']);
        $this->assertSame('CZ12345678', $client['dic']);
    }

    /**
     * find() vrátí computed full_name - osoba "Jan Novák", firma "Novák s.r.o.".
     */
    public function test_find_by_id_vrati_full_name(): void
    {
        // Osoba
        $osobaId = $this->repo->create([
            'type'       => 'individual',
            'first_name' => 'Jan',
            'last_name'  => 'Novák',
        ]);
        $osoba = $this->repo->find($osobaId);
        $this->assertSame('Jan Novák', $osoba['full_name']);

        // Firma
        $firmaId = $this->repo->create([
            'type'         => 'company',
            'company_name' => 'Novák s.r.o.',
        ]);
        $firma = $this->repo->find($firmaId);
        $this->assertSame('Novák s.r.o.', $firma['full_name']);
    }

    /**
     * update() změní údaje klienta.
     */
    public function test_update_zmeni_udaje(): void
    {
        $id = $this->repo->create([
            'type'       => 'individual',
            'first_name' => 'Jan',
            'last_name'  => 'Novák',
        ]);

        $this->repo->update($id, [
            'last_name' => 'Svoboda',
            'email'     => 'jan.svoboda@example.cz',
        ]);

        $client = $this->repo->find($id);
        $this->assertSame('Svoboda', $client['last_name']);
        $this->assertSame('jan.svoboda@example.cz', $client['email']);
        $this->assertSame('Jan', $client['first_name']);
    }

    /**
     * delete() smaže klienta.
     */
    public function test_delete_smaze_klienta(): void
    {
        $id = $this->repo->create([
            'type'       => 'individual',
            'first_name' => 'Jan',
            'last_name'  => 'Novák',
        ]);

        $this->assertTrue($this->repo->delete($id));
        $this->assertNull($this->repo->find($id));
    }

    /**
     * all() vrátí seznam klientů s paginací - total=3, data má 3 položky.
     */
    public function test_all_seznam_klientu_s_paginaci(): void
    {
        $this->repo->create(['type' => 'individual', 'first_name' => 'A', 'last_name' => 'Novák']);
        $this->repo->create(['type' => 'individual', 'first_name' => 'B', 'last_name' => 'Svoboda']);
        $this->repo->create(['type' => 'individual', 'first_name' => 'C', 'last_name' => 'Dvořák']);

        $result = $this->repo->all(1, 50);

        $this->assertSame(3, $result['total']);
        $this->assertCount(3, $result['data']);
    }

    /**
     * all() search podle e-mailu - "novak" najde klienta s emailem novak@example.cz.
     */
    public function test_all_search_podle_emailu(): void
    {
        $this->repo->create([
            'type'       => 'individual',
            'first_name' => 'Jan',
            'last_name'  => 'Novák',
            'email'      => 'novak@example.cz',
        ]);
        $this->repo->create([
            'type'       => 'individual',
            'first_name' => 'Petr',
            'last_name'  => 'Svoboda',
            'email'      => 'svoboda@example.cz',
        ]);

        $result = $this->repo->all(1, 50, 'novak');

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['data']);
        $this->assertSame('novak@example.cz', $result['data'][0]['email']);
    }
}
