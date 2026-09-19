-- Migration 006: Tabulka tasks
-- Fáze 2 - Úkoly (CRUD)
-- Datum: 2026-09-11

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `tasks`;
CREATE TABLE `tasks` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `project_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NULL DEFAULT NULL,
    `status` ENUM('todo','in_progress','done','cancelled') NOT NULL DEFAULT 'todo',
    `priority` ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
    `due_date` DATE NULL DEFAULT NULL,
    `assigned_to` VARCHAR(100) NULL DEFAULT NULL,
    `estimated_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
    `spent_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE,
    INDEX `idx_project_id` (`project_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_priority` (`priority`),
    INDEX `idx_due_date` (`due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
