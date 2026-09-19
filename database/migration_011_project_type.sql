-- Migrace 011: Typ projektu (static/wordpress)
ALTER TABLE projects
  ADD COLUMN type ENUM('static','wordpress') NULL AFTER folder_path,
  ADD INDEX idx_type (type);
