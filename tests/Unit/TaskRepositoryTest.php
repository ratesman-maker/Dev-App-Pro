<?php
declare(strict_types=1);

namespace DevAppPro\Tests\Unit;

use DevAppPro\Tests\TestCase;
use DevAppPro\Repositories\TaskRepository;
use DevAppPro\Repositories\ProjectRepository;

/**
 * Unit testy pro TaskRepository (přímo nad DB přes TestCase).
 */
class TaskRepositoryTest extends TestCase
{
    private TaskRepository $repo;
    private ProjectRepository $projects;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new TaskRepository();
        $this->projects = new ProjectRepository();
    }

    /**
     * Pomocná metoda - vytvoří projekt a vrátí jeho ID.
     */
    private function createProject(): int
    {
        return $this->projects->create(['name' => 'Test projekt']);
    }

    /**
     * Vytvoří úkol a ověří title a status='todo'.
     */
    public function test_create_ukol(): void
    {
        $projectId = $this->createProject();

        $id = $this->repo->create([
            'project_id' => $projectId,
            'title'      => 'Napsat testy',
        ]);

        $this->assertGreaterThan(0, $id);

        $task = $this->repo->find($id);
        $this->assertNotNull($task);
        $this->assertSame('Napsat testy', $task['title']);
        $this->assertSame('todo', $task['status']);
    }

    /**
     * find() vrátí project_name z JOIN s projects.
     */
    public function test_find_vrati_project_name(): void
    {
        $projectId = $this->projects->create(['name' => 'Web redesign']);

        $id = $this->repo->create([
            'project_id' => $projectId,
            'title'      => 'Napsat testy',
        ]);

        $task = $this->repo->find($id);
        $this->assertNotNull($task);
        $this->assertSame('Web redesign', $task['project_name']);
    }

    /**
     * update() změní status úkolu.
     */
    public function test_update_zmeni_status(): void
    {
        $projectId = $this->createProject();

        $id = $this->repo->create([
            'project_id' => $projectId,
            'title'      => 'Napsat testy',
        ]);

        $this->repo->update($id, ['status' => 'done']);

        $task = $this->repo->find($id);
        $this->assertSame('done', $task['status']);
    }

    /**
     * delete() smaže úkol.
     */
    public function test_delete_smaze_ukol(): void
    {
        $projectId = $this->createProject();

        $id = $this->repo->create([
            'project_id' => $projectId,
            'title'      => 'Napsat testy',
        ]);

        $this->assertTrue($this->repo->delete($id));
        $this->assertNull($this->repo->find($id));
    }

    /**
     * all() vrátí seznam úkolů s paginací.
     */
    public function test_all_seznam_s_paginaci(): void
    {
        $projectId = $this->createProject();

        $this->repo->create(['project_id' => $projectId, 'title' => 'Úkol A']);
        $this->repo->create(['project_id' => $projectId, 'title' => 'Úkol B']);
        $this->repo->create(['project_id' => $projectId, 'title' => 'Úkol C']);

        $result = $this->repo->all(1, 50);

        $this->assertSame(3, $result['total']);
        $this->assertCount(3, $result['data']);
    }

    /**
     * all() filtruje podle project_id.
     */
    public function test_all_filtrovat_podle_project_id(): void
    {
        $projectId1 = $this->projects->create(['name' => 'Projekt 1']);
        $projectId2 = $this->projects->create(['name' => 'Projekt 2']);

        $this->repo->create(['project_id' => $projectId1, 'title' => 'Úkol A']);
        $this->repo->create(['project_id' => $projectId2, 'title' => 'Úkol B']);
        $this->repo->create(['project_id' => $projectId1, 'title' => 'Úkol C']);

        $result = $this->repo->all(1, 50, '', $projectId1);

        $this->assertSame(2, $result['total']);
        $this->assertCount(2, $result['data']);
        foreach ($result['data'] as $task) {
            $this->assertSame($projectId1, (int) $task['project_id']);
        }
    }

    /**
     * all() filtruje podle status.
     */
    public function test_all_filtrovat_podle_status(): void
    {
        $projectId = $this->createProject();

        $this->repo->create(['project_id' => $projectId, 'title' => 'Úkol A', 'status' => 'todo']);
        $this->repo->create(['project_id' => $projectId, 'title' => 'Úkol B', 'status' => 'done']);
        $this->repo->create(['project_id' => $projectId, 'title' => 'Úkol C', 'status' => 'todo']);

        $result = $this->repo->all(1, 50, '', null, 'done');

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['data']);
        $this->assertSame('done', $result['data'][0]['status']);
    }

    /**
     * all() filtruje podle priority.
     */
    public function test_all_filtrovat_podle_priority(): void
    {
        $projectId = $this->createProject();

        $this->repo->create(['project_id' => $projectId, 'title' => 'Úkol A', 'priority' => 'low']);
        $this->repo->create(['project_id' => $projectId, 'title' => 'Úkol B', 'priority' => 'high']);
        $this->repo->create(['project_id' => $projectId, 'title' => 'Úkol C', 'priority' => 'low']);

        $result = $this->repo->all(1, 50, '', null, null, 'high');

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['data']);
        $this->assertSame('high', $result['data'][0]['priority']);
    }

    /**
     * all() overdue filtr - úkol s due_date v minulosti a status='todo' → nalezen;
     * úkol se status='done' → nenalezen.
     */
    public function test_all_overdue_filtr(): void
    {
        $projectId = $this->createProject();

        // Úkol po termínu (due_date v minulosti) se status='todo' → overdue
        $this->repo->create([
            'project_id' => $projectId,
            'title'      => 'Úkol po termínu',
            'status'     => 'todo',
            'due_date'   => date('Y-m-d', strtotime('-5 days')),
        ]);

        // Úkol po termínu ale se status='done' → není overdue
        $this->repo->create([
            'project_id' => $projectId,
            'title'      => 'Úkol hotový',
            'status'     => 'done',
            'due_date'   => date('Y-m-d', strtotime('-5 days')),
        ]);

        $result = $this->repo->all(1, 50, '', null, null, null, true);

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['data']);
        $this->assertSame('Úkol po termínu', $result['data'][0]['title']);
    }
}
