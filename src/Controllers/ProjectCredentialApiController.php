<?php
declare(strict_types=1);

namespace DevAppPro\Controllers;

use DevAppPro\Core\ApiController;
use DevAppPro\Auth;
use DevAppPro\Repositories\ProjectCredentialRepository;

/**
 * API kontroler pro přístupy k projektům (CRUD).
 * GET    /api/projects/{projectId}/credentials        → index()  (seznam pro projekt)
 * GET    /api/project-credentials/{id}                → show()   (detail)
 * POST   /api/projects/{projectId}/credentials        → store()  (vytvoření)
 * PUT    /api/project-credentials/{id}                → update() (úprava)
 * DELETE /api/project-credentials/{id}                → destroy() (smazání)
 */
class ProjectCredentialApiController extends ApiController
{
    private Auth $auth;

    public function __construct()
    {
        $this->auth = $this->repo(\DevAppPro\Auth::class);
    }

    public function handle(): void
    {
        $this->auth->requireAuth();

        $method = $this->getMethod();
        $id = $this->getId();

        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            require_csrf();
        }

        // Parse path: /api/projects/{id}/credentials nebo /api/project-credentials/{id}
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
        $parts = array_values(array_filter(explode('/', $path), fn($p) => $p !== ''));

        // /api/projects/{projectId}/credentials
        $projectId = null;
        if (count($parts) >= 4 && $parts[1] === 'projects' && $parts[3] === 'credentials') {
            $projectId = (int) $parts[2];
        }

        // /api/project-credentials/{id}
        $credentialId = null;
        if (count($parts) >= 3 && $parts[1] === 'project-credentials') {
            $credentialId = (int) $parts[2];
        }

