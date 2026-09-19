<?php
declare(strict_types=1);

namespace DevAppPro\Controllers;

use DevAppPro\Core\ApiController;
use DevAppPro\Core\Constants;
use DevAppPro\Auth;
use DevAppPro\Repositories\NoteRepository;

/**
 * API kontroler pro poznámky (CRUD).
 * GET    /api/notes        → index()  (seznam s paginací)
 * GET    /api/notes/{id}   → show()   (detail)
 * POST   /api/notes        → store()  (vytvoření)
 * PUT    /api/notes/{id}   → update() (úprava)
 * DELETE /api/notes/{id}   → destroy() (smazání)
 */
class NoteApiController extends ApiController
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
        $id = $this->getId();

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
     * GET /api/notes - seznam poznámek s paginací, vyhledáváním a filtrem podle entity.
     */
    protected function index(): void
    {
        $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
        $perPage = isset($_GET['per_page']) ? (int) $_GET['per_page'] : Constants::DEFAULT_PER_PAGE;
        $perPage = max(1, min(Constants::MAX_PER_PAGE, $perPage));
        $search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
        $entityType = isset($_GET['entity_type']) ? (string) $_GET['entity_type'] : null;
        $entityId = isset($_GET['entity_id']) ? (int) $_GET['entity_id'] : null;

        if ($entityType === '') {
            $entityType = null;
        }
        if ($entityId === 0) {
            $entityId = null;
        }

        $repo = $this->repo(\DevAppPro\Repositories\NoteRepository::class);
        $result = $repo->all($page, $perPage, $search, $entityType, $entityId);

        $this->jsonSuccess([
            'data'     => $result['data'],
            'total'    => $result['total'],
            'page'     => $page,
            'per_page' => $perPage,
        ], 200);
    }

    /**
     * GET /api/notes/{id} - detail poznámky.
     */
    protected function show(?int $id = null): void
    {
        if ($id === null) {
            $this->jsonError('ID je vyžadováno.', 400);
            return;
        }

        $repo = $this->repo(\DevAppPro\Repositories\NoteRepository::class);
        $note = $repo->find($id);

        if ($note === null) {
            $this->jsonError('Poznámka nenalezena.', 404);
            return;
        }

        $this->jsonSuccess($note, 200);
    }

    /**
     * POST /api/notes - vytvoření poznámky.
     */
    protected function store(): void
    {
        $input = json_input();
        $result = $this->validate($input);

        if (!$result['valid']) {
            json_response(['error' => 'Validace selhala.', 'fields' => $result['fields']], 422);
            return;
        }

        $repo = $this->repo(\DevAppPro\Repositories\NoteRepository::class);
        $id = $repo->create($result['data'], $result['attachments']);
        $note = $repo->find($id);

        $this->jsonSuccess($note, 201);
    }

    /**
     * PUT /api/notes/{id} - úprava poznámky (attachments se nahradí celým seznamem).
     */
    protected function update(int $id): void
    {
        $repo = $this->repo(\DevAppPro\Repositories\NoteRepository::class);

        $existing = $repo->find($id);
        if ($existing === null) {
            $this->jsonError('Poznámka nenalezena.', 404);
            return;
        }

        $input = json_input();
        $result = $this->validate($input, true);

        if (!$result['valid']) {
            json_response(['error' => 'Validace selhala.', 'fields' => $result['fields']], 422);
            return;
        }

        $repo->update($id, $result['data'], $result['attachments']);
        $note = $repo->find($id);

        $this->jsonSuccess($note, 200);
    }

    /**
     * DELETE /api/notes/{id} - smazání poznámky (kaskádově smaže noteables).
     */
    protected function destroy(int $id): void
    {
        $repo = $this->repo(\DevAppPro\Repositories\NoteRepository::class);

        $existing = $repo->find($id);
        if ($existing === null) {
            $this->jsonError('Poznámka nenalezena.', 404);
            return;
        }

        $repo->delete($id);

        http_response_code(204);
    }

    /**
     * Validace vstupních dat poznámky.
     * content povinné, title volitelný, attachments pole [{entity_type, entity_id}].
     *
     * @return array{valid: bool, fields: array<string,string>, data: array, attachments: array}
     */
    protected function validate(array $input, bool $isUpdate = false): array
    {
        $fields = [];
        $data = [];

        // title volitelný
        $title = $input['title'] ?? null;
        if (is_string($title)) {
            $title = trim($title);
            if ($title === '') {
                $title = null;
            }
        } elseif (!is_null($title)) {
            $title = null;
        }
        if ($title !== null && mb_strlen($title) > 200) {
            $fields['title'] = 'Pole title je příliš dlouhé (max 200 znaků).';
        }
        $data['title'] = $title;

        // content povinné
        $content = $input['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            $fields['content'] = 'Obsah poznámky je povinný.';
        } elseif (mb_strlen($content) > 5000) {
            $fields['content'] = 'Pole content je příliš dlouhé (max 5000 znaků).';
        } else {
            $data['content'] = $content;
        }

        // attachments volitelné
        $attachments = [];
        $rawAttachments = $input['attachments'] ?? [];
        if (!is_array($rawAttachments)) {
            $fields['attachments'] = 'Přílohy musí být pole.';
        } else {
            $validTypes = ['client', 'project', 'task', 'invoice', 'transaction'];
            $repo = $this->repo(\DevAppPro\Repositories\NoteRepository::class);
            foreach ($rawAttachments as $idx => $att) {
                if (!is_array($att)) {
                    $fields['attachments'] = 'Přílohy musí obsahovat objekty s entity_type a entity_id.';
                    break;
                }
                $type = $att['entity_type'] ?? null;
                $eid = $att['entity_id'] ?? null;
                if (!is_string($type) || !in_array($type, $validTypes, true)) {
                    $fields['attachments'] = 'Neplatný typ entity v přílohách.';
                    break;
                }
                if ($eid === null || !is_numeric($eid)) {
                    $fields['attachments'] = 'Neplatné ID entity v přílohách.';
                    break;
                }
                $eid = (int) $eid;
                if (!$repo->entityExists($type, $eid)) {
                    $fields['attachments'] = 'Odkazovaná entita neexistuje.';
                    break;
                }
                $attachments[] = ['entity_type' => $type, 'entity_id' => $eid];
            }
        }

        return [
            'valid'       => empty($fields),
            'fields'      => $fields,
            'data'        => $data,
            'attachments' => $attachments,
        ];
    }
}
