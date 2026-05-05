-- Spring Result Module SQL Schema
-- Run AFTER database.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. sr_results: one row per published result set (e.g. "Spring 2026 Result")
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sr_results` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `title`        VARCHAR(300) NOT NULL                   COMMENT 'e.g. Spring 2026 Result',
  `semester`     VARCHAR(100) DEFAULT NULL               COMMENT 'e.g. Spring 2026',
  `description`  TEXT         DEFAULT NULL,
  `is_published` TINYINT(1)   NOT NULL DEFAULT 0         COMMENT '1 = visible on public page',
  `created_by`   INT UNSIGNED DEFAULT NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_sr_published` (`is_published`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'Result sets (one per semester/occasion)';

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. sr_result_entries: one row per student × course per result set
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sr_result_entries` (
  `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `result_id`    INT UNSIGNED  NOT NULL                  COMMENT 'FK → sr_results.id',
  `student_id`   VARCHAR(50)   NOT NULL                  COMMENT 'Student ID string',
  `student_name` VARCHAR(200)  DEFAULT NULL,
  `course_code`  VARCHAR(50)   DEFAULT NULL,
  `course_title` VARCHAR(300)  NOT NULL,
  `letter_grade` VARCHAR(10)   NOT NULL                  COMMENT 'e.g. A+, B-',
  `grade_point`  DECIMAL(4,2)  DEFAULT NULL              COMMENT 'e.g. 4.00, 3.25',
  `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_sre_result`    (`result_id`),
  KEY `idx_sre_student`   (`student_id`),
  CONSTRAINT `fk_sre_result` FOREIGN KEY (`result_id`) REFERENCES `sr_results`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'Individual student course grade rows';

SET FOREIGN_KEY_CHECKS = 1;

-- ─────────────────────────────────────────────────────────────────────────────
-- Register module in the access-control system
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`, `is_active`)
VALUES
  ('Spring Result',        'spring-result',        'Manage and publish semester result sheets', 'fas fa-poll',        55, 1),
  ('Spring Result Entries','spring-result-entries','Manage individual student grade entries',   'fas fa-list-ol',     56, 1);
