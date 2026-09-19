<?php
declare(strict_types=1);

namespace DevAppPro\Core;

/**
 * Abstraktní základní třída pro repozitáře.
 * Poskytuje CRUD operace nad tabulkou definovanou v $table.
 */
abstract class Repository
{
    protected \PDO $pdo;
    protected string $table;

    /**
     * Sloupce, které se vrací v find/all (override v subclass).
     * Prázdné = všechny sloupce (SELECT *).
     */
    protected array $columns = [];

    public function __construct()
    {
        $this->pdo = db();
    }

    /**
     * Najde záznam podle ID.
     */
    public function find(int $id): ?array
    {
        $cols = $this->columnList();
        $stmt = $this->pdo->prepare("SELECT {$cols} FROM `{$this->table}` WHERE `id` = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Vrátí všechny záznamy s paginací.
     * @return array{data: array, total: int}
     */
    public function all(int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $offset = ($page - 1) * $perPage;

        $countStmt = $this->pdo->query("SELECT COUNT(*) AS cnt FROM `{$this->table}`");
        $total = (int) $countStmt->fetch()['cnt'];

        $cols = $this->columnList();
        $stmt = $this->pdo->prepare(
            "SELECT {$cols} FROM `{$this->table}` ORDER BY `id` DESC LIMIT ? OFFSET ?"
        );
        $stmt->bindValue(1, $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll();

        return ['data' => $data, 'total' => $total];
    }

    /**
     * Vytvoří nový záznam.
     * @return int ID nového záznamu
     */
    public function create(array $data): int
    {
        $data = $this->filterColumns($data);
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
     * Aktualizuje záznam podle ID.
     */
    public function update(int $id, array $data): bool
    {
        $data = $this->filterColumns($data);
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
     * Smaže záznam podle ID.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM `{$this->table}` WHERE `id` = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Vrátí seznam sloupců pro SELECT.
     */
    protected function columnList(): string
    {
        if (empty($this->columns)) {
            return '*';
        }
        return '`' . implode('`, `', $this->columns) . '`';
    }

    /**
     * Vyfiltruje data pouze na sloupce, které jsou definovány v $columns.
     * Pokud je $columns prázdné, vrací data beze změny.
     */
    protected function filterColumns(array $data): array
    {
        if (empty($this->columns)) {
            return $data;
        }
        return array_intersect_key($data, array_flip($this->columns));
    }
}
