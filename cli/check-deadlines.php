<?php
declare(strict_types=1);

/**
 * CLI skript pro kontrolu termínů úkolů a faktur.
 * Vytváří notifikace:
 * - X dní před termínem úkolu (task_deadline_soon) — dle nastavení notif_days_before
 * - úkol po termínu (task_overdue) — jednorázově
 * - X dní před splatností faktury (invoice_due_soon)
 * - faktura po splatnosti (invoice_overdue) — jednorázově
 *
 * Respektuje nastavení z DB tabulky settings (notif_* klíče).
 *
 * Použití: php check-deadlines.php
 * Cron: každou minutu
 */

require_once __DIR__ . '/../bootstrap.php';

use DevAppPro\Services\NotificationService;
use DevAppPro\Repositories\NotificationRepository;
use DevAppPro\Repositories\SettingsRepository;

$pdo = db();
$notifService = new NotificationService(new NotificationRepository());
$settingsRepo = new SettingsRepository();

// Načíst nastavení notifikací
$daysBefore = (int) ($settingsRepo->get('notif_days_before') ?? '2');
$taskDeadlineSoon = ($settingsRepo->get('notif_task_deadline_soon') ?? '1') === '1';
$taskOverdue = ($settingsRepo->get('notif_task_overdue') ?? '1') === '1';
$invoiceDueSoon = ($settingsRepo->get('notif_invoice_due_soon') ?? '1') === '1';
$invoiceOverdue = ($settingsRepo->get('notif_invoice_overdue') ?? '1') === '1';

// Načíst všechny uživatele (notifikace pro každého)
$users = $pdo->query('SELECT id FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);

$today = date('Y-m-d');
$daysAhead = date('Y-m-d', strtotime("+{$daysBefore} days"));
$yesterday = date('Y-m-d', strtotime('-1 day'));

echo date('Y-m-d H:i:s') . " Kontrola termínů (dnes=$today, +{$daysBefore}dny=$daysAhead, včera=$yesterday)\n";

$total = 0;

// --- Úkoly: X dní před termínem ---
if ($taskDeadlineSoon) {
    $stmt = $pdo->prepare("
        SELECT id, title, due_date, status 
        FROM tasks 
        WHERE due_date = ? 
          AND status NOT IN ('done', 'cancelled')
    ");
    $stmt->execute([$daysAhead]);
    $tasksSoon = $stmt->fetchAll();

    foreach ($tasksSoon as $task) {
        foreach ($users as $userId) {
            $notifService->taskDeadlineSoon((int) $userId, (int) $task['id'], $task['title'], $task['due_date']);
        }
        echo "  Úkol #{$task['id']} „{$task['title']}“ — termín za {$daysBefore} dní\n";
    }
    $total += count($tasksSoon);
}

// --- Úkoly: po termínu (včera) ---
if ($taskOverdue) {
    $stmt = $pdo->prepare("
        SELECT id, title, due_date, status 
        FROM tasks 
        WHERE due_date = ? 
          AND status NOT IN ('done', 'cancelled')
    ");
    $stmt->execute([$yesterday]);
    $tasksOverdue = $stmt->fetchAll();

    foreach ($tasksOverdue as $task) {
        foreach ($users as $userId) {
            $notifService->taskOverdue((int) $userId, (int) $task['id'], $task['title'], $task['due_date']);
        }
        echo "  Úkol #{$task['id']} „{$task['title']}“ — po termínu\n";
    }
    $total += count($tasksOverdue);
}

// --- Faktury: X dní před splatností ---
if ($invoiceDueSoon) {
    $stmt = $pdo->prepare("
        SELECT id, invoice_number, due_date, status 
        FROM invoices 
        WHERE due_date = ? 
          AND status IN ('sent', 'overdue')
    ");
    $stmt->execute([$daysAhead]);
    $invoicesSoon = $stmt->fetchAll();

    foreach ($invoicesSoon as $invoice) {
        foreach ($users as $userId) {
            $notifService->invoiceDueSoon((int) $userId, (int) $invoice['id'], $invoice['invoice_number'], $invoice['due_date']);
        }
        echo "  Faktura #{$invoice['id']} {$invoice['invoice_number']} — splatnost za {$daysBefore} dní\n";
    }
    $total += count($invoicesSoon);
}

// --- Faktury: po splatnosti (včera) ---
if ($invoiceOverdue) {
    $stmt = $pdo->prepare("
        SELECT id, invoice_number, due_date, status 
        FROM invoices 
        WHERE due_date = ? 
          AND status IN ('sent', 'overdue')
    ");
    $stmt->execute([$yesterday]);
    $invoicesOverdue = $stmt->fetchAll();

    foreach ($invoicesOverdue as $invoice) {
        foreach ($users as $userId) {
            $notifService->invoiceOverdue((int) $userId, (int) $invoice['id'], $invoice['invoice_number'], $invoice['due_date']);
        }
        echo "  Faktura #{$invoice['id']} {$invoice['invoice_number']} — po splatnosti\n";
    }
    $total += count($invoicesOverdue);
}

echo "Hotovo. Vytvořeno $total notifikací.\n";
