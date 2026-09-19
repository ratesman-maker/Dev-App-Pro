-- Migrace 014: Fronta pro přepínání PHP verzí
CREATE TABLE IF NOT EXISTS php_version_jobs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  php_version VARCHAR(10) NOT NULL,
  old_php_version VARCHAR(10) NULL,
  status ENUM('pending','starting_fpm','regenerating','reloading','completed','failed') NOT NULL DEFAULT 'pending',
  error_message TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP(),
  INDEX idx_status (status),
  INDEX idx_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
