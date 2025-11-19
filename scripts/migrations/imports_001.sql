-- Importer MVP schema additions

CREATE TABLE IF NOT EXISTS imports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  filename VARCHAR(255) NOT NULL,
  mapping_json JSON NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL,
  INDEX (project_id),
  CONSTRAINT fk_imports_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Optional: track provenance of tasks created by the importer
ALTER TABLE tasks
  ADD COLUMN IF NOT EXISTS source_import_id INT NULL,
  ADD INDEX source_import_id (source_import_id);

-- Add the FK separately to avoid failures if column already exists
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_tasks_import');
SET @sql := IF(@fk_exists=0, 'ALTER TABLE tasks ADD CONSTRAINT fk_tasks_import FOREIGN KEY (source_import_id) REFERENCES imports(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;