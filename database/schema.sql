-- Dev App Pro - Databázové schema
-- Verze: 1.0.0
-- Kompatibilní s MariaDB 11.x / MySQL 8.x

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- Tabulka: users
-- ============================================================
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(100) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `password_hint` VARCHAR(255) NULL DEFAULT NULL,
    `email` VARCHAR(255) NOT NULL,
    `theme` ENUM('light','dark') NOT NULL DEFAULT 'dark',
    `sidebar_collapsed` TINYINT NOT NULL DEFAULT 0,
    `per_page` INT UNSIGNED NOT NULL DEFAULT 20,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Tabulka: company_profile
-- ============================================================
DROP TABLE IF EXISTS `company_profile`;
CREATE TABLE `company_profile` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `type` ENUM('individual','company') NOT NULL DEFAULT 'individual',
    `first_name` VARCHAR(100) NULL DEFAULT NULL,
    `last_name` VARCHAR(100) NULL DEFAULT NULL,
    `company_name` VARCHAR(200) NULL DEFAULT NULL,
    `ico` VARCHAR(20) NULL DEFAULT NULL,
    `dic` VARCHAR(30) NULL DEFAULT NULL,
    `email` VARCHAR(255) NULL DEFAULT NULL,
    `phone` VARCHAR(30) NULL DEFAULT NULL,
    `address` VARCHAR(255) NULL DEFAULT NULL,
    `bank_account` VARCHAR(50) NULL DEFAULT NULL,
    `iban` VARCHAR(34) NULL DEFAULT NULL,
    `swift` VARCHAR(11) NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Tabulka: settings
-- ============================================================
DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `key` VARCHAR(100) NOT NULL,
    `value` TEXT NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_settings_key` (`key`),
    INDEX `idx_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Tabulka: login_attempts
-- ============================================================
DROP TABLE IF EXISTS `login_attempts`;
CREATE TABLE `login_attempts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ip_address` VARCHAR(45) NOT NULL,
    `username` VARCHAR(100) NULL DEFAULT NULL,
    `attempted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `success` TINYINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    INDEX `idx_ip_time` (`ip_address`, `attempted_at`),
    INDEX `idx_username_time` (`username`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Tabulka: clients
-- ============================================================
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

-- ============================================================
-- Tabulka: projects
-- ============================================================
DROP TABLE IF EXISTS `projects`;
CREATE TABLE `projects` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id` INT UNSIGNED NULL DEFAULT NULL,
    `name` VARCHAR(200) NOT NULL,
    `description` TEXT NULL DEFAULT NULL,
    `status` ENUM('active','on_hold','completed','cancelled','archived') NOT NULL DEFAULT 'active',
    `type` ENUM('static','wordpress','php') NULL DEFAULT NULL,
    `php_version` VARCHAR(10) NULL DEFAULT NULL,
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
    INDEX `idx_folder_path` (`folder_path`),
    INDEX `idx_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Tabulka: tasks
-- ============================================================
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

-- ============================================================
-- Tabulka: invoices
-- ============================================================
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
    `taxable_date` DATE NULL DEFAULT NULL,
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

-- ============================================================
-- Tabulka: invoice_items
-- ============================================================
DROP TABLE IF EXISTS `invoice_items`;
CREATE TABLE `invoice_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `invoice_id` INT UNSIGNED NOT NULL,
    `description` VARCHAR(500) NOT NULL,
    `quantity` DECIMAL(12,3) NOT NULL DEFAULT 1,
    `unit` VARCHAR(20) NULL DEFAULT NULL,
    `unit_price_cents` INT UNSIGNED NOT NULL DEFAULT 0,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON DELETE CASCADE,
    INDEX `idx_invoice_id` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Tabulka: invoice_payments
-- ============================================================
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

-- ============================================================
-- Tabulka: transactions
-- ============================================================
DROP TABLE IF EXISTS `transactions`;
CREATE TABLE `transactions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `project_id` INT UNSIGNED NULL DEFAULT NULL,
    `client_id` INT UNSIGNED NULL DEFAULT NULL,
    `invoice_id` INT UNSIGNED NULL DEFAULT NULL,
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
    INDEX `idx_invoice` (`invoice_id`),
    INDEX `idx_type` (`type`),
    INDEX `idx_category` (`category`),
    INDEX `idx_transaction_date` (`transaction_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Tabulka: notes
