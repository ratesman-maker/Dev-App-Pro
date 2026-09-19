<?php
declare(strict_types=1);

namespace DevAppPro\Repositories;

use DevAppPro\Core\Repository;

/**
 * Repozitář pro tabulku invoice_payments (platby faktur).
 */
class InvoicePaymentRepository extends Repository
{
    protected string $table = 'invoice_payments';

    /**
     * Povolené sloupce pro create/update.
     */
    private const ALLOWED_COLUMNS = [
        'invoice_id',
        'amount_cents',
        'payment_date',
        'method',
        'note',
    ];

    /**
     * Najde platbu podle ID.
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM `{$this->table}` WHERE `id` = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Vrátí všechny platby pro danou fakturu seřazené podle payment_date.
     */
    public function allForInvoice(int $invoiceId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM `{$this->table}` WHERE `invoice_id` = ? ORDER BY `payment_date`"
        );
        $stmt->execute([$invoiceId]);
        return $stmt->fetchAll();
    }

    /**
     * Vytvoří novou platbu.
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
     * Aktualizuje platbu podle ID.
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
        $params = array_values($data);
        $params[] = $id;

        $stmt = $this->pdo->prepare(
            "UPDATE `{$this->table}` SET {$setList} WHERE `id` = ?"
        );
        return $stmt->execute($params);
    }

    /**
     * Smaže platbu podle ID.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM `{$this->table}` WHERE `id` = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Zkontroluje, zda faktura existuje.
     */
    public function invoiceExists(int $invoiceId): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM `invoices` WHERE `id` = ? LIMIT 1");
        $stmt->execute([$invoiceId]);
        return $stmt->fetch() !== false;
    }
}
