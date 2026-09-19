<?php
declare(strict_types=1);

namespace DevAppPro\Repositories;

use DevAppPro\Core\Repository;

/**
 * Repozitář pro tabulku wp_installs.
 * Spravuje záznamy o WordPress instalacích.
 */
class WpInstallRepository extends Repository
{
    protected string $table = 'wp_installs';

    /**
     * Najde instalaci podle ID.
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM `{$this->table}` WHERE `id` = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Vrátí všechny instalace seřazené od nejnovější.
     * @return array{data: array, total: int}
     */
    public function all(int $page = 1, int $perPage = 50): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $countStmt = $this->pdo->query("SELECT COUNT(*) AS cnt FROM `{$this->table}`");
        $total = (int) $countStmt->fetch()['cnt'];

        $stmt = $this->pdo->prepare(
            "SELECT * FROM `{$this->table}` ORDER BY `id` DESC LIMIT ? OFFSET ?"
        );
        $stmt->bindValue(1, $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll();

        return ['data' => $data, 'total' => $total];
    }

    /**
     * Najde instalaci podle site_url.
     */
    public function findBySiteUrl(string $siteUrl): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM `{$this->table}` WHERE `site_url` = ?");
        $stmt->execute([$siteUrl]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Najde instalaci podle db_name.
     */
    public function findByDbName(string $dbName): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM `{$this->table}` WHERE `db_name` = ?");
        $stmt->execute([$dbName]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Vytvoří novou instalaci.
     * @return int ID nového záznamu
     */
    public function create(array $data): int
    {
        $allowed = [
            'site_name', 'site_url', 'document_root',
            'db_name', 'db_user', 'db_password',
            'wp_version', 'admin_user', 'admin_password', 'admin_email',
            'status', 'error_message',
        ];
        $data = array_intersect_key($data, array_flip($allowed));
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
     * Aktualizuje instalaci podle ID.
     */
    public function update(int $id, array $data): bool
    {
        $allowed = [
            'site_name', 'site_url', 'document_root',
            'db_name', 'db_user', 'db_password',
            'wp_version', 'admin_user', 'admin_password', 'admin_email',
            'status', 'error_message',
        ];
        $data = array_intersect_key($data, array_flip($allowed));
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
     * Aktualizuje status instalace.
     */
    public function updateStatus(int $id, string $status, ?string $errorMessage = null): bool
    {
        if ($errorMessage !== null) {
            $stmt = $this->pdo->prepare(
                "UPDATE `{$this->table}` SET `status` = ?, `error_message` = ? WHERE `id` = ?"
            );
            return $stmt->execute([$status, $errorMessage, $id]);
        }
        $stmt = $this->pdo->prepare(
            "UPDATE `{$this->table}` SET `status` = ?, `error_message` = NULL WHERE `id` = ?"
        );
        return $stmt->execute([$status, $id]);
    }

    /**
     * Smaže instalaci podle ID.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM `{$this->table}` WHERE `id` = ?");
        return $stmt->execute([$id]);
    }
}
