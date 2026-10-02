<?php
declare(strict_types=1);

namespace DevAppPro\Controllers;

use DevAppPro\Core\ApiController;
use DevAppPro\Core\Constants;
use DevAppPro\Auth;
use DevAppPro\Repositories\ProjectRepository;

/**
 * API kontroler pro projekty (CRUD + archive/restore).
 * GET    /api/projects              → index()   (seznam s paginací)
 * GET    /api/projects/{id}         → show()    (detail)
 * POST   /api/projects              → store()   (vytvoření)
 * PUT    /api/projects/{id}         → update()  (úprava)
 * POST   /api/projects/{id}/archive → archive() (archivace)
 * POST   /api/projects/{id}/restore → restore() (obnova)
 * DELETE /api/projects/{id}         → destroy() (smazání)
 */
class ProjectApiController extends ApiController
{
    private Auth $auth;

    public function __construct()
    {
        $this->auth = $this->repo(\DevAppPro\Auth::class);
    }

    /**
     * Dispatchuje podle HTTP metody a URI.
     * Vše vyžaduje auth, POST/PUT/DELETE/archive/restore vyžadují CSRF.
     */
    public function handle(): void
    {
        $this->auth->requireAuth();

        $method = $this->getMethod();
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $path = trim($uri, '/');

        // Parsování URI: /api/projects, /api/projects/{id}, /api/projects/{id}/archive, /api/projects/{id}/restore
        $id = null;
        $action = null;

        if (preg_match('#^api/projects/(\d+)/([a-z-]+)$#', $path, $m)) {
            $id = (int) $m[1];
            $action = $m[2];
        } elseif (preg_match('#^api/projects/([a-z-]+)$#', $path, $m)) {
            $action = $m[1];
        } elseif (preg_match('#^api/projects/(\d+)$#', $path, $m)) {
            $id = (int) $m[1];
        }

        // CSRF ochrana pro mutující operace
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            require_csrf();
        }

