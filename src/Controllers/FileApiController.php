<?php
declare(strict_types=1);

namespace DevAppPro\Controllers;

use DevAppPro\Core\ApiController;
use DevAppPro\Core\Constants;
use DevAppPro\Auth;
use DevAppPro\Repositories\FileRepository;
use DevAppPro\Services\ImageService;

/**
 * API kontroler pro soubory (upload, download, CRUD attachments).
 * GET    /api/files             → index()
 * GET    /api/files/{id}        → show()
 * GET    /api/files/{id}/download → download()
 * POST   /api/files             → store()  (multipart/form-data)
 * PUT    /api/files/{id}         → update() (jen attachments)
 * DELETE /api/files/{id}         → destroy()
 */
class FileApiController extends ApiController
{
    private Auth $auth;

    /** Adresář pro uložení souborů. */
    private string $storageDir;

    /** Povolené MIME typy. */
    private const ALLOWED_MIME = [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
        'image/svg+xml',
        'text/plain',
        'text/csv',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',
    ];

    /** Povolené přípony. */
    private const ALLOWED_EXT = [
        'pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg',
        'txt', 'csv', 'docx', 'xlsx', 'zip',
    ];

    /** Zakázané přípony. */
    private const FORBIDDEN_EXT = [
        'exe', 'sh', 'php', 'bat', 'cmd', 'js', 'html', 'htaccess',
    ];

    /** Obrázkové přípony (is_image = 1). */
    private const IMAGE_EXT = ['png', 'jpg', 'jpeg', 'webp', 'gif'];

