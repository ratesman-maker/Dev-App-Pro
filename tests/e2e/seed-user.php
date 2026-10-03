<?php
declare(strict_types=1);

// Seed E2E uživatele do testovací databáze.
// Spouští tests/e2e/serve.sh před php -S serverem pro Playwright.

$dbConfig = require __DIR__ . '/../../config/database.php';
if (getenv('DB_HOST')) $dbConfig['host'] = getenv('DB_HOST');
if (getenv('DB_NAME')) $dbConfig['dbname'] = getenv('DB_NAME');
if (getenv('DB_USER')) $dbConfig['username'] = getenv('DB_USER');
if (getenv('DB_PASS')) $dbConfig['password'] = getenv('DB_PASS');

// Pojistka: nikdy neseedovat produkční databázi.
if (!str_ends_with($dbConfig['dbname'], '_test')) {
    fwrite(STDERR, "seed-user: databáze '{$dbConfig['dbname']}' není testovací (musí končit _test).\n");
    exit(1);
}

$username = getenv('E2E_USERNAME') ?: 'e2e_admin';
$password = getenv('E2E_PASSWORD') ?: 'e2e_test_heslo_123';

$pdo = new PDO(
    "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['dbname']};charset=utf8mb4",
    $dbConfig['username'],
    $dbConfig['password'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

// Test DB se před PHPUnit běhy resetuje — když chybí tabulky, založit schéma.
$hasUsers = $pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
if (!$hasUsers) {
    $schema = file_get_contents(__DIR__ . '/../../database/schema.sql');
    if ($schema === false) {
        fwrite(STDERR, "seed-user: database/schema.sql nejde přečíst.\n");
        exit(1);
    }
    $pdo->exec($schema);
    echo "seed-user: schéma načteno z database/schema.sql.\n";
}

$hash = password_hash($password, PASSWORD_BCRYPT);
$stmt = $pdo->prepare(
    'INSERT INTO users (username, name, password_hash, email)
     VALUES (:username, :name, :hash, :email)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)'
);
$stmt->execute([
    'username' => $username,
    'name'     => 'E2E Test',
    'hash'     => $hash,
    'email'    => 'e2e@test.local',
]);

// Reset rate limitu, aby opakované běhy neskončily lockoutem.
$pdo->prepare('DELETE FROM login_attempts WHERE username = :u')->execute(['u' => $username]);

echo "seed-user: uživatel '{$username}' připraven v {$dbConfig['dbname']}.\n";
