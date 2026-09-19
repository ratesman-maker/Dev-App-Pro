<?php
declare(strict_types=1);

namespace DevAppPro\Tests\Unit;

use DevAppPro\Tests\TestCase;
use DevAppPro\Repositories\NoteRepository;
use DevAppPro\Repositories\ClientRepository;

/**
 * Unit testy pro NoteRepository (přímo nad DB přes TestCase).
 */
class NoteRepositoryTest extends TestCase
{
    private NoteRepository $repo;
    private ClientRepository $clients;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new NoteRepository();
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
     * Vytvoří poznámku a ověří content.
     */
    public function test_create_poznamka(): void
    {
        $id = $this->repo->create(['content' => 'Testovací poznámka']);

        $this->assertGreaterThan(0, $id);

        $note = $this->repo->find($id);
        $this->assertNotNull($note);
        $this->assertSame('Testovací poznámka', $note['content']);
    }

    /**
     * Vytvoří poznámku s attachments a ověří noteables.
     */
    public function test_create_s_attachments(): void
    {
        $clientId = $this->createClient();

        $id = $this->repo->create(
            ['content' => 'Poznámka s přílohou'],
            [['entity_type' => 'client', 'entity_id' => $clientId]]
        );

        $attachments = $this->repo->getAttachments($id);
        $this->assertCount(1, $attachments);
        $this->assertSame('client', $attachments[0]['entity_type']);
        $this->assertSame($clientId, (int) $attachments[0]['entity_id']);
    }

    /**
     * find() vrátí attachments včetně entity_name (full_name klienta).
     */
    public function test_find_vrati_attachments_s_entity_name(): void
    {
        $clientId = $this->createClient();

        $id = $this->repo->create(
            ['content' => 'Poznámka'],
            [['entity_type' => 'client', 'entity_id' => $clientId]]
        );

        $note = $this->repo->find($id);
        $this->assertNotNull($note);
        $this->assertArrayHasKey('attachments', $note);
        $this->assertCount(1, $note['attachments']);
        $this->assertSame('Jan Novák', $note['attachments'][0]['entity_name']);
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

        // Vytvořím s 2 attachments
        $id = $this->repo->create(
            ['content' => 'Poznámka'],
            [
                ['entity_type' => 'client', 'entity_id' => $clientId1],
                ['entity_type' => 'client', 'entity_id' => $clientId2],
            ]
        );

        $this->assertCount(2, $this->repo->getAttachments($id));

        // Update s 1 attachment
        $this->repo->update($id, ['content' => 'Změněno'], [
            ['entity_type' => 'client', 'entity_id' => $clientId1],
        ]);

        $attachments = $this->repo->getAttachments($id);
        $this->assertCount(1, $attachments);
        $this->assertSame($clientId1, (int) $attachments[0]['entity_id']);

        $note = $this->repo->find($id);
        $this->assertSame('Změněno', $note['content']);
    }

    /**
     * delete() kaskádově smaže noteables.
     */
    public function test_delete_kaskadne_smaze_noteables(): void
    {
        $clientId = $this->createClient();

        $id = $this->repo->create(
            ['content' => 'Poznámka'],
            [['entity_type' => 'client', 'entity_id' => $clientId]]
        );

        $this->assertCount(1, $this->repo->getAttachments($id));

        $this->assertTrue($this->repo->delete($id));
        $this->assertNull($this->repo->find($id));

        // noteables by měly být smazány kaskádově
        $this->assertCount(0, $this->repo->getAttachments($id));
    }

    /**
     * all() vrátí seznam poznámek s paginací.
     */
    public function test_all_seznam_s_paginaci(): void
    {
        $this->repo->create(['content' => 'Poznámka A']);
        $this->repo->create(['content' => 'Poznámka B']);
        $this->repo->create(['content' => 'Poznámka C']);

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

        // 2 poznámky s attachment na klienta
        $this->repo->create(
            ['content' => 'Poznámka 1'],
            [['entity_type' => 'client', 'entity_id' => $clientId]]
        );
        $this->repo->create(
            ['content' => 'Poznámka 2'],
            [['entity_type' => 'client', 'entity_id' => $clientId]]
        );
        // 1 poznámka bez attachment
        $this->repo->create(['content' => 'Poznámka 3']);

        $result = $this->repo->all(1, 50, '', 'client', $clientId);

        $this->assertSame(2, $result['total']);
        $this->assertCount(2, $result['data']);
    }

    /**
     * all() vyhledává podle content.
     */
    public function test_all_search_podle_content(): void
    {
        $this->repo->create(['content' => 'Důležitá poznámka']);
        $this->repo->create(['content' => 'Běžná poznámka']);

        $result = $this->repo->all(1, 50, 'Důležitá');

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['data']);
        $this->assertSame('Důležitá poznámka', $result['data'][0]['content']);
    }
}
