<?php
declare(strict_types=1);

namespace DevAppPro\Controllers;

use DevAppPro\Core\ApiController;
use DevAppPro\Auth;
use DevAppPro\Repositories\UserRepository;

/**
 * API kontroler pro autentizaci a uživatelské preference.
 */
class AuthApiController extends ApiController
{
    private Auth $auth;
    private UserRepository $users;

    public function __construct()
    {
        $this->auth = $this->repo(\DevAppPro\Auth::class);
        $this->users = $this->repo(\DevAppPro\Repositories\UserRepository::class);
    }

    /**
     * Dispatchuje podle URI akce a HTTP metody.
     */
    public function handle(): void
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $method = $this->getMethod();

        // GET endpointy (nevyžadují CSRF)
        if ($uri === '/api/auth/csrf-token' && $method === 'GET') {
            $this->csrfToken();
            return;
        }
        if ($uri === '/api/auth/me' && $method === 'GET') {
            $this->show();
            return;
        }

        // POST/PUT endpointy (vyžadují CSRF)
        if ($uri === '/api/auth/login' && $method === 'POST') {
            require_csrf();
            $this->store();
            return;
        }
        if ($uri === '/api/auth/logout' && $method === 'POST') {
            require_csrf();
            $this->logout();
            return;
        }
        if ($uri === '/api/auth/password-hint' && $method === 'POST') {
            require_csrf();
            $this->hint();
            return;
        }
        if ($uri === '/api/auth/reset-password' && $method === 'POST') {
            require_csrf();
            $this->reset();
            return;
        }
        if ($uri === '/api/users/me/password-hint' && $method === 'PUT') {
            require_csrf();
            $this->updateHint();
            return;
        }
        if ($uri === '/api/users/me/preferences' && $method === 'PUT') {
            require_csrf();
            $this->updatePreferences();
            return;
        }
        if ($uri === '/api/users/me/profile' && $method === 'PUT') {
            require_csrf();
            $this->updateProfile();
            return;
        }
        if ($uri === '/api/users/me/password' && $method === 'PUT') {
            require_csrf();
            $this->changePassword();
            return;
        }

