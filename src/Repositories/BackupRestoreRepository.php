<?php
declare(strict_types=1);

namespace DevAppPro\Repositories;

use DevAppPro\Core\Repository;

/**
 * Repozitář pro restore jobs (obnova záloh jako projekty).
 */
class BackupRestoreRepository
{
    private \PDO $pdo;
    private string $table = 'backup_restores';

    public function __construct()
    {
        $this->pdo = db();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM `{$this->table}` WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function all(int $page = 1, int $perPage = 100): array
    {
        $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare("SELECT * FROM `{$this->table}` ORDER BY id DESC LIMIT ? OFFSET ?");
        $stmt->bindValue(1, $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function create(array $data): int
    {
        $allowed = [
            'backup_path', 'project_name', 'site_url', 'document_root',
            'db_name', 'db_user', 'db_password', 'old_url',
            'status', 'error_message',
        ];
        $filtered = array_intersect_key($data, array_flip($allowed));
        if (empty($filtered['status'])) {
            $filtered['status'] = 'pending';
        }

        $columns = implode(', ', array_map(fn($c) => "`{$c}`", array_keys($filtered)));
        $placeholders = implode(', ', array_fill(0, count($filtered), '?'));
        $stmt = $this->pdo->prepare(
            "INSERT INTO `{$this->table}` ({$columns}) VALUES ({$placeholders})"
        );
        $stmt->execute(array_values($filtered));
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $allowed = [
            'backup_path', 'project_name', 'site_url', 'document_root',
            'db_name', 'db_user', 'db_password', 'old_url',
            'status', 'error_message',
        ];
        $filtered = array_intersect_key($data, array_flip($allowed));
        if (empty($filtered)) {
            return false;
        }
        $set = implode(', ', array_map(fn($c) => "`{$c}` = ?", array_keys($filtered)));
        $stmt = $this->pdo->prepare("UPDATE `{$this->table}` SET {$set} WHERE id = ?");
        $stmt->execute([...array_values($filtered), $id]);
        return $stmt->rowCount() > 0;
    }

    public function findPending(): ?array
    {
        $stmt = $this->pdo->query("SELECT * FROM `{$this->table}` WHERE status = 'pending' ORDER BY id ASC LIMIT 1");
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM `{$this->table}` WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }
}
