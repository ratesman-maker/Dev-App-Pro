-- Migrace 012: Záloha restore jobs (obnova Duplicator Pro záloh jako projekty)
CREATE TABLE IF NOT EXISTS backup_restores (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  backup_path VARCHAR(500) NOT NULL,
  project_name VARCHAR(100) NOT NULL,
  site_url VARCHAR(200) NOT NULL,
  document_root VARCHAR(500) NOT NULL,
  db_name VARCHAR(100) NOT NULL,
  db_user VARCHAR(100) NOT NULL,
  db_password VARCHAR(255) NOT NULL,
  old_url VARCHAR(500) NULL,
  status ENUM('pending','extracting','creating_db','importing_sql','configuring','replacing_urls','regenerating_ssl','completed','failed') NOT NULL DEFAULT 'pending',
  error_message MEDIUMTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (id),
  INDEX idx_status (status),
  INDEX idx_project_name (project_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
