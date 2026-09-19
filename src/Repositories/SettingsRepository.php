<?php
declare(strict_types=1);

namespace DevAppPro\Repositories;

use DevAppPro\Core\Repository;

/**
 * Repozitář pro tabulku settings.
 */
class SettingsRepository extends Repository
{
    protected string $table = 'settings';

    /**
     * Vrátí všechna nastavení jako key=>value asociativní pole.
     * @return array<string,string>
     */
    public function allSettings(): array
    {
        $stmt = $this->pdo->query('SELECT `key`, `value` FROM `settings`');
        $rows = $stmt->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $result[$row['key']] = $row['value'];
        }
        return $result;
    }

    /**
     * Vrátí hodnotu nastavení podle klíče.
     */
    public function get(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT `value` FROM `settings` WHERE `key` = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row !== false ? $row['value'] : null;
    }

    /**
     * Nastaví hodnotu (INSERT ... ON DUPLICATE KEY UPDATE).
     */
    public function set(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO `settings` (`key`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
        );
        $stmt->execute([$key, $value]);
    }
}
