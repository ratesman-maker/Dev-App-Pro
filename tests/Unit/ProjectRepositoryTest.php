<?php
declare(strict_types=1);

namespace DevAppPro\Tests\Unit;

use DevAppPro\Tests\UnitTestCase;
use DevAppPro\Repositories\ProjectRepository;
use DevAppPro\Repositories\ClientRepository;

/**
 * Unit testy pro ProjectRepository (přímo nad DB přes TestCase).
  * @group projects
 */
class ProjectRepositoryTest extends UnitTestCase
{
    private ProjectRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new ProjectRepository();
    }

    /**
     * Vytvoří projekt a ověří name a status='active'.
     */
    public function test_create_projekt(): void
    {
        $id = $this->repo->create([
            'name' => 'Web redesign',
        ]);

        $this->assertGreaterThan(0, $id);

        $project = $this->repo->find($id);
        $this->assertNotNull($project);
        $this->assertSame('Web redesign', $project['name']);
        $this->assertSame('active', $project['status']);
    }

    /**
     * Vytvoří projekt s client_id a ověří.
     */
    public function test_create_projekt_s_clientem(): void
    {
        $clientRepo = new ClientRepository();
        $clientId = $clientRepo->create([
            'type'       => 'individual',
            'first_name' => 'Jan',
            'last_name'  => 'Novák',
        ]);

        $id = $this->repo->create([
            'name'      => 'Web redesign',
            'client_id' => $clientId,
        ]);

        $this->assertGreaterThan(0, $id);

        $project = $this->repo->find($id);
        $this->assertNotNull($project);
        $this->assertSame($clientId, (int) $project['client_id']);
        $this->assertSame('Web redesign', $project['name']);
    }

    /**
     * find() vrátí client_name = full_name klienta.
     */
    public function test_find_vrati_client_name(): void
    {
        $clientRepo = new ClientRepository();
        $clientId = $clientRepo->create([
            'type'       => 'individual',
            'first_name' => 'Jan',
            'last_name'  => 'Novák',
        ]);

        $id = $this->repo->create([
            'name'      => 'Web redesign',
            'client_id' => $clientId,
        ]);

        $project = $this->repo->find($id);
        $this->assertNotNull($project);
        $this->assertSame('Jan Novák', $project['client_name']);
    }

    /**
     * Projekt bez client_id → client_name = null.
     */
    public function test_find_bez_clienta(): void
    {
        $id = $this->repo->create([
            'name' => 'Interní projekt',
        ]);

        $project = $this->repo->find($id);
        $this->assertNotNull($project);
        $this->assertNull($project['client_id']);
        $this->assertNull($project['client_name']);
    }

    /**
     * update() změní status projektu.
     */
    public function test_update_zmeni_status(): void
    {
        $id = $this->repo->create([
            'name' => 'Web redesign',
        ]);

        $this->repo->update($id, ['status' => 'on_hold']);

        $project = $this->repo->find($id);
        $this->assertSame('on_hold', $project['status']);
    }

    /**
     * archive() nastaví status='archived'.
     */
    public function test_archive_nastavi_status_archived(): void
    {
        $id = $this->repo->create([
            'name' => 'Web redesign',
        ]);

        $this->assertTrue($this->repo->archive($id));

        $project = $this->repo->find($id);
        $this->assertSame('archived', $project['status']);
    }

    /**
     * restore() nastaví status='active'.
     */
    public function restore_nastavi_status_active(): void
    {
        $id = $this->repo->create([
            'name' => 'Web redesign',
            'status' => 'archived',
        ]);

        $this->assertTrue($this->repo->restore($id));

        $project = $this->repo->find($id);
        $this->assertSame('active', $project['status']);
    }

    /**
     * delete() smaže projekt.
     */
    public function test_delete_smaze_projekt(): void
    {
        $id = $this->repo->create([
            'name' => 'Web redesign',
        ]);

        $this->assertTrue($this->repo->delete($id));
        $this->assertNull($this->repo->find($id));
    }

    /**
     * all() vrátí seznam projektů s paginací.
     */
    public function test_all_seznam_s_paginaci(): void
    {
        $this->repo->create(['name' => 'Projekt A']);
        $this->repo->create(['name' => 'Projekt B']);
        $this->repo->create(['name' => 'Projekt C']);

        $result = $this->repo->all(1, 50);

        $this->assertSame(3, $result['total']);
        $this->assertCount(3, $result['data']);
    }

    /**
     * all() filtruje podle client_id.
     */
    public function test_all_filtrovat_podle_client_id(): void
    {
        $clientRepo = new ClientRepository();
        $clientId = $clientRepo->create([
            'type'       => 'individual',
            'first_name' => 'Jan',
            'last_name'  => 'Novák',
        ]);

        $this->repo->create(['name' => 'Projekt A', 'client_id' => $clientId]);
        $this->repo->create(['name' => 'Projekt B']);
        $this->repo->create(['name' => 'Projekt C', 'client_id' => $clientId]);

        $result = $this->repo->all(1, 50, '', $clientId);

        $this->assertSame(2, $result['total']);
        $this->assertCount(2, $result['data']);
        foreach ($result['data'] as $project) {
            $this->assertSame($clientId, (int) $project['client_id']);
        }
    }

    /**
     * all() filtruje podle status.
     */
    public function test_all_filtrovat_podle_status(): void
    {
        $this->repo->create(['name' => 'Projekt A', 'status' => 'active']);
        $this->repo->create(['name' => 'Projekt B', 'status' => 'completed']);
        $this->repo->create(['name' => 'Projekt C', 'status' => 'active']);

        $result = $this->repo->all(1, 50, '', null, 'completed');

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['data']);
        $this->assertSame('completed', $result['data'][0]['status']);
    }

    /**
     * all() search podle name.
     */
    public function test_all_search_podle_name(): void
    {
        $this->repo->create(['name' => 'Web redesign']);
        $this->repo->create(['name' => 'Mobilní aplikace']);
        $this->repo->create(['name' => 'Web optimalizace']);

        $result = $this->repo->all(1, 50, 'Web');

        $this->assertSame(2, $result['total']);
        $this->assertCount(2, $result['data']);
    }
}
