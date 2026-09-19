<?php
declare(strict_types=1);

/**
 * Cron skript: smaže staré login_attempts starší než 24 hodin.
 * Spouští se denně přes cron.
 *
 * Použití v cronu:
 * 0 3 * * * ratesman /usr/bin/php /var/www/devapppro/cli/cleanup-login-attempts.php >> /var/log/devapppro-cleanup.log 2>&1
 */

require_once __DIR__ . '/../bootstrap.php';

$pdo = db();

// Smaže záznamy starší 24 hodin
$stmt = $pdo->prepare("DELETE FROM `login_attempts` WHERE `attempted_at` < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
$stmt->execute();

$deleted = $stmt->rowCount();
echo date('Y-m-d H:i:s') . " - Smazáno {$deleted} starých login_attempts záznamů." . PHP_EOL;
