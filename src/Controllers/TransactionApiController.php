<?php
declare(strict_types=1);

namespace DevAppPro\Controllers;

use DevAppPro\Core\ApiController;
use DevAppPro\Core\Constants;
use DevAppPro\Auth;
use DevAppPro\Repositories\TransactionRepository;
use DevAppPro\Services\NotificationService;

/**
 * API kontroler pro transakce (CRUD).
 * GET    /api/transactions        → index()  (seznam s paginací)
 * GET    /api/transactions/{id}   → show()   (detail)
 * POST   /api/transactions        → store()  (vytvoření)
 * PUT    /api/transactions/{id}   → update() (úprava)
 * DELETE /api/transactions/{id}   → destroy() (smazání)
 */
class TransactionApiController extends ApiController
{
    private Auth $auth;

    /**
     * Kategorie povolené pro typ expense.
     */
    private const EXPENSE_CATEGORIES = [
        'office',
        'software',
        'travel',
        'marketing',
        'hardware',
        'services',
        'other',
    ];

    /**
     * Kategorie povolené pro typ income.
     */
    private const INCOME_CATEGORIES = [
        'income_project',
        'income_consulting',
        'other',
    ];

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
     * GET /api/transactions - seznam transakcí s paginací, vyhledáváním a filtry.
     */
    protected function index(): void
    {
        $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
        $perPage = isset($_GET['per_page']) ? (int) $_GET['per_page'] : Constants::DEFAULT_PER_PAGE;
        $perPage = max(1, min(Constants::MAX_PER_PAGE, $perPage));
        $search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
        $projectId = isset($_GET['project_id']) && $_GET['project_id'] !== '' ? (int) $_GET['project_id'] : null;
        $clientId = isset($_GET['client_id']) && $_GET['client_id'] !== '' ? (int) $_GET['client_id'] : null;
        $type = isset($_GET['type']) && $_GET['type'] !== '' ? (string) $_GET['type'] : null;
        $category = isset($_GET['category']) && $_GET['category'] !== '' ? (string) $_GET['category'] : null;
        $from = isset($_GET['from']) && $_GET['from'] !== '' ? (string) $_GET['from'] : null;
        $to = isset($_GET['to']) && $_GET['to'] !== '' ? (string) $_GET['to'] : null;

        $repo = $this->repo(\DevAppPro\Repositories\TransactionRepository::class);
        $result = $repo->all($page, $perPage, $search, $projectId, $clientId, $type, $category, $from, $to);

        $this->jsonSuccess([
            'data'     => $result['data'],
            'total'    => $result['total'],
            'page'     => $page,
            'per_page' => $perPage,
        ], 200);
    }

    /**
     * GET /api/transactions/{id} - detail transakce.
     */
    protected function show(?int $id = null): void
    {
        if ($id === null) {
            $this->jsonError('ID je vyžadováno.', 400);
            return;
        }

        $repo = $this->repo(\DevAppPro\Repositories\TransactionRepository::class);
        $transaction = $repo->find($id);

        if ($transaction === null) {
            $this->jsonError('Transakce nenalezena.', 404);
            return;
        }

        $this->jsonSuccess($transaction, 200);
    }

    /**
     * POST /api/transactions - vytvoření transakce.
     */
    protected function store(): void
    {
        $input = json_input();
        $result = $this->validate($input);

        if (!$result['valid']) {
            json_response(['error' => 'Validace selhala.', 'fields' => $result['fields']], 422);
            return;
        }

        $repo = $this->repo(\DevAppPro\Repositories\TransactionRepository::class);
        $id = $repo->create($result['data']);
        $transaction = $repo->find($id);

        $notif = $this->repo(NotificationService::class);
        $notif->transactionCreated(
            (int) ($_SESSION['user_id'] ?? 0),
            $id,
            $transaction['description'] ?? '',
            $transaction['type'] ?? 'expense'
        );

        $this->jsonSuccess($transaction, 201);
    }

    /**
     * PUT /api/transactions/{id} - úprava transakce.
     */
    protected function update(int $id): void
    {
        $repo = $this->repo(\DevAppPro\Repositories\TransactionRepository::class);

        $existing = $repo->find($id);
        if ($existing === null) {
            $this->jsonError('Transakce nenalezena.', 404);
            return;
        }

        $input = json_input();
        $result = $this->validate($input, true, $existing);

        if (!$result['valid']) {
            json_response(['error' => 'Validace selhala.', 'fields' => $result['fields']], 422);
            return;
        }

        $repo->update($id, $result['data']);
        $transaction = $repo->find($id);

        $notif = $this->repo(NotificationService::class);
        $notif->transactionUpdated((int) ($_SESSION['user_id'] ?? 0), $id, $transaction['description'] ?? '');

        $this->jsonSuccess($transaction, 200);
    }

    /**
     * DELETE /api/transactions/{id} - smazání transakce.
     */
    protected function destroy(int $id): void
    {
        $repo = $this->repo(\DevAppPro\Repositories\TransactionRepository::class);

        $existing = $repo->find($id);
        if ($existing === null) {
            $this->jsonError('Transakce nenalezena.', 404);
            return;
        }

        $repo->delete($id);

        $notif = $this->repo(NotificationService::class);
        $notif->transactionDeleted((int) ($_SESSION['user_id'] ?? 0), $existing['description'] ?? '');

        http_response_code(204);
        // 204 No Content - žádné tělo odpovědi
    }

