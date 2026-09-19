<?php
declare(strict_types=1);

namespace DevAppPro\Repositories;

use DevAppPro\Core\Repository;

/**
 * Repozitář pro tabulku notifications.
 */
class NotificationRepository extends Repository
{
    protected string $table = 'notifications';

    private const ALLOWED_COLUMNS = [
        'user_id',
        'type',
        'title',
        'message',
        'entity_type',
        'entity_id',
        'is_read',
    ];

    /**
     * Vrátí nepřečtené notifikace pro uživatele.
     */
    public function unreadForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM `{$this->table}` WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /**
     * Vrátí notifikace pro uživatele s paginací.
     */
    public function forUser(int $userId, int $page = 1, int $perPage = 50): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $countStmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS cnt FROM `{$this->table}` WHERE user_id = ?"
        );
        $countStmt->execute([$userId]);
        $total = (int) $countStmt->fetch()['cnt'];

        $stmt = $this->pdo->prepare(
            "SELECT * FROM `{$this->table}` WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?"
        );
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll();

        return ['data' => $data, 'total' => $total];
    }

    /**
     * Počet nepřečtených notifikací pro uživatele.
     */
    public function unreadCount(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS cnt FROM `{$this->table}` WHERE user_id = ? AND is_read = 0"
        );
        $stmt->execute([$userId]);
        return (int) $stmt->fetch()['cnt'];
    }

    /**
     * Označí notifikaci jako přečtenou (pokud patří uživateli).
     */
    public function markAsRead(int $id, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE `{$this->table}` SET is_read = 1 WHERE id = ? AND user_id = ?"
        );
        return $stmt->execute([$id, $userId]);
    }

    /**
     * Označí všechny notifikace uživatele jako přečtené.
     */
    public function markAllAsRead(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE `{$this->table}` SET is_read = 1 WHERE user_id = ? AND is_read = 0"
        );
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }

    /**
     * Smaže notifikaci (pokud patří uživateli).
     */
    public function deleteForUser(int $id, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM `{$this->table}` WHERE id = ? AND user_id = ?"
        );
        return $stmt->execute([$id, $userId]);
    }

    /**
     * Smaže všechny přečtené notifikace uživatele.
     */
    public function deleteReadForUser(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM `{$this->table}` WHERE user_id = ? AND is_read = 1"
        );
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }

    /**
     * Zkontroluje, zda už existuje notifikace daného typu pro entitu (deduplikace).
     */
    public function exists(int $userId, string $type, string $entityType, int $entityId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS cnt FROM `{$this->table}` 
             WHERE user_id = ? AND type = ? AND entity_type = ? AND entity_id = ?"
        );
        $stmt->execute([$userId, $type, $entityType, $entityId]);
        return (int) $stmt->fetch()['cnt'] > 0;
    }
}
