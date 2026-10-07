-- Migrace 018: rozšířit type enumy o 'nonprofit' a 'government'
-- Validace v ClientApiController/CompanyProfileApiController a frontend tyto
-- typy už podporují, ale enum je odmítal → INSERT padal na
-- "Data truncated for column 'type'" (HTTP 500).
-- Idempotentní: ENUM obsahuje všechny hodnoty, opakování je no-op.

ALTER TABLE `clients`
    MODIFY COLUMN `type` ENUM('individual','company','nonprofit','government') NOT NULL DEFAULT 'individual';

ALTER TABLE `company_profile`
    MODIFY COLUMN `type` ENUM('individual','company','nonprofit','government') NOT NULL DEFAULT 'individual';
