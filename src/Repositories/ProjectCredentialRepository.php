<?php
declare(strict_types=1);

namespace DevAppPro\Repositories;

use DevAppPro\Core\Repository;
use DevAppPro\Services\CryptoService;

/**
 * Repozitář pro tabulku project_credentials (přístupy k projektům).
 * Hesla jsou šifrována (AES-256-GCM) přes CryptoService.
 */
class ProjectCredentialRepository extends Repository
{
    protected string $table = 'project_credentials';

    private const ALLOWED_COLUMNS = [
        'project_id',
        'type',
        'name',
        'host',
        'port',
        'username',
        'password_encrypted',
        'database_name',
        'extra',
        'note',
    ];

    public const ALLOWED_TYPES = ['sftp', 'ftp', 'ssh', 'smtp', 'database', 'admin', 'api', 'other'];

    private CryptoService $crypto;

    public function __construct()
    {
        parent::__construct();
        $this->crypto = new CryptoService();
    }

    /**
     * Vrátí všechny přístupy pro projekt (s dešifrovaným heslem).
     */
    public function allForProject(int $projectId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM `{$this->table}` WHERE `project_id` = ? ORDER BY `type`, `id`"
        );
        $stmt->execute([$projectId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['password'] = $this->crypto->decrypt((string) ($row['password_encrypted'] ?? ''));
            unset($row['password_encrypted']);
        }
        unset($row);

        return $rows;
    }

    /**
     * Najde přístup podle ID (s dešifrovaným heslem).
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM `{$this->table}` WHERE `id` = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $row['password'] = $this->crypto->decrypt((string) ($row['password_encrypted'] ?? ''));
        unset($row['password_encrypted']);

        return $row;
    }

    /**
     * Vytvoří nový přístup. Heslo se šifruje před uložením.
     */
    public function create(array $data): int
    {
        $data['password_encrypted'] = $this->crypto->encrypt((string) ($data['password'] ?? ''));
        unset($data['password']);

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
     * Aktualizuje přístup. Heslo se šifruje před uložením.
     */
    public function update(int $id, array $data): bool
    {
        if (isset($data['password'])) {
            $data['password_encrypted'] = $this->crypto->encrypt((string) $data['password']);
            unset($data['password']);
        }

        $data = array_intersect_key($data, array_flip(self::ALLOWED_COLUMNS));
        if (empty($data)) {
            return false;
        }

        $sets = [];
        foreach (array_keys($data) as $col) {
            $sets[] = "`{$col}` = ?";
        }

        $params = array_values($data);
        $params[] = $id;

        $stmt = $this->pdo->prepare(
            "UPDATE `{$this->table}` SET " . implode(', ', $sets) . " WHERE `id` = ?"
        );
        return $stmt->execute($params);
    }

    /**
     * Smaže přístup.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM `{$this->table}` WHERE `id` = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Ověří, že projekt existuje.
     */
    public function projectExists(int $projectId): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM `projects` WHERE `id` = ?");
        $stmt->execute([$projectId]);
        return $stmt->fetch() !== false;
    }
}