        $this->jsonError('Endpoint nenalezen.', 404);
    }

    /**
     * GET /api/auth/csrf-token - vydá CSRF token v JSON a cookie.
     */
    private function csrfToken(): void
    {
        $token = csrf_token();
        $this->jsonSuccess(['csrf_token' => $token]);
    }

    /**
     * POST /api/auth/login - přihlášení uživatele.
     */
    protected function store(): void
    {
        $input = json_input();
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';
        $ip = client_ip();

        if ($username === '' || $password === '') {
            $this->jsonError('Vyplňte uživatelské jméno a heslo.', 422);
            return;
        }

        if (mb_strlen($username) > 100) {
            $this->jsonError('Pole username je příliš dlouhé (max 100 znaků).', 422);
            return;
        }
        if (mb_strlen($password) > 100) {
            $this->jsonError('Pole password je příliš dlouhé (max 100 znaků).', 422);
            return;
        }

        // Rate limit check
        if (!$this->auth->checkRateLimit($ip, $username)) {
            $this->jsonError('Příliš mnoho pokusů. Zkuste to znovu později.', 429);
            return;
        }

        $user = $this->auth->login($username, $password);

        if ($user === false) {
            $this->auth->recordLoginAttempt($ip, $username, false);
            $this->jsonError('Neplatné přihlašovací údaje.', 401);
            return;
        }

        $this->auth->recordLoginAttempt($ip, $username, true);
        $this->jsonSuccess(['user' => $user], 200);
    }

    /**
     * POST /api/auth/logout - odhlášení.
     */
    protected function logout(): void
    {
        $this->auth->logout();
        $this->jsonSuccess(['success' => true]);
    }

    /**
     * GET /api/auth/me - vrátí aktuálního uživatele.
     */
    protected function show(?int $id = null): void
    {
        $this->auth->requireAuth();
        $user = $this->auth->user();
        if ($user === null) {
            $this->jsonError('Neautorizováno.', 401);
            return;
        }
        $this->jsonSuccess(['user' => $user]);
    }

    /**
     * POST /api/auth/password-hint - vrátí hint pro username.
     */
    private function hint(): void
    {
        $input = json_input();
        $username = trim($input['username'] ?? '');
        $ip = client_ip();

        if ($username === '') {
            $this->jsonError('Vyplňte uživatelské jméno.', 422);
            return;
        }

        if (mb_strlen($username) > 100) {
            $this->jsonError('Pole username je příliš dlouhé (max 100 znaků).', 422);
            return;
        }

        // Rate limit check
        if (!$this->auth->checkRateLimit($ip, $username)) {
            $this->jsonError('Příliš mnoho pokusů. Zkuste to znovu později.', 429);
            return;
        }

        $hint = $this->auth->getPasswordHint($username);
        if ($hint === null) {
            $this->jsonError('Uživatel nenalezen.', 404);
            return;
        }

        $this->jsonSuccess(['username' => $username, 'hint' => $hint]);
    }

    /**
     * POST /api/auth/reset-password - reset hesla.
     */
    private function reset(): void
    {
        $input = json_input();
        $username = trim($input['username'] ?? '');
        $newPassword = $input['new_password'] ?? '';
        $newPasswordConfirm = $input['new_password_confirm'] ?? '';
        $ip = client_ip();

        if ($username === '') {
            $this->jsonError('Vyplňte uživatelské jméno.', 422);
            return;
        }

        if (mb_strlen($username) > 100) {
            $this->jsonError('Pole username je příliš dlouhé (max 100 znaků).', 422);
            return;
        }

        // Rate limit check
        if (!$this->auth->checkRateLimit($ip, $username)) {
            $this->jsonError('Příliš mnoho pokusů. Zkuste to znovu později.', 429);
            return;
        }

        // Validace hesel
        if ($newPassword !== $newPasswordConfirm) {
            $this->jsonError('Hesla se neshodují.', 422);
            return;
        }

        if (mb_strlen($newPassword) < PASSWORD_MIN_LENGTH) {
            $this->jsonError('Heslo musí mít alespoň ' . PASSWORD_MIN_LENGTH . ' znaků.', 422);
            return;
        }

        if (mb_strlen($newPassword) > 100) {
            $this->jsonError('Pole new_password je příliš dlouhé (max 100 znaků).', 422);
            return;
        }

        $success = $this->auth->resetPassword($username, $newPassword);
        if (!$success) {
            $this->jsonError('Uživatel nenalezen.', 404);
            return;
        }

        $this->jsonSuccess(['message' => 'Heslo bylo změněno. Nyní se můžete přihlásit.']);
    }

    /**
     * PUT /api/users/me/password-hint - nastaví hint pro reset hesla.
     */
    private function updateHint(): void
    {
        $this->auth->requireAuth();

        $input = json_input();
        $hint = trim($input['password_hint'] ?? '');

        if (mb_strlen($hint) > 255) {
            $this->jsonError('Hint je příliš dlouhý (max 255 znaků).', 422);
            return;
        }

        $userId = (int) $_SESSION['user_id'];
        $this->users->updateHint($userId, $hint);

        $this->jsonSuccess(['password_hint' => $hint === '' ? null : $hint]);
    }

    /**
     * PUT /api/users/me/preferences - aktualizuje preference.
     */
    private function updatePreferences(): void
    {
        $this->auth->requireAuth();

        $input = json_input();
        $userId = (int) $_SESSION['user_id'];

        $prefs = [];
        if (isset($input['theme'])) {
            $prefs['theme'] = $input['theme'];
        }
        if (isset($input['sidebar_collapsed'])) {
            $prefs['sidebar_collapsed'] = $input['sidebar_collapsed'];
        }
        if (isset($input['per_page'])) {
            $prefs['per_page'] = $input['per_page'];
        }

        $this->users->updatePreferences($userId, $prefs);

        $user = $this->auth->user();
        $this->jsonSuccess([
            'theme' => $user['theme'],
            'sidebar_collapsed' => $user['sidebar_collapsed'],
            'per_page' => $user['per_page'],
        ]);
    }

    /**
     * PUT /api/users/me/profile - aktualizuje profil (name, email).
     */
    private function updateProfile(): void
    {
        $this->auth->requireAuth();

        $input = json_input();
        $userId = (int) $_SESSION['user_id'];

        $data = [];
        if (array_key_exists('name', $input)) {
            $data['name'] = $input['name'];
        }
        if (array_key_exists('email', $input)) {
            $data['email'] = $input['email'];
        }

        if (empty($data)) {
            $this->jsonError('Nebyla poskytnuta žádná pole k aktualizaci.', 422);
            return;
        }

        // Validace emailu
        if (isset($data['email']) && $data['email'] !== '' && $data['email'] !== null) {
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $this->jsonError('Neplatný formát e-mailu.', 422);
                return;
            }
        }

        // Délková omezení
        if (isset($data['name']) && mb_strlen($data['name']) > 100) {
            $this->jsonError('Jméno je příliš dlouhé (max 100 znaků).', 422);
            return;
        }
        if (isset($data['email']) && mb_strlen($data['email']) > 255) {
            $this->jsonError('E-mail je příliš dlouhý (max 255 znaků).', 422);
            return;
        }

        $ok = $this->users->updateProfile($userId, $data);
        if (!$ok) {
            $this->jsonError('Aktualizace profilu selhala.', 500);
            return;
        }

        $user = $this->auth->user();
        $this->jsonSuccess([
            'name' => $user['name'],
            'email' => $user['email'],
        ]);
    }

    /**
     * PUT /api/users/me/password - změna hesla (vyžaduje aktuální heslo).
     */
    private function changePassword(): void
    {
        $this->auth->requireAuth();

        $input = json_input();
        $userId = (int) $_SESSION['user_id'];
        $currentPassword = $input['current_password'] ?? '';
        $newPassword = $input['new_password'] ?? '';
        $newPasswordConfirm = $input['new_password_confirm'] ?? '';

        if ($currentPassword === '' || $newPassword === '' || $newPasswordConfirm === '') {
            $this->jsonError('Vyplňte všechna pole.', 422);
            return;
        }

        if ($newPassword !== $newPasswordConfirm) {
            $this->jsonError('Nová hesla se neshodují.', 422);
            return;
        }

        if (mb_strlen($newPassword) < PASSWORD_MIN_LENGTH) {
            $this->jsonError('Heslo musí mít alespoň ' . PASSWORD_MIN_LENGTH . ' znaků.', 422);
            return;
        }

        if (mb_strlen($currentPassword) > 100) {
            $this->jsonError('Pole current_password je příliš dlouhé (max 100 znaků).', 422);
            return;
        }
        if (mb_strlen($newPassword) > 100) {
            $this->jsonError('Pole new_password je příliš dlouhé (max 100 znaků).', 422);
            return;
        }

        // Ověřit aktuální heslo
        $currentHash = $this->users->getPasswordHash($userId);
        if ($currentHash === null || !password_verify($currentPassword, $currentHash)) {
            $this->jsonError('Aktuální heslo je nesprávné.', 422);
            return;
        }

        // Zahashovat a uložit nové heslo
        $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
        $this->users->updatePassword($userId, $newHash);

        $this->jsonSuccess(['message' => 'Heslo bylo změněno.']);
    }
}
