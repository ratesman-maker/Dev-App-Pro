<?php
declare(strict_types=1);

namespace DevAppPro\Controllers;

use DevAppPro\Core\ApiController;
use DevAppPro\Auth;
use DevAppPro\Repositories\SettingsRepository;

/**
 * API kontroler pro nastavení aplikace.
 * GET  /api/settings  → vrátí všechna nastavení
 * PUT  /api/settings  → aktualizuje povolená nastavení
 */
class SettingsApiController extends ApiController
{
    private Auth $auth;

    /**
     * Povolené klíče nastavení pro aktualizaci.
     */
    private const ALLOWED_KEYS = [
        'default_vat_rate',
        'default_due_days',
        'timezone',
        'first_day_of_week',
        'fiscal_year_start',
        'currency',
        'currency_decimals',
        'invoice_number_format',
        // Notifikace — CRUD akce (1/0)
        'notif_task_created',
        'notif_task_updated',
        'notif_task_deleted',
        'notif_invoice_created',
        'notif_invoice_updated',
        'notif_invoice_deleted',
        'notif_payment_created',
        'notif_payment_updated',
        'notif_payment_deleted',
        'notif_transaction_created',
        'notif_transaction_updated',
        'notif_transaction_deleted',
        // Notifikace — termínové (1/0)
        'notif_task_deadline_soon',
        'notif_task_overdue',
        'notif_invoice_due_soon',
        'notif_invoice_overdue',
        // Notifikace — dny předem
        'notif_days_before',
    ];

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
     * GET /api/settings - vrátí všechna nastavení.
     */
    protected function show(?int $id = null): void
    {
        $this->auth->requireAuth();
        $settings = $this->repo(\DevAppPro\Repositories\SettingsRepository::class)->allSettings();
        $this->jsonSuccess($settings);
    }

    /**
     * PUT /api/settings - aktualizuje nastavení (pouze povolené klíče).
     */
    protected function update(?int $id = null): void
    {
        $this->auth->requireAuth();
        require_csrf();

        $input = json_input();
        $repo = $this->repo(\DevAppPro\Repositories\SettingsRepository::class);

        $textLimits = [
            'timezone'              => 100,
            'fiscal_year_start'     => 50,
            'currency'              => 50,
            'invoice_number_format' => 200,
        ];

        foreach (self::ALLOWED_KEYS as $key) {
            if (array_key_exists($key, $input)) {
                $value = (string) $input[$key];
                if (isset($textLimits[$key]) && mb_strlen($value) > $textLimits[$key]) {
                    $this->jsonError('Pole ' . $key . ' je příliš dlouhé (max ' . $textLimits[$key] . ' znaků).', 422);
                    return;
                }
                $repo->set($key, $value);
            }
        }

        $settings = $repo->allSettings();
        $this->jsonSuccess($settings);
    }
}