    /**
     * Validace vstupních dat transakce.
     * @param array $input Vstupní data z requestu
     * @param bool $isUpdate True pokud jde o aktualizaci (povinná pole volitelná)
     * @param array|null $existing Existující záznam z DB (pro update)
     * @return array{valid: bool, fields: array<string,string>, data: array}
     */
    protected function validate(array $input, bool $isUpdate = false, ?array $existing = null): array
    {
        $fields = [];
        $data = [];

        // type - povinné pro create, volitelné pro update (income nebo expense)
        $type = $input['type'] ?? null;
        if ($type !== null && $type !== '') {
            $type = is_string($type) ? trim($type) : '';
            if (!in_array($type, ['income', 'expense'], true)) {
                $fields['type'] = 'Typ transakce musí být income nebo expense.';
            } else {
                $data['type'] = $type;
            }
        } elseif (!$isUpdate) {
            $fields['type'] = 'Typ transakce je povinný.';
        }

        // amount_cents - povinné pro create, volitelné pro update, > 0 (int)
        $amountCents = $input['amount_cents'] ?? null;
        if ($amountCents !== null) {
            if (!is_numeric($amountCents)) {
                $fields['amount_cents'] = 'Částka musí být kladné celé číslo.';
            } else {
                $amountCents = (int) $amountCents;
                if ($amountCents <= 0) {
                    $fields['amount_cents'] = 'Částka musí být kladné celé číslo.';
                } else {
                    $data['amount_cents'] = $amountCents;
                }
            }
        } elseif (!$isUpdate) {
            $fields['amount_cents'] = 'Částka je povinná.';
        }

        // transaction_date - povinné pro create, volitelné pro update, datum YYYY-MM-DD
        $transactionDate = $input['transaction_date'] ?? null;
        if ($transactionDate !== null && $transactionDate !== '') {
            if (!$this->isValidDate((string) $transactionDate)) {
                $fields['transaction_date'] = 'Datum transakce musí být ve formátu YYYY-MM-DD.';
            } else {
                $data['transaction_date'] = $transactionDate;
            }
        } elseif (!$isUpdate) {
            $fields['transaction_date'] = 'Datum transakce je povinné.';
        }

        // category - z enum, musí odpovídat typu
        // Typ pro validaci: z inputu, nebo z existujícího záznamu (při update)
        $validType = $data['type'] ?? ($existing['type'] ?? null);

        if (array_key_exists('category', $input)) {
            $category = $input['category'];
            $category = is_string($category) ? trim($category) : 'other';
            $allCategories = array_merge(self::EXPENSE_CATEGORIES, self::INCOME_CATEGORIES);
            if (!in_array($category, $allCategories, true)) {
                $fields['category'] = 'Neplatná kategorie.';
            } else {
                // Kategorie musí odpovídat typu (pokud je typ známý)
                if ($validType !== null) {
                    $allowed = $validType === 'expense' ? self::EXPENSE_CATEGORIES : self::INCOME_CATEGORIES;
                    if (!in_array($category, $allowed, true)) {
                        $fields['category'] = 'Kategorie neodpovídá typu transakce.';
                    } else {
                        $data['category'] = $category;
                    }
                } else {
                    // Typ není známý, ale kategorie ano - uložíme
                    $data['category'] = $category;
                }
            }
        } elseif (!$isUpdate) {
            $data['category'] = 'other';
        }

        // project_id - volitelný, musí existovat
        if (array_key_exists('project_id', $input)) {
            $projectId = $input['project_id'];
            if ($projectId === null || $projectId === '') {
                $data['project_id'] = null;
            } else {
                $projectId = (int) $projectId;
                if ($projectId <= 0) {
                    $fields['project_id'] = 'Projekt neexistuje.';
                } else {
                    $repo = $this->repo(\DevAppPro\Repositories\TransactionRepository::class);
                    if (!$repo->projectExists($projectId)) {
                        $fields['project_id'] = 'Projekt neexistuje.';
                    } else {
                        $data['project_id'] = $projectId;
                    }
                }
            }
        } elseif (!$isUpdate) {
            $data['project_id'] = null;
        }

        // client_id - volitelný, musí existovat
        if (array_key_exists('client_id', $input)) {
            $clientId = $input['client_id'];
            if ($clientId === null || $clientId === '') {
                $data['client_id'] = null;
            } else {
                $clientId = (int) $clientId;
                if ($clientId <= 0) {
                    $fields['client_id'] = 'Klient neexistuje.';
                } else {
                    $repo = $this->repo(\DevAppPro\Repositories\TransactionRepository::class);
                    if (!$repo->clientExists($clientId)) {
                        $fields['client_id'] = 'Klient neexistuje.';
                    } else {
                        $data['client_id'] = $clientId;
                    }
                }
            }
        } elseif (!$isUpdate) {
            $data['client_id'] = null;
        }

        // invoice_id - volitelný, musí existovat
        if (array_key_exists('invoice_id', $input)) {
            $invoiceId = $input['invoice_id'];
            if ($invoiceId === null || $invoiceId === '') {
                $data['invoice_id'] = null;
            } else {
                $invoiceId = (int) $invoiceId;
                if ($invoiceId <= 0) {
                    $fields['invoice_id'] = 'Faktura neexistuje.';
                } else {
                    $repo = $this->repo(\DevAppPro\Repositories\TransactionRepository::class);
                    if (!$repo->invoiceExists($invoiceId)) {
                        $fields['invoice_id'] = 'Faktura neexistuje.';
                    } else {
                        $data['invoice_id'] = $invoiceId;
                    }
                }
            }
        } elseif (!$isUpdate) {
            $data['invoice_id'] = null;
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