-- ============================================================
DROP TABLE IF EXISTS `notes`;
CREATE TABLE `notes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NULL DEFAULT NULL,
    `title` VARCHAR(200) NULL DEFAULT NULL,
    `content` TEXT NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Tabulka: noteables (polymorfní vazby poznámek)
-- ============================================================
DROP TABLE IF EXISTS `noteables`;
CREATE TABLE `noteables` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `note_id` INT UNSIGNED NOT NULL,
    `entity_type` ENUM('client','project','task','invoice') NOT NULL,
    `entity_id` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`note_id`) REFERENCES `notes`(`id`) ON DELETE CASCADE,
    INDEX `idx_note_id` (`note_id`),
    INDEX `idx_entity` (`entity_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Tabulka: files
-- ============================================================
DROP TABLE IF EXISTS `files`;
CREATE TABLE `files` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NULL DEFAULT NULL,
    `original_name` VARCHAR(255) NOT NULL,
    `stored_name` VARCHAR(255) NOT NULL,
    `mime_type` VARCHAR(100) NOT NULL,
    `size_bytes` INT UNSIGNED NOT NULL,
    `storage_path` VARCHAR(500) NOT NULL,
    `is_image` TINYINT(1) NOT NULL DEFAULT 0,
    `thumbnail_path` VARCHAR(500) NULL DEFAULT NULL,
    `medium_path` VARCHAR(500) NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_mime_type` (`mime_type`),
    INDEX `idx_is_image` (`is_image`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Tabulka: fileables (polymorfní vazby souborů)
-- ============================================================
DROP TABLE IF EXISTS `fileables`;
CREATE TABLE `fileables` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `file_id` INT UNSIGNED NOT NULL,
    `entity_type` ENUM('client','project','task','invoice') NOT NULL,
    `entity_id` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`file_id`) REFERENCES `files`(`id`) ON DELETE CASCADE,
    INDEX `idx_file_id` (`file_id`),
    INDEX `idx_entity` (`entity_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Tabulka: `wp_installs`
