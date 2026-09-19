<?php
declare(strict_types=1);

namespace DevAppPro\Repositories;

use DevAppPro\Core\Repository;
use DevAppPro\Core\Constants;

/**
 * Repozitář pro tabulku users.
 */
class UserRepository extends Repository
{
    protected string $table = 'users';

    /**
     * Najde uživatele podle username.
     */
    public function findByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM `users` WHERE `username` = ?');
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Aktualizuje heslo uživatele.
     */
    public function updatePassword(int $id, string $hash): bool
    {
        $stmt = $this->pdo->prepare('UPDATE `users` SET `password_hash` = ? WHERE `id` = ?');
        return $stmt->execute([$hash, $id]);
    }

    /**
     * Aktualizuje hint pro reset hesla.
     * Prázdný string nebo null = smazat hint.
     */
    public function updateHint(int $id, ?string $hint): bool
    {
        $hint = ($hint === '') ? null : $hint;
        $stmt = $this->pdo->prepare('UPDATE `users` SET `password_hint` = ? WHERE `id` = ?');
        return $stmt->execute([$hint, $id]);
    }

    /**
     * Aktualizuje profil uživatele (name, email).
     */
    public function updateProfile(int $id, array $data): bool
    {
        $allowed = ['name', 'email'];
        $data = array_intersect_key($data, array_flip($allowed));
        if (empty($data)) {
            return false;
        }

        // Validace emailu
        if (isset($data['email'])) {
            $data['email'] = trim($data['email']);
            if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                return false;
            }
            if ($data['email'] === '') {
                $data['email'] = null;
            }
        }

        // Trim string fields
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
            if ($data['name'] === '') {
                $data['name'] = null;
            }
        }

        if (empty($data)) {
            return false;
        }

        return $this->update($id, $data);
    }

    /**
     * Vrátí hash hesla uživatele.
     */
    public function getPasswordHash(int $id): ?string
    {
        $stmt = $this->pdo->prepare('SELECT `password_hash` FROM `users` WHERE `id` = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row !== false ? (string) $row['password_hash'] : null;
    }

    /**
     * Aktualizuje preference uživatele (theme, sidebar_collapsed, per_page).
     */
    public function updatePreferences(int $id, array $prefs): bool
    {
        $allowed = ['theme', 'sidebar_collapsed', 'per_page'];
        $data = array_intersect_key($prefs, array_flip($allowed));
        if (empty($data)) {
            return false;
        }

        // Validace hodnot
        if (isset($data['theme']) && !in_array($data['theme'], ['light', 'dark'], true)) {
            unset($data['theme']);
        }
        if (isset($data['sidebar_collapsed'])) {
            $data['sidebar_collapsed'] = $data['sidebar_collapsed'] ? 1 : 0;
        }
        if (isset($data['per_page'])) {
            $data['per_page'] = max(1, min(Constants::MAX_PER_PAGE, (int) $data['per_page']));
        }

        if (empty($data)) {
            return false;
        }

        return $this->update($id, $data);
    }
}
