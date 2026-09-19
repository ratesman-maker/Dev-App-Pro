<?php
declare(strict_types=1);

namespace DevAppPro\Tests\Integration;

use DevAppPro\Tests\TestCase;

/**
 * Integrační testy pro Files API (přes HTTP).
  * @group files
 */
class FileApiTest extends TestCase
{
    /**
     * Pomocná metoda - vytvoří klienta přes API a vrátí jeho ID.
     */
    private function createClient(): int
    {
        $response = $this->http->post('/api/clients', [
            'json' => [
                'type'       => 'individual',
                'first_name' => 'Jan',
                'last_name'  => 'Novák',
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);
        $body = json_decode((string) $response->getBody(), true);
        return (int) $body['id'];
    }

    /**
     * Pomocná metoda - upload test.txt souboru, vrátí response.
     */
    private function uploadFile(string $filename = 'test.txt', string $content = 'Test content', string $attachments = '[]')
    {
        return $this->http->request('POST', '/api/files', [
            'multipart' => [
                ['name' => 'file', 'contents' => $content, 'filename' => $filename],
                ['name' => 'attachments', 'contents' => $attachments],
            ],
            'headers' => ['X-CSRF-Token' => $this->csrfToken],
        ]);
    }

    /**
     * GET /api/files bez přihlášení → 401.
     */
    public function test_seznam_bez_prihlaseni_vrati_401(): void
    {
        $response = $this->http->get('/api/files');

        $this->assertEquals(401, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    /**
     * Login, GET /api/files → 200, má "data" a "total".
     */
    public function test_seznam_po_prihlaseni_vrati_200(): void
    {
        $this->login();

        $response = $this->http->get('/api/files');

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('total', $body);
    }

    /**
     * Upload test.txt → 201, original_name="test.txt".
     */
    public function test_upload_souboru(): void
    {
        $this->login();

        $response = $this->uploadFile('test.txt', 'Test content');

        $this->assertEquals(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('test.txt', $body['original_name']);
        $this->assertSame('text/plain', $body['mime_type']);
        $this->assertSame(0, (int) $body['is_image']);
    }

    /**
     * Upload bez souboru → 422.
     */
    public function test_upload_bez_souboru_vrati_422(): void
    {
        $this->login();

        $response = $this->http->request('POST', '/api/files', [
            'multipart' => [
                ['name' => 'attachments', 'contents' => '[]'],
            ],
            'headers' => ['X-CSRF-Token' => $this->csrfToken],
        ]);

        $this->assertEquals(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('fields', $body);
        $this->assertArrayHasKey('file', $body['fields']);
    }

    /**
     * Upload zakázaného typu (test.sh) → 422.
     */
    public function test_upload_zakazany_typ_vrati_422(): void
    {
        $this->login();

        $response = $this->uploadFile('test.sh', '#!/bin/bash');

        $this->assertEquals(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('fields', $body);
        $this->assertArrayHasKey('file', $body['fields']);
    }

    /**
     * Upload příliš velkého souboru (> 10 MB) → 422.
     */
    public function test_upload_prilis_velky_vrati_422(): void
    {
        $this->login();

        // Vytvořím dočasný soubor větší než 10 MB
        $tmpFile = tempnam(sys_get_temp_dir(), 'bigtest');
        // 11 MB
        $fh = fopen($tmpFile, 'wb');
        // Zapíšu 11 MB nul (ftruncate je rychlejší)
        ftruncate($fh, 11 * 1024 * 1024);
        fclose($fh);

        $response = $this->http->request('POST', '/api/files', [
            'multipart' => [
                [
                    'name' => 'file',
                    'contents' => fopen($tmpFile, 'rb'),
                    'filename' => 'big.txt',
                ],
                ['name' => 'attachments', 'contents' => '[]'],
            ],
            'headers' => ['X-CSRF-Token' => $this->csrfToken],
        ]);

        @unlink($tmpFile);

        $this->assertEquals(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('fields', $body);
        $this->assertArrayHasKey('file', $body['fields']);
    }

    /**
     * Vytvořím klienta, upload s attachments → 201, GET → attachments.
     */
    public function test_upload_s_attachments(): void
    {
        $this->login();
        $clientId = $this->createClient();

        $response = $this->uploadFile(
            'test.txt',
            'Test content',
            json_encode([['entity_type' => 'client', 'entity_id' => $clientId]])
        );

        $this->assertEquals(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('attachments', $body);
        $this->assertCount(1, $body['attachments']);
        $this->assertSame('client', $body['attachments'][0]['entity_type']);
        $this->assertSame($clientId, (int) $body['attachments'][0]['entity_id']);

        // GET detail → attachments s entity_name
        $getResp = $this->http->get('/api/files/' . $body['id']);
        $getBody = json_decode((string) $getResp->getBody(), true);
        $this->assertCount(1, $getBody['attachments']);
        $this->assertSame('Jan Novák', $getBody['attachments'][0]['entity_name']);
    }

    /**
     * GET /api/files/99999 → 404.
     */
    public function test_detail_vrati_404(): void
    {
        $this->login();

        $response = $this->http->get('/api/files/99999');

        $this->assertEquals(404, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    /**
     * Upload, GET → 200, má original_name.
     */
    public function test_detail_po_uploadu(): void
    {
        $this->login();

        $createResp = $this->uploadFile('detail.txt', 'Detail content');
        $created = json_decode((string) $createResp->getBody(), true);
        $id = $created['id'];

        $response = $this->http->get('/api/files/' . $id);

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('detail.txt', $body['original_name']);
    }

    /**
     * Upload, GET /api/files/{id}/download → 200, Content-Disposition, obsah.
     */
    public function test_download_souboru(): void
    {
        $this->login();

        $createResp = $this->uploadFile('download.txt', 'Download content');
        $created = json_decode((string) $createResp->getBody(), true);
        $id = $created['id'];

        $response = $this->http->get('/api/files/' . $id . '/download');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('attachment', $response->getHeaderLine('Content-Disposition'));
        $this->assertStringContainsString('download.txt', $response->getHeaderLine('Content-Disposition'));
        $this->assertSame('Download content', (string) $response->getBody());
    }

    /**
     * Upload, PUT {attachments} → 200.
     */
    public function test_update_attachments(): void
    {
        $this->login();
        $clientId = $this->createClient();

        $createResp = $this->uploadFile('update.txt', 'Update content');
        $created = json_decode((string) $createResp->getBody(), true);
        $id = $created['id'];

        $this->assertCount(0, $created['attachments']);

        $response = $this->http->request('PUT', '/api/files/' . $id, [
            'json' => [
                'attachments' => [
                    ['entity_type' => 'client', 'entity_id' => $clientId],
                ],
            ],
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertCount(1, $body['attachments']);
        $this->assertSame($clientId, (int) $body['attachments'][0]['entity_id']);
    }

    /**
     * Upload, DELETE → 204, GET → 404.
     */
    public function test_delete_souboru(): void
    {
        $this->login();

        $createResp = $this->uploadFile('delete.txt', 'Delete content');
        $created = json_decode((string) $createResp->getBody(), true);
        $id = $created['id'];

        $deleteResp = $this->http->request('DELETE', '/api/files/' . $id, [
            'headers' => [
                'X-CSRF-Token' => $this->csrfToken,
            ],
        ]);

        $this->assertEquals(204, $deleteResp->getStatusCode());

        $getResp = $this->http->get('/api/files/' . $id);
        $this->assertEquals(404, $getResp->getStatusCode());
    }

    /**
     * Login, POST bez X-CSRF-Token → 403.
     */
    public function test_post_bez_csrf_vrati_403(): void
    {
        $this->login();

        $response = $this->http->request('POST', '/api/files', [
            'multipart' => [
                ['name' => 'file', 'contents' => 'Test', 'filename' => 'test.txt'],
                ['name' => 'attachments', 'contents' => '[]'],
            ],
            // Záměrně bez X-CSRF-Token hlavičky
        ]);

        $this->assertEquals(403, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }
}
