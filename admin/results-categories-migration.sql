-- ─────────────────────────────────────────────────────────────────────────────
-- Migration: Marking Categories + Signoff Fields for Result Module
-- Run this ONCE on existing installs that already have result_exams,
-- result_subjects, and result_grades tables.
-- ─────────────────────────────────────────────────────────────────────────────

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. Per-subject marking category breakdown
--    e.g. Attendance 10%, Class Test 10%, Mid Term 30%, Final 50%
CREATE TABLE IF NOT EXISTS `result_mark_categories` (
  `id`            INT UNSIGNED   NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `subject_id`    INT UNSIGNED   NOT NULL               COMMENT 'FK → result_subjects.id',
  `category_name` VARCHAR(100)   NOT NULL               COMMENT 'e.g. Attendance, Class Test, Mid Term, Final',
  `max_marks`     DECIMAL(5,2)   NOT NULL DEFAULT 100   COMMENT 'Maximum marks allocated to this category',
  `sort_order`    SMALLINT       NOT NULL DEFAULT 0,
  `created_at`    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_rmc_subject` (`subject_id`),
  CONSTRAINT `fk_rmc_subject` FOREIGN KEY (`subject_id`) REFERENCES `result_subjects`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'Marking category breakdown per subject';

-- 2. Per-category marks for each grade row
CREATE TABLE IF NOT EXISTS `result_grade_details` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `grade_id`       INT UNSIGNED  NOT NULL               COMMENT 'FK → result_grades.id',
  `category_id`    INT UNSIGNED  NOT NULL               COMMENT 'FK → result_mark_categories.id',
  `marks_obtained` DECIMAL(5,2)  NOT NULL DEFAULT 0     COMMENT 'Marks obtained in this category',
  `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_grade_cat` (`grade_id`, `category_id`),
  KEY `idx_rgd_grade`    (`grade_id`),
  KEY `idx_rgd_category` (`category_id`),
  CONSTRAINT `fk_rgd_grade`    FOREIGN KEY (`grade_id`)    REFERENCES `result_grades`(`id`)         ON DELETE CASCADE,
  CONSTRAINT `fk_rgd_category` FOREIGN KEY (`category_id`) REFERENCES `result_mark_categories`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'Per-category marks for each student grade row';

-- 3. Add signoff columns to result_grades
ALTER TABLE `result_grades`
  ADD COLUMN IF NOT EXISTS `marked_by`   VARCHAR(200) DEFAULT NULL COMMENT 'Name of person who entered the marks',
  ADD COLUMN IF NOT EXISTS `reviewed_by` VARCHAR(200) DEFAULT NULL COMMENT 'Name of reviewer',
  ADD COLUMN IF NOT EXISTS `approved_by` VARCHAR(200) DEFAULT NULL COMMENT 'Name of approver';

SET FOREIGN_KEY_CHECKS = 1;
