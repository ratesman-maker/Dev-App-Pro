-- Migration 016: Položky faktur + datum uskutečnění zdanitelného plnění
-- Fáze 2.5 - Vystavování faktur (zákonné náležitosti, QR platba)
-- Datum: 2026-09-18

SET FOREIGN_KEY_CHECKS = 0;

-- Datum uskutečnění zdanitelného plnění (DZP) - povinná náležitost dle ZDPH §28
ALTER TABLE `invoices`
    ADD COLUMN `taxable_date` DATE NULL DEFAULT NULL AFTER `due_date`;

-- Položky faktury (rozpad na řádky služeb/zboží)
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

SET FOREIGN_KEY_CHECKS = 1;
