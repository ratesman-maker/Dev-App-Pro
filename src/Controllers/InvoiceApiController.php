<?php
declare(strict_types=1);

namespace DevAppPro\Controllers;

use DevAppPro\Core\ApiController;
use DevAppPro\Core\Constants;
use DevAppPro\Auth;
use DevAppPro\Repositories\InvoiceRepository;
use DevAppPro\Services\InvoicePdfService;
use DevAppPro\Repositories\SettingsRepository;
use DevAppPro\Services\NotificationService;

/**
 * API kontroler pro faktury (CRUD + PDF).
 * GET    /api/invoices            → index()        (seznam s paginací)
 * GET    /api/invoices/{id}       → show()         (detail)
 * GET    /api/invoices/{id}/pdf   → downloadPdf()  (PDF faktury)
 * POST   /api/invoices            → store()        (vytvoření)
 * PUT    /api/invoices/{id}       → update()       (úprava)
 * DELETE /api/invoices/{id}       → destroy()      (smazání)
 */
class InvoiceApiController extends ApiController
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

        // Detekce PDF endpointu: GET /api/invoices/{id}/pdf
        if ($method === 'GET') {
            $pdfId = $this->getPdfId();
            if ($pdfId !== null) {
                $this->downloadPdf($pdfId);
                return;
            }
        }

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
     * GET /api/invoices - seznam faktur s paginací, vyhledáváním a filtry.
     */
    protected function index(): void
    {
        $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
        $perPage = isset($_GET['per_page']) ? (int) $_GET['per_page'] : Constants::DEFAULT_PER_PAGE;
        $perPage = max(1, min(Constants::MAX_PER_PAGE, $perPage));
        $search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
        $clientId = isset($_GET['client_id']) ? (int) $_GET['client_id'] : null;
        $projectId = isset($_GET['project_id']) ? (int) $_GET['project_id'] : null;
        $status = isset($_GET['status']) ? (string) $_GET['status'] : null;
        if ($status === '') {
            $status = null;
        }
        $overdue = isset($_GET['overdue']) ? filter_var($_GET['overdue'], FILTER_VALIDATE_BOOLEAN) : false;

        $repo = $this->repo(\DevAppPro\Repositories\InvoiceRepository::class);
        $result = $repo->all($page, $perPage, $search, $clientId, $status, $overdue, $projectId);

        $this->jsonSuccess([
            'data'     => $result['data'],
            'total'    => $result['total'],
            'page'     => $page,
            'per_page' => $perPage,
        ], 200);
    }

    /**
     * GET /api/invoices/{id} - detail faktury.
     */
    protected function show(?int $id = null): void
    {
        if ($id === null) {
            $this->jsonError('ID je vyžadováno.', 400);
            return;
        }

        $repo = $this->repo(\DevAppPro\Repositories\InvoiceRepository::class);
        $invoice = $repo->find($id);

        if ($invoice === null) {
            $this->jsonError('Faktura nenalezena.', 404);
            return;
        }

        $this->jsonSuccess($invoice, 200);
    }

    /**
     * Detekuje URI ve tvaru /api/invoices/{id}/pdf.
     * Vrací ID faktury nebo null pokud URI neodpovídá.
     */
    protected function getPdfId(): ?int
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        if (preg_match('#^/api/invoices/(\d+)/pdf$#', $uri, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * GET /api/invoices/{id}/pdf - vygeneruje a stáhne PDF faktury.
     * Nevrací JSON, ale binární PDF data s příslušnými hlavičkami.
     */
    protected function downloadPdf(int $id): void
    {
        $repo = $this->repo(\DevAppPro\Repositories\InvoiceRepository::class);
        $invoice = $repo->find($id);

        if ($invoice === null) {
            $this->jsonError('Faktura nenalezena.', 404);
            return;
        }

        $service = $this->repo(\DevAppPro\Services\InvoicePdfService::class);
        $pdf = $service->generate($id);

        $filename = 'faktura-' . $invoice['invoice_number'];
        $clientName = $this->slugifyFilename((string) ($invoice['client_name'] ?? ''));
        if ($clientName !== '') {
            $filename .= '-' . $clientName;
        }
        $filename .= '.pdf';

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    /**
     * POST /api/invoices - vytvoření faktury.
     */
    protected function store(): void
    {
        $input = json_input();
        $result = $this->validate($input, false);

        if (!$result['valid']) {
            json_response(['error' => 'Validace selhala.', 'fields' => $result['fields']], 422);
            return;
        }

        $repo = $this->repo(\DevAppPro\Repositories\InvoiceRepository::class);
        $id = $repo->create($result['data']);
        $invoice = $repo->find($id);

        $notif = $this->repo(NotificationService::class);
        $notif->invoiceCreated((int) ($_SESSION['user_id'] ?? 0), $id, $invoice['invoice_number']);

        $this->jsonSuccess($invoice, 201);
    }

    /**
     * PUT /api/invoices/{id} - úprava faktury.
     */
    protected function update(int $id): void
    {
        $repo = $this->repo(\DevAppPro\Repositories\InvoiceRepository::class);

        $existing = $repo->find($id);
        if ($existing === null) {
            $this->jsonError('Faktura nenalezena.', 404);
            return;
        }

        $input = json_input();
        // Podpora částečných úprav: chybějící pole se doplní z existující faktury
        $input = array_merge($this->mergeExistingForUpdate($existing), $input);
        $result = $this->validate($input, true);

        if (!$result['valid']) {
            json_response(['error' => 'Validace selhala.', 'fields' => $result['fields']], 422);
            return;
        }

        $repo->update($id, $result['data']);
        $invoice = $repo->find($id);

        $notif = $this->repo(NotificationService::class);
        $notif->invoiceUpdated((int) ($_SESSION['user_id'] ?? 0), $id, $invoice['invoice_number']);

        $this->jsonSuccess($invoice, 200);
    }

    /**
     * Vrátí vstup pro validaci s hodnotami z existující faktury jako výchozími.
     * Umožňuje částečný update (např. jen změna statusu).
     */
    private function mergeExistingForUpdate(array $existing): array
    {
        return [
            'client_id'        => $existing['client_id'],
            'project_id'       => $existing['project_id'],
            'invoice_number'   => $existing['invoice_number'],
            'status'           => $existing['status'],
            'subtotal_cents'   => (int) $existing['subtotal_cents'],
            'vat_rate_percent' => (float) $existing['vat_rate_percent'],
            'currency'         => $existing['currency'],
            'variable_symbol'  => $existing['variable_symbol'],
            'constant_symbol'  => $existing['constant_symbol'],
            'iban'             => $existing['iban'],
            'issue_date'       => $existing['issue_date'],
            'due_date'         => $existing['due_date'],
            'taxable_date'     => $existing['taxable_date'],
            'note'             => $existing['note'],
            'items'            => $existing['items'] ?? [],
        ];
    }

    /**
     * DELETE /api/invoices/{id} - smazání faktury (kaskádově smaže platby).
     */
    protected function destroy(int $id): void
    {
        $repo = $this->repo(\DevAppPro\Repositories\InvoiceRepository::class);

        $existing = $repo->find($id);
        if ($existing === null) {
            $this->jsonError('Faktura nenalezena.', 404);
            return;
        }

        $repo->delete($id);

        $notif = $this->repo(NotificationService::class);
        $notif->invoiceDeleted((int) ($_SESSION['user_id'] ?? 0), $existing['invoice_number']);

        http_response_code(204);
        // 204 No Content - žádné tělo odpovědi
    }

    /**
     * Validace vstupních dat faktury.
     * Počítá vat_amount_cents a amount_cents na serveru.
     * @return array{valid: bool, fields: array<string,string>, data: array}
     */
    protected function validate(array $input, bool $isUpdate = false): array
    {
        $fields = [];
        $data = [];

        // invoice_number - volitelné, pokud není zadáno, vygeneruje se automaticky
        $invoiceNumber = $input['invoice_number'] ?? null;
        if (is_string($invoiceNumber)) {
            $invoiceNumber = trim($invoiceNumber);
        }
        if (($invoiceNumber === null || $invoiceNumber === '') && !$isUpdate) {
            // Auto-číslování: {year}{seq:03d}
            $invoiceNumber = $this->generateInvoiceNumber();
        }
        if ($invoiceNumber === null || $invoiceNumber === '') {
            $fields['invoice_number'] = 'Číslo faktury je povinné.';
        } elseif (mb_strlen($invoiceNumber) > 200) {
            $fields['invoice_number'] = 'Pole invoice_number je příliš dlouhé (max 200 znaků).';
        } else {
            $data['invoice_number'] = $invoiceNumber;
        }

        // client_id - volitelný, pokud zadáno musí existovat v clients
        $clientId = $input['client_id'] ?? null;
        if ($clientId !== null && $clientId !== '') {
            $clientId = (int) $clientId;
            if ($clientId <= 0) {
                $fields['client_id'] = 'Klient neexistuje.';
            } else {
                $repo = $this->repo(\DevAppPro\Repositories\InvoiceRepository::class);
                if (!$repo->clientExists($clientId)) {
                    $fields['client_id'] = 'Klient neexistuje.';
                } else {
                    $data['client_id'] = $clientId;
                }
            }
        } else {
            $data['client_id'] = null;
        }

        // project_id - volitelný, pokud zadáno musí existovat v projects
        $projectId = $input['project_id'] ?? null;
        if ($projectId !== null && $projectId !== '') {
            $projectId = (int) $projectId;
            if ($projectId <= 0) {
                $fields['project_id'] = 'Projekt neexistuje.';
            } else {
                $repo = $this->repo(\DevAppPro\Repositories\InvoiceRepository::class);
                if (!$repo->projectExists($projectId)) {
                    $fields['project_id'] = 'Projekt neexistuje.';
                } else {
                    $data['project_id'] = $projectId;
                }
            }
        } else {
            $data['project_id'] = null;
        }

        // status - z enum (draft, sent, paid, overdue, cancelled)
        $status = $input['status'] ?? 'draft';
        $status = is_string($status) ? trim($status) : 'draft';
        $allowedStatuses = Constants::INVOICE_STATUSES;
        if (!in_array($status, $allowedStatuses, true)) {
            $fields['status'] = 'Status musí být draft, sent, paid, overdue nebo cancelled.';
        } else {
            $data['status'] = $status;
        }

        // subtotal_cents - povinné, >= 0 (int)
        // Pokud jsou zadány položky (items), částka se spočítá na serveru z položek.
        $items = $input['items'] ?? null;
        $itemsError = false;
        if (is_array($items)) {
            $validatedItems = [];
            foreach ($items as $i => $item) {
                if (!is_array($item)) {
                    $fields['items'] = 'Položky faktury mají neplatný formát.';
                    $itemsError = true;
                    break;
                }
                $description = isset($item['description']) ? trim((string) $item['description']) : '';
                if ($description === '') {
                    $fields['items'] = 'Popis položky ' . ($i + 1) . ' je povinný.';
                    $itemsError = true;
                    break;
                }
                if (mb_strlen($description) > 500) {
                    $fields['items'] = 'Popis položky ' . ($i + 1) . ' je příliš dlouhý (max 500 znaků).';
                    $itemsError = true;
                    break;
                }
                $quantity = isset($item['quantity']) ? (float) $item['quantity'] : 1.0;
                if (!is_numeric($item['quantity'] ?? '1') || $quantity <= 0) {
                    $fields['items'] = 'Množství položky ' . ($i + 1) . ' musí být větší než 0.';
                    $itemsError = true;
                    break;
                }
                $unitPrice = isset($item['unit_price_cents']) ? (int) $item['unit_price_cents'] : 0;
                if (!is_numeric($item['unit_price_cents'] ?? 0) || $unitPrice < 0) {
                    $fields['items'] = 'Cena položky ' . ($i + 1) . ' musí být nezáporná.';
                    $itemsError = true;
                    break;
                }
                $validatedItems[] = [
                    'description'     => $description,
                    'quantity'        => $quantity,
                    'unit'            => isset($item['unit']) ? trim((string) $item['unit']) : null,
                    'unit_price_cents' => $unitPrice,
                ];
            }
            if (!$itemsError && !empty($validatedItems)) {
                $data['items'] = $validatedItems;
                // Mezisoučet spočítaný z položek (zaokrouhleno na haléře)
                $subtotalCents = 0;
                foreach ($validatedItems as $it) {
                    $subtotalCents += (int) round($it['quantity'] * $it['unit_price_cents']);
                }
                $data['subtotal_cents'] = $subtotalCents;
            }
        }

        if (!isset($data['subtotal_cents']) && !$itemsError) {
            $subtotalCents = $input['subtotal_cents'] ?? null;
            if ($subtotalCents === null) {
                $fields['subtotal_cents'] = 'Částka bez DPH je povinná.';
            } elseif (!is_numeric($subtotalCents)) {
                $fields['subtotal_cents'] = 'Částka bez DPH musí být nezáporné celé číslo.';
            } else {
                $subtotalCents = (int) $subtotalCents;
                if ($subtotalCents < 0) {
                    $fields['subtotal_cents'] = 'Částka bez DPH musí být nezáporné celé číslo.';
                } else {
                    $data['subtotal_cents'] = $subtotalCents;
                }
            }
        }

        // vat_rate_percent - 0-100 (default 0)
        $vatRatePercent = $input['vat_rate_percent'] ?? 0;
        if (!is_numeric($vatRatePercent)) {
            $fields['vat_rate_percent'] = 'Sazba DPH musí být číslo 0-100.';
        } else {
            $vatRatePercent = (float) $vatRatePercent;
            if ($vatRatePercent < 0 || $vatRatePercent > 100) {
                $fields['vat_rate_percent'] = 'Sazba DPH musí být číslo 0-100.';
            } else {
                $data['vat_rate_percent'] = $vatRatePercent;
            }
        }

        // Výpočet DPH na serveru: vat_amount_cents = round(subtotal * vat_rate / 100)
        // amount_cents = subtotal + vat_amount_cents
        if (isset($data['subtotal_cents']) && !isset($fields['vat_rate_percent'])) {
            $subtotal = (int) $data['subtotal_cents'];
            $vatRate = (float) $data['vat_rate_percent'];
            $vatAmount = (int) round($subtotal * $vatRate / 100);
            $amount = $subtotal + $vatAmount;
            $data['vat_amount_cents'] = $vatAmount;
            $data['amount_cents'] = $amount;
        }

        // currency - VARCHAR 3 (default CZK)
        $currency = $input['currency'] ?? 'CZK';
        $currency = is_string($currency) ? trim($currency) : 'CZK';
        if ($currency === '') {
            $currency = 'CZK';
        }
        if (mb_strlen($currency) > 50) {
            $fields['currency'] = 'Pole currency je příliš dlouhé (max 50 znaků).';
        } else {
            $data['currency'] = $currency;
        }

        // variable_symbol - string nebo NULL
        $variableSymbol = $input['variable_symbol'] ?? null;
        if (is_string($variableSymbol)) {
            $variableSymbol = trim($variableSymbol);
            if ($variableSymbol !== '' && mb_strlen($variableSymbol) > 100) {
                $fields['variable_symbol'] = 'Pole variable_symbol je příliš dlouhé (max 100 znaků).';
            }
            $data['variable_symbol'] = $variableSymbol !== '' ? $variableSymbol : null;
        } else {
            $data['variable_symbol'] = null;
        }

        // constant_symbol - string nebo NULL
        $constantSymbol = $input['constant_symbol'] ?? null;
        if (is_string($constantSymbol)) {
            $constantSymbol = trim($constantSymbol);
            if ($constantSymbol !== '' && mb_strlen($constantSymbol) > 100) {
                $fields['constant_symbol'] = 'Pole constant_symbol je příliš dlouhé (max 100 znaků).';
            }
            $data['constant_symbol'] = $constantSymbol !== '' ? $constantSymbol : null;
        } else {
            $data['constant_symbol'] = null;
        }

        // iban - string nebo NULL, validace formátu
        $iban = $input['iban'] ?? null;
        if (is_string($iban)) {
            $iban = trim(str_replace(' ', '', strtoupper($iban)));
            if ($iban !== '' && !$this->isValidIban($iban)) {
                $fields['iban'] = 'IBAN není platný (musí mít 15–34 znaků, začínat 2 písmeny + 2 číslicemi).';
            } else {
                $data['iban'] = $iban !== '' ? $iban : null;
            }
        } else {
            $data['iban'] = null;
        }

        // issue_date - volitelné, pokud není zadáno, použije se dnešní datum
        $issueDate = $input['issue_date'] ?? null;
        if ($issueDate === null || $issueDate === '') {
            $issueDate = date('Y-m-d');
        }
        if (!$this->isValidDate((string) $issueDate)) {
            $fields['issue_date'] = 'Datum vystavení musí být ve formátu YYYY-MM-DD.';
        } else {
            $data['issue_date'] = $issueDate;
        }

        // due_date - volitelné, pokud není zadáno, použije se issue_date + default_due_days
        $dueDate = $input['due_date'] ?? null;
        if ($dueDate === null || $dueDate === '') {
            $dueDays = $this->getDefaultDueDays();
            $dueDate = date('Y-m-d', strtotime((string) $issueDate . " +{$dueDays} days"));
        }
        if (!$this->isValidDate((string) $dueDate)) {
            $fields['due_date'] = 'Datum splatnosti musí být ve formátu YYYY-MM-DD.';
        } else {
            $data['due_date'] = $dueDate;
        }

        // taxable_date (DZP) - datum uskutečnění zdanitelného plnění.
        // Pokud není zadáno, použije se issue_date (zákonná náležitost dle ZDPH §28).
        $taxableDate = $input['taxable_date'] ?? null;
        if ($taxableDate === null || $taxableDate === '') {
            $taxableDate = $issueDate;
        }
        if (!$this->isValidDate((string) $taxableDate)) {
            $fields['taxable_date'] = 'Datum zdanitelného plnění musí být ve formátu YYYY-MM-DD.';
        } else {
            $data['taxable_date'] = $taxableDate;
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
     * Upraví jméno klienta pro název souboru:
     * ASCII bez diakritiky, nepovolené znaky → pomlčka.
     */
    private function slugifyFilename(string $name): string
    {
        $name = strtr($name, [
            'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i',
            'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u',
            'ů' => 'u', 'ý' => 'y', 'ž' => 'z', 'Á' => 'A', 'Č' => 'C', 'Ď' => 'D',
            'É' => 'E', 'Ě' => 'E', 'Í' => 'I', 'Ň' => 'N', 'Ó' => 'O', 'Ř' => 'R',
            'Š' => 'S', 'Ť' => 'T', 'Ú' => 'U', 'Ů' => 'U', 'Ý' => 'Y', 'Ž' => 'Z',
        ]);
        $name = preg_replace('/[^a-zA-Z0-9]+/', '-', $name);
        $name = trim((string) $name, '-');
        return mb_substr($name, 0, 60);
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

    /**
     * Ověří IBAN formát (ISO 13616):
     * - 2 písmena (kód země)
     * - 2 číslice (kontrolní číslice)
     * - 11–31 znaků (BBAN)
     * - Celkem 15–34 znaků
     * Pro plnou validaci kontrolních číslic používá mod-97 algoritmus.
     */
    private function isValidIban(string $iban): bool
    {
        // Základní formát: 2 písmena + 2 číslice + 11-31 alfanumerických znaků
        if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,31}$/', $iban)) {
            return false;
        }
        // Mod-97 kontrola
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $numeric = '';
        for ($i = 0; $i < strlen($rearranged); $i++) {
            $char = $rearranged[$i];
            if (ctype_alpha($char)) {
                $numeric .= (string) (ord($char) - 55);
            } else {
                $numeric .= $char;
            }
        }
        // bcmod pro velká čísla
        $remainder = (string) bcmod($numeric, '97');
        return $remainder === '1';
    }

    /**
     * Vygeneruje další číslo faktury ve formátu {year}{seq:03d}.
     * Resetuje sekvenci při změně roku.
     * Atomicky inkrementuje sekvenci v settings.
     */
    private function generateInvoiceNumber(): string
    {
        $settingsRepo = $this->repo(\DevAppPro\Repositories\SettingsRepository::class);
        $settings = $settingsRepo->allSettings();
        $format = $settings['invoice_number_format'] ?? '{year}{seq:03d}';
        $currentYear = (int) date('Y');
        $seqYear = (int) ($settings['invoice_seq_year'] ?? $currentYear);
        $seq = (int) ($settings['invoice_seq'] ?? 0);

        // Reset sekvence při změně roku
        if ($seqYear !== $currentYear) {
            $seq = 0;
            $settingsRepo->set('invoice_seq_year', (string) $currentYear);
        }

        $seq++;
        $settingsRepo->set('invoice_seq', (string) $seq);

        // Formátování: {year} → rok, {seq:03d} → sekvence s paddingem
        $number = str_replace(['{year}', '{seq:03d}'], [(string) $currentYear, str_pad((string) $seq, 3, '0', STR_PAD_LEFT)], $format);

        return $number;
    }

    /**
     * Vrátí výchozí počet dní splatnosti z settings.
     */
    private function getDefaultDueDays(): int
    {
        $settingsRepo = $this->repo(\DevAppPro\Repositories\SettingsRepository::class);
        $settings = $settingsRepo->allSettings();
        return (int) ($settings['default_due_days'] ?? 14);
    }
}
