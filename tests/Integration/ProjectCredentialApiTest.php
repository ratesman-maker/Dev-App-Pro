<?php
declare(strict_types=1);

namespace DevAppPro\Tests\Integration;

use DevAppPro\Tests\TestCase;

/**
 * Integrační testy pro přístupy k projektům: heslo jen v detailu, ne v seznamu.
 * @group security
 */
class ProjectCredentialApiTest extends TestCase
{
    /**
     * Seznam přístupů nevrací heslo (jen has_password), detail heslo vrací.
     */
    public function test_heslo_je_v_detailu_ne_v_seznamu(): void
    {
        $this->login();

        $project = $this->http->post('/api/projects', [
            'json' => ['name' => 'Credentials test'],
            'headers' => ['X-CSRF-Token' => $this->csrfToken],
        ]);
        $projectId = (int) json_decode((string) $project->getBody(), true)['id'];

        $created = $this->http->post("/api/projects/{$projectId}/credentials", [
            'json' => ['type' => 'ftp', 'name' => 'FTP test', 'username' => 'u', 'password' => 'tajne-heslo'],
            'headers' => ['X-CSRF-Token' => $this->csrfToken],
        ]);
        $this->assertEquals(201, $created->getStatusCode(), (string) $created->getBody());
        $credId = (int) json_decode((string) $created->getBody(), true)['id'];

        $list = json_decode((string) $this->http->get("/api/projects/{$projectId}/credentials")->getBody(), true);
        $item = $list['data'][0];
        $this->assertArrayNotHasKey('password', $item);
        $this->assertArrayNotHasKey('password_encrypted', $item);
        $this->assertTrue($item['has_password']);

        $detail = json_decode((string) $this->http->get("/api/project-credentials/{$credId}")->getBody(), true);
        $this->assertSame('tajne-heslo', $detail['password']);
    }

    /**
     * Úprava bez pole password ponechá stávající heslo.
     */
    public function test_uprava_bez_hesla_ponecha_stavajici(): void
    {
        $this->login();

        $project = $this->http->post('/api/projects', [
            'json' => ['name' => 'Credentials update test'],
            'headers' => ['X-CSRF-Token' => $this->csrfToken],
        ]);
        $projectId = (int) json_decode((string) $project->getBody(), true)['id'];

        $created = $this->http->post("/api/projects/{$projectId}/credentials", [
            'json' => ['type' => 'ssh', 'name' => 'SSH test', 'password' => 'zustane'],
            'headers' => ['X-CSRF-Token' => $this->csrfToken],
        ]);
        $credId = (int) json_decode((string) $created->getBody(), true)['id'];

        $this->http->put("/api/project-credentials/{$credId}", [
            'json' => ['type' => 'ssh', 'name' => 'SSH test přejmenovaný'],
            'headers' => ['X-CSRF-Token' => $this->csrfToken],
        ]);

        $detail = json_decode((string) $this->http->get("/api/project-credentials/{$credId}")->getBody(), true);
        $this->assertSame('zustane', $detail['password']);
        $this->assertSame('SSH test přejmenovaný', $detail['name']);
    }
}
