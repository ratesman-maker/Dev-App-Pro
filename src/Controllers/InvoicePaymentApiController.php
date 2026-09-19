<?php
declare(strict_types=1);

namespace DevAppPro\Controllers;

use DevAppPro\Core\ApiController;
use DevAppPro\Auth;
use DevAppPro\Repositories\InvoicePaymentRepository;
use DevAppPro\Repositories\InvoiceRepository;
use DevAppPro\Services\NotificationService;

/**
 * API kontroler pro platby faktur.
 * GET    /api/invoice-payments?invoice_id={id} → index()   (seznam plateb faktury)
 * POST   /api/invoice-payments                 → store()  (vytvoření platby)
 * PUT    /api/invoice-payments/{id}            → update()  (úprava platby)
 * DELETE /api/invoice-payments/{id}            → destroy() (smazání platby)
 */
class InvoicePaymentApiController extends ApiController
{
    private Auth $auth;

    public function __construct()
    {
        $this->auth = $this->repo(\DevAppPro\Auth::class);
    }

    /**
     * Dispatchuje podle HTTP metody a URI.
     * Vše vyžaduje auth, POST/DELETE vyžadují CSRF.
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
                $this->index();
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
     * GET /api/invoice-payments?invoice_id={id} - seznam plateb pro fakturu.
     */
    protected function index(): void
    {
        $invoiceId = isset($_GET['invoice_id']) ? (int) $_GET['invoice_id'] : null;
        if ($invoiceId === null || $invoiceId <= 0) {
            $this->jsonError('invoice_id je vyžadováno.', 400);
            return;
        }

        $repo = $this->repo(\DevAppPro\Repositories\InvoicePaymentRepository::class);
        $payments = $repo->allForInvoice($invoiceId);

        $this->jsonSuccess(['data' => $payments], 200);
    }

    /**
     * POST /api/invoice-payments - vytvoření platby.
     * Po vytvoření přepočítá invoices.paid_cents a případně nastaví status='paid'.
     */
    protected function store(): void
    {
        $input = json_input();
        $result = $this->validate($input);

        if (!$result['valid']) {
            json_response(['error' => 'Validace selhala.', 'fields' => $result['fields']], 422);
            return;
        }

        $repo = $this->repo(\DevAppPro\Repositories\InvoicePaymentRepository::class);
        $invoiceRepo = $this->repo(\DevAppPro\Repositories\InvoiceRepository::class);

        $id = $repo->create($result['data']);

        // Přepočítat paid_cents a případně status='paid'
        $invoiceRepo->recalculatePaid((int) $result['data']['invoice_id']);

        $payment = $repo->find($id);

        $invoice = $invoiceRepo->find((int) $result['data']['invoice_id']);
        $notif = $this->repo(NotificationService::class);
        $notif->paymentCreated(
            (int) ($_SESSION['user_id'] ?? 0),
            $id,
            $invoice['invoice_number'] ?? '',
            $payment['amount_cents'] ?? 0
        );

        $this->jsonSuccess($payment, 201);
    }

    /**
     * PUT /api/invoice-payments/{id} - úprava platby.
     * Po úpravě přepočítá invoices.paid_cents a případně status.
     */
    protected function update(int $id): void
    {
        $repo = $this->repo(\DevAppPro\Repositories\InvoicePaymentRepository::class);
        $invoiceRepo = $this->repo(\DevAppPro\Repositories\InvoiceRepository::class);

        $existing = $repo->find($id);
        if ($existing === null) {
            $this->jsonError('Platba nenalezena.', 404);
            return;
        }

        $input = json_input();
        $result = $this->validate($input, true);

        if (!$result['valid']) {
            json_response(['error' => 'Validace selhala.', 'fields' => $result['fields']], 422);
            return;
        }

        $repo->update($id, $result['data']);

        // Přepočítat paid_cents a případně status
        $invoiceId = (int) ($result['data']['invoice_id'] ?? $existing['invoice_id']);
        $invoiceRepo->recalculatePaid($invoiceId);

        $payment = $repo->find($id);

        $invoice = $invoiceRepo->find($invoiceId);
        $notif = $this->repo(NotificationService::class);
        $notif->paymentUpdated((int) ($_SESSION['user_id'] ?? 0), $id, $invoice['invoice_number'] ?? '');

        $this->jsonSuccess($payment, 200);
    }

