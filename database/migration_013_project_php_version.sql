-- Migrace 013: PHP verze pro projekty (per-project PHP-FPM)
ALTER TABLE projects
  ADD COLUMN php_version VARCHAR(10) NULL DEFAULT NULL
  AFTER type;
