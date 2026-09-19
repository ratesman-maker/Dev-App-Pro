<?php
declare(strict_types=1);

namespace DevAppPro\Core;

/**
 * Jednoduchý DI kontejner pro resoluci závislostí.
 * Podporuje registraci factory funkcí a singleton instancí.
 *
 * Použití:
 *   $container = Container::getInstance();
 *   $container->set(ClientRepository::class, fn() => new ClientRepository());
 *   $repo = $container->get(ClientRepository::class);
 */
class Container
{
    private static ?Container $instance = null;

    /** @var array<string, callable> */
    private array $factories = [];

    /** @var array<string, object> */
    private array $singletons = [];

    /** @var array<string, bool> */
    private array $isSingleton = [];

    private function __construct()
    {
        $this->registerDefaults();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Zaregistruje factory pro daný typ.
     */
    public function set(string $type, callable $factory, bool $singleton = false): void
    {
        $this->factories[$type] = $factory;
        $this->isSingleton[$type] = $singleton;
    }

    /**
     * Získá instanci daného typu.
     */
    public function get(string $type): object
    {
        // Pokud je singleton a už existuje, vrať ji
        if (isset($this->singletons[$type])) {
            return $this->singletons[$type];
        }

        if (!isset($this->factories[$type])) {
            // Auto-wiring: pokus o vytvoření bez parametrů
            if (class_exists($type)) {
                $instance = new $type();
            } else {
                throw new \RuntimeException("Typ '{$type}' není registrován v DI kontejneru.");
            }
        } else {
            $instance = ($this->factories[$type])();
        }

        if ($this->isSingleton[$type] ?? false) {
            $this->singletons[$type] = $instance;
        }

        return $instance;
    }

    /**
     * Registrace výchozích závislostí.
     */
    private function registerDefaults(): void
    {
        // Repositories (nové instance pokaždé - ne singleton, protože drží stav)
        $this->set(\DevAppPro\Repositories\ClientRepository::class, fn() => new \DevAppPro\Repositories\ClientRepository());
        $this->set(\DevAppPro\Repositories\ProjectRepository::class, fn() => new \DevAppPro\Repositories\ProjectRepository());
        $this->set(\DevAppPro\Repositories\TaskRepository::class, fn() => new \DevAppPro\Repositories\TaskRepository());
        $this->set(\DevAppPro\Repositories\InvoiceRepository::class, fn() => new \DevAppPro\Repositories\InvoiceRepository());
        $this->set(\DevAppPro\Repositories\InvoicePaymentRepository::class, fn() => new \DevAppPro\Repositories\InvoicePaymentRepository());
        $this->set(\DevAppPro\Repositories\TransactionRepository::class, fn() => new \DevAppPro\Repositories\TransactionRepository());
        $this->set(\DevAppPro\Repositories\NoteRepository::class, fn() => new \DevAppPro\Repositories\NoteRepository());
        $this->set(\DevAppPro\Repositories\FileRepository::class, fn() => new \DevAppPro\Repositories\FileRepository());
        $this->set(\DevAppPro\Repositories\WorklogRepository::class, fn() => new \DevAppPro\Repositories\WorklogRepository());
        $this->set(\DevAppPro\Repositories\SettingsRepository::class, fn() => new \DevAppPro\Repositories\SettingsRepository(), true);
        $this->set(\DevAppPro\Repositories\ProjectCredentialRepository::class, fn() => new \DevAppPro\Repositories\ProjectCredentialRepository());
        $this->set(\DevAppPro\Repositories\WpInstallRepository::class, fn() => new \DevAppPro\Repositories\WpInstallRepository());
        $this->set(\DevAppPro\Repositories\CompanyProfileRepository::class, fn() => new \DevAppPro\Repositories\CompanyProfileRepository());
        $this->set(\DevAppPro\Repositories\UserRepository::class, fn() => new \DevAppPro\Repositories\UserRepository());
        $this->set(\DevAppPro\Repositories\NotificationRepository::class, fn() => new \DevAppPro\Repositories\NotificationRepository());

        // Services
        $this->set(\DevAppPro\Services\ImageService::class, fn() => new \DevAppPro\Services\ImageService());
        $this->set(\DevAppPro\Services\InvoicePdfService::class, fn() => new \DevAppPro\Services\InvoicePdfService());
        $this->set(\DevAppPro\Services\CryptoService::class, fn() => new \DevAppPro\Services\CryptoService(), true);
        $this->set(\DevAppPro\Services\SystemInfoService::class, fn() => new \DevAppPro\Services\SystemInfoService());
        $this->set(\DevAppPro\Services\NotificationService::class, fn() => new \DevAppPro\Services\NotificationService(new \DevAppPro\Repositories\NotificationRepository()));

        // Auth (singleton - drží session stav)
        $this->set(\DevAppPro\Auth::class, fn() => new \DevAppPro\Auth(), true);
    }
}
