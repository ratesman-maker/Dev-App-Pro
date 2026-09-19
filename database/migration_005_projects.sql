-- Migration 005: Tabulka projects
-- Fáze 2 - Projekty (CRUD + archive/restore)
-- Datum: 2026-09-11

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `projects`;
CREATE TABLE `projects` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id` INT UNSIGNED NULL DEFAULT NULL,
    `name` VARCHAR(200) NOT NULL,
    `description` TEXT NULL DEFAULT NULL,
    `status` ENUM('active','on_hold','completed','cancelled','archived') NOT NULL DEFAULT 'active',
    `folder_path` VARCHAR(500) NULL DEFAULT NULL,
    `budget_cents` INT UNSIGNED NOT NULL DEFAULT 0,
    `started_at` DATE NULL DEFAULT NULL,
    `deadline` DATE NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE SET NULL,
    INDEX `idx_client_id` (`client_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_deadline` (`deadline`),
    INDEX `idx_folder_path` (`folder_path`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
