<?php
declare(strict_types=1);

namespace DevAppPro\Services;

use DevAppPro\Repositories\NotificationRepository;
use DevAppPro\Repositories\SettingsRepository;

/**
 * Služba pro vytváření in-app notifikací.
 *
 * Notifikace se vytvářejí:
 * - Při CRUD akcích (úkoly, faktury, platby, transakce)
 * - Při termínových připomínkách (cron: X dní před, po termínu)
 *
 * Respektuje nastavení z DB tabulky settings (notif_* klíče).
 */
class NotificationService
{
    private NotificationRepository $notifications;
    private SettingsRepository $settings;

    public function __construct(NotificationRepository $notifications)
    {
        $this->notifications = $notifications;
        $this->settings = new SettingsRepository();
    }

    /**
     * Zkontroluje, zda je daný typ notifikace povolen v nastavení.
     */
    private function isEnabled(string $type): bool
    {
        $key = 'notif_' . $type;
        $value = $this->settings->get($key);
        return $value === null || $value === '1';
    }

    /**
     * Vytvoří notifikaci (s deduplikací pro termínové typy).
     */
    public function create(
        int $userId,
        string $type,
        string $title,
        ?string $message = null,
        ?string $entityType = null,
        ?int $entityId = null,
        bool $deduplicate = false
    ): void {
        // Kontrola, zda je typ notifikace povolen v nastavení
        if (!$this->isEnabled($type)) {
            return;
        }

        // Deduplikace pro termínové notifikace (nezáleží na tom, kolikrát cron běží)
        if ($deduplicate && $entityType !== null && $entityId !== null) {
            if ($this->notifications->exists($userId, $type, $entityType, $entityId)) {
                return;
            }
        }

        $this->notifications->create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'is_read' => 0,
        ]);
    }

    // --- Úkoly ---

    public function taskCreated(int $userId, int $taskId, string $title): void
    {
        $this->create($userId, 'task_created', "Nový úkol: {$title}", null, 'task', $taskId);
    }

    public function taskUpdated(int $userId, int $taskId, string $title): void
    {
        $this->create($userId, 'task_updated', "Úkol upraven: {$title}", null, 'task', $taskId);
    }

    public function taskDeleted(int $userId, string $title): void
    {
        $this->create($userId, 'task_deleted', "Úkol smazán: {$title}");
    }

    public function taskDeadlineSoon(int $userId, int $taskId, string $title, string $dueDate): void
    {
        $days = (int) ($this->settings->get('notif_days_before') ?? '2');
        $this->create(
            $userId,
            'task_deadline_soon',
            "Úkol „{$title}“ má termín za {$days} " . $this->sklonDni($days) . " ({$dueDate})",
            "Termín úkolu se blíží. Zkontrolujte stav a dokončete včas.",
            'task',
            $taskId,
            true
        );
    }

    public function taskOverdue(int $userId, int $taskId, string $title, string $dueDate): void
    {
        $this->create(
            $userId,
            'task_overdue',
            "Úkol „{$title}“ je po termínu ({$dueDate})",
            "Úkol překročil termín splnění. Zkontrolujte a upravte stav nebo termín.",
            'task',
            $taskId,
            true
        );
    }

    // --- Faktury ---

    public function invoiceCreated(int $userId, int $invoiceId, string $invoiceNumber): void
    {
        $this->create($userId, 'invoice_created', "Nová faktura: {$invoiceNumber}", null, 'invoice', $invoiceId);
    }

    public function invoiceUpdated(int $userId, int $invoiceId, string $invoiceNumber): void
    {
        $this->create($userId, 'invoice_updated', "Faktura upravena: {$invoiceNumber}", null, 'invoice', $invoiceId);
    }

    public function invoiceDeleted(int $userId, string $invoiceNumber): void
    {
        $this->create($userId, 'invoice_deleted', "Faktura smazána: {$invoiceNumber}");
    }

    public function invoiceOverdue(int $userId, int $invoiceId, string $invoiceNumber, string $dueDate): void
    {
        $this->create(
            $userId,
            'invoice_overdue',
            "Faktura {$invoiceNumber} je po splatnosti ({$dueDate})",
            "Faktura překročila datum splatnosti. Vyzvěte klienta k úhradě.",
            'invoice',
            $invoiceId,
            true
        );
    }

    public function invoiceDueSoon(int $userId, int $invoiceId, string $invoiceNumber, string $dueDate): void
    {
        $days = (int) ($this->settings->get('notif_days_before') ?? '2');
        $this->create(
            $userId,
            'invoice_due_soon',
            "Faktuře {$invoiceNumber} končí splatnost za {$days} " . $this->sklonDni($days) . " ({$dueDate})",
            "Splatnost faktury se blíží. Připomeňte klientovi úhradu.",
            'invoice',
            $invoiceId,
            true
        );
    }

    // --- Platby ---

    public function paymentCreated(int $userId, int $paymentId, string $invoiceNumber, string|int $amount): void
    {
        $this->create(
            $userId,
            'payment_created',
            "Platba {$amount} přidána (faktura {$invoiceNumber})",
            null,
            'invoice_payment',
            $paymentId
        );
    }

    public function paymentUpdated(int $userId, int $paymentId, string $invoiceNumber): void
    {
        $this->create($userId, 'payment_updated', "Platba upravena (faktura {$invoiceNumber})", null, 'invoice_payment', $paymentId);
    }

    public function paymentDeleted(int $userId, string $invoiceNumber): void
    {
        $this->create($userId, 'payment_deleted', "Platba smazána (faktura {$invoiceNumber})");
    }

    // --- Transakce ---

    public function transactionCreated(int $userId, int $transactionId, string $description, string $type): void
    {
        $label = $type === 'income' ? 'Příjem' : 'Výdaj';
        $this->create(
            $userId,
            'transaction_created',
            "{$label}: {$description}",
            null,
            'transaction',
            $transactionId
        );
    }

    public function transactionUpdated(int $userId, int $transactionId, string $description): void
    {
        $this->create($userId, 'transaction_updated', "Transakce upravena: {$description}", null, 'transaction', $transactionId);
    }

    public function transactionDeleted(int $userId, string $description): void
    {
        $this->create($userId, 'transaction_deleted', "Transakce smazána: {$description}");
    }

    // --- Pomocné metody ---

    /**
     * České skloňování "den/dny/dní".
     */
    private function sklonDni(int $days): string
    {
        if ($days === 1) {
            return 'den';
        }
        if ($days >= 2 && $days <= 4) {
            return 'dny';
        }
        return 'dní';
    }
}