        switch ($method) {
            case 'GET':
                if ($id !== null && $action === 'wp-login-url') {
                    $this->wpLoginUrl($id);
                } elseif ($id === null && $action === 'hosting-jobs') {
                    $this->hostingJobs();
                } elseif ($id !== null) {
                    $this->show($id);
                } else {
                    $this->index();
                }
                break;
            case 'POST':
                if ($id !== null && $action === 'archive') {
                    $this->archive($id);
                } elseif ($id !== null && $action === 'restore') {
                    $this->restore($id);
                } elseif ($id !== null && $action === 'php-version') {
                    $this->setPhpVersion($id);
                } else {
                    $this->store();
                }
                break;
            case 'PUT':
            case 'PATCH':
                if ($id !== null) {
                    $this->update($id);
                } else {
                    $this->jsonError('ID je vyžadováno pro úpravu.', 400);
                }
                break;
            case 'DELETE':
                if ($id !== null) {
                    $this->destroy($id);
                } else {
                    $this->jsonError('ID je vyžadováno pro smazání.', 400);
                }
                break;
            default:
                $this->jsonError('Nepodporovaná HTTP metoda.', 405);
                break;
        }
    }

    /**
     * GET /api/projects - seznam projektů s paginací, vyhledáváním a filtry.
     */
    protected function index(): void
    {
        $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
        $perPage = isset($_GET['per_page']) ? (int) $_GET['per_page'] : Constants::DEFAULT_PER_PAGE;
        $perPage = max(1, min(Constants::MAX_PER_PAGE, $perPage));
        $search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
        $clientId = isset($_GET['client_id']) ? (int) $_GET['client_id'] : null;
        $status = isset($_GET['status']) ? (string) $_GET['status'] : null;

        $repo = $this->repo(\DevAppPro\Repositories\ProjectRepository::class);
        $result = $repo->all($page, $perPage, $search, $clientId, $status);

        $this->jsonSuccess([
            'data'     => $result['data'],
            'total'    => $result['total'],
            'page'     => $page,
            'per_page' => $perPage,
        ], 200);
    }

    /**
     * GET /api/projects/{id} - detail projektu.
     */
    protected function show(?int $id = null): void
    {
        if ($id === null) {
            $this->jsonError('ID je vyžadováno.', 400);
            return;
        }

        $repo = $this->repo(\DevAppPro\Repositories\ProjectRepository::class);
        $project = $repo->find($id);

        if ($project === null) {
            $this->jsonError('Projekt nenalezen.', 404);
            return;
        }

        $this->jsonSuccess($project, 200);
    }

    /**
     * POST /api/projects - vytvoření projektu.
     */
    protected function store(): void
    {
        $input = json_input();
        $result = $this->validate($input);

        if (!$result['valid']) {
            json_response(['error' => 'Validace selhala.', 'fields' => $result['fields']], 422);
            return;
        }

        $repo = $this->repo(\DevAppPro\Repositories\ProjectRepository::class);
        $id = $repo->create($result['data']);
        $project = $repo->find($id);

        // Automaticky regenerovat SSL + vhost pokud má projekt folder_path
        if (!empty($result['data']['folder_path'])) {
            $this->queueHostingJob($id, 'create', $result['data']['folder_path']);
        }

        $this->jsonSuccess($project, 201);
    }

    /**
     * PUT /api/projects/{id} - úprava projektu.
     */
    protected function update(int $id): void
    {
        $repo = $this->repo(\DevAppPro\Repositories\ProjectRepository::class);

        $existing = $repo->find($id);
        if ($existing === null) {
            $this->jsonError('Projekt nenalezen.', 404);
            return;
        }

        $input = json_input();
        $result = $this->validate($input);

        if (!$result['valid']) {
            json_response(['error' => 'Validace selhala.', 'fields' => $result['fields']], 422);
            return;
        }

        $oldFolderPath = $existing['folder_path'] ?? null;
        $newFolderPath = $result['data']['folder_path'] ?? null;

        $repo->update($id, $result['data']);
        $project = $repo->find($id);

        // Regenerovat SSL + vhost pokud se folder_path změnil nebo nastavil
        if ($newFolderPath && $newFolderPath !== $oldFolderPath) {
            $this->queueHostingJob($id, 'update', $newFolderPath);
        } elseif ($oldFolderPath && !$newFolderPath) {
            // folder_path odebrán — regenerovat bez této domény
            $this->queueHostingJob($id, 'remove', $oldFolderPath);
        }

        $this->jsonSuccess($project, 200);
    }

    /**
     * POST /api/projects/{id}/php-version - přepnutí PHP verze (asynchronně přes cron worker).
     */
    protected function setPhpVersion(int $id): void
    {
        $repo = $this->repo(\DevAppPro\Repositories\ProjectRepository::class);
        $project = $repo->find($id);
        if ($project === null) {
            $this->jsonError('Projekt nenalezen.', 404);
            return;
        }

        $input = json_input();
        $version = $input['php_version'] ?? '';

        $allowed = ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'];
        if (!in_array($version, $allowed, true)) {
            $this->jsonError('Neplatná PHP verze. Povolené: ' . implode(', ', $allowed), 422);
            return;
        }

        // Kontrola - je už nějaký pending job pro tento projekt?
        $pdo = db();
        $stmt = $pdo->prepare('SELECT id FROM php_version_jobs WHERE project_id = ? AND status IN ("pending","starting_fpm","regenerating","reloading") LIMIT 1');
        $stmt->execute([$id]);
        if ($stmt->fetch() !== false) {
            $this->jsonError('Pro tento projekt už běží přepnutí PHP verze.', 409);
            return;
        }

        // Vytvořit job v DB - systemd timer/cron worker (root) ho zpracuje
        $oldVersion = $project['php_version'] ?: '8.5';
        $stmt = $pdo->prepare('INSERT INTO php_version_jobs (project_id, php_version, old_php_version, status) VALUES (?, ?, ?, "pending")');
        $stmt->execute([$id, $version, $oldVersion]);

        $jobId = (int) $pdo->lastInsertId();
        $this->jsonSuccess(['job_id' => $jobId, 'status' => 'pending', 'php_version' => $version], 202);
    }

    /**
     * POST /api/projects/{id}/archive - archivace projektu.
     */
    protected function archive(int $id): void
    {
        $repo = $this->repo(\DevAppPro\Repositories\ProjectRepository::class);

        $existing = $repo->find($id);
        if ($existing === null) {
            $this->jsonError('Projekt nenalezen.', 404);
            return;
        }

        $repo->archive($id);

        $this->jsonSuccess(['id' => $id, 'status' => 'archived'], 200);
    }

    /**
     * POST /api/projects/{id}/restore - obnova projektu.
     */
    protected function restore(int $id): void
    {
        $repo = $this->repo(\DevAppPro\Repositories\ProjectRepository::class);

        $existing = $repo->find($id);
        if ($existing === null) {
            $this->jsonError('Projekt nenalezen.', 404);
            return;
        }

        $repo->restore($id);

        $this->jsonSuccess(['id' => $id, 'status' => 'active'], 200);
    }

    /**
     * DELETE /api/projects/{id} - smazání projektu (vše - soubory, DB, vhost, SSL).
     * Vytvoří job v DB, systemd worker (root) ho zpracuje.
     */
    protected function destroy(int $id): void
    {
        $repo = $this->repo(\DevAppPro\Repositories\ProjectRepository::class);

        $existing = $repo->find($id);
        if ($existing === null) {
            $this->jsonError('Projekt nenalezen.', 404);
            return;
        }

        $folderPath = $existing['folder_path'];
        $siteUrl = $folderPath ? $folderPath . '.localhost' : null;

        // Zjistit DB jméno a uživatele z wp-config.php (pokud existuje)
        $dbName = null;
        $dbUser = null;
        if ($folderPath) {
            $configFile = PROJECTS_WATCH_DIR . '/' . $folderPath . '/wp-config.php';
            if (file_exists($configFile)) {
                $config = file_get_contents($configFile);
                if (preg_match("/define\(\s*'DB_NAME',\s*'([^']+)'\s*\);/", $config, $m)) {
                    $dbName = $m[1];
                }
                if (preg_match("/define\(\s*'DB_USER',\s*'([^']+)'\s*\);/", $config, $m)) {
                    $dbUser = $m[1];
                }
            }
        }

        // Vytvořit delete job
        $pdo = db();
        $stmt = $pdo->prepare('INSERT INTO project_delete_jobs (project_id, project_name, folder_path, site_url, db_name, db_user, status) VALUES (?, ?, ?, ?, ?, ?, "pending")');
        $stmt->execute([
            $id,
            $existing['name'],
            $folderPath,
            $siteUrl,
            $dbName,
            $dbUser,
        ]);

        $jobId = (int) $pdo->lastInsertId();

        // Smazat projekt z projects tabulky hned (worker smaže zbytek)
        $repo->delete($id);

        // Smazat záznam z wp_installs pokud existuje
        if ($siteUrl) {
            $stmt = $pdo->prepare('DELETE FROM wp_installs WHERE site_url = ?');
            $stmt->execute([$siteUrl]);
        }

        $this->jsonSuccess(['job_id' => $jobId, 'status' => 'pending', 'message' => 'Projekt bude smazán (soubory, databáze, vhost, SSL).'], 202);
    }

    /**
     * Validace vstupních dat projektu.
     * @return array{valid: bool, fields: array<string,string>, data: array}
     */
    protected function validate(array $input): array
    {
        $fields = [];
        $data = [];

        // name - povinné (neprázdné)
        $name = $input['name'] ?? null;
        if (is_string($name)) {
            $name = trim($name);
        }
        if ($name === null || $name === '') {
            $fields['name'] = 'Název projektu je povinný.';
        } elseif (mb_strlen($name) > 200) {
            $fields['name'] = 'Pole name je příliš dlouhé (max 200 znaků).';
        } else {
            $data['name'] = $name;
        }

        // client_id - volitelný, pokud zadáno musí existovat v clients
        $clientId = $input['client_id'] ?? null;
        if ($clientId !== null && $clientId !== '') {
            $clientId = (int) $clientId;
            if ($clientId <= 0) {
                $fields['client_id'] = 'Klient neexistuje.';
            } else {
                $repo = $this->repo(\DevAppPro\Repositories\ProjectRepository::class);
                if (!$repo->clientExists($clientId)) {
                    $fields['client_id'] = 'Klient neexistuje.';
                } else {
                    $data['client_id'] = $clientId;
                }
            }
        } else {
            $data['client_id'] = null;
        }

        // status - z enum (active, on_hold, completed, cancelled, archived)
        $status = $input['status'] ?? 'active';
        $status = is_string($status) ? trim($status) : 'active';
        $allowedStatuses = Constants::PROJECT_STATUSES;
        if (!in_array($status, $allowedStatuses, true)) {
            $fields['status'] = 'Status musí být active, on_hold, completed, cancelled nebo archived.';
        } else {
            $data['status'] = $status;
        }

        // budget_cents - >= 0 (int)
        $budgetCents = $input['budget_cents'] ?? 0;
        if (!is_numeric($budgetCents)) {
            $fields['budget_cents'] = 'Rozpočet musí být nezáporné celé číslo.';
        } else {
            $budgetCents = (int) $budgetCents;
            if ($budgetCents < 0) {
                $fields['budget_cents'] = 'Rozpočet musí být nezáporné celé číslo.';
            } else {
                $data['budget_cents'] = $budgetCents;
            }
        }

        // started_at - datum YYYY-MM-DD nebo NULL
        $startedAt = $input['started_at'] ?? null;
        if ($startedAt !== null && $startedAt !== '') {
            if ($this->isValidDate($startedAt)) {
                $data['started_at'] = $startedAt;
            } else {
                $fields['started_at'] = 'Datum zahájení musí být ve formátu YYYY-MM-DD.';
            }
        } else {
            $data['started_at'] = null;
        }

        // deadline - datum YYYY-MM-DD nebo NULL
        $deadline = $input['deadline'] ?? null;
        if ($deadline !== null && $deadline !== '') {
            if ($this->isValidDate($deadline)) {
                $data['deadline'] = $deadline;
            } else {
                $fields['deadline'] = 'Termín musí být ve formátu YYYY-MM-DD.';
            }
        } else {
            $data['deadline'] = null;
        }

        // description - string nebo NULL
        $description = $input['description'] ?? null;
        if (is_string($description)) {
            $description = trim($description);
            if ($description !== '' && mb_strlen($description) > 5000) {
                $fields['description'] = 'Pole description je příliš dlouhé (max 5000 znaků).';
            }
            $data['description'] = $description !== '' ? $description : null;
        } else {
            $data['description'] = null;
        }

        // type - static, wordpress nebo NULL (jen pokud je v inputu)
        if (array_key_exists('type', $input)) {
            $type = $input['type'];
            if ($type !== null && $type !== '') {
                $type = is_string($type) ? trim($type) : null;
                if (!in_array($type, ['static', 'wordpress'], true)) {
                    $fields['type'] = 'Typ musí být "static" nebo "wordpress".';
                } else {
                    $data['type'] = $type;
                }
            } else {
                $data['type'] = null;
            }
        }

        // folder_path - název složky v PROJECTS_WATCH_DIR/ (jen pokud je v inputu)
        if (array_key_exists('folder_path', $input)) {
            $folderPath = $input['folder_path'];
            if ($folderPath !== null && $folderPath !== '') {
                $folderPath = is_string($folderPath) ? trim($folderPath) : null;
                // Bezpečnost: pouze alfanumerické znaky, pomlčky, podtržítka
                if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/', $folderPath)) {
                    $fields['folder_path'] = 'Název složky může obsahovat pouze písmena, číslice, pomlčky a podtržítka.';
                } elseif (mb_strlen($folderPath) > 200) {
                    $fields['folder_path'] = 'Pole folder_path je příliš dlouhé (max 200 znaků).';
                } else {
                    $data['folder_path'] = $folderPath;
                }
            } else {
                $data['folder_path'] = null;
            }
        }

        return [
            'valid'  => empty($fields),
            'fields' => $fields,
            'data'   => $data,
        ];
    }

    /**
     * Ověří, zda je řetězec platné datum ve formátu YYYY-MM-DD.
     */
    private function isValidDate(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        $parts = explode('-', $date);
        return checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
    }

    /**
     * Vytvoří job pro regeneraci SSL certifikátu a vhostů.
     * Worker (systemd timer) asynchronně zpracuje job.
     */
    private function queueHostingJob(int $projectId, string $action, string $folderPath): void
    {
        $pdo = db();
        $stmt = $pdo->prepare(
            'INSERT INTO project_hosting_jobs (project_id, action, folder_path, status) VALUES (?, ?, ?, "pending")'
        );
        $stmt->execute([$projectId, $action, $folderPath]);
    }

    /**
     * GET /api/projects/hosting-jobs
     * Vrátí stav hosting jobů (pro frontend polling).
     */
    protected function hostingJobs(): void
    {
        $pdo = db();
        $stmt = $pdo->query(
            'SELECT j.id, j.project_id, j.action, j.folder_path, j.status, j.error_message, j.created_at, j.updated_at,
                    p.name AS project_name
             FROM project_hosting_jobs j
             LEFT JOIN projects p ON p.id = j.project_id
             ORDER BY j.id DESC LIMIT 20'
        );
        $jobs = $stmt->fetchAll();
        $this->jsonSuccess(['data' => $jobs]);
    }

    /**
     * GET /api/projects/{id}/wp-login-url
     * Vygeneruje URL pro automatické přihlášení do WP administrace.
     */
    protected function wpLoginUrl(int $id): void
    {
        $repo = $this->repo(\DevAppPro\Repositories\ProjectRepository::class);
        $project = $repo->find($id);
        if ($project === null) {
            $this->jsonError('Projekt nenalezen.', 404);
            return;
        }

        if (($project['type'] ?? null) !== 'wordpress') {
            $this->jsonError('Tento projekt není WordPress.', 400);
            return;
        }

        if (empty($project['folder_path'])) {
            $this->jsonError('Projekt nemá složku na disku.', 400);
            return;
        }

        // Najít admin uživatele z wp_installs
        $wpRepo = $this->repo(\DevAppPro\Repositories\WpInstallRepository::class);
        $wpInstall = $wpRepo->findBySiteUrl($project['folder_path'] . '.localhost');
        $adminUser = $wpInstall['admin_user'] ?? null;

        // Pokud není v wp_installs (např. restore ze zálohy), najít admina z WP DB
        if ($adminUser === null) {
            $adminUser = $this->detectWpAdminUser($project);
        }

        if ($adminUser === null) {
            $this->jsonError('Nepodařilo se najít admin uživatele WordPressu.', 500);
            return;
        }

        // Vygenerovat token
        $timestamp = time();
        $signature = hash_hmac('sha256', $adminUser . '|' . $timestamp, WP_AUTOLOGIN_SECRET);
        $token = base64_encode($adminUser . '|' . $timestamp . '|' . $signature);
        $url = 'https://' . $project['folder_path'] . '.localhost/?devapppro_login=' . urlencode($token);

        $this->jsonSuccess(['url' => $url, 'admin_user' => $adminUser]);
    }

    /**
     * Najít admin uživatele WordPressu přímo z WP databáze projektu.
     */
    private function detectWpAdminUser(array $project): ?string
    {
        $docRoot = PROJECTS_WATCH_DIR . '/' . $project['folder_path'];
        $configFile = $docRoot . '/wp-config.php';
        if (!file_exists($configFile)) {
            return null;
        }

        $config = file_get_contents($configFile);
        if (!preg_match("/define\(\s*'DB_NAME',\s*'([^']+)'\s*\);/", $config, $dbNameMatch)) {
            return null;
        }
        if (!preg_match("/define\(\s*'DB_USER',\s*'([^']+)'\s*\);/", $config, $dbUserMatch)) {
            return null;
        }
        if (!preg_match("/define\(\s*'DB_PASSWORD',\s*'([^']+)'\s*\);/", $config, $dbPassMatch)) {
            return null;
        }
        if (!preg_match("/define\(\s*'DB_HOST',\s*'([^']+)'\s*\);/", $config, $dbHostMatch)) {
            return null;
        }
        if (!preg_match("/\\\$table_prefix\s*=\s*'([^']+)'\s*;/", $config, $prefixMatch)) {
            return null;
        }

        try {
            $pdo = new \PDO(
                'mysql:host=' . $dbHostMatch[1] . ';dbname=' . $dbNameMatch[1] . ';charset=utf8mb4',
                $dbUserMatch[1],
                $dbPassMatch[1],
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
        } catch (\PDOException $e) {
            return null;
        }

        $prefix = $prefixMatch[1];
        $usersTable = $prefix . 'users';
        $userMetaTable = $prefix . 'usermeta';

        try {
            // Najít uživatele s rolí administrator
            $stmt = $pdo->prepare("SELECT u.user_login FROM {$usersTable} u
                JOIN {$userMetaTable} m ON u.ID = m.user_id
                WHERE m.meta_key = '{$prefix}capabilities' AND m.meta_value LIKE '%administrator%'
                ORDER BY u.ID ASC LIMIT 1");
            $stmt->execute();
            $user = $stmt->fetchColumn();
            return $user ?: null;
        } catch (\PDOException $e) {
            return null;
        }
    }
}
