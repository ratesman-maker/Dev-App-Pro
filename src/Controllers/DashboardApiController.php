<?php
declare(strict_types=1);

namespace DevAppPro\Controllers;

use DevAppPro\Core\ApiController;
use DevAppPro\Auth;

/**
 * API kontroler pro dashboard (agregovaná data).
 * GET /api/dashboard → show()
 */
class DashboardApiController extends ApiController
{
    private Auth $auth;

    public function __construct()
    {
        $this->auth = $this->repo(\DevAppPro\Auth::class);
    }

    /**
     * Dispatchuje - pouze GET /api/dashboard, vyžaduje auth, bez CSRF.
     */
    public function handle(): void
    {
        $this->auth->requireAuth();

        $method = $this->getMethod();
        if ($method !== 'GET') {
            $this->jsonError('Nepodporovaná HTTP metoda.', 405);
            return;
        }

        $this->show();
    }

    /**
     * GET /api/dashboard - přehled klientů a projektů s agregovanými vazbami
     * (faktury, finance, poznámky) + pás "Co řešit".
     */
    protected function show(?int $id = null): void
    {
        $pdo = db();

        // === KPI ===
        $open = $pdo->query(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(`amount_cents` - `paid_cents`), 0) AS cents
             FROM `invoices` WHERE `status` IN ('sent','overdue')"
        )->fetch();
        $overdue = $pdo->query(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(`amount_cents`), 0) AS cents
             FROM `invoices`
             WHERE `due_date` < CURDATE() AND `status` NOT IN ('paid','cancelled','draft')"
        )->fetch();

        $kpis = [
            'clients'             => $this->countTable($pdo, 'clients'),
            'projects'            => $this->countTable($pdo, 'projects'),
            'active_projects'     => $this->countWhere($pdo, 'projects', "status = 'active'"),
            'open_invoices_count' => (int) $open['cnt'],
            'open_cents'          => (int) $open['cents'],
            'overdue_count'       => (int) $overdue['cnt'],
            'overdue_cents'       => (int) $overdue['cents'],
        ];

        // === FINANCE souhrn (pro widgety v sekci Finance) ===
        $invoicePaid = $this->sumWhere($pdo, 'invoices', 'paid_cents', "status = 'paid'");
        $incomeTransactions = $this->sumWhere($pdo, 'transactions', 'amount_cents', "type = 'income'");
        $expenseTransactions = $this->sumWhere($pdo, 'transactions', 'amount_cents', "type = 'expense'");

        $finance = [
            'total_paid_cents'    => $invoicePaid + $incomeTransactions,
            'total_income_cents'  => $incomeTransactions,
            'total_expense_cents' => $expenseTransactions,
            'total_open_cents'    => $this->sumWhere(
                $pdo,
                'invoices',
                'amount_cents - paid_cents',
                "status IN ('sent','overdue')"
            ),
            'overdue_count'       => (int) $overdue['cnt'],
            'overdue_cents'       => (int) $overdue['cents'],
        ];

        // === KLIENTI s agregacemi (řazeno dle poslední aktivity) ===
        $clientsSql = "SELECT c.*, "
            . "(SELECT COUNT(*) FROM `projects` p WHERE p.`client_id` = c.`id`) AS projects_count, "
            . "(SELECT COUNT(*) FROM `projects` p WHERE p.`client_id` = c.`id` AND p.`status` = 'active') AS active_projects_count, "
            . "(SELECT COUNT(*) FROM `invoices` i WHERE i.`client_id` = c.`id`) AS invoices_count, "
            . "(SELECT COALESCE(SUM(i.`amount_cents` - i.`paid_cents`), 0) FROM `invoices` i WHERE i.`client_id` = c.`id` AND i.`status` IN ('sent','overdue')) AS open_cents, "
            . "(SELECT COUNT(*) FROM `invoices` i WHERE i.`client_id` = c.`id` AND i.`due_date` < CURDATE() AND i.`status` NOT IN ('paid','cancelled','draft')) AS overdue_count, "
            . "(SELECT COALESCE(SUM(i.`amount_cents`), 0) FROM `invoices` i WHERE i.`client_id` = c.`id` AND i.`due_date` < CURDATE() AND i.`status` NOT IN ('paid','cancelled','draft')) AS overdue_cents, "
            . "(SELECT COALESCE(SUM(t.`amount_cents`), 0) FROM `transactions` t WHERE t.`client_id` = c.`id` AND t.`type` = 'income') AS income_cents, "
            . "GREATEST("
            . "COALESCE((SELECT MAX(n.`created_at`) FROM `noteables` nb JOIN `notes` n ON n.`id` = nb.`note_id` WHERE nb.`entity_type` = 'client' AND nb.`entity_id` = c.`id`), '1970-01-01 00:00:00'), "
            . "COALESCE((SELECT MAX(i.`updated_at`) FROM `invoices` i WHERE i.`client_id` = c.`id`), '1970-01-01 00:00:00'), "
            . "COALESCE((SELECT MAX(CONCAT(t.`transaction_date`, ' 00:00:00')) FROM `transactions` t WHERE t.`client_id` = c.`id`), '1970-01-01 00:00:00'), "
            . "COALESCE((SELECT MAX(p.`updated_at`) FROM `projects` p WHERE p.`client_id` = c.`id`), '1970-01-01 00:00:00')"
            . ") AS last_activity_at "
            . "FROM `clients` c "
            . "ORDER BY last_activity_at DESC, c.`id` DESC";
        $clients = $pdo->query($clientsSql)->fetchAll();
        foreach ($clients as &$row) {
            $row['name'] = $this->clientDisplayName($row);
        }
        unset($row);

        // Poslední poznámka každého klienta
        $lastNotes = [];
        $noteRows = $pdo->query(
            "SELECT nb.`entity_id` AS client_id, n.`title`, n.`content`, n.`created_at`
             FROM `noteables` nb
             JOIN `notes` n ON n.`id` = nb.`note_id`
             WHERE nb.`entity_type` = 'client'
             ORDER BY n.`created_at` DESC"
        )->fetchAll();
        foreach ($noteRows as $n) {
            $cid = (int) $n['client_id'];
            if (!isset($lastNotes[$cid])) {
                $lastNotes[$cid] = [
                    'title'      => $n['title'],
                    'snippet'    => $this->snippet((string) $n['content'], 70),
                    'created_at' => $n['created_at'],
                ];
            }
        }
        foreach ($clients as &$row) {
            $row['last_note'] = $lastNotes[(int) $row['id']] ?? null;
        }
        unset($row);

        // === AKTIVNÍ PROJEKTY s agregacemi ===
        $projectsSql = "SELECT p.*, "
            . "c.`type` AS client_type, c.`first_name` AS client_first_name, "
            . "c.`last_name` AS client_last_name, c.`company_name` AS client_company_name, "
            . "(SELECT COALESCE(SUM(t.`amount_cents`), 0) FROM `transactions` t WHERE t.`project_id` = p.`id` AND t.`type` = 'income') AS income_cents, "
            . "(SELECT COUNT(*) FROM `invoices` i WHERE i.`project_id` = p.`id`) AS invoices_count, "
            . "(SELECT COALESCE(SUM(i.`amount_cents` - i.`paid_cents`), 0) FROM `invoices` i WHERE i.`project_id` = p.`id` AND i.`status` IN ('sent','overdue')) AS open_cents, "
            . "(SELECT COUNT(*) FROM `noteables` nb WHERE nb.`entity_type` = 'project' AND nb.`entity_id` = p.`id`) AS notes_count, "
            . "(SELECT COUNT(*) FROM `fileables` fb WHERE fb.`entity_type` = 'project' AND fb.`entity_id` = p.`id`) AS files_count "
            . "FROM `projects` p "
            . "LEFT JOIN `clients` c ON c.`id` = p.`client_id` "
            . "WHERE p.`status` = 'active' "
            . "ORDER BY (p.`deadline` IS NULL) ASC, p.`deadline` ASC, p.`id` DESC "
            . "LIMIT 50";
        $projects = $pdo->query($projectsSql)->fetchAll();
        foreach ($projects as &$row) {
            $row['client_name'] = $this->formatClientName($row);
            unset($row['client_type'], $row['client_first_name'], $row['client_last_name'], $row['client_company_name']);
        }
        unset($row);

        // === PÁS "CO ŘEŠIT" ===
        // Faktury po splatnosti
        $overdueRows = $pdo->query(
            "SELECT i.`id`, i.`invoice_number`, i.`amount_cents`, i.`due_date`, "
            . "DATEDIFF(CURDATE(), i.`due_date`) AS days_overdue, "
            . "c.`type` AS client_type, c.`first_name` AS client_first_name, "
            . "c.`last_name` AS client_last_name, c.`company_name` AS client_company_name "
            . "FROM `invoices` i "
            . "LEFT JOIN `clients` c ON c.`id` = i.`client_id` "
            . "WHERE i.`due_date` < CURDATE() AND i.`status` NOT IN ('paid','cancelled','draft') "
            . "ORDER BY i.`due_date` ASC, i.`id` ASC LIMIT 10"
        )->fetchAll();
        foreach ($overdueRows as &$row) {
            $row['client_name'] = $this->formatClientName($row);
            unset($row['client_type'], $row['client_first_name'], $row['client_last_name'], $row['client_company_name']);
        }
        unset($row);

        // Blížící se termíny projektů (≤ 14 dní)
        $deadlineRows = $pdo->query(
            "SELECT p.`id`, p.`name`, p.`deadline`, "
            . "DATEDIFF(p.`deadline`, CURDATE()) AS days_left, "
            . "c.`type` AS client_type, c.`first_name` AS client_first_name, "
            . "c.`last_name` AS client_last_name, c.`company_name` AS client_company_name "
            . "FROM `projects` p "
            . "LEFT JOIN `clients` c ON c.`id` = p.`client_id` "
            . "WHERE p.`status` = 'active' AND p.`deadline` IS NOT NULL "
            . "AND p.`deadline` <= DATE_ADD(CURDATE(), INTERVAL 14 DAY) "
            . "ORDER BY p.`deadline` ASC LIMIT 10"
        )->fetchAll();
        foreach ($deadlineRows as &$row) {
            $row['client_name'] = $this->formatClientName($row);
            unset($row['client_type'], $row['client_first_name'], $row['client_last_name'], $row['client_company_name']);
        }
        unset($row);

        // Poslední poznámky napříč entitami
        $recentNoteRows = $pdo->query(
            "SELECT n.`id`, n.`title`, n.`content`, n.`created_at`, "
            . "nb.`entity_type`, nb.`entity_id` "
            . "FROM `notes` n "
            . "JOIN `noteables` nb ON nb.`note_id` = n.`id` "
            . "ORDER BY n.`created_at` DESC LIMIT 8"
        )->fetchAll();

        // Mapy jmen entit pro poznámky
        $clientNames = [];
        foreach ($pdo->query("SELECT `id`, `first_name`, `last_name`, `company_name`, `type` FROM `clients`")->fetchAll() as $r) {
            $clientNames[(int) $r['id']] = $this->clientDisplayName($r);
        }
        $projectNames = [];
        foreach ($pdo->query("SELECT `id`, `name` FROM `projects`")->fetchAll() as $r) {
            $projectNames[(int) $r['id']] = (string) $r['name'];
        }

        $recentNotes = [];
        foreach ($recentNoteRows as $n) {
            $label = $this->entityLabel($n, $clientNames, $projectNames);
            $recentNotes[] = [
                'id'          => (int) $n['id'],
                'title'       => $n['title'],
                'snippet'     => $this->snippet((string) $n['content'], 80),
                'created_at'  => $n['created_at'],
                'entity_label'=> $label,
            ];
        }

        // === FINANCE: příjmy/výdaje za posledních 12 měsíců (pro graf) ===
        $monthRows = $pdo->query(
            "SELECT DATE_FORMAT(`transaction_date`, '%Y-%m') AS month, "
            . "SUM(CASE WHEN `type` = 'income' THEN `amount_cents` ELSE 0 END) AS income_cents, "
            . "SUM(CASE WHEN `type` = 'expense' THEN `amount_cents` ELSE 0 END) AS expense_cents "
            . "FROM `transactions` "
            . "WHERE `transaction_date` >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH) "
            . "GROUP BY month ORDER BY month"
        )->fetchAll();

        $monthMap = [];
        foreach ($monthRows as $r) {
            $monthMap[$r['month']] = [
                'income_cents'  => (int) $r['income_cents'],
                'expense_cents' => (int) $r['expense_cents'],
            ];
        }
        $financeSeries = [];
        for ($i = 11; $i >= 0; $i--) {
            $month = date('Y-m', strtotime("-{$i} months"));
            $financeSeries[] = [
                'month'         => $month,
                'income_cents'  => $monthMap[$month]['income_cents'] ?? 0,
                'expense_cents' => $monthMap[$month]['expense_cents'] ?? 0,
            ];
        }

        $this->jsonSuccess([
            'kpis'     => $kpis,
            'finance'  => $finance,
            'clients'  => $clients,
            'projects' => $projects,
            'finance_series' => $financeSeries,
            'attention' => [
                'overdue_invoices'   => $overdueRows,
                'upcoming_deadlines' => $deadlineRows,
                'recent_notes'       => $recentNotes,
            ],
        ], 200);
    }

    /**
     * Vrátí jméno klienta z přímých sloupců clients (type/first_name/last_name/company_name).
     */
    private function clientDisplayName(array $row): string
    {
        if (in_array($row['type'] ?? 'individual', ['company', 'nonprofit', 'government'], true)) {
            return (string) ($row['company_name'] ?? '');
        }
        return trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
    }

    /**
     * Vytvoří popisek entity poznámky ("Klient: …", "Projekt: …").
     */
    private function entityLabel(array $note, array $clientNames, array $projectNames): string
    {
        $id = (int) ($note['entity_id'] ?? 0);
        switch ($note['entity_type'] ?? '') {
            case 'client':
                return 'Klient: ' . ($clientNames[$id] ?? '#' . $id);
            case 'project':
                return 'Projekt: ' . ($projectNames[$id] ?? '#' . $id);
            case 'task':
                return 'Úkol #' . $id;
            case 'invoice':
                return 'Faktura #' . $id;
            default:
                return 'Poznámka';
        }
    }

    /**
     * Zkrátí text na daný počet znaků a doplní "…".
     */
    private function snippet(string $text, int $length): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        return mb_substr($text, 0, $length) . '…';
    }

    /**
     * Spočítá všechny záznamy v tabulce.
     */
    private function countTable(\PDO $pdo, string $table): int
    {
        // Whitelist tabulek pro bezpečnost
        $allowed = ['clients', 'projects', 'tasks', 'invoices', 'transactions', 'notes', 'files', 'worklog'];
        if (!in_array($table, $allowed, true)) {
            throw new \InvalidArgumentException("Neplatná tabulka: {$table}");
        }
        return (int) $pdo->query("SELECT COUNT(*) AS cnt FROM `{$table}`")->fetch()['cnt'];
    }

    /**
     * Spočítá záznamy splňující podmínku.
     */
    private function countWhere(\PDO $pdo, string $table, string $where): int
    {
        $allowed = ['clients', 'projects', 'tasks', 'invoices', 'transactions', 'notes', 'files', 'worklog'];
        if (!in_array($table, $allowed, true)) {
            throw new \InvalidArgumentException("Neplatná tabulka: {$table}");
        }
        // $where je vždy hardcoded string literal z show() - bez user input
        return (int) $pdo->query("SELECT COUNT(*) AS cnt FROM `{$table}` WHERE {$where}")->fetch()['cnt'];
    }

    /**
     * Sečte sloupec (nebo výraz) splňující podmínku.
     */
    private function sumWhere(\PDO $pdo, string $table, string $expr, string $where): int
    {
        $allowed = ['clients', 'projects', 'tasks', 'invoices', 'transactions', 'notes', 'files', 'worklog'];
        if (!in_array($table, $allowed, true)) {
            throw new \InvalidArgumentException("Neplatná tabulka: {$table}");
        }
        // $expr a $where jsou vždy hardcoded string literals z show() - bez user input
        return (int) $pdo->query("SELECT COALESCE(SUM({$expr}), 0) AS total FROM `{$table}` WHERE {$where}")->fetch()['total'];
    }

    /**
     * Vrátí plné jméno klienta z JOIN sloupců.
     */
    private function formatClientName(array $row): ?string
    {
        if (($row['client_id'] ?? null) === null) {
            return null;
        }
        $type = $row['client_type'] ?? 'individual';
        if (in_array($type, ['company', 'nonprofit', 'government'], true)) {
            $name = (string) ($row['client_company_name'] ?? '');
        } else {
            $first = (string) ($row['client_first_name'] ?? '');
            $last = (string) ($row['client_last_name'] ?? '');
            $name = trim($first . ' ' . $last);
        }
        return $name !== '' ? $name : null;
    }
}
