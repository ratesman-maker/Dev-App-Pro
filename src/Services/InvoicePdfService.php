<?php
declare(strict_types=1);

namespace DevAppPro\Services;

use DevAppPro\Repositories\InvoiceRepository;
use DevAppPro\Repositories\ClientRepository;
use DevAppPro\Repositories\CompanyProfileRepository;
use DevAppPro\Repositories\SettingsRepository;

/**
 * Služba pro generování PDF faktur pomocí mPDF.
 */
class InvoicePdfService
{
    private InvoiceRepository $invoices;
    private ClientRepository $clients;
    private CompanyProfileRepository $profileRepo;
    private SettingsRepository $settings;

    /**
     * Počet desetinných míst pro měnu (z settings).
     */
    private int $currencyDecimals;

    /**
     * Měna (z settings).
     */
    private string $currency;

    public function __construct()
    {
        $this->invoices = new InvoiceRepository();
        $this->clients = new ClientRepository();
        $this->profileRepo = new CompanyProfileRepository();
        $this->settings = new SettingsRepository();

        $this->currency = $this->settings->get('currency') ?? 'CZK';
        $decimals = $this->settings->get('currency_decimals');
        $this->currencyDecimals = $decimals !== null ? (int) $decimals : 0;
    }

    /**
     * Vygeneruje PDF faktury jako string.
     *
     * @param int $invoiceId ID faktury.
     * @return string Binární PDF data.
     * @throws \RuntimeException Pokud faktura neexistuje.
     */
    public function generate(int $invoiceId): string
    {
        $invoice = $this->invoices->find($invoiceId);
        if ($invoice === null) {
            throw new \RuntimeException('Faktura nenalezena.');
        }

        $client = null;
        if (!empty($invoice['client_id'])) {
            $client = $this->clients->find((int) $invoice['client_id']);
        }

        $profile = $this->profileRepo->get();

        $html = $this->buildHtml($invoice, $client, $profile);

        $mpdf = new \Mpdf\Mpdf([
            'mode'  => 'utf-8',
            'format' => 'A4',
        ]);

        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
    }

    /**
     * Vygeneruje PDF a uloží ho jako zmrazenou archivní kopii
     * do storage/invoices/. Volá se při přechodu faktury na
     * status sent/paid/overdue — vydaná faktura je účetní doklad
     * a stažené PDF musí odpovídat verzi, která byla vydána.
     *
     * @return string Relativní cesta pod storage/ (např. invoices/faktura-2026001.pdf).
     * @throws \RuntimeException Pokud faktura neexistuje nebo zápis selže.
     */
    public function freeze(int $invoiceId): string
    {
        $invoice = $this->invoices->find($invoiceId);
        if ($invoice === null) {
            throw new \RuntimeException('Faktura nenalezena.');
        }

        $pdf = $this->generate($invoiceId);

        $safeNumber = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $invoice['invoice_number']);
        $relativePath = 'invoices/faktura-' . $safeNumber . '.pdf';

