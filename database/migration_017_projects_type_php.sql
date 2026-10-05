-- Migrace 017: přidat 'php' do projects.type enum
-- Nový typ projektu z detekce (index.php/*.php v kořenu, ne WordPress).
-- Bez něj sync padá na "Data truncated for column 'type'".

ALTER TABLE `projects`
    MODIFY COLUMN `type` ENUM('static','wordpress','php') NULL DEFAULT NULL;
