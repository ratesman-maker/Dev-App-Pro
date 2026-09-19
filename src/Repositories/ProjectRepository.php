<?php
declare(strict_types=1);

namespace DevAppPro\Repositories;

use DevAppPro\Core\Repository;

/**
 * Repozitář pro tabulku projects.
 */
class ProjectRepository extends Repository
{
    protected string $table = 'projects';

    /**
     * Povolené sloupce pro create/update.
     */
    private const ALLOWED_COLUMNS = [
        'client_id',
        'name',
        'description',
        'status',
        'folder_path',
        'type',
        'budget_cents',
        'started_at',
        'deadline',
    ];

    /**
     * Povolené statusy (ENUM).
     */
    private const ALLOWED_STATUSES = [
        'active',
        'on_hold',
        'completed',
        'cancelled',
        'archived',
    ];

    /**
     * Najde projekt podle folder_path.
     * Vrací první projekt s daným folder_path nebo null.
     */
    public function findByFolderPath(string $folderPath): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM `{$this->table}` WHERE `folder_path` = ? LIMIT 1"
        );
        $stmt->execute([$folderPath]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Vrátí všechny projekty, které mají folder_path NOT NULL.
     * @return array<int, array>
     */
    public function allWithFolderPath(): array
    {
        $stmt = $this->pdo->query(
            "SELECT * FROM `{$this->table}` WHERE `folder_path` IS NOT NULL"
        );
        return $stmt->fetchAll();
    }

    /**
     * Najde projekt podle ID s LEFT JOIN clients pro client_name.
     */
    public function find(int $id): ?array
    {
        $sql = "SELECT p.*, c.type AS client_type, c.first_name AS client_first_name, "
            . "c.last_name AS client_last_name, c.company_name AS client_company_name "
            . "FROM `{$this->table}` p "
            . "LEFT JOIN `clients` c ON c.id = p.client_id "
            . "WHERE p.id = ?";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $row['client_name'] = $this->formatClientName($row);
        $row['tasks_count'] = 0;
        $row['tasks_open'] = 0;

        // Odstranění pomocných sloupců z JOINu
        unset($row['client_type'], $row['client_first_name'], $row['client_last_name'], $row['client_company_name']);

        return $row;
    }

    /**
     * Vrátí všechny projekty s paginací, vyhledáváním a filtry.
     * @return array{data: array, total: int}
     */
    public function all(int $page = 1, int $perPage = 50, string $search = '', ?int $clientId = null, ?string $status = null): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];

        if ($search !== '') {
            $where[] = "(p.`name` LIKE ? OR p.`description` LIKE ?)";
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
        }

        if ($clientId !== null) {
            $where[] = "p.`client_id` = ?";
            $params[] = $clientId;
        }

        if ($status !== null && in_array($status, self::ALLOWED_STATUSES, true)) {
            $where[] = "p.`status` = ?";
            $params[] = $status;
        }

        $whereClause = '';
        if (!empty($where)) {
            $whereClause = ' WHERE ' . implode(' AND ', $where);
        }

        // Počet záznamů
        $countSql = "SELECT COUNT(*) AS cnt FROM `{$this->table}` p" . $whereClause;
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['cnt'];

        // Data s LEFT JOIN clients
        $dataSql = "SELECT p.*, c.type AS client_type, c.first_name AS client_first_name, "
            . "c.last_name AS client_last_name, c.company_name AS client_company_name "
            . "FROM `{$this->table}` p "
            . "LEFT JOIN `clients` c ON c.id = p.client_id"
            . $whereClause
            . " ORDER BY p.`id` DESC LIMIT ? OFFSET ?";

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
            $row['tasks_count'] = 0;
            $row['tasks_open'] = 0;
            unset($row['client_type'], $row['client_first_name'], $row['client_last_name'], $row['client_company_name']);
        }
        unset($row);

        return ['data' => $rows, 'total' => $total];
    }

    /**
     * Vytvoří nový projekt.
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
     * Aktualizuje projekt podle ID.
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
     * Smaže projekt podle ID.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM `{$this->table}` WHERE `id` = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Archivuje projekt (status → 'archived').
     */
    public function archive(int $id): bool
    {
        $stmt = $this->pdo->prepare("UPDATE `{$this->table}` SET `status` = 'archived' WHERE `id` = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Obnoví projekt (status → 'active').
     */
    public function restore(int $id): bool
    {
        $stmt = $this->pdo->prepare("UPDATE `{$this->table}` SET `status` = 'active' WHERE `id` = ?");
        return $stmt->execute([$id]);
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
