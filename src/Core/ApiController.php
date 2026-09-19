<?php
declare(strict_types=1);

namespace DevAppPro\Core;

/**
 * Abstraktní základní třída pro API kontrolery.
 * Poskytuje dispatch podle HTTP metody a společné helpery.
 */
abstract class ApiController
{
    /**
     * Hlavní metoda - dispatchuje podle HTTP metody.
     * GET → index/show, POST → store, PUT → update, DELETE → destroy.
     * Override v subclass pro vlastní routování.
     */
    public function handle(): void
    {
        $method = $this->getMethod();
        $id = $this->getId();

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
     * Wrapper pro json_response - úspěšná odpověď.
     */
    protected function jsonSuccess($data, int $status = 200): void
    {
        json_response($data, $status);
    }

    /**
     * Wrapper pro error response.
     */
    protected function jsonError(string $msg, int $status): void
    {
        json_response(['error' => $msg], $status);
    }

    /**
     * Pokud není uživatel přihlášen → 401 a die.
     */
    protected function requireAuth(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['user_id'])) {
            $this->jsonError('Neautorizováno.', 401);
            die;
        }
    }

    /**
     * Vrátí HTTP metodu (velká písmena).
     */
    protected function getMethod(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /**
     * Vrátí ID z URL (např. /api/clients/5 → 5).
     * Vrací null pokud URL neobsahuje ID.
     */
    protected function getId(): ?int
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $parts = explode('/', trim($uri, '/'));
        // Najde poslední část, která je číslo
        $last = end($parts);
        if ($last !== false && ctype_digit($last)) {
            return (int) $last;
        }
        return null;
    }

    /**
     * Vrátí instanci z DI kontejneru.
     * Nahrazuje `new XxxRepository()` volání.
     *
     * @template T
     * @param class-string<T> $type
     * @return T
     */
    protected function repo(string $type): object
    {
        return Container::getInstance()->get($type);
    }

    // Metody pro override v subclass
    protected function index(): void
    {
        $this->jsonError('Není implementováno.', 404);
    }

    protected function show(?int $id = null): void
    {
        $this->jsonError('Není implementováno.', 404);
    }

    protected function store(): void
    {
        $this->jsonError('Není implementováno.', 404);
    }

    protected function update(int $id): void
    {
        $this->jsonError('Není implementováno.', 404);
    }

    protected function destroy(int $id): void
    {
        $this->jsonError('Není implementováno.', 404);
    }
}
