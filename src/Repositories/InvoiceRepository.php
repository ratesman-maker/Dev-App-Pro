<?php
declare(strict_types=1);

namespace DevAppPro\Repositories;

use DevAppPro\Core\Repository;

/**
 * Repozitář pro tabulku invoices (faktury).
 */
class InvoiceRepository extends Repository
{
    protected string $table = 'invoices';

    /**
     * Povolené sloupce pro create/update.
     */
    private const ALLOWED_COLUMNS = [
        'client_id',
        'project_id',
        'invoice_number',
        'status',
        'subtotal_cents',
        'vat_rate_percent',
        'vat_amount_cents',
        'amount_cents',
        'paid_cents',
        'currency',
        'variable_symbol',
        'constant_symbol',
        'iban',
        'issue_date',
        'due_date',
        'taxable_date',
        'note',
    ];

    /**
     * Najde fakturu podle ID s LEFT JOIN clients a projects.
     * Vrací client_name a project_name.
     */
    public function find(int $id): ?array
    {
        $sql = "SELECT i.*, "
            . "c.type AS client_type, c.first_name AS client_first_name, "
            . "c.last_name AS client_last_name, c.company_name AS client_company_name, "
            . "p.name AS project_name "
            . "FROM `{$this->table}` i "
            . "LEFT JOIN `clients` c ON c.id = i.client_id "
            . "LEFT JOIN `projects` p ON p.id = i.project_id "
            . "WHERE i.id = ?";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $row['client_name'] = $this->formatClientName($row);
        unset($row['client_type'], $row['client_first_name'], $row['client_last_name'], $row['client_company_name']);

        $row['items'] = $this->itemsFor($id);

        return $row;
    }

    /**
     * Vrátí všechny faktury s paginací, vyhledáváním a filtry.
     *
     * @return array{data: array, total: int}
     */
    public function all(
        int $page = 1,
        int $perPage = 50,
        string $search = '',
        ?int $clientId = null,
        ?string $status = null,
        bool $overdue = false,
        ?int $projectId = null
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];

        if ($search !== '') {
            $where[] = "(i.`invoice_number` LIKE ? OR i.`variable_symbol` LIKE ?)";
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
        }

        if ($clientId !== null) {
            $where[] = "i.`client_id` = ?";
            $params[] = $clientId;
        }

        if ($projectId !== null) {
            $where[] = "i.`project_id` = ?";
            $params[] = $projectId;
        }

        if ($status !== null) {
            $where[] = "i.`status` = ?";
            $params[] = $status;
        }

        if ($overdue) {
            $where[] = "i.`due_date` < CURDATE() AND i.`status` NOT IN ('paid', 'cancelled', 'draft')";
        }

        $whereClause = '';
        if (!empty($where)) {
            $whereClause = ' WHERE ' . implode(' AND ', $where);
        }

        // Počet záznamů
        $countSql = "SELECT COUNT(*) AS cnt FROM `{$this->table}` i" . $whereClause;
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['cnt'];

        // Data s LEFT JOIN clients a projects
        $dataSql = "SELECT i.*, "
            . "c.type AS client_type, c.first_name AS client_first_name, "
            . "c.last_name AS client_last_name, c.company_name AS client_company_name, "
            . "p.name AS project_name "
            . "FROM `{$this->table}` i "
            . "LEFT JOIN `clients` c ON c.id = i.client_id "
            . "LEFT JOIN `projects` p ON p.id = i.project_id"
            . $whereClause
            . " ORDER BY i.`id` DESC LIMIT ? OFFSET ?";