        $dir = self::storageDir() . '/invoices';
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new \RuntimeException('Nelze vytvořit adresář ' . $dir);
        }

        if (file_put_contents(self::storageDir() . '/' . $relativePath, $pdf) === false) {
            throw new \RuntimeException('Zápis zmrazeného PDF selhal: ' . $relativePath);
        }

        return $relativePath;
    }

    /**
     * Absolutní cesta k adresáři storage/.
     */
    public static function storageDir(): string
    {
        return dirname(__DIR__, 2) . '/storage';
    }

    /**
     * Naformátuje datum z ISO formátu (2026-03-26) na český formát (26.3.2026).
     */
    public function formatDate(string $date): string
    {
        $ts = strtotime($date);
        if ($ts === false) {
            return $date;
        }
        return date('j.n.Y', $ts);
    }

    /**
     * Naformátuje částku v centech bez desetinných míst.
     * 150000 → "1 500 Kč"
     */
    public function formatMoney(int $cents): string
    {
        $whole = intdiv($cents, 100);
        $formatted = number_format($whole, 0, '', ' ');
        return $formatted . ' ' . $this->currency;
    }

    /**
     * Naformátuje částku v centech s desetinnými místy podle settings.
     * 150000 → "1 500,00" (při currency_decimals=2)
     */
    public function formatMoneyDecimal(int $cents): string
    {
        $value = $cents / 100.0;
        $decSep = ',';
        $thousandsSep = ' ';
        return number_format($value, $this->currencyDecimals, $decSep, $thousandsSep);
    }

    /**
     * Vrátí HTML blok pro prodávajícího (z company_profile).
     */
    public function getSellerHtml(array $profile): string
    {
        $name = $this->formatEntityName($profile);
        $lines = [];
        if (!empty($profile['ico'])) {
            $lines[] = 'IČO: ' . $this->e($profile['ico']);
        }
        if (!empty($profile['dic'])) {
            $lines[] = 'DIČ: ' . $this->e($profile['dic']);
        }
        if (!empty($profile['address'])) {
            $lines[] = $this->e($profile['address']);
        }
        if (!empty($profile['email'])) {
            $lines[] = $this->e($profile['email']);
        }
        if (!empty($profile['phone'])) {
            $lines[] = $this->e($profile['phone']);
        }
        if (!empty($profile['iban'])) {
            $lines[] = 'IBAN: ' . $this->e($profile['iban']);
        }

        $html = "<strong>Prodávající</strong><br>\n";
        $html .= "<strong>" . $this->e($name) . "</strong><br>\n";
        foreach ($lines as $line) {
            $html .= $line . "<br>\n";
        }
        return $html;
    }

    /**
     * Vrátí HTML blok pro kupujícího (z clients).
     */
    public function getBuyerHtml(?array $client): string
    {
        if ($client === null) {
            return "<strong>Kupující</strong><br>\n—";
        }

        $name = $this->formatEntityName($client);
        $lines = [];
        if (!empty($client['ico'])) {
            $lines[] = 'IČO: ' . $this->e($client['ico']);
        }
        if (!empty($client['dic'])) {
            $lines[] = 'DIČ: ' . $this->e($client['dic']);
        }
        if (!empty($client['address'])) {
            $lines[] = $this->e($client['address']);
        }
        if (!empty($client['email'])) {
            $lines[] = $this->e($client['email']);
        }
        if (!empty($client['phone'])) {
            $lines[] = $this->e($client['phone']);
        }

        $html = "<strong>Kupující</strong><br>\n";
        $html .= "<strong>" . $this->e($name) . "</strong><br>\n";
        foreach ($lines as $line) {
            $html .= $line . "<br>\n";
        }
        return $html;
    }

    /**
     * Vrátí HTML tabulku s položkami faktury.
     * Podporuje položky (invoice_items); staré faktury bez položek
     * spadnou na jednu řádku "Fakturované služby".
     */
    public function getItemsHtml(array $invoice): string
    {
        $subtotal = $this->formatMoneyDecimal((int) ($invoice['subtotal_cents'] ?? 0));
        $vatRate = (float) ($invoice['vat_rate_percent'] ?? 0);
        $vatAmount = $this->formatMoneyDecimal((int) ($invoice['vat_amount_cents'] ?? 0));
        $total = $this->formatMoneyDecimal((int) ($invoice['amount_cents'] ?? 0));
        $totalFormatted = $this->formatMoney((int) ($invoice['amount_cents'] ?? 0));

        $html = "<table style=\"width:100%;border-collapse:collapse;margin-top:20px;\">\n";
        $html .= "<thead>\n";
        $html .= "<tr style=\"background:#f0f0f0;font-weight:bold;\">\n";
        $html .= "<th style=\"border:1px solid #ccc;padding:5px;text-align:left;\">Popis</th>\n";
        $html .= "<th style=\"border:1px solid #ccc;padding:5px;text-align:right;\">Množství</th>\n";
        $html .= "<th style=\"border:1px solid #ccc;padding:5px;text-align:left;\">MJ</th>\n";
        $html .= "<th style=\"border:1px solid #ccc;padding:5px;text-align:right;\">Cena/MJ</th>\n";
        $html .= "<th style=\"border:1px solid #ccc;padding:5px;text-align:right;\">Celkem</th>\n";
        $html .= "</tr>\n";
        $html .= "</thead>\n";
        $html .= "<tbody>\n";

        $items = $invoice['items'] ?? [];
        if (empty($items)) {
            $html .= "<tr>\n";
            $html .= "<td style=\"border:1px solid #ccc;padding:5px;\">Fakturované služby</td>\n";
            $html .= "<td style=\"border:1px solid #ccc;padding:5px;text-align:right;\">1</td>\n";
            $html .= "<td style=\"border:1px solid #ccc;padding:5px;\"></td>\n";
            $html .= "<td style=\"border:1px solid #ccc;padding:5px;text-align:right;\">" . $this->e($subtotal) . "</td>\n";
            $html .= "<td style=\"border:1px solid #ccc;padding:5px;text-align:right;\">" . $this->e($subtotal) . "</td>\n";
            $html .= "</tr>\n";
        } else {
            foreach ($items as $item) {
                $qty = (float) ($item['quantity'] ?? 1);
                $unit = (string) ($item['unit'] ?? '');
                $unitPrice = (int) ($item['unit_price_cents'] ?? 0);
                $rowTotal = (int) round($qty * $unitPrice);
                $html .= "<tr>\n";
                $html .= "<td style=\"border:1px solid #ccc;padding:5px;\">" . $this->e((string) ($item['description'] ?? '')) . "</td>\n";
                $html .= "<td style=\"border:1px solid #ccc;padding:5px;text-align:right;\">" . $this->e($this->formatQuantity($qty)) . "</td>\n";
                $html .= "<td style=\"border:1px solid #ccc;padding:5px;\">" . $this->e($unit) . "</td>\n";
                $html .= "<td style=\"border:1px solid #ccc;padding:5px;text-align:right;\">" . $this->e($this->formatMoneyDecimal($unitPrice)) . "</td>\n";
                $html .= "<td style=\"border:1px solid #ccc;padding:5px;text-align:right;\">" . $this->e($this->formatMoneyDecimal($rowTotal)) . "</td>\n";
                $html .= "</tr>\n";
            }
        }

        $html .= "</tbody>\n";
        $html .= "</table>\n";

        $html .= "<table style=\"width:100%;border-collapse:collapse;margin-top:10px;\">\n";
        $html .= "<tr>\n";
        $html .= "<td style=\"padding:3px;text-align:right;\">Mezisoučet:</td>\n";
        $html .= "<td style=\"padding:3px;text-align:right;width:120px;\">" . $this->e($subtotal) . "</td>\n";
        $html .= "</tr>\n";
        $html .= "<tr>\n";
        $html .= "<td style=\"padding:3px;text-align:right;\">DPH " . $this->e(number_format($vatRate, 1, ',', '')) . "%:</td>\n";
        $html .= "<td style=\"padding:3px;text-align:right;\">" . $this->e($vatAmount) . "</td>\n";
        $html .= "</tr>\n";
        $html .= "<tr style=\"font-weight:bold;font-size:14px;\">\n";
        $html .= "<td style=\"padding:3px;text-align:right;border-top:2px solid #333;\">Celkem k úhradě:</td>\n";
        $html .= "<td style=\"padding:3px;text-align:right;border-top:2px solid #333;\">" . $this->e($totalFormatted) . "</td>\n";
        $html .= "</tr>\n";
        $html .= "</table>\n";

        return $html;
    }

    /**
     * Sestaví kompletní HTML šablonu faktury.
     */
    private function buildHtml(array $invoice, ?array $client, ?array $profile): string
    {
        $invoiceNumber = $this->e((string) ($invoice['invoice_number'] ?? ''));
        $issueDate = $this->formatDate((string) ($invoice['issue_date'] ?? ''));
        $dueDate = $this->formatDate((string) ($invoice['due_date'] ?? ''));
        $taxableDate = $this->formatDate((string) ($invoice['taxable_date'] ?? $invoice['issue_date'] ?? ''));
        $variableSymbol = $this->e((string) ($invoice['variable_symbol'] ?? ''));
        $constantSymbol = $this->e((string) ($invoice['constant_symbol'] ?? ''));
        $ibanRaw = (string) ($invoice['iban'] ?? '');
        if ($ibanRaw === '') {
            $ibanRaw = (string) ($profile['iban'] ?? '');
        }
        $iban = $this->e($ibanRaw);
        $swift = $this->e((string) ($profile['swift'] ?? ''));

        $sellerHtml = $profile !== null ? $this->getSellerHtml($profile) : '<strong>Prodávající</strong><br>—';
        $buyerHtml = $this->getBuyerHtml($client);
        $itemsHtml = $this->getItemsHtml($invoice);

        // QR Platba (standard ČBA) - pokud je k dispozici účet/IBAN
        $qrHtml = '';
        $qrData = $this->buildQrPlatba($invoice, $profile);
        if ($qrData !== '') {
            $qrHtml = "<div class=\"qr\">\n"
                . "<barcode code=\"" . $this->e($qrData) . "\" type=\"QR\" error=\"M\" size=\"1.8\" />\n"
                . "<div style=\"font-size:8px;color:#666;margin-top:3px;\">QR Platba - načtěte bankovní aplikací</div>\n"
                . "</div>\n";
        }

        // Prohlášení k DPH
        $isVatPayer = !empty($profile['dic']);
        if ($isVatPayer) {
            $vatNote = 'Plátce DPH. Faktura je daňovým dokladem dle zákona č. 235/2004 Sb., o dani z přidané hodnoty.';
        } else {
            $vatNote = 'Nejsem plátce DPH. Faktura není daňovým dokladem dle zákona č. 235/2004 Sb.';
        }

        $html = "<!DOCTYPE html>\n<html lang=\"cs\">\n<head>\n";
        $html .= "<meta charset=\"UTF-8\">\n";
        $html .= "<style>\n";
        $html .= "body { font-family: sans-serif; font-size: 11px; color: #333; margin: 20px; }\n";
        $html .= "h1 { font-size: 20px; margin-bottom: 3px; }\n";
        $html .= "h2 { font-size: 13px; color: #666; margin-top: 0; }\n";
        $html .= ".header { margin-bottom: 14px; }\n";
        $html .= ".parties { display: table; width: 100%; margin-bottom: 10px; }\n";
        $html .= ".party { display: table-cell; width: 50%; vertical-align: top; padding: 6px; }\n";
        $html .= ".info-table { width: 100%; margin-bottom: 10px; }\n";
        $html .= ".info-table td { padding: 2px 8px; }\n";
        $html .= ".info-table td:first-child { font-weight: bold; width: 180px; }\n";
        $html .= ".qr { text-align: center; }\n";
        $html .= "</style>\n";
        $html .= "</head>\n<body>\n";

        $html .= "<div class=\"header\">\n";
        $html .= "<h1>Faktura č. " . $invoiceNumber . "</h1>\n";
        if ($profile !== null) {
            $html .= "<h2>" . $this->e($this->formatEntityName($profile)) . "</h2>\n";
        }
        $html .= "</div>\n";

        $html .= "<div class=\"parties\">\n";
        $html .= "<div class=\"party\">\n" . $sellerHtml . "</div>\n";
        $html .= "<div class=\"party\">\n" . $buyerHtml . "</div>\n";
        $html .= "</div>\n";

        $html .= "<table class=\"info-table\">\n";
        $html .= "<tr><td>Číslo faktury:</td><td>" . $invoiceNumber . "</td></tr>\n";
        $html .= "<tr><td>Datum vystavení:</td><td>" . $this->e($issueDate) . "</td></tr>\n";
        $html .= "<tr><td>Datum uskutečnění zdanitelného plnění:</td><td>" . $this->e($taxableDate) . "</td></tr>\n";
        $html .= "<tr><td>Datum splatnosti:</td><td>" . $this->e($dueDate) . "</td></tr>\n";
        $html .= "<tr><td>Variabilní symbol:</td><td>" . $variableSymbol . "</td></tr>\n";
        $html .= "<tr><td>Konstantní symbol:</td><td>" . $constantSymbol . "</td></tr>\n";
        $html .= "</table>\n";

        $html .= $itemsHtml;

        // Platební údaje + QR Platba
        $html .= "<table style=\"width:100%;margin-top:12px;border-collapse:collapse;\">\n";
        $html .= "<tr>\n";
        $html .= "<td style=\"vertical-align:top;padding:10px;border:1px solid #ccc;\">\n";
        $html .= "<strong>Platební údaje</strong><br>\n";
        $html .= "Číslo účtu: " . $this->e((string) ($profile['bank_account'] ?? '')) . "<br>\n";
        $html .= "IBAN: " . $iban . "<br>\n";
        if ($swift !== '') {
            $html .= "SWIFT/BIC: " . $swift . "<br>\n";
        }
        $html .= "Variabilní symbol: " . $variableSymbol . "<br>\n";
        if ($constantSymbol !== '') {
            $html .= "Konstantní symbol: " . $constantSymbol . "<br>\n";
        }
        $html .= "</td>\n";
        $html .= "<td style=\"width:190px;vertical-align:middle;text-align:center;border:1px solid #ccc;\">\n";
        $html .= $qrHtml !== '' ? $qrHtml : '&nbsp;';
        $html .= "</td>\n";
        $html .= "</tr>\n";
        $html .= "</table>\n";

        if (!empty($invoice['note'])) {
            $html .= "<div style=\"margin-top:20px;\">\n";
            $html .= "<strong>Poznámka:</strong><br>\n";
            $html .= $this->e((string) $invoice['note']) . "\n";
            $html .= "</div>\n";
        }

        $html .= "<div style=\"margin-top:20px;font-size:10px;color:#555;\">\n";
        $html .= $this->e($vatNote) . "\n";
        $html .= "</div>\n";

        $html .= "<div style=\"margin-top:35px;\">\n";
        $html .= "<table style=\"width:100%;\">\n";
        $html .= "<tr>\n";
        $html .= "<td style=\"width:50%;text-align:center;\">_________________________<br>Vystavil: " . $this->e($profile !== null ? $this->formatEntityName($profile) : '') . "</td>\n";
        $html .= "<td style=\"width:50%;text-align:center;\">_________________________<br>Razítko a podpis</td>\n";
        $html .= "</tr>\n";
        $html .= "</table>\n";
        $html .= "</div>\n";

        $html .= "</body>\n</html>\n";

        return $html;
    }

    /**
     * Sestaví QR Platbu (SPD 1.0, standard ČBA) z faktury a profilu.
     * Vrací prázdný řetězec, pokud není k dispozici IBAN ani číslo účtu.
     */
    private function buildQrPlatba(array $invoice, ?array $profile): string
    {
        $iban = (string) ($invoice['iban'] ?? '');
        if ($iban === '') {
            $iban = (string) ($profile['iban'] ?? '');
        }
        if ($iban === '') {
            $bankAccount = (string) ($profile['bank_account'] ?? '');
            if ($bankAccount !== '' && preg_match('/^(\d{1,16})[\/\s-]*(\d{4})$/', $bankAccount, $m)) {
                // Převod českého čísla účtu na IBAN (CZkk BBBB SSSS SSSS SSSS)
                $account = str_pad($m[1], 16, '0', STR_PAD_LEFT);
                $bank = $m[2];
                $bban = $bank . $account;
                $iban = $this->ibanFromBban('CZ', $bban);
            }
        }
        if ($iban === '') {
            return '';
        }

        $iban = preg_replace('/\s+/', '', strtoupper($iban));

        $amount = number_format(((int) ($invoice['amount_cents'] ?? 0)) / 100, 2, '.', '');
        $currency = strtoupper((string) ($invoice['currency'] ?? 'CZK'));
        $variableSymbol = preg_replace('/\D/', '', (string) ($invoice['variable_symbol'] ?? ''));
        $message = $this->qrMessage((string) ($invoice['invoice_number'] ?? ''));

        $parts = ['SPD*1.0', 'ACC:' . $iban];
        $parts[] = 'AM:' . $amount;
        $parts[] = 'CC:' . $currency;
        if ($variableSymbol !== '') {
            $parts[] = 'X-SS:' . $variableSymbol;
        }
        if ($message !== '') {
            $parts[] = 'MSG:' . $message;
        }

        $qr = implode('*', $parts) . '*';

        // Limit standardu je 400 znaků
        if (strlen($qr) > 400) {
            $qr = 'SPD*1.0*ACC:' . $iban . '*AM:' . $amount . '*CC:' . $currency . '*';
        }

        return $qr;
    }

    /**
     * Vypočítá IBAN z BBAN (ISO 13616, mod-97).
     */
    private function ibanFromBban(string $country, string $bban): string
    {
        $rearranged = $bban . $country . '00';
        $numeric = '';
        for ($i = 0; $i < strlen($rearranged); $i++) {
            $char = $rearranged[$i];
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }
        $remainder = (int) bcmod($numeric, '97');
        $checksum = 98 - $remainder;
        return $country . str_pad((string) $checksum, 2, '0', STR_PAD_LEFT) . $bban;
    }

    /**
     * Upraví zprávu pro QR Platbu: ASCII bez diakritiky, povolené znaky,
     * mezery na %20, max 60 znaků.
     */
    private function qrMessage(string $message): string
    {
        $message = strtr($message, [
            'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i',
            'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u',
            'ů' => 'u', 'ý' => 'y', 'ž' => 'z', 'Á' => 'A', 'Č' => 'C', 'Ď' => 'D',
            'É' => 'E', 'Ě' => 'E', 'Í' => 'I', 'Ň' => 'N', 'Ó' => 'O', 'Ř' => 'R',
            'Š' => 'S', 'Ť' => 'T', 'Ú' => 'U', 'Ů' => 'U', 'Ý' => 'Y', 'Ž' => 'Z',
        ]);
        $message = preg_replace('/[^0-9A-Za-z%*+\-.,\/:]/', ' ', $message);
        $message = trim((string) preg_replace('/\s+/', ' ', (string) $message));
        $message = str_replace(' ', '%20', $message);
        return mb_substr($message, 0, 60);
    }

    /**
     * Naformátuje množství: celá čísla bez desetinných míst,
     * jinak max 3 desetinná místa (bez koncových nul).
     */
    private function formatQuantity(float $quantity): string
    {
        if (floor($quantity) === $quantity) {
            return number_format($quantity, 0, ',', '');
        }
        $formatted = number_format($quantity, 3, ',', '');
        return rtrim(rtrim($formatted, '0'), ',');
    }

    /**
     * Vrátí název entity (osoba nebo firma) z dat profilu/klienta.
     */
    private function formatEntityName(array $data): string
    {
        $type = $data['type'] ?? 'individual';
        if (in_array($type, ['company', 'nonprofit', 'government'], true)) {
            return (string) ($data['company_name'] ?? '');
        }
        $first = (string) ($data['first_name'] ?? '');
        $last = (string) ($data['last_name'] ?? '');
        return trim($first . ' ' . $last);
    }

    /**
     * Escapuje HTML speciální znaky.
     */
    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
