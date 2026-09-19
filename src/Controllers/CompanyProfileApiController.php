<?php
declare(strict_types=1);

namespace DevAppPro\Controllers;

use DevAppPro\Core\ApiController;
use DevAppPro\Auth;
use DevAppPro\Repositories\CompanyProfileRepository;

/**
 * API kontroler profil firmy.
 * GET  /api/company-profile  → vrátí profil firmy
 * PUT  /api/company-profile  → aktualizuje profil firmy
 */
class CompanyProfileApiController extends ApiController
{
    private Auth $auth;

    public function __construct()
    {
        $this->auth = $this->repo(\DevAppPro\Auth::class);
    }

    /**
     * Dispatchuje podle HTTP metody.
     * GET → show(), PUT/PATCH → update().
     */
    public function handle(): void
    {
        $method = $this->getMethod();
        switch ($method) {
            case 'GET':
                $this->show();
                break;
            case 'PUT':
            case 'PATCH':
                $this->update();
                break;
            default:
                $this->jsonError('Nepodporovaná HTTP metoda.', 405);
                break;
        }
    }

    /**
     * GET /api/company-profile - vrátí profil firmy.
     */
    protected function show(?int $id = null): void
    {
        $this->auth->requireAuth();
        $profile = $this->repo(\DevAppPro\Repositories\CompanyProfileRepository::class)->get();
        $this->jsonSuccess($profile);
    }

    /**
     * PUT /api/company-profile - aktualizuje profil firmy.
     */
    protected function update(?int $id = null): void
    {
        $this->auth->requireAuth();
        require_csrf();

        $input = json_input();

        // Validace typu profilu
        if (isset($input['type']) && !in_array($input['type'], ['individual', 'company', 'nonprofit', 'government'], true)) {
            $this->jsonError('Typ musí být "individual", "company", "nonprofit" nebo "government".', 422);
            return;
        }

        // Délková omezení textových polí
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
        foreach ($fieldLimits as $field => $limit) {
            if (isset($input[$field]) && is_string($input[$field]) && mb_strlen($input[$field]) > $limit) {
                $this->jsonError('Pole ' . $field . ' je příliš dlouhé (max ' . $limit . ' znaků).', 422);
                return;
            }
        }

        $repo = $this->repo(\DevAppPro\Repositories\CompanyProfileRepository::class);
        $repo->update(1, $input);

        $profile = $repo->get();
        $this->jsonSuccess($profile);
    }
}
