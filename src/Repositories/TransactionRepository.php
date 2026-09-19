<?php
declare(strict_types=1);

namespace DevAppPro\Repositories;

use DevAppPro\Core\Repository;

/**
 * Repozitář pro tabulku transactions (příjmy a výdaje).
 */
class TransactionRepository extends Repository
{
    protected string $table = 'transactions';

    /**
     * Povolené sloupce pro create/update.
     */
    private const ALLOWED_COLUMNS = [
        'project_id',
        'client_id',
        'invoice_id',
        'type',
        'amount_cents',
        'category',
        'description',
        'transaction_date',
    ];

    /**
     * Najde transakci podle ID s LEFT JOIN clients a projects.
     */
    public function find(int $id): ?array
    {
        $sql = "SELECT t.*, c.type AS client_type, c.first_name AS client_first_name, "
            . "c.last_name AS client_last_name, c.company_name AS client_company_name, "
            . "p.name AS project_name, i.invoice_number AS invoice_number "
            . "FROM `{$this->table}` t "
            . "LEFT JOIN `clients` c ON c.id = t.client_id "
            . "LEFT JOIN `projects` p ON p.id = t.project_id "
            . "LEFT JOIN `invoices` i ON i.id = t.invoice_id "
            . "WHERE t.id = ?";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $row['client_name'] = $this->formatClientName($row);
        unset($row['client_type'], $row['client_first_name'], $row['client_last_name'], $row['client_company_name']);

        return $row;
    }

    /**
     * Vrátí všechny transakce s paginací, vyhledáváním a filtry.
     *
     * @return array{data: array, total: int}
     */
    public function all(
        int $page = 1,
        int $perPage = 50,
        string $search = '',
        ?int $projectId = null,
        ?int $clientId = null,
        ?string $type = null,
        ?string $category = null,
        ?string $from = null,
        ?string $to = null
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];

        if ($search !== '') {
            $where[] = "t.`description` LIKE ?";
            $params[] = '%' . $search . '%';
        }

        if ($projectId !== null) {
            $where[] = "t.`project_id` = ?";
            $params[] = $projectId;
        }

        if ($clientId !== null) {
            $where[] = "t.`client_id` = ?";
            $params[] = $clientId;
        }

        if ($type !== null) {
            $where[] = "t.`type` = ?";
            $params[] = $type;
        }

        if ($category !== null) {
            $where[] = "t.`category` = ?";
            $params[] = $category;
        }

        if ($from !== null) {
            $where[] = "t.`transaction_date` >= ?";
            $params[] = $from;
        }

        if ($to !== null) {
            $where[] = "t.`transaction_date` <= ?";
            $params[] = $to;
        }

        $whereClause = '';
        if (!empty($where)) {
            $whereClause = ' WHERE ' . implode(' AND ', $where);
        }

        // Počet záznamů
        $countSql = "SELECT COUNT(*) AS cnt FROM `{$this->table}` t" . $whereClause;
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['cnt'];

        // Data s LEFT JOIN clients, projects a invoices
        $dataSql = "SELECT t.*, c.type AS client_type, c.first_name AS client_first_name, "
            . "c.last_name AS client_last_name, c.company_name AS client_company_name, "
            . "p.name AS project_name, i.invoice_number AS invoice_number "
            . "FROM `{$this->table}` t "
            . "LEFT JOIN `clients` c ON c.id = t.client_id "
            . "LEFT JOIN `projects` p ON p.id = t.project_id "
            . "LEFT JOIN `invoices` i ON i.id = t.invoice_id"
            . $whereClause
            . " ORDER BY t.`id` DESC LIMIT ? OFFSET ?";

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
     * Vytvoří novou transakci.
     * @return int ID nového záznamu
     */
    public function create(array $data): int
    {
        $data = array_intersect_key($data, array_flip(self::ALLOWED_COLUMNS));
        if (empty($data)) {
            throw new \InvalidArgumentException('Žádná data pro vytvoření záznamu.');
        }
        $cols = array_keys($data);
        $placeholders = array_fill(0, count($cols), '?');
        $colList = '`' . implode('`, `', $cols) . '`';
        $phList = implode(', ', $placeholders);

        $stmt = $this->pdo->prepare(
            "INSERT INTO `{$this->table}` ({$colList}) VALUES ({$phList})"
        );
        $stmt->execute(array_values($data));
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Aktualizuje transakci podle ID.
     */
    public function update(int $id, array $data): bool
    {
        $data = array_intersect_key($data, array_flip(self::ALLOWED_COLUMNS));
        if (empty($data)) {
            return false;
        }
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
        return $stmt->execute($values);
    }

    /**
     * Smaže transakci podle ID.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM `{$this->table}` WHERE `id` = ?");
        return $stmt->execute([$id]);
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
     * Zkontroluje, zda klient existuje.
     */
    public function clientExists(int $clientId): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM `clients` WHERE `id` = ? LIMIT 1");
        $stmt->execute([$clientId]);
        return $stmt->fetch() !== false;
    }

    /**
     * Zkontroluje, zda faktura existuje.
     */
    public function invoiceExists(int $invoiceId): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM `invoices` WHERE `id` = ? LIMIT 1");
        $stmt->execute([$invoiceId]);
        return $stmt->fetch() !== false;
    }

    /**
     * Vrátí plné jméno klienta z JOIN sloupců.
     * individual: "first_name last_name", company: "company_name".
     */
    private function formatClientName(array $row): ?string
    {
        if ($row['client_id'] === null) {
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