    /**
     * DELETE /api/invoice-payments/{id} - smazání platby.
     * Po smazání přepočítá invoices.paid_cents a případně status='sent'.
     */
    protected function destroy(int $id): void
    {
        $repo = $this->repo(\DevAppPro\Repositories\InvoicePaymentRepository::class);
        $invoiceRepo = $this->repo(\DevAppPro\Repositories\InvoiceRepository::class);

        $payment = $repo->find($id);
        if ($payment === null) {
            $this->jsonError('Platba nenalezena.', 404);
            return;
        }

        $invoiceId = (int) $payment['invoice_id'];

        $invoice = $invoiceRepo->find($invoiceId);
        $notif = $this->repo(NotificationService::class);
        $notif->paymentDeleted((int) ($_SESSION['user_id'] ?? 0), $invoice['invoice_number'] ?? '');

        $repo->delete($id);

        // Přepočítat paid_cents a případně status='sent'
        $invoiceRepo->recalculatePaidAfterDelete($invoiceId);

        http_response_code(204);
        // 204 No Content - žádné tělo odpovědi
    }

    /**
     * Validace vstupních dat platby.
     * @return array{valid: bool, fields: array<string,string>, data: array}
     */
    protected function validate(array $input, bool $isUpdate = false): array
    {
        $fields = [];
        $data = [];

        // invoice_id - povinné při vytvoření, volitelné při úpravě
        $invoiceId = $input['invoice_id'] ?? null;
        if ($invoiceId === null || $invoiceId === '') {
            if (!$isUpdate) {
                $fields['invoice_id'] = 'Faktura je povinná.';
            }
        } else {
            $invoiceId = (int) $invoiceId;
            if ($invoiceId <= 0) {
                $fields['invoice_id'] = 'Faktura neexistuje.';
            } else {
                $repo = $this->repo(\DevAppPro\Repositories\InvoicePaymentRepository::class);
                if (!$repo->invoiceExists($invoiceId)) {
                    $fields['invoice_id'] = 'Faktura neexistuje.';
                } else {
                    $data['invoice_id'] = $invoiceId;
                }
            }
        }

        // amount_cents - povinné, > 0 (int)
        $amountCents = $input['amount_cents'] ?? null;
        if ($amountCents === null) {
            $fields['amount_cents'] = 'Částka platby je povinná.';
        } elseif (!is_numeric($amountCents)) {
            $fields['amount_cents'] = 'Částka platby musí být kladné celé číslo.';
        } else {
            $amountCents = (int) $amountCents;
            if ($amountCents <= 0) {
                $fields['amount_cents'] = 'Částka platby musí být kladné celé číslo.';
            } else {
                $data['amount_cents'] = $amountCents;
            }
        }

        // payment_date - povinné, datum YYYY-MM-DD
        $paymentDate = $input['payment_date'] ?? null;
        if ($paymentDate === null || $paymentDate === '') {
            $fields['payment_date'] = 'Datum platby je povinné.';
        } elseif (!$this->isValidDate((string) $paymentDate)) {
            $fields['payment_date'] = 'Datum platby musí být ve formátu YYYY-MM-DD.';
        } else {
            $data['payment_date'] = $paymentDate;
        }

        // method - z enum (cash, bank_transfer, card, other) - default bank_transfer
        $method = $input['method'] ?? 'bank_transfer';
        $method = is_string($method) ? trim($method) : 'bank_transfer';
        $allowedMethods = ['cash', 'bank_transfer', 'card', 'other'];
        if (!in_array($method, $allowedMethods, true)) {
            $fields['method'] = 'Metoda platby musí být cash, bank_transfer, card nebo other.';
        } else {
            $data['method'] = $method;
        }

        // note - string nebo NULL
        $note = $input['note'] ?? null;
        if (is_string($note)) {
            $note = trim($note);
            if ($note !== '' && mb_strlen($note) > 5000) {
                $fields['note'] = 'Pole note je příliš dlouhé (max 5000 znaků).';
            }
            $data['note'] = $note !== '' ? $note : null;
        } else {
            $data['note'] = null;
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
