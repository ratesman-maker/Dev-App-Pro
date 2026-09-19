<?php
declare(strict_types=1);

namespace DevAppPro\Controllers;

use DevAppPro\Core\ApiController;
use DevAppPro\Auth;
use DevAppPro\Repositories\BackupRestoreRepository;

/**
 * API kontroler pro zálohy.
 * GET  /api/backups         → index()  (seznam zip souborů)
 * GET  /api/backups/restores → listRestores() (seznam restore jobů)
 * POST /api/backups/restore → restore() (vytvoří restore job)
 */
class BackupsApiController extends ApiController
{
    private Auth $auth;

    public function __construct()
    {
        $this->auth = $this->repo(\DevAppPro\Auth::class);
    }

    public function handle(): void
    {
        $this->auth->requireAuth();

        $method = $this->getMethod();
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $path = trim($uri, '/');

        if ($method === 'GET' && $path === 'api/backups') {
            $this->index();
            return;
        }

        if ($method === 'GET' && $path === 'api/backups/restores') {
            $this->listRestores();
            return;
        }

        if ($method === 'POST' && $path === 'api/backups/restore') {
            require_csrf();
            $this->restore();
            return;
        }

        if ($method === 'DELETE' && preg_match('#^api/backups/restores/(\d+)$#', $path, $m)) {
            require_csrf();
            $this->deleteRestore((int) $m[1]);
            return;
        }

        if ($method === 'DELETE' && $path === 'api/backups/delete') {
            require_csrf();
            $this->deleteBackup();
            return;
        }

        $this->jsonError('Nepodporovaná HTTP metoda nebo cesta.', 405);
    }

    protected function index(): void
    {
        $dir = BACKUPS_DIR;
        if (!is_dir($dir)) {
            $this->jsonSuccess([
                'backups' => [],
                'dir' => $dir,
                'exists' => false,
            ]);
            return;
        }

        $backups = $this->scanBackups($dir);
        usort($backups, function ($a, $b) {
            return $b['modified_ts'] <=> $a['modified_ts'];
        });

        $this->jsonSuccess([
            'backups' => $backups,
            'dir' => $dir,
            'exists' => true,
            'total_size' => array_sum(array_column($backups, 'size')),
            'count' => count($backups),
        ]);
    }

    protected function listRestores(): void
    {
        $repo = new BackupRestoreRepository();
        $restores = $repo->all(1, 100);
        $this->jsonSuccess(['data' => $restores]);
    }

    protected function deleteRestore(int $id): void
    {
        $repo = new BackupRestoreRepository();
        $job = $repo->find($id);
        if ($job === null) {
            $this->jsonError('Restore job nebyl nalezen.', 404);
            return;
        }
        if (!in_array($job['status'], ['completed', 'failed'], true)) {
            $this->jsonError('Lze smazat pouze dokončené nebo selhané joby.', 400);
            return;
        }
        $repo->delete($id);
        $this->jsonSuccess(['deleted' => true]);
    }

    protected function restore(): void
    {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $backupPath = $body['backup_path'] ?? '';
        $projectName = $body['project_name'] ?? '';

        // Bezpečnost: is_safe_path() kontrola proti directory traversal a symlinkům
        if (!is_safe_path($backupPath, BACKUPS_DIR)) {
            $this->jsonError('Neplatná cesta k záloze.', 400);
            return;
        }
        if (!is_file($backupPath)) {
            $this->jsonError('Soubor neexistuje.', 400);
            return;
        }
        $ext = strtolower(pathinfo($backupPath, PATHINFO_EXTENSION));
        if (!in_array($ext, ['zip', 'daf'], true)) {
            $this->jsonError('Soubor není platná záloha (.zip nebo .daf).', 400);
            return;
        }

        $projectName = strtolower(trim($projectName));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,48}[a-z0-9]$/', $projectName)) {
            $this->jsonError('Název projektu musí mít 3–50 znaků (malá písmena, číslice, pomlčky).', 400);
            return;
        }

        $targetRoot = PROJECTS_WATCH_DIR . '/' . $projectName;
        if (is_dir($targetRoot)) {
            $this->jsonError("Složka '{$projectName}' již existuje.", 409);
            return;
        }

        $dbName = 'wp_' . str_replace('-', '_', $projectName);
        $dbUser = $dbName;
        $dbPassword = $this->generatePassword(24);

        $repo = new BackupRestoreRepository();
        $id = $repo->create([
            'backup_path' => $backupPath,
            'project_name' => $projectName,
            'site_url' => $projectName . '.localhost',
            'document_root' => $targetRoot,
            'db_name' => $dbName,
            'db_user' => $dbUser,
            'db_password' => $dbPassword,
            'status' => 'pending',
        ]);

        $record = $repo->find($id);
        $this->jsonSuccess($record, 201);
    }

    private function scanBackups(string $dir): array
    {
        $result = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if ($ext !== 'zip' && $ext !== 'daf') {
                continue;
            }

            $fullPath = $file->getPathname();
            $relativePath = ltrim(substr($fullPath, strlen($dir)), '/');

            $type = $ext === 'daf' ? 'duplicator' : 'zip';

            $result[] = [
                'name' => $file->getFilename(),
                'path' => $fullPath,
                'relative_path' => $relativePath,
                'type' => $type,
                'size' => $file->getSize(),
                'size_formatted' => $this->formatBytes($file->getSize()),
                'modified' => date('j.n.Y H:i', $file->getMTime()),
                'modified_ts' => $file->getMTime(),
            ];
        }

        return $result;
    }

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

    private function formatBytes(float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 1) . ' ' . $units[$i];
    }

    /**
     * DELETE /api/backups/delete - trvale smaže záložní soubor z disku.
     * Body: { path: string }
     */
    protected function deleteBackup(): void
    {
        $input = json_input();
        $path = $input['path'] ?? null;

        if (!is_string($path) || $path === '') {
            $this->jsonError('Cesta k záloze je povinná.', 422);
            return;
        }

        if (mb_strlen($path) > 2000) {
            $this->jsonError('Pole path je příliš dlouhé (max 2000 znaků).', 422);
            return;
        }

        // Bezpečnost: is_safe_path() kontrola proti directory traversal a symlinkům
        if (!is_safe_path($path, BACKUPS_DIR)) {
            $this->jsonError('Soubor není v adresáři záloh.', 403);
            return;
        }

        // Smazat soubor
        if (!is_file($path)) {
            $this->jsonError('Soubor neexistuje nebo není soubor.', 404);
            return;
        }

        if (!unlink($path)) {
            $this->jsonError('Soubor se nepodařilo smazat.', 500);
            return;
        }

        $this->jsonSuccess(['deleted' => true, 'path' => $path]);
    }
}
