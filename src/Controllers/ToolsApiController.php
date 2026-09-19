<?php
declare(strict_types=1);

namespace DevAppPro\Controllers;

use DevAppPro\Core\ApiController;
use DevAppPro\Auth;
use DevAppPro\Repositories\WpInstallRepository;
use DevAppPro\Services\SystemInfoService;

/**
 * API kontroler pro WordPress instalace.
 * GET    /api/tools/wp-installs        → index()  (seznam instalací)
 * GET    /api/tools/wp-installs/{id}   → show()   (detail instalace)
 * POST   /api/tools/wp-installs        → store()  (vytvoření jobu)
 * DELETE /api/tools/wp-installs/{id}   → destroy() (smazání instalace)
 */
class ToolsApiController extends ApiController
{
    private Auth $auth;

    public function __construct()
    {
        $this->auth = $this->repo(\DevAppPro\Auth::class);
    }

    /**
     * Dispatchuje podle HTTP metody.
     * Vše vyžaduje auth, POST/DELETE vyžadují CSRF.
     */
    public function handle(): void
    {
        $this->auth->requireAuth();

        $method = $this->getMethod();
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $path = trim($uri, '/');

        // /api/tools/system-info
        if ($path === 'api/tools/system-info' && $method === 'GET') {
            $this->systemInfo();
            return;
        }

        // /api/tools/php-versions - seznam dostupných PHP verzí
        if ($path === 'api/tools/php-versions' && $method === 'GET') {
            $this->phpVersions();
            return;
        }

        // /api/tools/php-version-jobs - status přepínání PHP verzí
        if ($path === 'api/tools/php-version-jobs' && $method === 'GET') {
            $this->phpVersionJobs();
            return;
        }

        $id = $this->getId();

        // CSRF ochrana pro mutující operace
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            require_csrf();
        }

