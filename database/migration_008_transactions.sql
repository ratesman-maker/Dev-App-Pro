-- Migration 008: Tabulka transactions
-- Fáze 2 - Transakce (CRUD)
-- Datum: 2026-09-11

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `transactions`;
CREATE TABLE `transactions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `project_id` INT UNSIGNED NULL DEFAULT NULL,
    `client_id` INT UNSIGNED NULL DEFAULT NULL,
    `type` ENUM('income','expense') NOT NULL,
    `amount_cents` INT UNSIGNED NOT NULL,
    `category` ENUM('office','software','travel','marketing','hardware','services','income_project','income_consulting','other') NOT NULL DEFAULT 'other',
    `description` VARCHAR(500) NULL DEFAULT NULL,
    `transaction_date` DATE NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE SET NULL,
    INDEX `idx_project_id` (`project_id`),
    INDEX `idx_client_id` (`client_id`),
    INDEX `idx_type` (`type`),
    INDEX `idx_category` (`category`),
    INDEX `idx_transaction_date` (`transaction_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
