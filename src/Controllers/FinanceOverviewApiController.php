<?php
declare(strict_types=1);

namespace DevAppPro\Controllers;

use DevAppPro\Core\ApiController;
use DevAppPro\Core\Constants;
use DevAppPro\Auth;

/**
 * API kontroler pro finanční přehled (sjednocené příjmy a výdaje).
 * GET /api/finance-overview → index()  (seznam všech pohybů)
 *
 * Spojuje:
 * - transactions (income/expense)
 * - invoice_payments (income z plateb faktur)
 */
class FinanceOverviewApiController extends ApiController
{
    private Auth $auth;

    public function __construct()
    {
        $this->auth = $this->repo(\DevAppPro\Auth::class);
    }

    public function handle(): void
    {
        $this->auth->requireAuth();

        $method = $this->getMethod();
        if ($method !== 'GET') {
            $this->jsonError('Nepodporovaná HTTP metoda.', 405);
            return;
        }

        $this->index();
    }

    /**
     * GET /api/finance-overview - sjednocený seznam všech příjmů a výdajů.
     * Parametry: type (income/expense), from, to, search, per_page, page
     */
    protected function index(): void
    {
        $pdo = db();

        $type = isset($_GET['type']) && $_GET['type'] !== '' ? (string) $_GET['type'] : null;
        $from = isset($_GET['from']) && $_GET['from'] !== '' ? (string) $_GET['from'] : null;
        $to = isset($_GET['to']) && $_GET['to'] !== '' ? (string) $_GET['to'] : null;
        $search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
        $perPage = isset($_GET['per_page']) ? max(1, min(Constants::MAX_PER_PAGE, (int) $_GET['per_page'])) : Constants::MAX_PER_PAGE;
        $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
        $offset = ($page - 1) * $perPage;

        // Sestavit WHERE podmínky pro oba zdroje
        $txWhere = [];
        $txParams = [];
        $payWhere = [];
        $payParams = [];

        if ($type !== null) {
            $txWhere[] = "t.type = ?";
            $txParams[] = $type;
            if ($type === 'income') {
                $payWhere[] = "1=1";
            } else {
                // expense → platby neukazujeme
                $payWhere[] = "1=0";
            }
        }

        if ($from !== null) {
            $txWhere[] = "t.transaction_date >= ?";
            $txParams[] = $from;
            $payWhere[] = "ip.payment_date >= ?";
            $payParams[] = $from;
        }

        if ($to !== null) {
            $txWhere[] = "t.transaction_date <= ?";
            $txParams[] = $to;
            $payWhere[] = "ip.payment_date <= ?";
            $payParams[] = $to;
        }

        if ($search !== '') {
            $like = '%' . $search . '%';
            $txWhere[] = "(t.description LIKE ? OR t.category LIKE ?)";
            $txParams[] = $like;
            $txParams[] = $like;
            $payWhere[] = "(ip.note LIKE ? OR i.invoice_number LIKE ?)";
            $payParams[] = $like;
            $payParams[] = $like;
        }

        $txWhereSql = count($txWhere) > 0 ? 'WHERE ' . implode(' AND ', $txWhere) : '';
        $payWhereSql = count($payWhere) > 0 ? 'WHERE ' . implode(' AND ', $payWhere) : '';

        // UNION dotaz - sjednocené záznamy
        $sql = "
            (
                SELECT
                    CONCAT('tx_', t.id) AS uid,
                    t.id AS source_id,
                    'transaction' AS source,
                    t.type,
                    t.amount_cents,
                    t.category,
                    t.description,
                    t.transaction_date AS date,
                    t.project_id,
                    p.name AS project_name,
                    t.client_id,
                    CASE
                        WHEN c.id IS NULL THEN NULL
                        WHEN c.type IN ('company','nonprofit','government') THEN c.company_name
                        ELSE CONCAT_WS(' ', c.first_name, c.last_name)
                    END AS client_name,
                    i.invoice_number AS invoice_number,
                    NULL AS payment_method,
                    t.created_at,
                    t.updated_at
                FROM transactions t
                LEFT JOIN projects p ON p.id = t.project_id
                LEFT JOIN clients c ON c.id = t.client_id
                LEFT JOIN invoices i ON i.id = t.invoice_id
                {$txWhereSql}
            )
            UNION ALL
            (
                SELECT
                    CONCAT('pay_', ip.id) AS uid,
                    ip.id AS source_id,
                    'invoice_payment' AS source,
                    'income' AS type,
                    ip.amount_cents,
                    NULL AS category,
                    ip.note AS description,
                    ip.payment_date AS date,
                    i.project_id,
                    p.name AS project_name,
                    i.client_id,
                    CASE
                        WHEN c.id IS NULL THEN NULL
                        WHEN c.type IN ('company','nonprofit','government') THEN c.company_name
                        ELSE CONCAT_WS(' ', c.first_name, c.last_name)
                    END AS client_name,
                    i.invoice_number,
                    ip.method AS payment_method,
                    ip.created_at,
                    NULL AS updated_at
                FROM invoice_payments ip
                INNER JOIN invoices i ON i.id = ip.invoice_id
                LEFT JOIN projects p ON p.id = i.project_id
                LEFT JOIN clients c ON c.id = i.client_id
                {$payWhereSql}
            )
            ORDER BY date DESC, uid DESC
            LIMIT {$perPage} OFFSET {$offset}
        ";

        // Počítadlo celkem
        $countSql = "
            SELECT
                (SELECT COUNT(*) FROM transactions t {$txWhereSql})
                +
                (
                    SELECT COUNT(*)
                    FROM invoice_payments ip
                    INNER JOIN invoices i ON i.id = ip.invoice_id
                    LEFT JOIN projects p ON p.id = i.project_id
                    LEFT JOIN clients c ON c.id = i.client_id
                    {$payWhereSql}
                ) AS total
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($txParams, $payParams));
        $rows = $stmt->fetchAll();

        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute(array_merge($txParams, $payParams));
        $total = (int) $countStmt->fetch()['total'];

        $this->jsonSuccess([
            'data' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ], 200);
    }
}
