<?php
declare(strict_types=1);

namespace DevAppPro\Controllers;

use DevAppPro\Core\ApiController;
use DevAppPro\Core\Constants;
use DevAppPro\Auth;
use DevAppPro\Repositories\WorklogRepository;

/**
 * API kontroler pro pracovní deník (CRUD).
 * GET    /api/worklog            → index()  (seznam s paginací)
 * GET    /api/worklog/{id}       → show()   (detail)
 * POST   /api/worklog            → store()  (vytvoření)
 * PUT    /api/worklog/{id}       → update() (úprava)
 * DELETE /api/worklog/{id}       → destroy() (smazání)
 */
class WorklogApiController extends ApiController
{
    private Auth $auth;

    public function __construct()
    {
        $this->auth = $this->repo(\DevAppPro\Auth::class);
    }

    /**
     * Dispatchuje podle HTTP metody.
     * Vše vyžaduje auth, POST/PUT/DELETE vyžadují CSRF.
     */
    public function handle(): void
    {
        $this->auth->requireAuth();

        $method = $this->getMethod();
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $id = $this->getId();

        // Detekce akce pro přílohy: /api/worklog/attachments/{id}/file
        if ($method === 'GET' && preg_match('#^/api/worklog/attachments/(\d+)/file$#', $uri, $m)) {
            $this->serveAttachment((int) $m[1]);
            return;
        }

        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            require_csrf();
        }

