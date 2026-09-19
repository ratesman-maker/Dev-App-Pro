<?php
declare(strict_types=1);

namespace DevAppPro\Repositories;

use DevAppPro\Core\Repository;

/**
 * Repozitář pro tabulku notes (poznámky).
 * Poskytuje polymorfní vazby přes noteables.
 */
class NoteRepository extends Repository
{
    protected string $table = 'notes';

    /**
     * Povolené sloupce pro create/update.
     */
    private const ALLOWED_COLUMNS = [
        'user_id',
        'title',
        'content',
    ];

    /**
     * Povolené typy entit pro polymorfní vazby.
     */
    private const ENTITY_TYPES = ['client', 'project', 'task', 'invoice'];

    /**
     * Vrátí všechny poznámky s paginací, vyhledáváním a filtrem podle entity.
     *
     * @return array{data: array, total: int}
     */
    public function all(
        int $page = 1,
        int $perPage = 50,
        string $search = '',
        ?string $entityType = null,
        ?int $entityId = null
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];

        if ($search !== '') {
            $where[] = "(n.`title` LIKE ? OR n.`content` LIKE ?)";
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $joinExtra = '';
        if ($entityType !== null && $entityId !== null && in_array($entityType, self::ENTITY_TYPES, true)) {
            $joinExtra = ' INNER JOIN `noteables` na ON na.note_id = n.id'
                . ' AND na.entity_type = ? AND na.entity_id = ?';
            $params[] = $entityType;
            $params[] = $entityId;
        }

        $whereClause = '';
        if (!empty($where)) {
            $whereClause = ' WHERE ' . implode(' AND ', $where);
        }

        // Počet záznamů (bez paginace)
        $countSql = "SELECT COUNT(DISTINCT n.id) AS cnt FROM `{$this->table}` n"
            . $joinExtra . $whereClause;
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['cnt'];

        // Data s LEFT JOIN users pro user_name
        $dataSql = "SELECT n.*, u.`name` AS user_name"
            . " FROM `{$this->table}` n"
            . " LEFT JOIN `users` u ON u.id = n.user_id"
            . $joinExtra
            . $whereClause
            . " GROUP BY n.id"
            . " ORDER BY n.`id` DESC LIMIT ? OFFSET ?";

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
            $row['attachments'] = $this->getAttachmentsWithEntityName((int) $row['id']);
        }
        unset($row);

        return ['data' => $rows, 'total' => $total];
    }

    /**
     * Najde poznámku podle ID s user_name a attachments (včetně entity_name).
     */
    public function find(int $id): ?array
    {
        $sql = "SELECT n.*, u.`name` AS user_name"
            . " FROM `{$this->table}` n"
            . " LEFT JOIN `users` u ON u.id = n.user_id"
            . " WHERE n.id = ?";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $row['attachments'] = $this->getAttachmentsWithEntityName((int) $row['id']);
        return $row;
    }

    /**
     * Vytvoří poznámku a případné polymorfní vazby (noteables).
     * @return int ID nové poznámky
     */
    public function create(array $data, array $attachments = []): int
    {
        $data = array_intersect_key($data, array_flip(self::ALLOWED_COLUMNS));
        if (!array_key_exists('content', $data) || $data['content'] === '') {
            throw new \InvalidArgumentException('Obsah poznámky je povinný.');
        }

        $cols = array_keys($data);
        $placeholders = array_fill(0, count($cols), '?');
        $colList = '`' . implode('`, `', $cols) . '`';
        $phList = implode(', ', $placeholders);

        $stmt = $this->pdo->prepare(
            "INSERT INTO `{$this->table}` ({$colList}) VALUES ({$phList})"
        );
        $stmt->execute(array_values($data));
        $noteId = (int) $this->pdo->lastInsertId();

        $this->syncAttachments($noteId, $attachments);

        return $noteId;
    }

    /**
     * Aktualizuje poznámku a nahradí všechny polymorfní vazby novým seznamem.
     */
    public function update(int $id, array $data, array $attachments = []): bool
    {
        $data = array_intersect_key($data, array_flip(self::ALLOWED_COLUMNS));
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

        $this->syncAttachments($id, $attachments);

        return true;
    }

    /**
     * Smaže poznámku (kaskádově smaže noteables přes FK).
     */
    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM `{$this->table}` WHERE `id` = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Vrátí polymorfní vazby poznámky (entity_type, entity_id).
     */
    public function getAttachments(int $noteId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT `entity_type`, `entity_id` FROM `noteables` WHERE `note_id` = ? ORDER BY `id`"
        );
        $stmt->execute([$noteId]);
        return $stmt->fetchAll();
    }

    /**
     * Vrátí polymorfní vazby poznámky včetně názvu entity (entity_name).
     */
    public function getAttachmentsWithEntityName(int $noteId): array
    {
        $attachments = $this->getAttachments($noteId);
        foreach ($attachments as &$att) {
            $att['entity_name'] = $this->getEntityName($att['entity_type'], (int) $att['entity_id']);
        }
        unset($att);
        return $attachments;
    }

    /**
     * Vrátí název entity podle typu a ID.
     * client → full_name, project → name, task → title, invoice → invoice_number.
     */
    public function getEntityName(string $type, int $id): ?string
    {
        switch ($type) {
            case 'client':
                $stmt = $this->pdo->prepare("SELECT * FROM `clients` WHERE `id` = ?");
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                if ($row === false) {
                    return null;
                }
                if (in_array($row['type'] ?? 'individual', ['company', 'nonprofit', 'government'], true)) {
                    return (string) ($row['company_name'] ?? '');
                }
                return trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['last_name'] ?? '')));
            case 'project':
                $stmt = $this->pdo->prepare("SELECT `name` FROM `projects` WHERE `id` = ?");
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                return $row !== false ? (string) $row['name'] : null;
            case 'task':
                $stmt = $this->pdo->prepare("SELECT `title` FROM `tasks` WHERE `id` = ?");
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                return $row !== false ? (string) $row['title'] : null;
            case 'invoice':
                $stmt = $this->pdo->prepare("SELECT `invoice_number` FROM `invoices` WHERE `id` = ?");
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                return $row !== false ? (string) $row['invoice_number'] : null;
            default:
                return null;
        }
    }

    /**
     * Ověří, zda entita daného typu existuje.
     */
    public function entityExists(string $type, int $id): bool
    {
        $table = match ($type) {
            'client' => 'clients',
            'project' => 'projects',
            'task' => 'tasks',
            'invoice' => 'invoices',
            default => null,
        };
        if ($table === null) {
            return false;
        }
        $stmt = $this->pdo->prepare("SELECT 1 FROM `{$table}` WHERE `id` = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch() !== false;
    }

    /**
     * Nahradí všechny polymorfní vazby poznámky novým seznamem.
     * Nejprve smaže staré noteables, pak vloží nové.
     */
    private function syncAttachments(int $noteId, array $attachments): void
    {
        $delStmt = $this->pdo->prepare("DELETE FROM `noteables` WHERE `note_id` = ?");
        $delStmt->execute([$noteId]);

        $insStmt = $this->pdo->prepare(
            "INSERT INTO `noteables` (`note_id`, `entity_type`, `entity_id`) VALUES (?, ?, ?)"
        );
        foreach ($attachments as $att) {
            $type = $att['entity_type'] ?? null;
            $eid = $att['entity_id'] ?? null;
            if (!is_string($type) || !in_array($type, self::ENTITY_TYPES, true)) {
                continue;
            }
            if ($eid === null || !is_numeric($eid)) {
                continue;
            }
            $insStmt->execute([$noteId, $type, (int) $eid]);
        }
    }
}
