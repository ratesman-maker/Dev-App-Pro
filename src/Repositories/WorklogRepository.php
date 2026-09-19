<?php
declare(strict_types=1);

namespace DevAppPro\Repositories;

use DevAppPro\Core\Repository;

/**
 * Repozitář pro tabulku worklog_entries (pracovní deník).
 */
class WorklogRepository extends Repository
{
    protected string $table = 'worklog_entries';

    /**
     * Povolené sloupce pro create/update.
     */
    private const ALLOWED_COLUMNS = [
        'user_id',
        'project_id',
        'client_id',
        'category',
        'severity',
        'title',
        'description',
        'hours',
        'is_done',
        'done_at',
    ];

    /**
     * Vrátí všechny záznamy s paginací, vyhledáváním a filtry.
     *
     * @return array{data: array, total: int}
     */
    public function all(
        int $page = 1,
        int $perPage = 50,
        string $search = '',
        ?string $category = null,
        ?string $severity = null,
        ?int $projectId = null,
        ?int $clientId = null
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];

        if ($search !== '') {
            $where[] = "(e.`title` LIKE ? OR e.`description` LIKE ?)";
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
        }

        if ($category !== null) {
            $where[] = "e.`category` = ?";
            $params[] = $category;
        }

        if ($severity !== null) {
            $where[] = "e.`severity` = ?";
            $params[] = $severity;
        }

        if ($projectId !== null) {
            $where[] = "e.`project_id` = ?";
            $params[] = $projectId;
        }

        if ($clientId !== null) {
            $where[] = "e.`client_id` = ?";
            $params[] = $clientId;
        }

        $whereClause = '';
        if (!empty($where)) {
            $whereClause = ' WHERE ' . implode(' AND ', $where);
        }

        // Počet záznamů
        $countSql = "SELECT COUNT(*) AS cnt FROM `{$this->table}` e" . $whereClause;
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['cnt'];

        // Data s JOIN na projekty a klienty
        $dataSql = "SELECT e.*, p.`name` AS project_name, c.`first_name` AS client_first_name, c.`last_name` AS client_last_name, c.`company_name` AS client_company_name, u.`name` AS user_name"
            . " FROM `{$this->table}` e"
            . " LEFT JOIN `projects` p ON p.id = e.project_id"
            . " LEFT JOIN `clients` c ON c.id = e.client_id"
            . " LEFT JOIN `users` u ON u.id = e.user_id"
            . $whereClause
            . " ORDER BY e.`created_at` DESC, e.`id` DESC LIMIT ? OFFSET ?";

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
            $row['attachments'] = $this->getAttachments((int) $row['id']);
        }
        unset($row);

        return ['data' => $rows, 'total' => $total];
    }

    /**
     * Najde záznam podle ID s JOIN na projekty a klienty.
     */
    public function find(int $id): ?array
    {
        $sql = "SELECT e.*, p.`name` AS project_name, c.`first_name` AS client_first_name, c.`last_name` AS client_last_name, c.`company_name` AS client_company_name, u.`name` AS user_name"
            . " FROM `{$this->table}` e"
            . " LEFT JOIN `projects` p ON p.id = e.project_id"
            . " LEFT JOIN `clients` c ON c.id = e.client_id"
            . " LEFT JOIN `users` u ON u.id = e.user_id"
            . " WHERE e.id = ?";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $row['client_name'] = $this->formatClientName($row);
        $row['attachments'] = $this->getAttachments((int) $row['id']);
        return $row;
    }

    /**
     * Vytvoří záznam v pracovním deníku.
     * @return int ID nového záznamu
     */
    public function create(array $data): int
    {
        $data = array_intersect_key($data, array_flip(self::ALLOWED_COLUMNS));
        if (empty($data)) {
            throw new \InvalidArgumentException('Žádná data pro vytvoření záznamu.');
        }
        if (empty($data['title'])) {
            throw new \InvalidArgumentException('Titulek je povinný.');
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
     * Aktualizuje záznam v pracovním deníku.
     */
    public function update(int $id, array $data): bool
    {
        $data = array_intersect_key($data, array_flip(self::ALLOWED_COLUMNS));
        if (empty($data)) {
            return true;
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
     * Smaže záznam (kaskádově smaže worklog_attachments přes FK).
     */
    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM `{$this->table}` WHERE `id` = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Vrátí přílohy záznamu.
     */
    public function getAttachments(int $entryId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM `worklog_attachments` WHERE `entry_id` = ? ORDER BY `id`"
        );
        $stmt->execute([$entryId]);
        return $stmt->fetchAll();
    }

    /**
     * Vrátí přílohu podle jejího ID.
     */
    public function getAttachmentById(int $attachmentId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM `worklog_attachments` WHERE `id` = ?"
        );
        $stmt->execute([$attachmentId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Formátuje název klienta z JOIN sloupců.
     */
    private function formatClientName(array $row): ?string
    {
        if (!empty($row['client_company_name'])) {
            return (string) $row['client_company_name'];
        }
        $name = trim(((string) ($row['client_first_name'] ?? '')) . ' ' . ((string) ($row['client_last_name'] ?? '')));
        return $name !== '' ? $name : null;
    }
}