    /** Mapování přípona → očekávané MIME typy (cross-check). */
    private const EXT_MIME_MAP = [
        'pdf'  => ['application/pdf'],
        'png'  => ['image/png'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'svg'  => ['image/svg+xml', 'text/html'],
        'txt'  => ['text/plain'],
        'csv'  => ['text/csv', 'text/plain', 'application/csv'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'zip'  => ['application/zip'],
    ];

    /** Maximální velikost souboru (10 MB). */
    private const MAX_SIZE = 10485760;

    public function __construct()
    {
        $this->auth = $this->repo(\DevAppPro\Auth::class);
        $this->storageDir = dirname(__DIR__, 2) . '/storage';
    }

    /**
     * Dispatchuje podle HTTP metody a URI.
     * Vše vyžaduje auth, POST/PUT/DELETE vyžadují CSRF.
     */
    public function handle(): void
    {
        $this->auth->requireAuth();

        $method = $this->getMethod();
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        // Detekce /download akce: /api/files/{id}/download
        if ($method === 'GET' && preg_match('#^/api/files/(\d+)/download$#', $uri, $m)) {
            $this->download((int) $m[1]);
            return;
        }

        // Detekce /thumbnail akce: /api/files/{id}/thumbnail
        if ($method === 'GET' && preg_match('#^/api/files/(\d+)/thumbnail$#', $uri, $m)) {
            $this->serveImageVariant((int) $m[1], 'thumbnail');
            return;
        }

        // Detekce /medium akce: /api/files/{id}/medium
        if ($method === 'GET' && preg_match('#^/api/files/(\d+)/medium$#', $uri, $m)) {
            $this->serveImageVariant((int) $m[1], 'medium');
            return;
        }

        $id = $this->getId();

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
     * GET /api/files - seznam souborů s paginací a filtrem podle entity.
     */
    protected function index(): void
    {
        $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
        $perPage = isset($_GET['per_page']) ? (int) $_GET['per_page'] : Constants::DEFAULT_PER_PAGE;
        $perPage = max(1, min(Constants::MAX_PER_PAGE, $perPage));
        $entityType = isset($_GET['entity_type']) ? (string) $_GET['entity_type'] : null;
        $entityId = isset($_GET['entity_id']) ? (int) $_GET['entity_id'] : null;

        if ($entityType === '') {
            $entityType = null;
        }
        if ($entityId === 0) {
            $entityId = null;
        }

        $repo = $this->repo(\DevAppPro\Repositories\FileRepository::class);
        $result = $repo->all($page, $perPage, $entityType, $entityId);

        $this->jsonSuccess([
            'data'  => $result['data'],
            'total' => $result['total'],
        ], 200);
    }

    /**
     * GET /api/files/{id} - detail souboru.
     */
    protected function show(?int $id = null): void
    {
        if ($id === null) {
            $this->jsonError('ID je vyžadováno.', 400);
            return;
        }

        $repo = $this->repo(\DevAppPro\Repositories\FileRepository::class);
        $file = $repo->find($id);

        if ($file === null) {
            $this->jsonError('Soubor nenalezen.', 404);
            return;
        }

        $this->jsonSuccess($file, 200);
    }

    /**
     * GET /api/files/{id}/download - stažení binárních dat.
     */
    protected function download(int $id): void
    {
        $repo = $this->repo(\DevAppPro\Repositories\FileRepository::class);
        $file = $repo->find($id);

        if ($file === null) {
            $this->jsonError('Soubor nenalezen.', 404);
            return;
        }

        $fullPath = $this->storageDir . '/' . $file['storage_path'];

        // Bezpečnost: is_safe_path() kontrola proti directory traversal a symlinkům
        if (!is_safe_path($fullPath, $this->storageDir)) {
            $this->jsonError('Přístup odepřen.', 403);
            return;
        }
        if (!is_file($fullPath)) {
            $this->jsonError('Soubor neexistuje na disku.', 404);
            return;
        }

        header('Content-Type: ' . $file['mime_type']);
        header('Content-Disposition: attachment; filename="' . $file['original_name'] . '"');
        header('Content-Length: ' . $file['size_bytes']);
        readfile($fullPath);
    }

    /**
     * GET /api/files/{id}/thumbnail nebo /api/files/{id}/medium
     * Slouží WebP náhled obrázku (200x200 nebo 800x800).
     */
    protected function serveImageVariant(int $id, string $variant): void
    {
        $repo = $this->repo(\DevAppPro\Repositories\FileRepository::class);
        $file = $repo->find($id);

        if ($file === null) {
            $this->jsonError('Soubor nenalezen.', 404);
            return;
        }

        $pathField = $variant === 'thumbnail' ? 'thumbnail_path' : 'medium_path';
        $relPath = $file[$pathField] ?? null;

        if ($relPath === null || $relPath === '') {
            // Fallback na originál
            $relPath = $file['storage_path'];
        }

        $fullPath = $this->storageDir . '/' . $relPath;

        // Bezpečnost: is_safe_path() kontrola proti directory traversal a symlinkům
        if (!is_safe_path($fullPath, $this->storageDir)) {
            $this->jsonError('Soubor neexistuje.', 404);
            return;
        }
        if (!is_file($fullPath)) {
            $this->jsonError('Soubor neexistuje na disku.', 404);
            return;
        }

        // Náhledy jsou WebP, originál může být jiný formát
        $mime = ($variant === 'thumbnail' || $variant === 'medium') && $file[$pathField]
            ? 'image/webp'
            : $file['mime_type'];

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($fullPath));
        header('Cache-Control: public, max-age=31536000, immutable');
        readfile($fullPath);
    }

    /**
     * POST /api/files - upload souboru (multipart/form-data).
     */
    protected function store(): void
    {
        if (!isset($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
            json_response(['error' => 'Validace selhala.', 'fields' => ['file' => 'Soubor je povinný.']], 422);
            return;
        }

        $upload = $_FILES['file'];
        if ($upload['error'] !== UPLOAD_ERR_OK) {
            $msg = $upload['error'] === UPLOAD_ERR_INI_SIZE || $upload['error'] === UPLOAD_ERR_FORM_SIZE
                ? 'Soubor je příliš velký (max 10 MB).'
                : 'Nahrávání souboru selhalo.';
            json_response(['error' => 'Validace selhala.', 'fields' => ['file' => $msg]], 422);
            return;
        }

        $originalName = (string) $upload['name'];
        $tmpPath = (string) $upload['tmp_name'];
        $size = (int) $upload['size'];
        $declaredMime = (string) $upload['type'];

        // Validace velikosti
        if ($size > self::MAX_SIZE) {
            json_response(['error' => 'Validace selhala.', 'fields' => ['file' => 'Soubor je příliš velký (max 10 MB).']], 422);
            return;
        }

        // Validace přípony
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (in_array($ext, self::FORBIDDEN_EXT, true)) {
            json_response(['error' => 'Validace selhala.', 'fields' => ['file' => 'Tento typ souboru není povolen.']], 422);
            return;
        }
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            json_response(['error' => 'Validace selhala.', 'fields' => ['file' => 'Nepodporovaný typ souboru.']], 422);
            return;
        }

        // Kontrola skutečného MIME typu obsahu
        $realMime = mime_content_type($tmpPath);
        if ($realMime === false) {
            $realMime = $declaredMime;
        }
        if (!in_array($realMime, self::ALLOWED_MIME, true)) {
            json_response(['error' => 'Validace selhala.', 'fields' => ['file' => 'Nepodporovaný typ souboru.']], 422);
            return;
        }

        // Cross-check: MIME typ musí odpovídat příponě
        $expectedMimes = self::EXT_MIME_MAP[$ext] ?? [];
        if (!empty($expectedMimes) && !in_array($realMime, $expectedMimes, true)) {
            json_response(['error' => 'Validace selhala.', 'fields' => ['file' => 'Přípona neodpovídá typu souboru.']], 422);
            return;
        }

        // Sanitizace SVG (odstranění script tagů a event handlerů)
        if ($ext === 'svg' && $realMime === 'image/svg+xml') {
            $svgContent = file_get_contents($tmpPath);
            if ($svgContent !== false) {
                $sanitized = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $svgContent);
                $sanitized = preg_replace('/\son\w+\s*=\s*"[^"]*"/i', '', $sanitized);
                $sanitized = preg_replace("/\son\w+\s*=\s*'[^']*'/i", '', $sanitized);
                $sanitized = preg_replace('/javascript:/i', '', $sanitized);
                if ($sanitized !== null && $sanitized !== $svgContent) {
                    file_put_contents($tmpPath, $sanitized);
                }
            }
        }

        // Uložení na disk: storage/{YYYY}/{MM}/{uuid}.{ext}
        $year = date('Y');
        $month = date('m');
        $relativeDir = $year . '/' . $month;
        $absDir = $this->storageDir . '/' . $relativeDir;
        if (!is_dir($absDir)) {
            mkdir($absDir, 0750, true);
        }

        $uuid = $this->uuid();
        $storedName = $uuid . '.' . $ext;
        $storagePath = $relativeDir . '/' . $storedName;
        $absPath = $absDir . '/' . $storedName;

        if (!move_uploaded_file($tmpPath, $absPath)) {
            // Fallback pro testovací server (tmp soubor vytvořený Guzzle)
            if (!copy($tmpPath, $absPath)) {
                json_response(['error' => 'Uložení souboru selhalo.'], 500);
                return;
            }
        }

        $isImage = in_array($ext, self::IMAGE_EXT, true) ? 1 : 0;

        // Generování náhledů + JPG→WebP konverze pro obrázky
        $thumbnailPath = null;
        $mediumPath = null;
        $finalStoragePath = $storagePath;
        $finalStoredName = $storedName;
        $finalMime = $realMime;
        $finalSize = $size;

        if ($isImage) {
            $imageService = $this->repo(\DevAppPro\Services\ImageService::class);
            $imgResult = $imageService->processImage($absPath, $storagePath, $storedName, $ext, $realMime);
            $thumbnailPath = $imgResult['thumbnail_path'];
            $mediumPath = $imgResult['medium_path'];
            $finalStoragePath = $imgResult['storage_path'];
            $finalStoredName = $imgResult['stored_name'];
            $finalMime = $imgResult['mime_type'];
            $finalSize = $imgResult['size_bytes'] ?? $size;
        }

        // Zpracování attachments (JSON string z multipart)
        $attachmentsRaw = $_POST['attachments'] ?? '[]';
        $attachments = $this->parseAttachments($attachmentsRaw);
        if ($attachments === null) {
            // Neplatné attachments
            @unlink($absPath);
            json_response(['error' => 'Validace selhala.', 'fields' => ['attachments' => 'Přílohy musí být platné pole.']], 422);
            return;
        }

        $userId = $_SESSION['user_id'] ?? null;

        $repo = $this->repo(\DevAppPro\Repositories\FileRepository::class);
        $id = $repo->create([
            'user_id'        => $userId,
            'original_name'  => $originalName,
            'stored_name'    => $finalStoredName,
            'mime_type'      => $finalMime,
            'size_bytes'     => $finalSize,
            'storage_path'   => $finalStoragePath,
            'is_image'       => $isImage,
            'thumbnail_path' => $thumbnailPath,
            'medium_path'    => $mediumPath,
        ], $attachments);

        $file = $repo->find($id);
        $this->jsonSuccess($file, 201);
    }

    /**
     * PUT /api/files/{id} - aktualizace attachments.
     */
    protected function update(int $id): void
    {
        $repo = $this->repo(\DevAppPro\Repositories\FileRepository::class);

        $existing = $repo->find($id);
        if ($existing === null) {
            $this->jsonError('Soubor nenalezen.', 404);
            return;
        }

        $input = json_input();
        $attachmentsRaw = $input['attachments'] ?? [];
        if (!is_array($attachmentsRaw)) {
            json_response(['error' => 'Validace selhala.', 'fields' => ['attachments' => 'Přílohy musí být pole.']], 422);
            return;
        }

        $attachments = $this->validateAttachments($attachmentsRaw);
        if ($attachments === null) {
            json_response(['error' => 'Validace selhala.', 'fields' => ['attachments' => 'Neplatné přílohy.']], 422);
            return;
        }

        $repo->update($id, $attachments);
        $file = $repo->find($id);

        $this->jsonSuccess($file, 200);
    }

    /**
     * DELETE /api/files/{id} - smazání DB záznamu + fyzického souboru.
     */
    protected function destroy(int $id): void
    {
        $repo = $this->repo(\DevAppPro\Repositories\FileRepository::class);

        $existing = $repo->find($id);
        if ($existing === null) {
            $this->jsonError('Soubor nenalezen.', 404);
            return;
        }

        // Smazání fyzického souboru + thumbnail + medium
        $this->deletePhysical($existing['storage_path'] ?? null);
        $this->deletePhysical($existing['thumbnail_path'] ?? null);
        $this->deletePhysical($existing['medium_path'] ?? null);

        $repo->delete($id);

        http_response_code(204);
    }

    /**
     * Smaže fyzický soubor relativní cesty vůči storage dir.
     */
    private function deletePhysical(?string $relativePath): void
    {
        if ($relativePath === null || $relativePath === '') {
            return;
        }
        $abs = $this->storageDir . '/' . $relativePath;
        if (is_file($abs)) {
            @unlink($abs);
        }
    }

    /**
     * Parsuje attachments z JSON stringu (multipart) a validuje.
     * @return array|null pole platných attachments nebo null při chybě.
     */
    private function parseAttachments(string $json): ?array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        return $this->validateAttachments($data);
    }

    /**
     * Validuje pole attachments [{entity_type, entity_id}].
     * @return array|null pole platných attachments nebo null při chybě.
     */
    private function validateAttachments(array $attachments): ?array
    {
        $validTypes = ['client', 'project', 'task', 'invoice', 'transaction'];
        $repo = $this->repo(\DevAppPro\Repositories\FileRepository::class);
        $result = [];
        foreach ($attachments as $att) {
            if (!is_array($att)) {
                return null;
            }
            $type = $att['entity_type'] ?? null;
            $eid = $att['entity_id'] ?? null;
            if (!is_string($type) || !in_array($type, $validTypes, true)) {
                return null;
            }
            if ($eid === null || !is_numeric($eid)) {
                return null;
            }
            $eid = (int) $eid;
            if (!$repo->entityExists($type, $eid)) {
                return null;
            }
            $result[] = ['entity_type' => $type, 'entity_id' => $eid];
        }
        return $result;
    }

    /**
     * Vygeneruje UUID v4.
     */
    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
