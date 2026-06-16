-- ============================================================
-- Exam Invigilation Module – Schema
-- Run after admin_primepnew2026.sql
-- ============================================================

SET NAMES utf8mb4;

-- Exams
CREATE TABLE IF NOT EXISTS `ei_exams` (
  `id`         int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `exam_name`  varchar(200)     NOT NULL,
  `exam_year`  year(4)          NOT NULL,
  `is_active`  tinyint(1)       NOT NULL DEFAULT 1,
  `created_at` datetime         NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ei_exams_year` (`exam_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Module settings
CREATE TABLE IF NOT EXISTS `ei_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_val` text DEFAULT NULL,
  `created_at`  datetime     NOT NULL DEFAULT current_timestamp(),
  `updated_at`  datetime     NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `ei_settings` (`setting_key`, `setting_val`)
VALUES ('auto_assign_max_slots', '12');

-- Faculty availability pool (shared across all exams)
CREATE TABLE IF NOT EXISTS `ei_faculty` (
  `id`                int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `dept_id`           int(10) UNSIGNED NOT NULL
                      COMMENT 'FK to dept_departments',
  `name`              varchar(200)     NOT NULL,
  `designation`       varchar(200)     DEFAULT NULL,
  `weekend_available` tinyint(1)       NOT NULL DEFAULT 0
                      COMMENT '1 = available on Saturday/Sunday',
  `weekend_days`      varchar(50)      NOT NULL DEFAULT '0,6'
                      COMMENT 'Faculty weekly weekend/off days; date(w) values (0=Sun..6=Sat)',
  `contact_number`    varchar(50)      DEFAULT NULL,
  `is_active`         tinyint(1)       NOT NULL DEFAULT 1,
  `created_at`        datetime         NOT NULL DEFAULT current_timestamp(),
  `updated_at`        datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ei_faculty_dept` (`dept_id`),
  CONSTRAINT `fk_ei_faculty_dept` FOREIGN KEY (`dept_id`)
      REFERENCES `dept_departments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Exam slots (date + time + room), with up to 2 assigned invigilators
CREATE TABLE IF NOT EXISTS `ei_slots` (
  `id`          int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `exam_id`     int(10) UNSIGNED NOT NULL,
  `slot_date`   date             NOT NULL,
  `time_slot`   varchar(100)     NOT NULL
                COMMENT 'e.g. 9:00 AM – 12:00 PM',
  `room_number` varchar(50)      NOT NULL,
  `faculty1_id` int(10) UNSIGNED DEFAULT NULL
                COMMENT 'Primary invigilator',
  `faculty2_id` int(10) UNSIGNED DEFAULT NULL
                COMMENT 'Secondary invigilator (different department)',
  `created_at`  datetime         NOT NULL DEFAULT current_timestamp(),
  `updated_at`  datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ei_slots_exam`      (`exam_id`),
  KEY `idx_ei_slots_date`      (`slot_date`),
  KEY `idx_ei_slots_faculty1`  (`faculty1_id`),
  KEY `idx_ei_slots_faculty2`  (`faculty2_id`),
  CONSTRAINT `fk_ei_slots_exam`     FOREIGN KEY (`exam_id`)     REFERENCES `ei_exams`   (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ei_slots_fac1`     FOREIGN KEY (`faculty1_id`) REFERENCES `ei_faculty` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ei_slots_fac2`     FOREIGN KEY (`faculty2_id`) REFERENCES `ei_faculty` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Register module (idempotent)
INSERT IGNORE INTO `modules` (`name`, `slug`, `icon`, `sort_order`, `is_active`) VALUES
('Exam Invigilation', 'exam-invigilation', 'fas fa-user-check', 75, 1);
