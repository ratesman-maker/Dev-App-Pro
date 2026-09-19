<?php
declare(strict_types=1);

namespace DevAppPro\Repositories;

use DevAppPro\Core\Repository;

/**
 * Repozitář pro tabulku company_profile (jednořádková tabulka, id = 1).
 */
class CompanyProfileRepository extends Repository
{
    protected string $table = 'company_profile';

    /**
     * Povolené sloupce pro aktualizaci.
     */
    private const ALLOWED_COLUMNS = [
        'type',
        'first_name',
        'last_name',
        'company_name',
        'ico',
        'dic',
        'email',
        'phone',
        'address',
        'bank_account',
        'iban',
        'swift',
    ];

    /**
     * Vrátí profil firmy (záznam s id = 1).
     * @return array|null Všechna pole nebo null pokud záznam neexistuje.
     */
    public function get(): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM `company_profile` WHERE `id` = 1');
        $stmt->execute();
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Aktualizuje profil firmy (záznam s id = 1).
     * Aktualizuje pouze povolená pole, ostatní ignoruje.
     *
     * @param int   $id   Ignoruje se - vždy se aktualizuje id = 1 (jednořádková tabulka).
     * @param array $data Asociativní pole s povolenými klíči.
     */
    public function update(int $id = 1, array $data = []): bool
    {
        // Jednořádková tabulka - vždy id = 1
        $filtered = array_intersect_key($data, array_flip(self::ALLOWED_COLUMNS));
        if (empty($filtered)) {
            return false;
        }

        $sets = [];
        foreach (array_keys($filtered) as $col) {
            $sets[] = "`{$col}` = ?";
        }
        $setList = implode(', ', $sets);
        $values = array_values($filtered);
        $values[] = 1;

        $stmt = $this->pdo->prepare(
            "UPDATE `company_profile` SET {$setList} WHERE `id` = ?"
        );
        return $stmt->execute($values);
    }
}
