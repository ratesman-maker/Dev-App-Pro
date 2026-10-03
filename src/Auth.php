<?php
declare(strict_types=1);

namespace DevAppPro;

use DevAppPro\Repositories\UserRepository;

/**
 * Třída pro autentizaci uživatelů.
 * Session-based auth s HTTP-only cookies.
 */
class Auth
{
    private UserRepository $users;

    public function __construct()
    {
        $this->users = new UserRepository();
    }

    /**
     * Ověří přihlášení, regeneruje session, nastaví $_SESSION['user_id'].
     * @return array|false User data nebo false
     */
    public function login(string $username, string $password): array|false
    {
        $user = $this->users->findByUsername($username);
        if (!$user) {
            return false;
        }
        if (!password_verify($password, $user['password_hash'])) {
            return false;
        }

        // Regenerovat session ID (ochrana proti session fixation)
        session_regenerate_id(true);

        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['login_time'] = time();
        $_SESSION['ip'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $_SESSION['ua'] = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Rotovat CSRF token po loginu
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        setcookie('csrf-token', $_SESSION['csrf_token'], [
            'httponly' => false,
            'samesite' => 'Lax',
            'path' => '/',
        ]);

        return $this->formatUser($user);
    }

    /**
     * Odhlásí uživatele - session_destroy, smaže cookie, regeneruje session.
     */
    public function logout(): void
    {
        $_SESSION = [];

        // Smazat session cookie
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        // Smazat CSRF cookie
        setcookie('csrf-token', '', [
            'expires' => time() - 42000,
            'path' => '/',
        ]);

        session_destroy();
        session_start();
        session_regenerate_id(true);
    }

    /**
     * Je uživatel přihlášen?
     */
    public function check(): bool
    {
        return !empty($_SESSION['user_id']);
    }

    /**
     * Vrátí aktuálního uživatele z DB.
     * @return array|null (id, name, username, theme, sidebar_collapsed, per_page)
     */
    public function user(): ?array
    {
        if (!$this->check()) {
            return null;
        }
        $user = $this->users->find((int) $_SESSION['user_id']);
        if (!$user) {
            return null;
        }
        return $this->formatUser($user);
    }

    /**
     * Pokud není přihlášen → 401 json a die.
     */
    public function requireAuth(): void
    {
        if (!$this->check()) {
            json_response(['error' => 'Neautorizováno.'], 401);
            die;
        }
    }

    /**
     * Ověří rate limit z login_attempts (max 5 za hodinu na IP a username).
     * @return bool true pokud je povoleno, false pokud překročeno
     */
    public function checkRateLimit(string $ip, ?string $username): bool
    {
        $pdo = db();
        $maxAttempts = RATE_LIMIT_MAX_ATTEMPTS;
        $windowHours = RATE_LIMIT_WINDOW_HOURS;

        // Časový okno počítá DB — attempted_at je CURRENT_TIMESTAMP v TZ serveru,
        // PHP date() by při rozdílné TZ app vs DB rate limit tiše vyplo
        $cutoff = (string) $pdo->query(
            "SELECT NOW() - INTERVAL " . (int)($windowHours * 3600) . " SECOND"
        )->fetchColumn();

        // Počet neúspěšných pokusů z IP za okno
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS cnt FROM `login_attempts`
             WHERE `ip_address` = ? AND `success` = 0
             AND `attempted_at` >= ?"
        );
        $stmt->execute([$ip, $cutoff]);
        $ipCount = (int) $stmt->fetch()['cnt'];

        if ($ipCount >= $maxAttempts) {
            return false;
        }

        // Počet neúspěšných pokusů pro username za okno
        if ($username !== null && $username !== '') {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) AS cnt FROM `login_attempts`
                 WHERE `username` = ? AND `success` = 0
                 AND `attempted_at` >= ?"
            );
            $stmt->execute([$username, $cutoff]);
            $userCount = (int) $stmt->fetch()['cnt'];

            if ($userCount >= $maxAttempts) {
                return false;
            }
        }

        return true;
    }

    /**
     * Zaznamená pokus o přihlášení do login_attempts.
     */
    public function recordLoginAttempt(string $ip, ?string $username, bool $success): void
    {
        $pdo = db();
        $stmt = $pdo->prepare(
            "INSERT INTO `login_attempts` (`ip_address`, `username`, `success`)
             VALUES (?, ?, ?)"
        );
        $stmt->execute([$ip, $username, $success ? 1 : 0]);
    }

    /**
     * Vrátí hint pro reset hesla.
     */
    public function getPasswordHint(string $username): ?string
    {
        $user = $this->users->findByUsername($username);
        if (!$user) {
            return null;
        }
        return $user['password_hint'];
    }

    /**
     * Změní heslo a zničí všechny aktivní sessions uživatele.
     */
    public function resetPassword(string $username, string $newPassword): bool
    {
        $user = $this->users->findByUsername($username);
        if (!$user) {
            return false;
        }

        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
        $this->users->updatePassword((int) $user['id'], $hash);

        // Zničit všechny aktivní sessions tohoto uživatele
        $this->destroyUserSessions((int) $user['id']);

        return true;
    }

    /**
     * Smaže filesystem sessions pro daný user_id.
     * Prochází session files a kontroluje $_SESSION['user_id'].
     */
    public function destroyUserSessions(int $userId): void
    {
        $savePath = session_save_path();
        if ($savePath === '') {
            $savePath = sys_get_temp_dir();
        }

        if (!is_dir($savePath)) {
            return;
        }

        // Uzamknout aktuální session před čtením souborů
        session_write_close();

        $currentSessionId = session_id();
        $currentSessionFile = $savePath . '/sess_' . $currentSessionId;

        $files = glob($savePath . '/sess_*');
        if ($files === false) {
            $files = [];
        }

        foreach ($files as $file) {
            // Přeskočit aktuální session (ta se zničí při dalším requestu)
            if ($file === $currentSessionFile) {
                continue;
            }

            $content = @file_get_contents($file);
            if ($content === false || $content === '') {
                continue;
            }

            $sessionData = @unserialize($content, ['allowed_classes' => false]);
            if (!is_array($sessionData)) {
                continue;
            }

            if (isset($sessionData['user_id']) && (int) $sessionData['user_id'] === $userId) {
                @unlink($file);
            }
        }

        // Restartovat session
        session_start();
    }

    /**
     * Naformátuje user data pro API response.
     * @return array{id: int, name: string, username: string, theme: string, sidebar_collapsed: bool, per_page: int}
     */
    private function formatUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'username' => $user['username'],
            'email' => $user['email'],
            'theme' => $user['theme'],
            'sidebar_collapsed' => (bool) (int) $user['sidebar_collapsed'],
            'per_page' => (int) $user['per_page'],
        ];
    }
}
