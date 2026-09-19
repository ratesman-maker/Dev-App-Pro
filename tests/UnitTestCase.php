<?php
declare(strict_types=1);

namespace DevAppPro\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Základní třída pro unit testy (repositáře, služby).
 * Připojí test DB a resetuje schema/seed — BEZ HTTP serveru (rychlé).
 * HTTP API testy používají TestCase (tento + built-in server).
 */
abstract class UnitTestCase extends BaseTestCase
{
    protected \PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Připojení k test DB
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $dbname = getenv('DB_NAME') ?: 'devapppro_test';
        $user = getenv('DB_USER') ?: 'devapppro';
        $pass = getenv('DB_PASS') ?: 'devapppro_secret';

        $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
        $this->pdo = new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // 2. Načtení schema.sql (DROP + CREATE)
        $schema = file_get_contents(__DIR__ . '/../database/schema.sql');
        $this->pdo->exec($schema);

        // 3. Načtení seed_test.sql
        $seed = file_get_contents(__DIR__ . '/../database/seed_test.sql');
        $this->pdo->exec($seed);
    }

    protected function tearDown(): void
    {
        // Drop all tables (vyčistit pro další test)
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $this->pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        parent::tearDown();
    }
}