        switch ($method) {
            case 'GET':
                if ($id !== null) {
                    $this->show($id);
                } else {
                    $this->index();
                }
                break;
            case 'POST':
                $this->store();
                break;
            case 'PUT':
            case 'PATCH':
                if ($id !== null) {
                    $this->update($id);
                } else {
                    $this->jsonError('ID je vyžadováno pro úpravu.', 400);
                }
                break;
            case 'DELETE':
                if ($id !== null) {
                    $this->destroy($id);
                } else {
                    $this->jsonError('ID je vyžadováno pro smazání.', 400);
                }
                break;
            default:
                $this->jsonError('Nepodporovaná HTTP metoda.', 405);
                break;
        }
    }

    /**
     * GET /api/worklog - seznam záznamů s paginací, vyhledáváním a filtry.
     */
    protected function index(): void
    {
        $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
        $perPage = isset($_GET['per_page']) ? (int) $_GET['per_page'] : Constants::DEFAULT_PER_PAGE;
        $perPage = max(1, min(Constants::MAX_PER_PAGE, $perPage));
        $search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
        $category = isset($_GET['category']) ? (string) $_GET['category'] : null;
        $severity = isset($_GET['severity']) ? (string) $_GET['severity'] : null;
        $projectId = isset($_GET['project_id']) ? (int) $_GET['project_id'] : null;
        $clientId = isset($_GET['client_id']) ? (int) $_GET['client_id'] : null;

        if ($category === '') {
            $category = null;
        }
        if ($severity === '') {
            $severity = null;
        }
        if ($projectId === 0) {
            $projectId = null;
        }
        if ($clientId === 0) {
            $clientId = null;
        }

        $repo = $this->repo(\DevAppPro\Repositories\WorklogRepository::class);
        $result = $repo->all($page, $perPage, $search, $category, $severity, $projectId, $clientId);

        $this->jsonSuccess([
            'data'     => $result['data'],
            'total'    => $result['total'],
            'page'     => $page,
            'per_page' => $perPage,
        ], 200);
    }

    /**
     * GET /api/worklog/{id} - detail záznamu.
     */
    protected function show(?int $id = null): void
    {
        if ($id === null) {
            $this->jsonError('ID je vyžadováno.', 400);
            return;
        }

        $repo = $this->repo(\DevAppPro\Repositories\WorklogRepository::class);
        $entry = $repo->find($id);

        if ($entry === null) {
            $this->jsonError('Záznam nenalezen.', 404);
            return;
        }

        $this->jsonSuccess($entry, 200);
    }

    /**
     * POST /api/worklog - vytvoření záznamu.
     */
    protected function store(): void
    {
        $input = json_input();
        $result = $this->validate($input);

        if (!$result['valid']) {
            json_response(['error' => 'Validace selhala.', 'fields' => $result['fields']], 422);
            return;
        }

        $repo = $this->repo(\DevAppPro\Repositories\WorklogRepository::class);
        $id = $repo->create($result['data']);
        $entry = $repo->find($id);

        $this->jsonSuccess($entry, 201);
    }

    /**
     * PUT /api/worklog/{id} - úprava záznamu.
     */
    protected function update(int $id): void
    {
        $repo = $this->repo(\DevAppPro\Repositories\WorklogRepository::class);

        $existing = $repo->find($id);
        if ($existing === null) {
            $this->jsonError('Záznam nenalezen.', 404);
            return;
        }

        $input = json_input();
        $result = $this->validate($input, true);

        if (!$result['valid']) {
            json_response(['error' => 'Validace selhala.', 'fields' => $result['fields']], 422);
            return;
        }

        $repo->update($id, $result['data']);
        $entry = $repo->find($id);

        $this->jsonSuccess($entry, 200);
    }

    /**
     * DELETE /api/worklog/{id} - smazání záznamu (kaskádově smaže přílohy).
     */
    protected function destroy(int $id): void
    {
        $repo = $this->repo(\DevAppPro\Repositories\WorklogRepository::class);

        $existing = $repo->find($id);
        if ($existing === null) {
            $this->jsonError('Záznam nenalezen.', 404);
            return;
        }

        $repo->delete($id);

        http_response_code(204);
    }

    /**
     * Validace vstupních dat záznamu pracovního deníku.
     * title povinný, ostatní volitelné.
     *
     * @return array{valid: bool, fields: array<string,string>, data: array}
     */
    protected function validate(array $input, bool $isUpdate = false): array
    {
        $fields = [];
        $data = [];

        // user_id - aktuální uživatel
        $user = $this->auth->user();
        $data['user_id'] = $user ? (int) $user['id'] : null;

        // title povinný
        $title = $input['title'] ?? null;
        if (!is_string($title) || trim($title) === '') {
            $fields['title'] = 'Titulek je povinný.';
        } else {
            $title = trim($title);
            if (mb_strlen($title) > 200) {
                $fields['title'] = 'Pole title je příliš dlouhé (max 200 znaků).';
            } else {
                $data['title'] = $title;
            }
        }

        // description volitelný
        $description = $input['description'] ?? null;
        if (is_string($description)) {
            if (mb_strlen($description) > 5000) {
                $fields['description'] = 'Pole description je příliš dlouhé (max 5000 znaků).';
            }
            $data['description'] = $description;
        }

        // category volitelný
        $category = $input['category'] ?? null;
        if (is_string($category) && $category !== '') {
            $validCategories = ['project', 'security', 'maintenance', 'meeting', 'other'];
            if (!in_array($category, $validCategories, true)) {
                $fields['category'] = 'Neplatná kategorie.';
            } else {
                $data['category'] = $category;
            }
        }

        // severity volitelný
        $severity = $input['severity'] ?? null;
        if (is_string($severity) && $severity !== '') {
            $validSeverities = ['info', 'warning', 'critical'];
            if (!in_array($severity, $validSeverities, true)) {
                $fields['severity'] = 'Neplatná závažnost.';
            } else {
                $data['severity'] = $severity;
            }
        }

        // project_id volitelný
        $projectId = $input['project_id'] ?? null;
        if ($projectId !== null && $projectId !== '' && $projectId !== 0) {
            $data['project_id'] = (int) $projectId;
        } else {
            $data['project_id'] = null;
        }

        // client_id volitelný
        $clientId = $input['client_id'] ?? null;
        if ($clientId !== null && $clientId !== '' && $clientId !== 0) {
            $data['client_id'] = (int) $clientId;
        } else {
            $data['client_id'] = null;
        }

        // hours volitelný
        $hours = $input['hours'] ?? null;
        if ($hours !== null && $hours !== '' && is_numeric($hours)) {
            $data['hours'] = (float) $hours;
        } else {
            $data['hours'] = null;
        }

        // is_done volitelný
        $isDone = $input['is_done'] ?? null;
        if ($isDone !== null) {
            $data['is_done'] = $isDone ? 1 : 0;
            if ($isDone && empty($data['done_at'])) {
                $data['done_at'] = date('Y-m-d H:i:s');
            }
        }

        return [
            'valid'  => empty($fields),
            'fields' => $fields,
            'data'   => $data,
        ];
    }

    /**
     * GET /api/worklog/attachments/{id}/file - servíruje binární data přílohy.
     * Obrázky vrací inline (pro zobrazení v <img>), ostatní jako attachment.
     */
    protected function serveAttachment(int $attachmentId): void
    {
        $repo = $this->repo(\DevAppPro\Repositories\WorklogRepository::class);
        $att = $repo->getAttachmentById($attachmentId);

        if ($att === null) {
            $this->jsonError('Příloha nenalezena.', 404);
            return;
        }

        $storageDir = dirname(__DIR__, 2) . '/storage';
        $fullPath = $storageDir . '/' . $att['storage_path'];

        // Bezpečnost: is_safe_path() kontrola proti directory traversal a symlinkům
        if (!is_safe_path($fullPath, $storageDir)) {
            $this->jsonError('Přístup odepřen.', 403);
            return;
        }
        if (!is_file($fullPath)) {
            $this->jsonError('Soubor přílohy neexistuje na disku.', 404);
            return;
        }

        $mime = $att['mime_type'];
        $isImage = (int) $att['is_image'] === 1;

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($fullPath));
        if ($isImage) {
            header('Content-Disposition: inline; filename="' . $att['original_name'] . '"');
            // Cache pro obrázky (neměnné)
            header('Cache-Control: private, max-age=86400, immutable');
        } else {
            header('Content-Disposition: attachment; filename="' . $att['original_name'] . '"');
        }
        readfile($fullPath);
    }
}
