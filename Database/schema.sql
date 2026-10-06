-- Programme schema only. No exported users, passwords or project records.

CREATE TABLE `apartments` (
  `id` int NOT NULL,
  `project_id` int NOT NULL,
  `block` varchar(50) DEFAULT NULL,
  `floor` varchar(50) DEFAULT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `type` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;


CREATE TABLE `audit_log` (
  `id` bigint NOT NULL,
  `entity_type` varchar(40) NOT NULL,
  `entity_id` int NOT NULL,
  `action` varchar(40) NOT NULL,
  `before_json` json DEFAULT NULL,
  `after_json` json DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;


CREATE TABLE `baselines` (
  `id` int NOT NULL,
  `project_id` int NOT NULL,
  `label` varchar(120) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;


CREATE TABLE `calendars` (
  `id` int NOT NULL,
  `name` varchar(100) NOT NULL DEFAULT 'Default',
  `workdays_json` json NOT NULL,
  `holidays_json` json DEFAULT NULL,
  `last_sync_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


CREATE TABLE `calendar_holidays` (
  `id` int NOT NULL,
  `calendar_id` int NOT NULL,
  `date` date NOT NULL,
  `is_working` tinyint(1) NOT NULL DEFAULT '0',
  `name` varchar(150) DEFAULT NULL,
  `source` enum('manual','govuk') NOT NULL DEFAULT 'manual',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;


CREATE TABLE `comments` (
  `id` int NOT NULL,
  `task_id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `message` text NOT NULL,
  `attachments_json` json DEFAULT NULL,
  `parent_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;


CREATE TABLE `contractors` (
  `id` int NOT NULL,
  `name` varchar(120) NOT NULL,
  `colour` char(7) NOT NULL DEFAULT '#4B5563',
  `contact_email` varchar(190) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;


CREATE TABLE `dependencies` (
  `id` int NOT NULL,
  `task_id` int NOT NULL,
  `predecessor_id` int NOT NULL,
  `type` enum('FS','SS') NOT NULL,
  `lag_days` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;


CREATE TABLE `imports` (
  `id` int NOT NULL,
  `project_id` int NOT NULL,
  `filename` varchar(255) NOT NULL,
  `mapping_json` json DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


CREATE TABLE `projects` (
  `id` int NOT NULL,
  `name` varchar(150) NOT NULL,
  `start_date` date NOT NULL,
  `timezone` varchar(40) NOT NULL DEFAULT 'Europe/London',
  `calendar_id` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;


CREATE TABLE `tasks` (
  `id` int NOT NULL,
  `project_id` int NOT NULL,
  `apartment_id` int NOT NULL,
  `name` varchar(190) NOT NULL,
  `contractor_id` int DEFAULT NULL,
  `operatives` int NOT NULL DEFAULT '1',
  `duration_days` int NOT NULL,
  `zone` varchar(80) DEFAULT NULL,
  `constraint_start` date DEFAULT NULL,
  `percent_complete` int NOT NULL DEFAULT '0',
  `is_milestone` tinyint(1) NOT NULL DEFAULT '0',
  `start_date` date DEFAULT NULL,
  `finish_date` date DEFAULT NULL,
  `slack_days` int DEFAULT NULL,
  `baseline_start` date DEFAULT NULL,
  `baseline_finish` date DEFAULT NULL,
  `alerts_json` json DEFAULT NULL,
  `source_import_id` int DEFAULT NULL,
  `notes` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;


CREATE TABLE `templates` (
  `id` int NOT NULL,
  `name` varchar(160) NOT NULL,
  `description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


CREATE TABLE `template_dependencies` (
  `id` int NOT NULL,
  `template_id` int NOT NULL,
  `task_code` varchar(40) NOT NULL,
  `predecessor_code` varchar(40) NOT NULL,
  `type` enum('FS','SS') NOT NULL DEFAULT 'FS',
  `lag_days` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


CREATE TABLE `template_tasks` (
  `id` int NOT NULL,
  `template_id` int NOT NULL,
  `code` varchar(40) NOT NULL,
  `name` varchar(255) NOT NULL,
  `contractor_id` int DEFAULT NULL,
  `operatives` int NOT NULL DEFAULT '1',
  `duration_days` int NOT NULL DEFAULT '1',
  `zone` varchar(120) DEFAULT NULL,
  `is_milestone` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


CREATE TABLE `users` (
  `id` int NOT NULL,
  `name` varchar(120) NOT NULL,
  `email` varchar(190) NOT NULL,
  `role` enum('admin','planner','commenter','viewer') NOT NULL DEFAULT 'viewer',
  `password_hash` varchar(255) NOT NULL,
  `confirmed` tinyint(1) NOT NULL DEFAULT '0',
  `confirm_token` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;


ALTER TABLE `apartments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ap_proj` (`project_id`);


ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_a_entity` (`entity_type`,`entity_id`),
  ADD KEY `idx_a_created` (`created_at`);


ALTER TABLE `baselines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_b_proj` (`project_id`);


ALTER TABLE `calendars`
  ADD PRIMARY KEY (`id`);


ALTER TABLE `calendar_holidays`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_cal_day` (`calendar_id`,`date`);


ALTER TABLE `comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_c_task` (`task_id`);


ALTER TABLE `contractors`
  ADD PRIMARY KEY (`id`);


ALTER TABLE `dependencies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_dep_task` (`task_id`),
  ADD KEY `idx_dep_pred` (`predecessor_id`);


ALTER TABLE `imports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_id` (`project_id`);


ALTER TABLE `projects`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_proj_cal` (`calendar_id`);


ALTER TABLE `tasks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_t_con` (`contractor_id`),
  ADD KEY `idx_t_proj_start` (`project_id`,`start_date`),
  ADD KEY `idx_t_ap` (`apartment_id`),
  ADD KEY `source_import_id` (`source_import_id`);


ALTER TABLE `templates`
  ADD PRIMARY KEY (`id`);


ALTER TABLE `template_dependencies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `template_id` (`template_id`);


ALTER TABLE `template_tasks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `template_id` (`template_id`,`code`),
  ADD KEY `template_id_2` (`template_id`);


ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);


ALTER TABLE `apartments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=492;


ALTER TABLE `audit_log`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;


ALTER TABLE `baselines`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;


ALTER TABLE `calendars`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;


ALTER TABLE `calendar_holidays`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;


ALTER TABLE `comments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;


ALTER TABLE `contractors`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=100;


ALTER TABLE `dependencies`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;


ALTER TABLE `imports`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;


ALTER TABLE `projects`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;


ALTER TABLE `tasks`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=508;


ALTER TABLE `templates`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;


ALTER TABLE `template_dependencies`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;


ALTER TABLE `template_tasks`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;


ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;


ALTER TABLE `apartments`
  ADD CONSTRAINT `fk_ap_proj` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;


ALTER TABLE `baselines`
  ADD CONSTRAINT `fk_b_proj` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;


ALTER TABLE `calendar_holidays`
  ADD CONSTRAINT `fk_calhol_cal` FOREIGN KEY (`calendar_id`) REFERENCES `calendars` (`id`) ON DELETE CASCADE;


ALTER TABLE `comments`
  ADD CONSTRAINT `fk_c_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE;


ALTER TABLE `dependencies`
  ADD CONSTRAINT `fk_dep_pred` FOREIGN KEY (`predecessor_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_dep_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE;


ALTER TABLE `imports`
  ADD CONSTRAINT `fk_imports_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;


ALTER TABLE `projects`
  ADD CONSTRAINT `fk_proj_cal` FOREIGN KEY (`calendar_id`) REFERENCES `calendars` (`id`) ON DELETE RESTRICT;


ALTER TABLE `tasks`
  ADD CONSTRAINT `fk_t_ap` FOREIGN KEY (`apartment_id`) REFERENCES `apartments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_t_con` FOREIGN KEY (`contractor_id`) REFERENCES `contractors` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_t_proj` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_tasks_import` FOREIGN KEY (`source_import_id`) REFERENCES `imports` (`id`) ON DELETE SET NULL;