        switch ($method) {
            case 'GET':
                if ($credentialId !== null && $credentialId > 0) {
                    $this->showCredential($credentialId);
                } elseif ($projectId !== null && $projectId > 0) {
                    $this->indexForProject($projectId);
                } else {
                    $this->jsonError('ID projektu nebo přístupu je vyžadováno.', 400);
                }
                break;
            case 'POST':
                if ($projectId !== null && $projectId > 0) {
                    $this->storeCredential($projectId);
                } else {
                    $this->jsonError('ID projektu je vyžadováno.', 400);
                }
                break;
            case 'PUT':
            case 'PATCH':
                if ($credentialId !== null && $credentialId > 0) {
                    $this->updateCredential($credentialId);
                } else {
                    $this->jsonError('ID přístupu je vyžadováno.', 400);
                }
                break;
            case 'DELETE':
                if ($credentialId !== null && $credentialId > 0) {
                    $this->destroyCredential($credentialId);
                } else {
                    $this->jsonError('ID přístupu je vyžadováno.', 400);
                }
                break;
            default:
                $this->jsonError('Nepodporovaná HTTP metoda.', 405);
                break;
        }
    }

    /**
     * GET /api/projects/{projectId}/credentials - seznam přístupů pro projekt.
     */
    protected function indexForProject(int $projectId): void
    {
        $repo = $this->repo(\DevAppPro\Repositories\ProjectCredentialRepository::class);
        $credentials = $repo->allForProject($projectId);
        $this->jsonSuccess(['data' => $credentials]);
    }

    /**
     * GET /api/project-credentials/{id} - detail přístupu.
     */
    protected function showCredential(int $id): void
    {
        $repo = $this->repo(\DevAppPro\Repositories\ProjectCredentialRepository::class);
        $credential = $repo->find($id);
        if ($credential === null) {
            $this->jsonError('Přístup nenalezen.', 404);
            return;
        }
        $this->jsonSuccess($credential);
    }

    /**
     * POST /api/projects/{projectId}/credentials - vytvoření přístupu.
     */
    protected function storeCredential(int $projectId): void
    {
        $repo = $this->repo(\DevAppPro\Repositories\ProjectCredentialRepository::class);

        if (!$repo->projectExists($projectId)) {
            $this->jsonError('Projekt nenalezen.', 404);
            return;
        }

        $input = json_input();
        $result = $this->validate($input, $projectId);

        if (!$result['valid']) {
            json_response(['error' => 'Validace selhala.', 'fields' => $result['fields']], 422);
            return;
        }

        $id = $repo->create($result['data']);
        $credential = $repo->find($id);
        $this->jsonSuccess($credential, 201);
    }

    /**
     * PUT /api/project-credentials/{id} - úprava přístupu.
     */
    protected function updateCredential(int $id): void
    {
        $repo = $this->repo(\DevAppPro\Repositories\ProjectCredentialRepository::class);

        $existing = $repo->find($id);
        if ($existing === null) {
            $this->jsonError('Přístup nenalezen.', 404);
            return;
        }

        $input = json_input();
        $result = $this->validate($input, (int) $existing['project_id'], $id);

        if (!$result['valid']) {
            json_response(['error' => 'Validace selhala.', 'fields' => $result['fields']], 422);
            return;
        }

        $repo->update($id, $result['data']);
        $credential = $repo->find($id);
        $this->jsonSuccess($credential);
    }

    /**
     * DELETE /api/project-credentials/{id} - smazání přístupu.
     */
    protected function destroyCredential(int $id): void
    {
        $repo = $this->repo(\DevAppPro\Repositories\ProjectCredentialRepository::class);

        $existing = $repo->find($id);
        if ($existing === null) {
            $this->jsonError('Přístup nenalezen.', 404);
            return;
        }

        $repo->delete($id);
        $this->jsonSuccess(['deleted' => true]);
    }

    /**
     * Validace vstupu.
     */
    private function validate(array $input, int $projectId, ?int $id = null): array
    {
        $fields = [];
        $data = [];

        $data['project_id'] = $projectId;

        // type - povinný, z enum
        $type = $input['type'] ?? null;
        if ($type === null || $type === '') {
            $fields['type'] = 'Typ je povinný.';
        } elseif (!in_array($type, ProjectCredentialRepository::ALLOWED_TYPES, true)) {
            $fields['type'] = 'Typ musí být: ' . implode(', ', ProjectCredentialRepository::ALLOWED_TYPES) . '.';
        } else {
            $data['type'] = $type;
        }

        // name - povinný
        $name = $input['name'] ?? null;
        if (is_string($name)) {
            $name = trim($name);
        }
        if ($name === null || $name === '') {
            $fields['name'] = 'Název je povinný.';
        } elseif (mb_strlen($name) > 200) {
            $fields['name'] = 'Pole name je příliš dlouhé (max 200 znaků).';
        } else {
            $data['name'] = $name;
        }

        // host - volitelný
        $host = $input['host'] ?? null;
        if (is_string($host) && trim($host) !== '') {
            $host = trim($host);
            if (mb_strlen($host) > 2000) {
                $fields['host'] = 'Pole host je příliš dlouhé (max 2000 znaků).';
            }
            $data['host'] = $host;
        } else {
            $data['host'] = null;
        }

        // port - volitelný, 1-65535
        $port = $input['port'] ?? null;
        if ($port !== null && $port !== '') {
            $port = (int) $port;
            if ($port < 1 || $port > 65535) {
                $fields['port'] = 'Port musí být v rozsahu 1-65535.';
            } else {
                $data['port'] = $port;
            }
        } else {
            $data['port'] = null;
        }

        // username - volitelný
        $username = $input['username'] ?? null;
        if (is_string($username) && trim($username) !== '') {
            $username = trim($username);
            if (mb_strlen($username) > 100) {
                $fields['username'] = 'Pole username je příliš dlouhé (max 100 znaků).';
            }
            $data['username'] = $username;
        } else {
            $data['username'] = null;
        }

        // password - volitelný (plain text ze vstupu, šifruje se v repozitáři)
        $password = $input['password'] ?? null;
        if (is_string($password)) {
            if (mb_strlen($password) > 100) {
                $fields['password'] = 'Pole password je příliš dlouhé (max 100 znaků).';
            }
            $data['password'] = $password;
        } else {
            $data['password'] = '';
        }

        // database_name - volitelný
        $dbName = $input['database_name'] ?? null;
        if (is_string($dbName) && trim($dbName) !== '') {
            $dbName = trim($dbName);
            if (mb_strlen($dbName) > 100) {
                $fields['database_name'] = 'Pole database_name je příliš dlouhé (max 100 znaků).';
            }
            $data['database_name'] = $dbName;
        } else {
            $data['database_name'] = null;
        }

        // extra - volitelný (JSON string pro další pole, např. SSL flag, API key)
        $extra = $input['extra'] ?? null;
        if (is_string($extra) && trim($extra) !== '') {
            $extra = trim($extra);
            if (mb_strlen($extra) > 5000) {
                $fields['extra'] = 'Pole extra je příliš dlouhé (max 5000 znaků).';
            }
            $data['extra'] = $extra;
        } else {
            $data['extra'] = null;
        }

        // note - volitelný
        $note = $input['note'] ?? null;
        if (is_string($note) && trim($note) !== '') {
            $note = trim($note);
            if (mb_strlen($note) > 5000) {
                $fields['note'] = 'Pole note je příliš dlouhé (max 5000 znaků).';
            }
            $data['note'] = $note;
        } else {
            $data['note'] = null;
        }

        return [
            'valid'  => empty($fields),
            'fields' => $fields,
            'data'   => $data,
        ];
    }
}
