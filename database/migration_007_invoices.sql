-- Migration 007: Tabulky invoices a invoice_payments
-- Fáze 2 - Faktury a platby (CRUD)
-- Datum: 2026-09-11

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `invoices`;
CREATE TABLE `invoices` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id` INT UNSIGNED NULL DEFAULT NULL,
    `project_id` INT UNSIGNED NULL DEFAULT NULL,
    `invoice_number` VARCHAR(50) NOT NULL,
    `status` ENUM('draft','sent','paid','overdue','cancelled') NOT NULL DEFAULT 'draft',
    `subtotal_cents` INT UNSIGNED NOT NULL,
    `vat_rate_percent` DECIMAL(5,2) NOT NULL DEFAULT 0,
    `vat_amount_cents` INT UNSIGNED NOT NULL DEFAULT 0,
    `amount_cents` INT UNSIGNED NOT NULL,
    `paid_cents` INT UNSIGNED NOT NULL DEFAULT 0,
    `currency` VARCHAR(3) NOT NULL DEFAULT 'CZK',
    `variable_symbol` VARCHAR(20) NULL DEFAULT NULL,
    `constant_symbol` VARCHAR(10) NULL DEFAULT NULL,
    `iban` VARCHAR(50) NULL DEFAULT NULL,
    `issue_date` DATE NOT NULL,
    `due_date` DATE NOT NULL,
    `note` TEXT NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_invoices_invoice_number` (`invoice_number`),
    FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE SET NULL,
    INDEX `idx_client_id` (`client_id`),
    INDEX `idx_project_id` (`project_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_due_date` (`due_date`),
    INDEX `idx_invoice_number` (`invoice_number`),
    INDEX `idx_variable_symbol` (`variable_symbol`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `invoice_payments`;
CREATE TABLE `invoice_payments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `invoice_id` INT UNSIGNED NOT NULL,
    `amount_cents` INT UNSIGNED NOT NULL,
    `payment_date` DATE NOT NULL,
    `method` ENUM('cash','bank_transfer','card','other') NOT NULL DEFAULT 'bank_transfer',
    `note` TEXT NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON DELETE CASCADE,
    INDEX `idx_invoice_id` (`invoice_id`),
    INDEX `idx_payment_date` (`payment_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
