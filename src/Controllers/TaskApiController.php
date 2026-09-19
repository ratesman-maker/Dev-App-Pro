<?php
declare(strict_types=1);

namespace DevAppPro\Controllers;

use DevAppPro\Core\ApiController;
use DevAppPro\Core\Constants;
use DevAppPro\Auth;
use DevAppPro\Repositories\TaskRepository;
use DevAppPro\Services\NotificationService;

/**
 * API kontroler pro úkoly (CRUD).
 * GET    /api/tasks        → index()  (seznam s paginací)
 * GET    /api/tasks/{id}   → show()   (detail)
 * POST   /api/tasks        → store()  (vytvoření)
 * PUT    /api/tasks/{id}   → update() (úprava)
 * DELETE /api/tasks/{id}   → destroy() (smazání)
 */
class TaskApiController extends ApiController
{
    private Auth $auth;

    public function __construct()
    {
        $this->auth = $this->repo(\DevAppPro\Auth::class);
    }

    /**
     * Dispatchuje podle HTTP metody a URI.
     * Vše vyžaduje auth, POST/PUT/DELETE vyžadují CSRF.
     */
    public function handle(): void
    {
        $this->auth->requireAuth();

        $method = $this->getMethod();
        $id = $this->getId();

        // CSRF ochrana pro mutující operace
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
     * GET /api/tasks - seznam úkolů s paginací, vyhledáváním a filtry.
     */
    protected function index(): void
    {
        $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
        $perPage = isset($_GET['per_page']) ? (int) $_GET['per_page'] : Constants::DEFAULT_PER_PAGE;
        $perPage = max(1, min(Constants::MAX_PER_PAGE, $perPage));
        $search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
        $projectId = isset($_GET['project_id']) ? (int) $_GET['project_id'] : null;
        $status = isset($_GET['status']) ? (string) $_GET['status'] : null;
        $priority = isset($_GET['priority']) ? (string) $_GET['priority'] : null;
        $overdue = isset($_GET['overdue']) ? filter_var($_GET['overdue'], FILTER_VALIDATE_BOOLEAN) : false;

        $repo = $this->repo(\DevAppPro\Repositories\TaskRepository::class);
        $result = $repo->all($page, $perPage, $search, $projectId, $status, $priority, $overdue);

        $this->jsonSuccess([
            'data'     => $result['data'],
            'total'    => $result['total'],
            'page'     => $page,
            'per_page' => $perPage,
        ], 200);
    }

    /**
     * GET /api/tasks/{id} - detail úkolu.
     */
    protected function show(?int $id = null): void
    {
        if ($id === null) {
            $this->jsonError('ID je vyžadováno.', 400);
            return;
        }

        $repo = $this->repo(\DevAppPro\Repositories\TaskRepository::class);
        $task = $repo->find($id);

        if ($task === null) {
            $this->jsonError('Úkol nenalezen.', 404);
            return;
        }

        $this->jsonSuccess($task, 200);
    }

    /**
     * POST /api/tasks - vytvoření úkolu.
     */
    protected function store(): void
    {
        $input = json_input();
        $result = $this->validate($input);

        if (!$result['valid']) {
            json_response(['error' => 'Validace selhala.', 'fields' => $result['fields']], 422);
            return;
        }

        $repo = $this->repo(\DevAppPro\Repositories\TaskRepository::class);
        $id = $repo->create($result['data']);
        $task = $repo->find($id);

        $notif = $this->repo(NotificationService::class);
        $notif->taskCreated((int) ($_SESSION['user_id'] ?? 0), $id, $task['title']);

        $this->jsonSuccess($task, 201);
    }

    /**
     * PUT /api/tasks/{id} - úprava úkolu.
     */
    protected function update(int $id): void
    {
        $repo = $this->repo(\DevAppPro\Repositories\TaskRepository::class);

        $existing = $repo->find($id);
        if ($existing === null) {
            $this->jsonError('Úkol nenalezen.', 404);
            return;
        }

        $input = json_input();
        $result = $this->validate($input, true);

        if (!$result['valid']) {
            json_response(['error' => 'Validace selhala.', 'fields' => $result['fields']], 422);
            return;
        }

        $repo->update($id, $result['data']);
        $task = $repo->find($id);

        $notif = $this->repo(NotificationService::class);
        $notif->taskUpdated((int) ($_SESSION['user_id'] ?? 0), $id, $task['title']);

        $this->jsonSuccess($task, 200);
    }

    /**
     * DELETE /api/tasks/{id} - smazání úkolu.
     */
    protected function destroy(int $id): void
    {
        $repo = $this->repo(\DevAppPro\Repositories\TaskRepository::class);

        $existing = $repo->find($id);
        if ($existing === null) {
            $this->jsonError('Úkol nenalezen.', 404);
            return;
        }

        $repo->delete($id);

        $notif = $this->repo(NotificationService::class);
        $notif->taskDeleted((int) ($_SESSION['user_id'] ?? 0), $existing['title']);

        http_response_code(204);
        // 204 No Content - žádné tělo odpovědi
    }

    /**
     * Validace vstupních dat úkolu.
     * @return array{valid: bool, fields: array<string,string>, data: array}
     */
    protected function validate(array $input, bool $isUpdate = false): array
    {
        $fields = [];
        $data = [];

        // project_id - povinné pro create, musí existovat v projects
        // Při update se project_id ignoruje (nelze měnit)
        if (!$isUpdate) {
            $projectId = $input['project_id'] ?? null;
            if ($projectId === null || $projectId === '') {
                $fields['project_id'] = 'Projekt je povinný.';
            } else {
                $projectId = (int) $projectId;
                if ($projectId <= 0) {
                    $fields['project_id'] = 'Projekt neexistuje.';
                } else {
                    $repo = $this->repo(\DevAppPro\Repositories\TaskRepository::class);
                    if (!$repo->projectExists($projectId)) {
                        $fields['project_id'] = 'Projekt neexistuje.';
                    } else {
                        $data['project_id'] = $projectId;
                    }
                }
            }
        }

        // title - povinné pro create (neprázdné), volitelné pro update
        $title = $input['title'] ?? null;
        if (is_string($title)) {
            $title = trim($title);
        }
        if (!$isUpdate && ($title === null || $title === '')) {
            $fields['title'] = 'Název úkolu je povinný.';
        } elseif ($title !== null && $title !== '') {
            if (mb_strlen($title) > 200) {
                $fields['title'] = 'Pole title je příliš dlouhé (max 200 znaků).';
            } else {
                $data['title'] = $title;
            }
        }

        // status - z enum (todo, in_progress, done, cancelled)
        // Pro update volitelné (jen pokud je v inputu), pro create výchozí 'todo'.
        if (array_key_exists('status', $input)) {
            $status = is_string($input['status']) ? trim($input['status']) : '';
            $allowedStatuses = Constants::TASK_STATUSES;
            if (!in_array($status, $allowedStatuses, true)) {
                $fields['status'] = 'Status musí být todo, in_progress, done nebo cancelled.';
            } else {
                $data['status'] = $status;
            }
        } elseif (!$isUpdate) {
            $data['status'] = 'todo';
        }

        // priority - z enum (low, medium, high, urgent)
        // Pro update volitelné (jen pokud je v inputu), pro create výchozí 'medium'.
        if (array_key_exists('priority', $input)) {
            $priority = is_string($input['priority']) ? trim($input['priority']) : '';
            $allowedPriorities = ['low', 'medium', 'high', 'urgent'];
            if (!in_array($priority, $allowedPriorities, true)) {
                $fields['priority'] = 'Priorita musí být low, medium, high nebo urgent.';
            } else {
                $data['priority'] = $priority;
            }
        } elseif (!$isUpdate) {
            $data['priority'] = 'medium';
        }

        // due_date - datum YYYY-MM-DD nebo NULL
        if (array_key_exists('due_date', $input)) {
            $dueDate = $input['due_date'];
            if ($dueDate !== null && $dueDate !== '') {
                if ($this->isValidDate((string) $dueDate)) {
                    $data['due_date'] = $dueDate;
                } else {
                    $fields['due_date'] = 'Termín musí být ve formátu YYYY-MM-DD.';
                }
            } else {
                $data['due_date'] = null;
            }
        } elseif (!$isUpdate) {
            $data['due_date'] = null;
        }

        // assigned_to - string nebo NULL
        if (array_key_exists('assigned_to', $input)) {
            $assignedTo = $input['assigned_to'];
            if (is_string($assignedTo)) {
                $assignedTo = trim($assignedTo);
                if ($assignedTo !== '' && mb_strlen($assignedTo) > 100) {
                    $fields['assigned_to'] = 'Pole assigned_to je příliš dlouhé (max 100 znaků).';
                }
                $data['assigned_to'] = $assignedTo !== '' ? $assignedTo : null;
            } else {
                $data['assigned_to'] = null;
            }
        } elseif (!$isUpdate) {
            $data['assigned_to'] = null;
        }

        // description - string nebo NULL
        if (array_key_exists('description', $input)) {
            $description = $input['description'];
            if (is_string($description)) {
                $description = trim($description);
                if ($description !== '' && mb_strlen($description) > 5000) {
                    $fields['description'] = 'Pole description je příliš dlouhé (max 5000 znaků).';
                }
                $data['description'] = $description !== '' ? $description : null;
            } else {
                $data['description'] = null;
            }
        } elseif (!$isUpdate) {
            $data['description'] = null;
        }

        // estimated_minutes - int >= 0
        if (array_key_exists('estimated_minutes', $input)) {
            $estimatedMinutes = $input['estimated_minutes'];
            if (!is_numeric($estimatedMinutes)) {
                $fields['estimated_minutes'] = 'Odhadovaný čas musí být nezáporné celé číslo.';
            } else {
                $estimatedMinutes = (int) $estimatedMinutes;
                if ($estimatedMinutes < 0) {
                    $fields['estimated_minutes'] = 'Odhadovaný čas musí být nezáporné celé číslo.';
                } else {
                    $data['estimated_minutes'] = $estimatedMinutes;
                }
            }
        } elseif (!$isUpdate) {
            $data['estimated_minutes'] = 0;
        }

        // spent_minutes - int >= 0
        if (array_key_exists('spent_minutes', $input)) {
            $spentMinutes = $input['spent_minutes'];
            if (!is_numeric($spentMinutes)) {
                $fields['spent_minutes'] = 'Strávený čas musí být nezáporné celé číslo.';
            } else {
                $spentMinutes = (int) $spentMinutes;
                if ($spentMinutes < 0) {
                    $fields['spent_minutes'] = 'Strávený čas musí být nezáporné celé číslo.';
                } else {
                    $data['spent_minutes'] = $spentMinutes;
                }
            }
        } elseif (!$isUpdate) {
            $data['spent_minutes'] = 0;
        }

        return [
            'valid'  => empty($fields),
            'fields' => $fields,
            'data'   => $data,
        ];
    }

    /**
     * Ověří, zda je řetězec platné datum ve formátu YYYY-MM-DD.
     */
    private function isValidDate(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        $parts = explode('-', $date);
        return checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
    }
}