        $dataStmt = $this->pdo->prepare($dataSql);
        $paramIndex = 1;
        foreach ($params as $value) {
            $dataStmt->bindValue($paramIndex++, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $dataStmt->bindValue($paramIndex++, $perPage, \PDO::PARAM_INT);
        $dataStmt->bindValue($paramIndex++, $offset, \PDO::PARAM_INT);
        $dataStmt->execute();
        $rows = $dataStmt->fetchAll();

        foreach ($rows as &$row) {
            $row['client_name'] = $this->formatClientName($row);
            unset($row['client_type'], $row['client_first_name'], $row['client_last_name'], $row['client_company_name']);
        }
        unset($row);

        return ['data' => $rows, 'total' => $total];
    }

    /**
     * Vytvoří novou fakturu včetně položek (transakčně).
     * @return int ID nového záznamu
     */
    public function create(array $data): int
    {
        $items = $data['items'] ?? [];
        unset($data['items']);
        $data = array_intersect_key($data, array_flip(self::ALLOWED_COLUMNS));
        if (empty($data)) {
            throw new \InvalidArgumentException('Žádná data pro vytvoření záznamu.');
        }

        $this->pdo->beginTransaction();
        try {
            $cols = array_keys($data);
            $placeholders = array_fill(0, count($cols), '?');
            $colList = '`' . implode('`, `', $cols) . '`';
            $phList = implode(', ', $placeholders);

            $stmt = $this->pdo->prepare(
                "INSERT INTO `{$this->table}` ({$colList}) VALUES ({$phList})"
            );
            $stmt->execute(array_values($data));
            $id = (int) $this->pdo->lastInsertId();

            $this->insertItems($id, $items);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $id;
    }

    /**
     * Aktualizuje fakturu podle ID. Pokud data obsahují klíč 'items',
     * nahradí se položky (delete + insert) v transakci.
     */
    public function update(int $id, array $data): bool
    {
        $hasItems = array_key_exists('items', $data);
        $items = $data['items'] ?? [];
        unset($data['items']);
        $data = array_intersect_key($data, array_flip(self::ALLOWED_COLUMNS));

        $this->pdo->beginTransaction();
        try {
            if (!empty($data)) {
                $sets = [];
                foreach (array_keys($data) as $col) {
                    $sets[] = "`{$col}` = ?";
                }
                $setList = implode(', ', $sets);
                $values = array_values($data);
                $values[] = $id;

                $stmt = $this->pdo->prepare(
                    "UPDATE `{$this->table}` SET {$setList} WHERE `id` = ?"
                );
                $stmt->execute($values);
            }

            if ($hasItems) {
                $del = $this->pdo->prepare("DELETE FROM `invoice_items` WHERE `invoice_id` = ?");
                $del->execute([$id]);
                $this->insertItems($id, $items);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return true;
    }

    /**
     * Vloží položky faktury.
     */
    private function insertItems(int $invoiceId, array $items): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO `invoice_items` (`invoice_id`, `description`, `quantity`, `unit`, `unit_price_cents`, `sort_order`)
             VALUES (?, ?, ?, ?, ?, ?)"
        );

        $sort = 0;
        foreach ($items as $item) {
            $stmt->execute([
                $invoiceId,
                (string) ($item['description'] ?? ''),
                (string) ($item['quantity'] ?? '1'),
                ($item['unit'] ?? null) !== '' ? (string) ($item['unit'] ?? null) : null,
                (int) ($item['unit_price_cents'] ?? 0),
                $sort++,
            ]);
        }
    }

    /**
     * Vrátí položky faktury seřazené podle sort_order.
     */
    public function itemsFor(int $invoiceId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT `id`, `description`, `quantity`, `unit`, `unit_price_cents`, `sort_order`
             FROM `invoice_items` WHERE `invoice_id` = ? ORDER BY `sort_order`, `id`"
        );
        $stmt->execute([$invoiceId]);
        return $stmt->fetchAll();
    }

    /**
     * Smaže fakturu podle ID (kaskádově smaže invoice_payments přes FK).
     */
    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM `{$this->table}` WHERE `id` = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Uloží cestu ke zmrazenému PDF (archivní kopie vydané faktury).
     * Interní sloupec — není součástí ALLOWED_COLUMNS, uživatel ho nemůže měnit.
     */
    public function setFrozenPdf(int $id, ?string $path): bool
    {
        $stmt = $this->pdo->prepare("UPDATE `{$this->table}` SET `frozen_pdf` = ? WHERE `id` = ?");
        return $stmt->execute([$path, $id]);
    }

    /**
     * Přepočítá paid_cents faktury z invoice_payments a případně změní status.
     * - Pokud paid_cents >= amount_cents a status != 'cancelled' → status='paid'
     */
    public function recalculatePaid(int $invoiceId): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(`amount_cents`), 0) AS paid FROM `invoice_payments` WHERE `invoice_id` = ?"
        );
        $stmt->execute([$invoiceId]);
        $paidCents = (int) $stmt->fetch()['paid'];

        $invStmt = $this->pdo->prepare(
            "SELECT `amount_cents`, `status` FROM `{$this->table}` WHERE `id` = ?"
        );
        $invStmt->execute([$invoiceId]);
        $invoice = $invStmt->fetch();
        if ($invoice === false) {
            return;
        }

        $amountCents = (int) $invoice['amount_cents'];
        $status = (string) $invoice['status'];

        if ($paidCents >= $amountCents && $status !== 'cancelled') {
            $updStmt = $this->pdo->prepare(
                "UPDATE `{$this->table}` SET `paid_cents` = ?, `status` = 'paid' WHERE `id` = ?"
            );
            $updStmt->execute([$paidCents, $invoiceId]);
        } else {
            $updStmt = $this->pdo->prepare(
                "UPDATE `{$this->table}` SET `paid_cents` = ? WHERE `id` = ?"
            );
            $updStmt->execute([$paidCents, $invoiceId]);
        }
    }

    /**
     * Přepočítá paid_cents a pokud je paid_cents < amount_cents a status='paid',
     * nastaví status='sent'.
     */
    public function recalculatePaidAfterDelete(int $invoiceId): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(`amount_cents`), 0) AS paid FROM `invoice_payments` WHERE `invoice_id` = ?"
        );
        $stmt->execute([$invoiceId]);
        $paidCents = (int) $stmt->fetch()['paid'];

        $invStmt = $this->pdo->prepare(
            "SELECT `amount_cents`, `status` FROM `{$this->table}` WHERE `id` = ?"
        );
        $invStmt->execute([$invoiceId]);
        $invoice = $invStmt->fetch();
        if ($invoice === false) {
            return;
        }

        $amountCents = (int) $invoice['amount_cents'];
        $status = (string) $invoice['status'];

        if ($paidCents < $amountCents && $status === 'paid') {
            $updStmt = $this->pdo->prepare(
                "UPDATE `{$this->table}` SET `paid_cents` = ?, `status` = 'sent' WHERE `id` = ?"
            );
            $updStmt->execute([$paidCents, $invoiceId]);
        } else {
            $updStmt = $this->pdo->prepare(
                "UPDATE `{$this->table}` SET `paid_cents` = ? WHERE `id` = ?"
            );
            $updStmt->execute([$paidCents, $invoiceId]);
        }
    }

    /**
     * Zkontroluje, zda klient existuje.
     */
    public function clientExists(int $clientId): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM `clients` WHERE `id` = ? LIMIT 1");
        $stmt->execute([$clientId]);
        return $stmt->fetch() !== false;
    }

    /**
     * Zkontroluje, zda projekt existuje.
     */
    public function projectExists(int $projectId): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM `projects` WHERE `id` = ? LIMIT 1");
        $stmt->execute([$projectId]);
        return $stmt->fetch() !== false;
    }

    /**
     * Vrátí plné jméno klienta z JOIN sloupců.
     * individual: "first_name last_name", company: "company_name".
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
