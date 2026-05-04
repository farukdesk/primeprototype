-- Course Offer Module
-- Run AFTER course-curriculum.sql and intake-migration.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. co_offers: one row per offered course (dept + program + batch + subject)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `co_offers` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `dept_id`       INT UNSIGNED  NOT NULL COMMENT 'Offering department',
  `program_id`    INT UNSIGNED  NOT NULL COMMENT 'Offering program',
  `batch_id`      INT UNSIGNED  NOT NULL COMMENT 'FK → course_curriculum_intakes.id',
  `curriculum_id` INT UNSIGNED  NOT NULL COMMENT 'FK → course_curriculum.id (the subject)',
  `status`        ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by`    INT UNSIGNED  DEFAULT NULL,
  `created_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_co_batch_subject` (`batch_id`, `curriculum_id`),
  KEY `idx_co_dept`       (`dept_id`),
  KEY `idx_co_program`    (`program_id`),
  KEY `idx_co_curriculum` (`curriculum_id`),
  CONSTRAINT `fk_co_dept`
    FOREIGN KEY (`dept_id`) REFERENCES `dept_departments`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_co_program`
    FOREIGN KEY (`program_id`) REFERENCES `dept_academic_programs`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_co_batch`
    FOREIGN KEY (`batch_id`) REFERENCES `course_curriculum_intakes`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_co_curriculum`
    FOREIGN KEY (`curriculum_id`) REFERENCES `course_curriculum`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. co_offer_teachers: many-to-many – multiple teachers per offered course
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `co_offer_teachers` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `offer_id`   INT UNSIGNED NOT NULL,
  `faculty_id` INT UNSIGNED NOT NULL,
  `sort_order` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cot_offer_faculty` (`offer_id`, `faculty_id`),
  KEY `idx_cot_faculty` (`faculty_id`),
  CONSTRAINT `fk_cot_offer`
    FOREIGN KEY (`offer_id`) REFERENCES `co_offers`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_cot_faculty`
    FOREIGN KEY (`faculty_id`) REFERENCES `dept_faculty`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. Register the module for access control
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `can_create`, `can_edit`, `can_delete`)
VALUES ('Course Offer', 'course-offer', 1, 1, 1);
