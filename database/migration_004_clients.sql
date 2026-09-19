-- Migration 004: Tabulka clients
-- Fáze 1b - Klienti (CRUD)
-- Datum: 2026-09-11

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `clients`;
CREATE TABLE `clients` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `type` ENUM('individual','company') NOT NULL DEFAULT 'individual',
    `first_name` VARCHAR(100) NULL DEFAULT NULL,
    `last_name` VARCHAR(100) NULL DEFAULT NULL,
    `company_name` VARCHAR(200) NULL DEFAULT NULL,
    `ico` VARCHAR(20) NULL DEFAULT NULL,
    `dic` VARCHAR(30) NULL DEFAULT NULL,
    `bank_account` VARCHAR(50) NULL DEFAULT NULL,
    `email` VARCHAR(255) NULL DEFAULT NULL,
    `phone` VARCHAR(50) NULL DEFAULT NULL,
    `address` VARCHAR(500) NULL DEFAULT NULL,
    `note` TEXT NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_last_name` (`last_name`, `first_name`),
    INDEX `idx_company_name` (`company_name`),
    INDEX `idx_email` (`email`),
    INDEX `idx_ico` (`ico`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
