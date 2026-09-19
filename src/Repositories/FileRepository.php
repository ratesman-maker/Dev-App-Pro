<?php
declare(strict_types=1);

namespace DevAppPro\Repositories;

use DevAppPro\Core\Repository;

/**
 * Repozitář pro tabulku files (soubory).
 * Poskytuje polymorfní vazby přes fileables.
 */
class FileRepository extends Repository
{
    protected string $table = 'files';

    /**
     * Povolené sloupce pro create/update.
     */
    private const ALLOWED_COLUMNS = [
        'user_id',
        'original_name',
        'stored_name',
        'mime_type',
        'size_bytes',
        'storage_path',
        'is_image',
        'thumbnail_path',
        'medium_path',
    ];

    /**
     * Povolené typy entit pro polymorfní vazby.
     */
    private const ENTITY_TYPES = ['client', 'project', 'task', 'invoice'];

    /**
     * Vrátí všechny soubory s paginací a filtrem podle entity.
     *
     * @return array{data: array, total: int}
     */
    public function all(
        int $page = 1,
        int $perPage = 50,
        ?string $entityType = null,
        ?int $entityId = null
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $params = [];
        $joinExtra = '';
        if ($entityType !== null && $entityId !== null && in_array($entityType, self::ENTITY_TYPES, true)) {
            $joinExtra = ' INNER JOIN `fileables` fa ON fa.file_id = f.id'
                . ' AND fa.entity_type = ? AND fa.entity_id = ?';
            $params[] = $entityType;
            $params[] = $entityId;
        }

        // Počet záznamů
        $countSql = "SELECT COUNT(DISTINCT f.id) AS cnt FROM `{$this->table}` f" . $joinExtra;
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['cnt'];

        // Data s LEFT JOIN users pro user_name
        $dataSql = "SELECT f.*, u.`name` AS user_name"
            . " FROM `{$this->table}` f"
            . " LEFT JOIN `users` u ON u.id = f.user_id"
            . $joinExtra
            . " GROUP BY f.id"
            . " ORDER BY f.`id` DESC LIMIT ? OFFSET ?";

        $dataStmt = $this->pdo->prepare($dataSql);
        // Bind WHERE parametry first, pak LIMIT/OFFSET
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
     * Najde soubor podle ID s user_name a attachments.
     */
    public function find(int $id): ?array
    {
        $sql = "SELECT f.*, u.`name` AS user_name"
            . " FROM `{$this->table}` f"
            . " LEFT JOIN `users` u ON u.id = f.user_id"
            . " WHERE f.id = ?";

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
     * Vytvoří soubor a případné polymorfní vazby (fileables).
     * @return int ID nového souboru
     */
    public function create(array $data, array $attachments = []): int
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
        $fileId = (int) $this->pdo->lastInsertId();

        $this->syncAttachments($fileId, $attachments);

        return $fileId;
    }

    /**
     * Aktualizuje polymorfní vazby souboru (nahradí celý seznam).
     */
    public function update(int $id, array $attachments): bool
    {
        $this->syncAttachments($id, $attachments);
        return true;
    }

    /**
     * Smaže soubor (kaskádově smaže fileables přes FK).
     */
    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM `{$this->table}` WHERE `id` = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Vrátí polymorfní vazby souboru (entity_type, entity_id).
     */
    public function getAttachments(int $fileId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT `entity_type`, `entity_id` FROM `fileables` WHERE `file_id` = ? ORDER BY `id`"
        );
        $stmt->execute([$fileId]);
        return $stmt->fetchAll();
    }

    /**
     * Vrátí polymorfní vazby souboru včetně názvu entity (entity_name).
     */
    public function getAttachmentsWithEntityName(int $fileId): array
    {
        $attachments = $this->getAttachments($fileId);
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
     * Nahradí všechny polymorfní vazby souboru novým seznamem.
     */
    private function syncAttachments(int $fileId, array $attachments): void
    {
        $delStmt = $this->pdo->prepare("DELETE FROM `fileables` WHERE `file_id` = ?");
        $delStmt->execute([$fileId]);

        $insStmt = $this->pdo->prepare(
            "INSERT INTO `fileables` (`file_id`, `entity_type`, `entity_id`) VALUES (?, ?, ?)"
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
            $insStmt->execute([$fileId, $type, (int) $eid]);
        }
    }
}