        switch ($method) {
            case 'GET':
                if ($id !== null) {
                    $this->show($id);
                } else {
                    $this->index();
                }
                break;
            case 'POST':
                $this->store();
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
     * GET /api/tools/wp-installs - seznam instalací.
     */
    protected function index(): void
    {
        $repo = $this->repo(\DevAppPro\Repositories\WpInstallRepository::class);
        $result = $repo->all(1, 100);

        $this->jsonSuccess([
            'data'  => $result['data'],
            'total' => $result['total'],
        ], 200);
    }

    /**
     * GET /api/tools/wp-installs/{id} - detail instalace (pro polling).
     */
    protected function show(?int $id = null): void
    {
        if ($id === null) {
            $this->jsonError('ID je vyžadováno.', 400);
            return;
        }

        $repo = $this->repo(\DevAppPro\Repositories\WpInstallRepository::class);
        $install = $repo->find($id);

        if ($install === null) {
            $this->jsonError('Instalace nenalezena.', 404);
            return;
        }

        $this->jsonSuccess($install, 200);
    }

    /**
     * POST /api/tools/wp-installs - vytvoří nový instalační job.
     */
    protected function store(): void
    {
        $input = json_input();
        $result = $this->validate($input);

        if (!$result['valid']) {
            json_response(['error' => 'Validace selhala.', 'fields' => $result['fields']], 422);
            return;
        }

        $data = $result['data'];
        $repo = $this->repo(\DevAppPro\Repositories\WpInstallRepository::class);

        // Kontrola unikátnosti site_url
        if ($repo->findBySiteUrl($data['site_url']) !== null) {
            json_response([
                'error'  => 'Validace selhala.',
                'fields' => ['site_name' => 'Tento název webu je již použit.'],
            ], 422);
            return;
        }

        // Kontrola unikátnosti db_name
        if ($repo->findByDbName($data['db_name']) !== null) {
            json_response([
                'error'  => 'Validace selhala.',
                'fields' => ['site_name' => 'Databáze pro tento název již existuje.'],
            ], 422);
            return;
        }

        // Vytvoření záznamu v DB
        $id = $repo->create([
            'site_name'      => $data['site_name'],
            'site_url'       => $data['site_url'],
            'document_root'  => $data['document_root'],
            'db_name'        => $data['db_name'],
            'db_user'        => $data['db_user'],
            'db_password'    => $data['db_password'],
            'admin_user'     => $data['admin_user'],
            'admin_password' => $data['admin_password'],
            'admin_email'    => $data['admin_email'],
            'status'         => 'pending',
        ]);

        // Spuštění CLI scriptu asynchronně (bez čekání)
        $this->launchInstaller($id);

        $install = $repo->find($id);
        $this->jsonSuccess($install, 201);
    }

    /**
     * DELETE /api/tools/wp-installs/{id} - smazání instalace.
     */
    protected function destroy(int $id): void
    {
        $repo = $this->repo(\DevAppPro\Repositories\WpInstallRepository::class);

        $install = $repo->find($id);
        if ($install === null) {
            $this->jsonError('Instalace nenalezena.', 404);
            return;
        }

        // Smazání povoleno pouze pro completed nebo failed
        if (!in_array($install['status'], ['completed', 'failed'], true)) {
            $this->jsonError('Instalaci lze smazat pouze ve stavu „completed" nebo „failed".', 409);
            return;
        }

        // Nastavíme status na pending_uninstall - cron zpracuje odinstalaci a smazání
        $repo->updateStatus($id, 'pending_uninstall', null);

        $this->jsonSuccess(['message' => 'Odinstalace naplánována.'], 202);
    }

    /**
     * Job je vytvořen v DB se statusem 'pending'.
     * Cron (cli/install-wordpress.php --process-pending) ho zpracuje jako root.
     */
    private function launchInstaller(int $id): void
    {
        // Nic neděláme - cron zpracuje pending job
    }

    /**
     * Odinstalace - naplánujeme přes DB (cron zpracuje).
     */
    private function launchUninstaller(int $id): void
    {
        // Nic neděláme - cron zpracuje uninstall job
    }

    /**
     * Validace vstupních dat pro vytvoření instalace.
     * @return array{valid: bool, fields: array<string,string>, data: array}
     */
    private function validate(array $input): array
    {
        $fields = [];
        $data = [];

        // site_name: required, 3-50 znaků, jen [a-z0-9-]
        $siteName = $input['site_name'] ?? '';
        $siteName = is_string($siteName) ? trim($siteName) : '';
        if ($siteName === '') {
            $fields['site_name'] = 'Název webu je povinný.';
        } elseif (strlen($siteName) < 3 || strlen($siteName) > 50) {
            $fields['site_name'] = 'Název webu musí mít 3–50 znaků.';
        } elseif (!preg_match('/^[a-z0-9-]+$/', $siteName)) {
            $fields['site_name'] = 'Název webu může obsahovat pouze malá písmena, číslice a pomlčky.';
        }
        $data['site_name'] = $siteName;

        // admin_email: valid email
        $adminEmail = $input['admin_email'] ?? '';
        $adminEmail = is_string($adminEmail) ? trim($adminEmail) : '';
        if ($adminEmail === '') {
            $fields['admin_email'] = 'E-mail administrátora je povinný.';
        } elseif (filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
            $fields['admin_email'] = 'E-mail administrátora není platný.';
        } elseif (mb_strlen($adminEmail) > 255) {
            $fields['admin_email'] = 'Pole admin_email je příliš dlouhé (max 255 znaků).';
        }
        $data['admin_email'] = $adminEmail;

        // admin_user: default 'admin', 3-60 znaků, alfanumerické
        $adminUser = $input['admin_user'] ?? 'admin';
        $adminUser = is_string($adminUser) ? trim($adminUser) : 'admin';
        if ($adminUser === '') {
            $adminUser = 'admin';
        } elseif (strlen($adminUser) < 3 || strlen($adminUser) > 60) {
            $fields['admin_user'] = 'Uživatelské jméno administrátora musí mít 3–60 znaků.';
        } elseif (!preg_match('/^[a-zA-Z0-9._-]+$/', $adminUser)) {
            $fields['admin_user'] = 'Uživatelské jméno může obsahovat pouze písmena, číslice, tečky, pomlčky a podtržítka.';
        }
        $data['admin_user'] = $adminUser;

        // admin_password: default náhodné 16 znaků
        $adminPassword = $input['admin_password'] ?? '';
        $adminPassword = is_string($adminPassword) ? $adminPassword : '';
        if ($adminPassword === '') {
            $adminPassword = bin2hex(random_bytes(8)); // 16 znaků
        } elseif (strlen($adminPassword) < 8) {
            $fields['admin_password'] = 'Heslo administrátora musí mít alespoň 8 znaků.';
        } elseif (mb_strlen($adminPassword) > 100) {
            $fields['admin_password'] = 'Pole admin_password je příliš dlouhé (max 100 znaků).';
        }
        $data['admin_password'] = $adminPassword;

        if (!empty($fields)) {
            return ['valid' => false, 'fields' => $fields, 'data' => $data];
        }

        // Generování odvozených hodnot
        $data['site_url'] = $siteName . '.localhost';
        $data['document_root'] = WP_INSTALL_BASE . '/' . $siteName;

        // db_name: wp_{site_name} s podtržítky místo pomlček, max 64 znaků
        $dbBase = 'wp_' . str_replace('-', '_', $siteName);
        if (strlen($dbBase) > 64) {
            $dbBase = substr($dbBase, 0, 64);
        }
        $data['db_name'] = $dbBase;
        $data['db_user'] = $dbBase;

        // db_password: random 24 chars
        $data['db_password'] = $this->generatePassword(24);

        return ['valid' => true, 'fields' => [], 'data' => $data];
    }

    /**
     * Vygeneruje náhodné heslo z bezpečného charsetu.
     */
    private function generatePassword(int $length = 24): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_=+';
        $max = strlen($chars) - 1;
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, $max)];
        }
        return $password;
    }

    /**
     * GET /api/tools/system-info - informace o systémových nástrojích.
     */
    protected function phpVersions(): void
    {
        $versions = [];
        $allVersions = ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'];

        foreach ($allVersions as $ver) {
            $bin = "/usr/bin/php{$ver}";
            $socket = "/run/php/php{$ver}-fpm.sock";
            $installed = file_exists($bin);
            $running = file_exists($socket);

            $version = null;
            if ($installed) {
                $version = trim(shell_exec("{$bin} -r 'echo PHP_VERSION;' 2>/dev/null") ?? '');
            }

            $versions[] = [
                'version' => $ver,
                'installed' => $installed,
                'running' => $running,
                'full_version' => $version,
            ];
        }

        $this->jsonSuccess(['versions' => $versions]);
    }

    protected function phpVersionJobs(): void
    {
        $pdo = db();
        $stmt = $pdo->query('SELECT * FROM php_version_jobs WHERE status IN ("pending","starting_fpm","regenerating","reloading") ORDER BY id DESC LIMIT 10');
        $active = $stmt->fetchAll();

        $stmt = $pdo->query('SELECT * FROM php_version_jobs WHERE status IN ("completed","failed") ORDER BY id DESC LIMIT 5');
        $recent = $stmt->fetchAll();

        $this->jsonSuccess(['active' => $active, 'recent' => $recent]);
    }

    protected function systemInfo(): void
    {
        $svc = $this->repo(\DevAppPro\Services\SystemInfoService::class);
        $info = [
            'php' => $svc->detectPhp(),
            'apache' => $svc->detectApache(),
            'mariadb' => $svc->detectMariadb(),
            'node' => $svc->detectNode(),
            'wpcli' => $svc->detectWpCli(),
            'mkcert' => $svc->detectMkcert(),
            'composer' => $svc->detectComposer(),
            'ssl_cert' => $svc->detectSslCert(),
            'disk' => $svc->detectDisk(),
            'cron' => $svc->detectCron(),
        ];

        $this->jsonSuccess($info);
    }

}