-- ============================================================
DROP TABLE IF EXISTS `wp_installs`;
CREATE TABLE `wp_installs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `site_name` varchar(100) NOT NULL,
  `site_url` varchar(255) NOT NULL,
  `document_root` varchar(255) NOT NULL,
  `db_name` varchar(100) NOT NULL,
  `db_user` varchar(100) NOT NULL,
  `db_password` varchar(255) NOT NULL,
  `wp_version` varchar(20) DEFAULT NULL,
  `admin_user` varchar(100) DEFAULT NULL,
  `admin_password` varchar(255) DEFAULT NULL,
  `admin_email` varchar(255) DEFAULT NULL,
  `status` enum('pending','downloading','extracting','creating_db','configuring','completed','failed','pending_uninstall') NOT NULL DEFAULT 'pending',
  `error_message` mediumtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_site_url` (`site_url`),
  UNIQUE KEY `uk_db_name` (`db_name`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Tabulka: `notifications`
-- ============================================================
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `type` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text DEFAULT NULL,
  `entity_type` varchar(50) DEFAULT NULL,
  `entity_id` int(10) unsigned DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_read` (`user_id`,`is_read`),
  KEY `idx_user_created` (`user_id`,`created_at`),
  KEY `idx_entity` (`entity_type`,`entity_id`),
  KEY `idx_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Tabulka: `project_credentials`
-- ============================================================
DROP TABLE IF EXISTS `project_credentials`;
CREATE TABLE `project_credentials` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` int(10) unsigned NOT NULL,
  `type` enum('sftp','ftp','ssh','smtp','database','admin','api','other') NOT NULL,
  `name` varchar(200) NOT NULL,
  `host` varchar(255) DEFAULT NULL,
  `port` int(10) unsigned DEFAULT NULL,
  `username` varchar(200) DEFAULT NULL,
  `password_encrypted` text DEFAULT NULL,
  `database_name` varchar(200) DEFAULT NULL,
  `extra` text DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_project_id` (`project_id`),
  KEY `idx_type` (`type`),
  CONSTRAINT `project_credentials_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Tabulka: `project_hosting_jobs`
-- ============================================================
DROP TABLE IF EXISTS `project_hosting_jobs`;
CREATE TABLE `project_hosting_jobs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` int(10) unsigned NOT NULL,
  `action` varchar(50) NOT NULL,
  `folder_path` varchar(200) NOT NULL,
  `status` enum('pending','regenerating_ssl','generating_vhosts','reloading','completed','failed') NOT NULL DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_project` (`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ============================================================
-- Tabulka: `php_version_jobs`
-- ============================================================
DROP TABLE IF EXISTS `php_version_jobs`;
CREATE TABLE `php_version_jobs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` int(10) unsigned NOT NULL,
  `php_version` varchar(10) NOT NULL,
  `old_php_version` varchar(10) DEFAULT NULL,
  `status` enum('pending','starting_fpm','regenerating','reloading','completed','failed') NOT NULL DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_project` (`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ============================================================
-- Tabulka: `backup_restores`
-- ============================================================
DROP TABLE IF EXISTS `backup_restores`;
CREATE TABLE `backup_restores` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `backup_path` varchar(500) NOT NULL,
  `project_name` varchar(100) NOT NULL,
  `site_url` varchar(200) NOT NULL,
  `document_root` varchar(500) NOT NULL,
  `db_name` varchar(100) NOT NULL,
  `db_user` varchar(100) NOT NULL,
  `db_password` varchar(255) NOT NULL,
  `old_url` varchar(500) DEFAULT NULL,
  `status` enum('pending','extracting','creating_db','importing_sql','configuring','replacing_urls','regenerating_ssl','completed','failed') NOT NULL DEFAULT 'pending',
  `error_message` mediumtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_project_name` (`project_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Tabulka: `project_delete_jobs`
-- ============================================================
DROP TABLE IF EXISTS `project_delete_jobs`;
CREATE TABLE `project_delete_jobs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` int(10) unsigned NOT NULL,
  `project_name` varchar(255) NOT NULL,
  `folder_path` varchar(255) DEFAULT NULL,
  `site_url` varchar(255) DEFAULT NULL,
  `db_name` varchar(255) DEFAULT NULL,
  `db_user` varchar(255) DEFAULT NULL,
  `status` enum('pending','deleting_files','dropping_db','removing_vhost','removing_ssl','completed','failed') NOT NULL DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ============================================================
-- Tabulka: `worklog_entries`
-- ============================================================
DROP TABLE IF EXISTS `worklog_entries`;
CREATE TABLE `worklog_entries` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `project_id` int(10) unsigned DEFAULT NULL,
  `client_id` int(10) unsigned DEFAULT NULL,
  `category` enum('project','security','maintenance','meeting','other') NOT NULL DEFAULT 'other',
  `severity` enum('info','warning','critical') NOT NULL DEFAULT 'info',
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `hours` decimal(5,2) DEFAULT NULL,
  `is_done` tinyint(1) NOT NULL DEFAULT 0,
  `done_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_worklog_project` (`project_id`),
  KEY `idx_worklog_client` (`client_id`),
  KEY `idx_worklog_category` (`category`),
  KEY `idx_worklog_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- ============================================================
-- Tabulka: `worklog_attachments`
-- ============================================================
DROP TABLE IF EXISTS `worklog_attachments`;
CREATE TABLE `worklog_attachments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `entry_id` int(10) unsigned NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `stored_name` varchar(255) NOT NULL,
  `mime_type` varchar(100) NOT NULL DEFAULT 'application/octet-stream',
  `size_bytes` int(10) unsigned NOT NULL DEFAULT 0,
  `storage_path` varchar(500) NOT NULL,
  `is_image` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_worklog_att_entry` (`entry_id`),
  CONSTRAINT `fk_worklog_att_entry` FOREIGN KEY (`entry_id`) REFERENCES `worklog_entries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

SET FOREIGN_KEY_CHECKS = 1;
