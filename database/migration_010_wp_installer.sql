-- Migrace 010: WordPress installer tabulky
-- Záloha: viz AGENTS.md postup

CREATE TABLE IF NOT EXISTS wp_installs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_name VARCHAR(100) NOT NULL,
  site_url VARCHAR(255) NOT NULL,
  document_root VARCHAR(255) NOT NULL,
  db_name VARCHAR(100) NOT NULL,
  db_user VARCHAR(100) NOT NULL,
  db_password VARCHAR(255) NOT NULL,
  wp_version VARCHAR(20) NULL,
  admin_user VARCHAR(100) NULL,
  admin_email VARCHAR(255) NULL,
  status ENUM('pending','downloading','extracting','creating_db','configuring','creating_vhost','reloading_apache','completed','failed') NOT NULL DEFAULT 'pending',
  error_message TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_site_url (site_url),
  UNIQUE KEY uk_db_name (db_name),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
