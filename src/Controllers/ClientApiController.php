<?php
declare(strict_types=1);

namespace DevAppPro\Controllers;

use DevAppPro\Core\ApiController;
use DevAppPro\Core\Constants;
use DevAppPro\Auth;
use DevAppPro\Repositories\ClientRepository;

/**
 * API kontroler pro klienty (CRUD).
 * GET    /api/clients        → index()  (seznam s paginací)
 * GET    /api/clients/{id}   → show()   (detail)
 * POST   /api/clients        → store()  (vytvoření)
 * PUT    /api/clients/{id}   → update() (úprava)
 * DELETE /api/clients/{id}   → destroy() (smazání)
 */
class ClientApiController extends ApiController
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
     * GET /api/clients - seznam klientů s paginací, vyhledáváním a řazením.
     */
    protected function index(): void
    {
        $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
        $perPage = isset($_GET['per_page']) ? (int) $_GET['per_page'] : Constants::DEFAULT_PER_PAGE;
        $perPage = max(1, min(Constants::MAX_PER_PAGE, $perPage));
        $search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
        $sort = isset($_GET['sort']) ? (string) $_GET['sort'] : 'last_name';

        $repo = $this->repo(\DevAppPro\Repositories\ClientRepository::class);
        $result = $repo->all($page, $perPage, $search, $sort);

        $this->jsonSuccess([
            'data'     => $result['data'],
            'total'    => $result['total'],
            'page'     => $page,
            'per_page' => $perPage,
        ], 200);
    }

    /**
     * GET /api/clients/{id} - detail klienta.
     */
    protected function show(?int $id = null): void
    {
        if ($id === null) {
            $this->jsonError('ID je vyžadováno.', 400);
            return;
        }

        $repo = $this->repo(\DevAppPro\Repositories\ClientRepository::class);
        $client = $repo->find($id);

        if ($client === null) {
            $this->jsonError('Klient nenalezen.', 404);
            return;
        }

        $this->jsonSuccess($client, 200);
    }

    /**
     * POST /api/clients - vytvoření klienta.
     */
    protected function store(): void
    {
        $input = json_input();
        $result = $this->validate($input, false);

        if (!$result['valid']) {
            json_response(['error' => 'Validace selhala.', 'fields' => $result['fields']], 422);
            return;
        }

        $repo = $this->repo(\DevAppPro\Repositories\ClientRepository::class);
        $id = $repo->create($result['data']);
        $client = $repo->find($id);

        $this->jsonSuccess($client, 201);
    }

    /**
     * PUT /api/clients/{id} - úprava klienta.
     */
    protected function update(int $id): void
    {
        $repo = $this->repo(\DevAppPro\Repositories\ClientRepository::class);

        $existing = $repo->find($id);
        if ($existing === null) {
            $this->jsonError('Klient nenalezen.', 404);
            return;
        }

        $input = json_input();
        $result = $this->validate($input, true);

        if (!$result['valid']) {
            json_response(['error' => 'Validace selhala.', 'fields' => $result['fields']], 422);
            return;
        }

        $repo->update($id, $result['data']);
        $client = $repo->find($id);

        $this->jsonSuccess($client, 200);
    }

    /**
     * DELETE /api/clients/{id} - smazání klienta.
     */
    protected function destroy(int $id): void
    {
        $repo = $this->repo(\DevAppPro\Repositories\ClientRepository::class);

        $existing = $repo->find($id);
        if ($existing === null) {
            $this->jsonError('Klient nenalezen.', 404);
            return;
        }

        $repo->delete($id);

        http_response_code(204);
        // 204 No Content - žádné tělo odpovědi
    }

    /**
     * Validace vstupních dat klienta.
     * @return array{valid: bool, fields: array<string,string>, data: array}
     */
    protected function validate(array $input, bool $isUpdate = false): array
    {
        $fields = [];
        $data = [];

        // type - individual, company, nonprofit, government (default individual)
        $type = $input['type'] ?? 'individual';
        $type = is_string($type) ? trim($type) : 'individual';
        if ($type === '') {
            $type = 'individual';
        }
        $validTypes = ['individual', 'company', 'nonprofit', 'government'];
        if (!in_array($type, $validTypes, true)) {
            $fields['type'] = 'Typ musí být "individual", "company", "nonprofit" nebo "government".';
            $type = 'individual';
        }
        $data['type'] = $type;

        // Trim všech textových polí
        $textFields = [
            'first_name', 'last_name', 'company_name', 'ico', 'dic',
            'bank_account', 'email', 'phone', 'address', 'note',
        ];
        $fieldLimits = [
            'first_name'    => 100,
            'last_name'     => 100,
            'company_name'  => 200,
            'ico'           => 50,
            'dic'           => 50,
            'bank_account'  => 50,
            'email'         => 255,
            'phone'         => 100,
            'address'       => 500,
            'note'          => 5000,
        ];
        foreach ($textFields as $field) {
            $value = $input[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    $value = null;
                }
            } elseif (!is_null($value)) {
                $value = (string) $value;
            }
            if ($value !== null && mb_strlen($value) > $fieldLimits[$field]) {
                $fields[$field] = 'Pole ' . $field . ' je příliš dlouhé (max ' . $fieldLimits[$field] . ' znaků).';
            }
            $data[$field] = $value;
        }

        if ($type === 'individual') {
            // first_name + last_name povinné
            if ($data['first_name'] === null) {
                $fields['first_name'] = 'Jméno je povinné.';
            }
            if ($data['last_name'] === null) {
                $fields['last_name'] = 'Příjmení je povinné.';
            }
            // company_name ignorováno → NULL (OSVČ fakturuje pod jménem);
            // ico/dic/bank_account povoleny — většina osob jsou OSVČ
            $data['company_name'] = null;
        } else {
            // company / nonprofit / government → company_name povinné
            if ($data['company_name'] === null) {
                $fields['company_name'] = 'Název organizace je povinný.';
            }
            // first_name/last_name ignorovány → NULL
            $data['first_name'] = null;
            $data['last_name'] = null;
        }

        // IČO: 8 číslic (pokud zadáno — i pro individual/OSVČ)
        if ($data['ico'] !== null) {
            $ico = preg_replace('/\s+/', '', $data['ico']);
            if (!preg_match('/^\d{8}$/', $ico)) {
                $fields['ico'] = 'IČO musí být 8 číslic.';
            } else {
                $data['ico'] = $ico;
            }
        }

        // DIČ: CZ + 8-10 číslic (pokud zadáno)
        if ($data['dic'] !== null) {
            $dic = preg_replace('/\s+/', '', $data['dic']);
            if (!preg_match('/^CZ\d{8,10}$/', $dic)) {
                $fields['dic'] = 'DIČ musí být ve formátu CZ + 8-10 číslic.';
            } else {
                $data['dic'] = $dic;
            }
        }

        // E-mail: platný formát (pokud zadáno)
        if ($data['email'] !== null) {
            if (filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
                $fields['email'] = 'E-mail není platný.';
            }
        }

        return [
            'valid'  => empty($fields),
            'fields' => $fields,
            'data'   => $data,
        ];
    }
}
