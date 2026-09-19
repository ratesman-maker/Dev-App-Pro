<?php
declare(strict_types=1);

namespace DevAppPro\Repositories;

use DevAppPro\Core\Repository;

/**
 * Repozitář pro tabulku tasks.
 */
class TaskRepository extends Repository
{
    protected string $table = 'tasks';

    /**
     * Povolené sloupce pro create/update.
     */
    private const ALLOWED_COLUMNS = [
        'project_id',
        'title',
        'description',
        'status',
        'priority',
        'due_date',
        'assigned_to',
        'estimated_minutes',
        'spent_minutes',
    ];

    /**
     * Najde úkol podle ID s LEFT JOIN projects pro project_name.
     */
    public function find(int $id): ?array
    {
        $sql = "SELECT t.*, p.`name` AS project_name "
            . "FROM `{$this->table}` t "
            . "LEFT JOIN `projects` p ON p.id = t.project_id "
            . "WHERE t.id = ?";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Vrátí všechny úkoly s paginací, vyhledáváním a filtry.
     *
     * @return array{data: array, total: int}
     */
    public function all(
        int $page = 1,
        int $perPage = 50,
        string $search = '',
        ?int $projectId = null,
        ?string $status = null,
        ?string $priority = null,
        bool $overdue = false
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];

        if ($search !== '') {
            $where[] = "(t.`title` LIKE ? OR t.`description` LIKE ?)";
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
        }

        if ($projectId !== null) {
            $where[] = "t.`project_id` = ?";
            $params[] = $projectId;
        }

        if ($status !== null) {
            $where[] = "t.`status` = ?";
            $params[] = $status;
        }

        if ($priority !== null) {
            $where[] = "t.`priority` = ?";
            $params[] = $priority;
        }

        if ($overdue) {
            $where[] = "t.`due_date` < CURDATE() AND t.`status` NOT IN ('done', 'cancelled')";
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

        // Data s LEFT JOIN projects
        $dataSql = "SELECT t.*, p.`name` AS project_name "
            . "FROM `{$this->table}` t "
            . "LEFT JOIN `projects` p ON p.id = t.project_id"
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

        return ['data' => $rows, 'total' => $total];
    }

    /**
     * Vytvoří nový úkol.
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
     * Aktualizuje úkol podle ID.
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
     * Smaže úkol podle ID.
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
}
