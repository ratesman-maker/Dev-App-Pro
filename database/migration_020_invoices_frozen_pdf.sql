-- Migrace 020: zmrazení PDF při vystavení faktury
-- Vydaná faktura (status sent/paid/overdue) je účetní doklad — její PDF
-- se proto při přechodu z draftu/cancelled zmrazí do storage/invoices/
-- a sloupec frozen_pdf drží relativní cestu k archivní kopii.
-- NULL = faktura ještě nebyla vydána, PDF se generuje živě.
-- Idempotentní: ADD COLUMN IF NOT EXISTS.

ALTER TABLE `invoices`
    ADD COLUMN IF NOT EXISTS `frozen_pdf` VARCHAR(255) NULL DEFAULT NULL AFTER `note`;
