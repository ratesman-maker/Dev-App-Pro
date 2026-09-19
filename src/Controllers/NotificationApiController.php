<?php
declare(strict_types=1);

namespace DevAppPro\Controllers;

use DevAppPro\Core\ApiController;
use DevAppPro\Auth;
use DevAppPro\Repositories\NotificationRepository;
use DevAppPro\Core\Constants;

/**
 * API kontroler pro notifikace.
 * GET    /api/notifications           → index()  (seznam s paginací)
 * GET    /api/notifications/unread-count → unreadCount()
 * PUT    /api/notifications/{id}/read → markAsRead()
 * PUT    /api/notifications/read-all  → markAllAsRead()
 * DELETE /api/notifications/{id}      → destroy()
 * DELETE /api/notifications/read       → deleteRead()
 */
class NotificationApiController extends ApiController
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
        $parts = explode('/', trim($uri, '/'));

        // /api/notifications/unread-count
        if ($method === 'GET' && isset($parts[2]) && $parts[2] === 'unread-count') {
            $this->unreadCount();
            return;
        }

        // /api/notifications/read-all
        if ($method === 'PUT' && isset($parts[2]) && $parts[2] === 'read-all') {
            $this->markAllAsRead();
            return;
        }

        // /api/notifications/read (DELETE — smazat přečtené)
        if ($method === 'DELETE' && isset($parts[2]) && $parts[2] === 'read') {
            $this->deleteRead();
            return;
        }

        // /api/notifications/{id}/read
        if ($method === 'PUT' && isset($parts[3]) && $parts[3] === 'read' && isset($parts[2]) && ctype_digit($parts[2])) {
            $this->markAsRead((int) $parts[2]);
            return;
        }

        // Standard CRUD dispatch
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            require_csrf();
        }

        switch ($method) {
            case 'GET':
                $id = $this->getId();
                if ($id !== null) {
                    $this->show($id);
                } else {
                    $this->index();
                }
                break;
            case 'DELETE':
                $id = $this->getId();
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

    private function userId(): int
    {
        return (int) ($_SESSION['user_id'] ?? 0);
    }

    protected function index(): void
    {
        $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
        $perPage = isset($_GET['per_page']) ? (int) $_GET['per_page'] : Constants::DEFAULT_PER_PAGE;
        $perPage = max(1, min(Constants::MAX_PER_PAGE, $perPage));

        $repo = $this->repo(NotificationRepository::class);
        $result = $repo->forUser($this->userId(), $page, $perPage);

        $this->jsonSuccess([
            'data' => $result['data'],
            'total' => $result['total'],
            'page' => $page,
            'per_page' => $perPage,
            'unread_count' => $repo->unreadCount($this->userId()),
        ], 200);
    }

    private function unreadCount(): void
    {
        $repo = $this->repo(NotificationRepository::class);
        $count = $repo->unreadCount($this->userId());
        $this->jsonSuccess(['unread_count' => $count], 200);
    }

    protected function show(?int $id = null): void
    {
        if ($id === null) {
            $this->jsonError('ID je vyžadováno.', 400);
            return;
        }
        $repo = $this->repo(NotificationRepository::class);
        $result = $repo->forUser($this->userId(), 1, 1);
        $notif = null;
        foreach ($result['data'] as $n) {
            if ((int) $n['id'] === $id) {
                $notif = $n;
                break;
            }
        }
        if ($notif === null) {
            $this->jsonError('Notifikace nenalezena.', 404);
            return;
        }
        $this->jsonSuccess($notif, 200);
    }

    private function markAsRead(int $id): void
    {
        $repo = $this->repo(NotificationRepository::class);
        $ok = $repo->markAsRead($id, $this->userId());
        if (!$ok) {
            $this->jsonError('Notifikace nenalezena.', 404);
            return;
        }
        $this->jsonSuccess(['ok' => true], 200);
    }

    private function markAllAsRead(): void
    {
        $repo = $this->repo(NotificationRepository::class);
        $count = $repo->markAllAsRead($this->userId());
        $this->jsonSuccess(['marked' => $count], 200);
    }

    private function deleteRead(): void
    {
        $repo = $this->repo(NotificationRepository::class);
        $count = $repo->deleteReadForUser($this->userId());
        $this->jsonSuccess(['deleted' => $count], 200);
    }

    protected function destroy(int $id): void
    {
        $repo = $this->repo(NotificationRepository::class);
        $ok = $repo->deleteForUser($id, $this->userId());
        if (!$ok) {
            $this->jsonError('Notifikace nenalezena.', 404);
            return;
        }
        http_response_code(204);
    }
}
