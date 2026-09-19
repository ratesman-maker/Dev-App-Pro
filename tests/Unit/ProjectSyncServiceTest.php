<?php
declare(strict_types=1);

namespace DevAppPro\Tests\Unit;

use DevAppPro\Tests\UnitTestCase;
use DevAppPro\Services\ProjectSyncService;
use DevAppPro\Repositories\ProjectRepository;

/**
 * Unit testy pro ProjectSyncService.
 * Používá dočasný adresář pro simulaci PROJECTS_WATCH_DIR.
  * @group projects
 */
class ProjectSyncServiceTest extends UnitTestCase
{
    protected string $watchDir;
    protected ProjectRepository $projects;

    protected function setUp(): void
    {
        parent::setUp();

        $this->watchDir = sys_get_temp_dir() . '/devapppro_test_' . uniqid();
        mkdir($this->watchDir, 0755, true);

        $this->projects = new ProjectRepository();
    }

    protected function tearDown(): void
    {
        // Smazat obsah dočasného adresáře
        if (is_dir($this->watchDir)) {
            $this->removeDir($this->watchDir);
        }

        parent::tearDown();
    }

    /**
     * Rekurzivně smaže adresář a jeho obsah.
     */
    private function removeDir(string $dir): void
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * Nová složka → vytvoří projekt v DB.
     */
    public function test_sync_nova_slozka_vytvori_projekt(): void
    {
        mkdir($this->watchDir . '/Test projekt', 0755);

        $service = new ProjectSyncService($this->projects, $this->watchDir);
        $result = $service->sync();

        $this->assertEquals(1, $result['created']);
        $this->assertEquals(0, $result['archived']);
        $this->assertEmpty($result['errors']);

        $project = $this->projects->findByFolderPath('Test projekt');
        $this->assertNotNull($project);
        $this->assertSame('Test projekt', $project['name']);
        $this->assertSame('active', $project['status']);
    }

    /**
     * Skryté složky (začínající .) se ignorují.
     */
    public function test_sync_ignoruje_skryte_slozky(): void
    {
        mkdir($this->watchDir . '/.hidden', 0755);
        mkdir($this->watchDir . '/Viditelný', 0755);

        $service = new ProjectSyncService($this->projects, $this->watchDir);
        $result = $service->sync();

        $this->assertEquals(1, $result['created']);
        $this->assertEmpty($result['errors']);

        $hidden = $this->projects->findByFolderPath('.hidden');
        $this->assertNull($hidden);

        $visible = $this->projects->findByFolderPath('Viditelný');
        $this->assertNotNull($visible);
    }

    /**
     * Symlinky se ignorují.
     */
    public function test_sync_ignoruje_symlinky(): void
    {
        mkdir($this->watchDir . '/Skutecny', 0755);
        // Vytvoř symlink na existující adresář
        $target = $this->watchDir . '/Skutecny';
        $link = $this->watchDir . '/Symlink';
        @symlink($target, $link);

        $service = new ProjectSyncService($this->projects, $this->watchDir);
        $result = $service->sync();

        $this->assertEquals(1, $result['created']);
        $this->assertEmpty($result['errors']);

        $symlinkProject = $this->projects->findByFolderPath('Symlink');
        $this->assertNull($symlinkProject);

        $realProject = $this->projects->findByFolderPath('Skutecny');
        $this->assertNotNull($realProject);
    }

    /**
     * Smazaná složka → projekt se archivuje.
     */
    public function test_sync_smazana_slozka_archivuje_projekt(): void
    {
        // Adresář nesmí být prázdný (prázdný = detekce odpojeného disku)
        mkdir($this->watchDir . '/Aktivni', 0755);

        // Vytvoř projekt v DB s folder_path, jehož složka na disku neexistuje
        $id = $this->projects->create([
            'name'        => 'Smazany',
            'status'      => 'active',
            'folder_path' => 'Smazany',
        ]);

        // Složka na disku neexistuje
        $service = new ProjectSyncService($this->projects, $this->watchDir);
        $result = $service->sync();

        $this->assertEquals(1, $result['created']);
        $this->assertEquals(1, $result['archived']);
        $this->assertEmpty($result['errors']);

        $project = $this->projects->find($id);
        $this->assertSame('archived', $project['status']);
    }

    /**
     * Druhé volání sync nevytváří duplikáty.
     */
    public function test_sync_druhe_volani_nevytvari_duplikaty(): void
    {
        mkdir($this->watchDir . '/Projekt', 0755);

        $service = new ProjectSyncService($this->projects, $this->watchDir);

        $result1 = $service->sync();
        $this->assertEquals(1, $result1['created']);

        $result2 = $service->sync();
        $this->assertEquals(0, $result2['created']);
        $this->assertEquals(0, $result2['archived']);
        $this->assertEmpty($result2['errors']);
    }

    /**
     * Neexistující adresář → vrátí error.
     */
    public function test_sync_neexistujici_adresar_vrati_error(): void
    {
        $service = new ProjectSyncService($this->projects, '/neexistujici/adresar/xyz');
        $result = $service->sync();

        $this->assertEquals(0, $result['created']);
        $this->assertEquals(0, $result['archived']);
        $this->assertNotEmpty($result['errors']);
    }

    /**
     * Neplatný název složky (s ..) je ignorován.
     * Nelze vytvořit na FS, tak testujeme isValidFolderName přímo.
     */
    public function test_sync_neplatny_nazev_slozky_ignorovan(): void
    {
        $service = new ProjectSyncService($this->projects, $this->watchDir);

        // Validní názvy
        $this->assertTrue($service->isValidFolderName('Projekt'));
        $this->assertTrue($service->isValidFolderName('Můj projekt 2026'));

        // Neplatné názvy
        $this->assertFalse($service->isValidFolderName(''));
        $this->assertFalse($service->isValidFolderName('../etc'));
        $this->assertFalse($service->isValidFolderName('a/../b'));
        $this->assertFalse($service->isValidFolderName('foo/bar'));
        $this->assertFalse($service->isValidFolderName(str_repeat('x', 201)));
    }
}
