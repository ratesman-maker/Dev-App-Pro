-- Migrace 015: Fronta pro mazání projektů (soubory, DB, vhost, SSL)
CREATE TABLE IF NOT EXISTS project_delete_jobs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  project_name VARCHAR(255) NOT NULL,
  folder_path VARCHAR(255) NULL,
  site_url VARCHAR(255) NULL,
  db_name VARCHAR(255) NULL,
  db_user VARCHAR(255) NULL,
  status ENUM('pending','deleting_files','dropping_db','removing_vhost','removing_ssl','completed','failed') NOT NULL DEFAULT 'pending',
  error_message TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP(),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
