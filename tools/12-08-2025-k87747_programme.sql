-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 10.35.233.124:3306
-- Generation Time: Aug 12, 2025 at 07:54 PM
-- Server version: 8.0.43
-- PHP Version: 8.4.8

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `k87747_programme`
--

-- --------------------------------------------------------

--
-- Table structure for table `apartments`
--

CREATE TABLE `apartments` (
  `id` int NOT NULL,
  `project_id` int NOT NULL,
  `block` varchar(50) DEFAULT NULL,
  `floor` varchar(50) DEFAULT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `type` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

--
-- Dumping data for table `apartments`
--

INSERT INTO `apartments` (`id`, `project_id`, `block`, `floor`, `unit`, `type`) VALUES
(1, 1, 'A', '1', '01', 'Type A');

-- --------------------------------------------------------

--
-- Table structure for table `audit_log`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `baselines`
--

CREATE TABLE `baselines` (
  `id` int NOT NULL,
  `project_id` int NOT NULL,
  `label` varchar(120) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Table structure for table `calendars`
--

CREATE TABLE `calendars` (
  `id` int NOT NULL,
  `name` varchar(100) NOT NULL DEFAULT 'Default',
  `workdays_json` json NOT NULL,
  `holidays_json` json DEFAULT NULL,
  `last_sync_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `calendars`
--

INSERT INTO `calendars` (`id`, `name`, `workdays_json`, `holidays_json`, `last_sync_at`) VALUES
(1, 'UK Mon–Fri', '{\"fri\": 1, \"mon\": 1, \"sat\": 0, \"sun\": 0, \"thu\": 1, \"tue\": 1, \"wed\": 1}', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `calendar_holidays`
--

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

--
-- Dumping data for table `calendar_holidays`
--

INSERT INTO `calendar_holidays` (`id`, `calendar_id`, `date`, `is_working`, `name`, `source`, `created_at`, `created_by`) VALUES
(1, 1, '2025-08-21', 0, 'test holiday', 'manual', '2025-08-12 10:57:08', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `comments`
--

CREATE TABLE `comments` (
  `id` int NOT NULL,
  `task_id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `message` text NOT NULL,
  `attachments_json` json DEFAULT NULL,
  `parent_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Table structure for table `contractors`
--

CREATE TABLE `contractors` (
  `id` int NOT NULL,
  `name` varchar(120) NOT NULL,
  `colour` char(7) NOT NULL DEFAULT '#4B5563',
  `contact_email` varchar(190) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

--
-- Dumping data for table `contractors`
--

INSERT INTO `contractors` (`id`, `name`, `colour`, `contact_email`) VALUES
(1, 'Panacea', '#60a5fa', NULL),
(2, 'GPL', '#34d399', NULL),
(3, 'TECL', '#f59e0b', NULL),
(4, 'Edencroft', '#f87171', NULL),
(5, 'Armstrong', '#a78bfa', NULL),
(6, 'Checks', '#93c5fd', NULL),
(7, 'Active Flooring', '#fbbf24', NULL),
(8, 'Smiths', '#6ee7b7', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `dependencies`
--

CREATE TABLE `dependencies` (
  `id` int NOT NULL,
  `task_id` int NOT NULL,
  `predecessor_id` int NOT NULL,
  `type` enum('FS','SS') NOT NULL,
  `lag_days` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

--
-- Dumping data for table `dependencies`
--

INSERT INTO `dependencies` (`id`, `task_id`, `predecessor_id`, `type`, `lag_days`) VALUES
(1, 2, 1, 'FS', 0),
(2, 3, 2, 'SS', 0),
(3, 4, 1, 'FS', 0),
(4, 5, 4, 'FS', 0),
(5, 6, 5, 'SS', 1),
(6, 7, 5, 'SS', 1),
(7, 8, 6, 'FS', 0),
(8, 8, 7, 'FS', 0),
(9, 9, 5, 'SS', 0),
(10, 10, 9, 'FS', 0),
(11, 11, 10, 'FS', 0),
(12, 12, 11, 'FS', 0),
(13, 13, 12, 'FS', 0),
(14, 14, 13, 'FS', 0),
(15, 15, 8, 'FS', 0),
(16, 15, 14, 'FS', 0),
(17, 16, 15, 'FS', 1),
(18, 17, 15, 'FS', 2),
(19, 18, 17, 'FS', 0);

-- --------------------------------------------------------

--
-- Table structure for table `projects`
--

CREATE TABLE `projects` (
  `id` int NOT NULL,
  `name` varchar(150) NOT NULL,
  `start_date` date NOT NULL,
  `timezone` varchar(40) NOT NULL DEFAULT 'Europe/London',
  `calendar_id` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

--
-- Dumping data for table `projects`
--

INSERT INTO `projects` (`id`, `name`, `start_date`, `timezone`, `calendar_id`) VALUES
(1, 'Sample Tower – Core Programme', '2025-08-18', 'Europe/London', 1);

-- --------------------------------------------------------

--
-- Table structure for table `tasks`
--

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
  `alerts_json` json DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

--
-- Dumping data for table `tasks`
--

INSERT INTO `tasks` (`id`, `project_id`, `apartment_id`, `name`, `contractor_id`, `operatives`, `duration_days`, `zone`, `constraint_start`, `percent_complete`, `is_milestone`, `start_date`, `finish_date`, `slack_days`, `baseline_start`, `baseline_finish`, `alerts_json`) VALUES
(1, 1, 1, 'BWH to structural walls', 1, 2, 3, 'Core', NULL, 0, 0, '2025-08-18', '2025-08-22', NULL, NULL, NULL, NULL),
(2, 1, 1, 'SVP/RWP install', 2, 2, 5, 'Wetrooms', NULL, 0, 0, '2025-08-22', '2025-08-29', NULL, NULL, NULL, '{\"tight\": [1]}'),
(3, 1, 1, 'Fire stop to SVP/RWP', 3, 2, 5, 'Wetrooms', NULL, 0, 0, '2025-08-22', '2025-08-29', NULL, NULL, NULL, NULL),
(4, 1, 1, 'Structural walls 1st fix', 4, 4, 5, 'Core', NULL, 0, 0, '2025-08-22', '2025-08-29', NULL, NULL, NULL, '{\"tight\": [1]}'),
(5, 1, 1, '', 1, 4, 6, 'Core', NULL, 0, 0, '2025-08-29', '2025-09-08', NULL, NULL, NULL, '{\"tight\": [4]}'),
(6, 1, 1, '1st fix wire', 4, 2, 5, 'Core', NULL, 0, 0, '2025-09-01', '2025-09-08', NULL, NULL, NULL, NULL),
(7, 1, 1, '1st fix plumbing (excl. kitchen waste)', 2, 2, 3, 'Wetrooms', NULL, 0, 0, '2025-09-01', '2025-09-04', NULL, NULL, NULL, NULL),
(8, 1, 1, '2nd side board structural walls', 1, 4, 3, 'Core', NULL, 0, 0, '2025-09-08', '2025-09-11', NULL, NULL, NULL, '{\"tight\": [6]}'),
(9, 1, 1, 'Vents/ducts 1st fix', 2, 2, 10, 'Ceilings', NULL, 0, 0, '2025-08-29', '2025-09-12', NULL, NULL, NULL, NULL),
(10, 1, 1, 'MF ceilings', 1, 2, 5, 'Ceilings', NULL, 0, 0, '2025-09-12', '2025-09-19', NULL, NULL, NULL, '{\"tight\": [9]}'),
(11, 1, 1, 'Sprinkler 1st fix', 5, 2, 3, 'Ceilings', NULL, 0, 0, '2025-09-19', '2025-09-24', NULL, NULL, NULL, '{\"tight\": [10]}'),
(12, 1, 1, 'Ceiling Void Closure Checks', 6, 1, 1, 'Ceilings', NULL, 0, 0, '2025-09-24', '2025-09-25', NULL, NULL, NULL, '{\"tight\": [11]}'),
(13, 1, 1, 'Sprinkler Test', 5, 2, 1, 'Ceilings', NULL, 0, 0, '2025-09-25', '2025-09-26', NULL, NULL, NULL, '{\"tight\": [12]}'),
(14, 1, 1, 'Board ceilings', 1, 2, 3, 'Ceilings', NULL, 0, 0, '2025-09-26', '2025-10-01', NULL, NULL, NULL, '{\"tight\": [13]}'),
(15, 1, 1, 'Spray plaster & sand', 1, 6, 4, 'Finishes', NULL, 0, 0, '2025-10-01', '2025-10-07', NULL, NULL, NULL, '{\"tight\": [14]}'),
(16, 1, 1, 'Latex floors – Visit 1', 7, 1, 2, 'Floors', NULL, 0, 0, '2025-10-08', '2025-10-10', NULL, NULL, NULL, NULL),
(17, 1, 1, 'Mist coat', 8, 1, 2, 'Finishes', NULL, 0, 0, '2025-10-09', '2025-10-13', NULL, NULL, NULL, NULL),
(18, 1, 1, 'Latex floors – Visit 2', 7, 1, 2, 'Floors', NULL, 0, 0, '2025-10-13', '2025-10-15', NULL, NULL, NULL, '{\"tight\": [17]}');

-- --------------------------------------------------------

--
-- Table structure for table `templates`
--

CREATE TABLE `templates` (
  `id` int NOT NULL,
  `name` varchar(160) NOT NULL,
  `description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `template_dependencies`
--

CREATE TABLE `template_dependencies` (
  `id` int NOT NULL,
  `template_id` int NOT NULL,
  `task_code` varchar(40) NOT NULL,
  `predecessor_code` varchar(40) NOT NULL,
  `type` enum('FS','SS') NOT NULL DEFAULT 'FS',
  `lag_days` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `template_tasks`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

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

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `role`, `password_hash`, `confirmed`, `confirm_token`, `created_at`) VALUES
(1, 'irlam', 'cirlam@gmail.com', 'admin', '$2y$10$1vZNpTzA1otuZJbcmlUlNex7Um/Ahe3tibPz08zZnZjSZxnTtlwCu', 0, NULL, '2025-08-12 19:51:27');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `apartments`
--
ALTER TABLE `apartments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ap_proj` (`project_id`);

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_a_entity` (`entity_type`,`entity_id`),
  ADD KEY `idx_a_created` (`created_at`);

--
-- Indexes for table `baselines`
--
ALTER TABLE `baselines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_b_proj` (`project_id`);

--
-- Indexes for table `calendars`
--
ALTER TABLE `calendars`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `calendar_holidays`
--
ALTER TABLE `calendar_holidays`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_cal_day` (`calendar_id`,`date`);

--
-- Indexes for table `comments`
--
ALTER TABLE `comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_c_task` (`task_id`);

--
-- Indexes for table `contractors`
--
ALTER TABLE `contractors`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `dependencies`
--
ALTER TABLE `dependencies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_dep_task` (`task_id`),
  ADD KEY `idx_dep_pred` (`predecessor_id`);

--
-- Indexes for table `projects`
--
ALTER TABLE `projects`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_proj_cal` (`calendar_id`);

--
-- Indexes for table `tasks`
--
ALTER TABLE `tasks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_t_con` (`contractor_id`),
  ADD KEY `idx_t_proj_start` (`project_id`,`start_date`),
  ADD KEY `idx_t_ap` (`apartment_id`);

--
-- Indexes for table `templates`
--
ALTER TABLE `templates`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `template_dependencies`
--
ALTER TABLE `template_dependencies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `template_id` (`template_id`);

--
-- Indexes for table `template_tasks`
--
ALTER TABLE `template_tasks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `template_id` (`template_id`,`code`),
  ADD KEY `template_id_2` (`template_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `apartments`
--
ALTER TABLE `apartments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `baselines`
--
ALTER TABLE `baselines`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `calendars`
--
ALTER TABLE `calendars`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `calendar_holidays`
--
ALTER TABLE `calendar_holidays`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `comments`
--
ALTER TABLE `comments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `contractors`
--
ALTER TABLE `contractors`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `dependencies`
--
ALTER TABLE `dependencies`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `projects`
--
ALTER TABLE `projects`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `tasks`
--
ALTER TABLE `tasks`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `templates`
--
ALTER TABLE `templates`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `template_dependencies`
--
ALTER TABLE `template_dependencies`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `template_tasks`
--
ALTER TABLE `template_tasks`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `apartments`
--
ALTER TABLE `apartments`
  ADD CONSTRAINT `fk_ap_proj` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `baselines`
--
ALTER TABLE `baselines`
  ADD CONSTRAINT `fk_b_proj` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `calendar_holidays`
--
ALTER TABLE `calendar_holidays`
  ADD CONSTRAINT `fk_calhol_cal` FOREIGN KEY (`calendar_id`) REFERENCES `calendars` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `comments`
--
ALTER TABLE `comments`
  ADD CONSTRAINT `fk_c_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `dependencies`
--
ALTER TABLE `dependencies`
  ADD CONSTRAINT `fk_dep_pred` FOREIGN KEY (`predecessor_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_dep_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `projects`
--
ALTER TABLE `projects`
  ADD CONSTRAINT `fk_proj_cal` FOREIGN KEY (`calendar_id`) REFERENCES `calendars` (`id`) ON DELETE RESTRICT;

--
-- Constraints for table `tasks`
--
ALTER TABLE `tasks`
  ADD CONSTRAINT `fk_t_ap` FOREIGN KEY (`apartment_id`) REFERENCES `apartments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_t_con` FOREIGN KEY (`contractor_id`) REFERENCES `contractors` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_t_proj` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
