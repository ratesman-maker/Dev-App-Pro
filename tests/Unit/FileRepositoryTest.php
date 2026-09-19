<?php
declare(strict_types=1);

namespace DevAppPro\Tests\Unit;

use DevAppPro\Tests\TestCase;
use DevAppPro\Repositories\FileRepository;
use DevAppPro\Repositories\ClientRepository;

/**
 * Unit testy pro FileRepository (přímo nad DB přes TestCase).
 */
class FileRepositoryTest extends TestCase
{
    private FileRepository $repo;
    private ClientRepository $clients;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new FileRepository();
        $this->clients = new ClientRepository();
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
        ]);
    }

    /**
     * Pomocná metoda - vytvoří záznam souboru (bez fyzického souboru).
     */
    private function createFileRecord(): int
    {
        return $this->repo->create([
            'original_name'  => 'test.txt',
            'stored_name'    => 'uuid-test.txt',
            'mime_type'      => 'text/plain',
            'size_bytes'     => 12,
            'storage_path'   => '2026/09/uuid-test.txt',
            'is_image'       => 0,
            'thumbnail_path' => null,
            'medium_path'    => null,
        ]);
    }

    /**
     * Vytvoří soubor a ověří original_name.
     */
    public function test_create_soubor(): void
    {
        $id = $this->createFileRecord();

        $this->assertGreaterThan(0, $id);

        $file = $this->repo->find($id);
        $this->assertNotNull($file);
        $this->assertSame('test.txt', $file['original_name']);
    }

    /**
     * Vytvoří soubor s attachments a ověří fileables.
     */
    public function test_create_s_attachments(): void
    {
        $clientId = $this->createClient();

        $id = $this->repo->create(
            [
                'original_name'  => 'test.txt',
                'stored_name'    => 'uuid-test.txt',
                'mime_type'      => 'text/plain',
                'size_bytes'     => 12,
                'storage_path'   => '2026/09/uuid-test.txt',
                'is_image'       => 0,
                'thumbnail_path' => null,
                'medium_path'    => null,
            ],
            [['entity_type' => 'client', 'entity_id' => $clientId]]
        );

        $attachments = $this->repo->getAttachments($id);
        $this->assertCount(1, $attachments);
        $this->assertSame('client', $attachments[0]['entity_type']);
        $this->assertSame($clientId, (int) $attachments[0]['entity_id']);
    }

    /**
     * find() vrátí attachments.
     */
    public function test_find_vrati_attachments(): void
    {
        $clientId = $this->createClient();

        $id = $this->repo->create(
            [
                'original_name'  => 'test.txt',
                'stored_name'    => 'uuid-test.txt',
                'mime_type'      => 'text/plain',
                'size_bytes'     => 12,
                'storage_path'   => '2026/09/uuid-test.txt',
                'is_image'       => 0,
                'thumbnail_path' => null,
                'medium_path'    => null,
            ],
            [['entity_type' => 'client', 'entity_id' => $clientId]]
        );

        $file = $this->repo->find($id);
        $this->assertNotNull($file);
        $this->assertArrayHasKey('attachments', $file);
        $this->assertCount(1, $file['attachments']);
        $this->assertSame('Jan Novák', $file['attachments'][0]['entity_name']);
    }

    /**
     * update() nahradí celý seznam attachments.
     */
    public function test_update_nahradi_attachments(): void
    {
        $clientId1 = $this->createClient();
        $clientId2 = $this->clients->create([
            'type'       => 'individual',
            'first_name' => 'Petr',
            'last_name'  => 'Svoboda',
        ]);

        $id = $this->repo->create(
            [
                'original_name'  => 'test.txt',
                'stored_name'    => 'uuid-test.txt',
                'mime_type'      => 'text/plain',
                'size_bytes'     => 12,
                'storage_path'   => '2026/09/uuid-test.txt',
                'is_image'       => 0,
                'thumbnail_path' => null,
                'medium_path'    => null,
            ],
            [
                ['entity_type' => 'client', 'entity_id' => $clientId1],
                ['entity_type' => 'client', 'entity_id' => $clientId2],
            ]
        );

        $this->assertCount(2, $this->repo->getAttachments($id));

        // Update s 1 attachment
        $this->repo->update($id, [
            ['entity_type' => 'client', 'entity_id' => $clientId1],
        ]);

        $attachments = $this->repo->getAttachments($id);
        $this->assertCount(1, $attachments);
        $this->assertSame($clientId1, (int) $attachments[0]['entity_id']);
    }

    /**
     * delete() kaskádově smaže fileables.
     */
    public function test_delete_kaskadne_smaze_fileables(): void
    {
        $clientId = $this->createClient();

        $id = $this->repo->create(
            [
                'original_name'  => 'test.txt',
                'stored_name'    => 'uuid-test.txt',
                'mime_type'      => 'text/plain',
                'size_bytes'     => 12,
                'storage_path'   => '2026/09/uuid-test.txt',
                'is_image'       => 0,
                'thumbnail_path' => null,
                'medium_path'    => null,
            ],
            [['entity_type' => 'client', 'entity_id' => $clientId]]
        );

        $this->assertCount(1, $this->repo->getAttachments($id));

        $this->assertTrue($this->repo->delete($id));
        $this->assertNull($this->repo->find($id));

        // fileables by měly být smazány kaskádově
        $this->assertCount(0, $this->repo->getAttachments($id));
    }

    /**
     * all() vrátí seznam souborů s paginací.
     */
    public function test_all_seznam_s_paginaci(): void
    {
        $this->createFileRecord();
        $this->createFileRecord();
        $this->createFileRecord();

        $result = $this->repo->all(1, 50);

        $this->assertSame(3, $result['total']);
        $this->assertCount(3, $result['data']);
    }

    /**
     * all() filtruje podle entity (entity_type + entity_id).
     */
    public function test_all_filtrovat_podle_entity(): void
    {
        $clientId = $this->createClient();

        // 2 soubory s attachment na klienta
        $this->repo->create(
            [
                'original_name'  => 'a.txt',
                'stored_name'    => 'a.txt',
                'mime_type'      => 'text/plain',
                'size_bytes'     => 1,
                'storage_path'   => '2026/09/a.txt',
                'is_image'       => 0,
                'thumbnail_path' => null,
                'medium_path'    => null,
            ],
            [['entity_type' => 'client', 'entity_id' => $clientId]]
        );
        $this->repo->create(
            [
                'original_name'  => 'b.txt',
                'stored_name'    => 'b.txt',
                'mime_type'      => 'text/plain',
                'size_bytes'     => 1,
                'storage_path'   => '2026/09/b.txt',
                'is_image'       => 0,
                'thumbnail_path' => null,
                'medium_path'    => null,
            ],
            [['entity_type' => 'client', 'entity_id' => $clientId]]
        );
        // 1 soubor bez attachment
        $this->createFileRecord();

        $result = $this->repo->all(1, 50, 'client', $clientId);

        $this->assertSame(2, $result['total']);
        $this->assertCount(2, $result['data']);
    }
}
