-- Migrace 019: kontaktní osoba (zástupce) u organizací
-- Firma/neziskový/státní správa — jednání probíhá se zástupcem,
-- proto nové pole pro jeho jméno + vlastní e-mail/telefon.
-- U individual (osoby) zůstávají NULL — kontakt je na osobě samotné.
-- Idempotentní: ADD COLUMN IF NOT EXISTS.

ALTER TABLE `clients`
    ADD COLUMN IF NOT EXISTS `contact_name` VARCHAR(200) NULL DEFAULT NULL AFTER `company_name`,
    ADD COLUMN IF NOT EXISTS `contact_email` VARCHAR(255) NULL DEFAULT NULL AFTER `contact_name`,
    ADD COLUMN IF NOT EXISTS `contact_phone` VARCHAR(50) NULL DEFAULT NULL AFTER `contact_email`;
