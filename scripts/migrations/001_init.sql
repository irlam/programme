-- Schema v1
CREATE TABLE calendars (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL DEFAULT 'Default',
  workdays_json JSON NOT NULL,
  holidays_json JSON NULL,
  last_sync_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE calendar_holidays (
  id INT AUTO_INCREMENT PRIMARY KEY,
  calendar_id INT NOT NULL,
  date DATE NOT NULL,
  is_working TINYINT(1) NOT NULL DEFAULT 0,
  name VARCHAR(150) DEFAULT NULL,
  source ENUM('manual','govuk') NOT NULL DEFAULT 'manual',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by INT NULL,
  UNIQUE KEY uniq_cal_day (calendar_id, date),
  CONSTRAINT fk_calhol_cal FOREIGN KEY (calendar_id) REFERENCES calendars(id) ON DELETE CASCADE
);

CREATE TABLE projects (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  start_date DATE NOT NULL,
  timezone VARCHAR(40) NOT NULL DEFAULT 'Europe/London',
  calendar_id INT NOT NULL,
  CONSTRAINT fk_proj_cal FOREIGN KEY (calendar_id) REFERENCES calendars(id) ON DELETE RESTRICT
);

CREATE TABLE apartments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  block VARCHAR(50) NULL,
  floor VARCHAR(50) NULL,
  unit VARCHAR(50) NULL,
  type VARCHAR(50) NULL,
  CONSTRAINT fk_ap_proj FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  INDEX idx_ap_proj (project_id)
);

CREATE TABLE contractors (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  colour CHAR(7) NOT NULL DEFAULT '#4B5563',
  contact_email VARCHAR(190) NULL
);

CREATE TABLE tasks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  apartment_id INT NOT NULL,
  name VARCHAR(190) NOT NULL,
  contractor_id INT NULL,
  operatives INT NOT NULL DEFAULT 1,
  duration_days INT NOT NULL,
  zone VARCHAR(80) NULL,
  constraint_start DATE NULL,
  percent_complete INT NOT NULL DEFAULT 0,
  is_milestone TINYINT(1) NOT NULL DEFAULT 0,
  start_date DATE NULL,
  finish_date DATE NULL,
  slack_days INT NULL,
  baseline_start DATE NULL,
  baseline_finish DATE NULL,
  alerts_json JSON NULL,
  CONSTRAINT fk_t_proj FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_t_ap FOREIGN KEY (apartment_id) REFERENCES apartments(id) ON DELETE CASCADE,
  CONSTRAINT fk_t_con FOREIGN KEY (contractor_id) REFERENCES contractors(id) ON DELETE SET NULL,
  INDEX idx_t_proj_start (project_id, start_date),
  INDEX idx_t_ap (apartment_id)
);

CREATE TABLE dependencies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  task_id INT NOT NULL,
  predecessor_id INT NOT NULL,
  type ENUM('FS','SS') NOT NULL,
  lag_days INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_dep_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_dep_pred FOREIGN KEY (predecessor_id) REFERENCES tasks(id) ON DELETE CASCADE,
  INDEX idx_dep_task (task_id),
  INDEX idx_dep_pred (predecessor_id)
);

CREATE TABLE comments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  task_id INT NOT NULL,
  user_id INT NULL,
  message TEXT NOT NULL,
  attachments_json JSON NULL,
  parent_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_c_task (task_id),
  CONSTRAINT fk_c_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
);

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  role ENUM('admin','planner','commenter','viewer') NOT NULL DEFAULT 'viewer',
  password_hash VARCHAR(255) NOT NULL,
  confirmed TINYINT(1) NOT NULL DEFAULT 0,
  confirm_token VARCHAR(100) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE baselines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  label VARCHAR(120) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_b_proj FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);

CREATE TABLE audit_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  entity_type VARCHAR(40) NOT NULL,
  entity_id INT NOT NULL,
  action VARCHAR(40) NOT NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  user_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_a_entity (entity_type, entity_id),
  INDEX idx_a_created (created_at)
);

INSERT INTO calendars (name, workdays_json) VALUES ('UK Mon–Fri', JSON_OBJECT('mon',1,'tue',1,'wed',1,'thu',1,'fri',1,'sat',0,'sun',0));
