<?php
declare(strict_types=1);

namespace DevAppPro\Repositories;

use DevAppPro\Core\Repository;

/**
 * Repozitář pro tabulku clients.
 */
class ClientRepository extends Repository
{
    protected string $table = 'clients';

    /**
     * Povolené sloupce pro create/update.
     */
    private const ALLOWED_COLUMNS = [
        'type',
        'first_name',
        'last_name',
        'company_name',
        'ico',
        'dic',
        'bank_account',
        'email',
        'phone',
        'address',
        'note',
    ];

    /**
     * Povolené sloupce pro řazení.
     */
    private const ALLOWED_SORTS = [
        'last_name'    => '`last_name`, `first_name`',
        'company_name' => '`company_name`',
        'email'        => '`email`',
        'created_at'   => '`created_at`',
    ];

    /**
     * Najde klienta podle ID a přidá computed full_name.
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM `{$this->table}` WHERE `id` = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $row['full_name'] = $this->formatFullName($row);
        return $row;
    }

    /**
     * Vrátí všechny klienty s paginací, vyhledáváním a řazením.
     * @return array{data: array, total: int}
     */
    public function all(int $page = 1, int $perPage = 50, string $search = '', string $sort = 'last_name'): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $orderClause = self::ALLOWED_SORTS[$sort] ?? self::ALLOWED_SORTS['last_name'];

        $where = '';
        $params = [];
        if ($search !== '') {
            $where = ' WHERE (`first_name` LIKE ? OR `last_name` LIKE ? OR `company_name` LIKE ? OR `email` LIKE ?)';
            $like = '%' . $search . '%';
            $params = [$like, $like, $like, $like];
        }

        // Počet záznamů
        $countSql = "SELECT COUNT(*) AS cnt FROM `{$this->table}`" . $where;
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['cnt'];

        // Data
        $dataSql = "SELECT * FROM `{$this->table}`" . $where
            . " ORDER BY {$orderClause} LIMIT ? OFFSET ?";
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
            $row['full_name'] = $this->formatFullName($row);
        }
        unset($row);

        return ['data' => $rows, 'total' => $total];
    }

    /**
     * Vytvoří nového klienta.
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
     * Aktualizuje klienta podle ID.
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
     * Smaže klienta podle ID.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM `{$this->table}` WHERE `id` = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Vrátí plné jméno klienta.
     * individual: "first_name last_name", company: "company_name".
     */
    public function formatFullName(array $client): string
    {
        if (in_array($client['type'] ?? 'individual', ['company', 'nonprofit', 'government'], true)) {
            return (string) ($client['company_name'] ?? '');
        }
        $first = (string) ($client['first_name'] ?? '');
        $last = (string) ($client['last_name'] ?? '');
        return trim($first . ' ' . $last);
    }
}
