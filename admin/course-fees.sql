-- Course Fees Calculator – SQL Schema
-- Run AFTER database.sql, departments.sql, and access tables

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. cf_programs: fee structure per department / program / degree
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `cf_programs` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `dept_id`         INT UNSIGNED  DEFAULT NULL COMMENT 'FK dept_departments.id',
  `program_id`      INT UNSIGNED  DEFAULT NULL COMMENT 'FK dept_academic_programs.id',
  `degree_type`     ENUM('bachelor','master','diploma','certificate') NOT NULL DEFAULT 'bachelor',
  `credit_fee`      INT UNSIGNED  NOT NULL DEFAULT 0  COMMENT 'Tuition fee per credit hour (BDT)',
  `total_credits`   SMALLINT UNSIGNED DEFAULT NULL    COMMENT 'Total program credits (for display)',
  `duration_years`  DECIMAL(4,1)  DEFAULT NULL        COMMENT 'e.g. 4.0 years',
  `is_active`       TINYINT(1)    NOT NULL DEFAULT 1,
  `sort_order`      SMALLINT      NOT NULL DEFAULT 0,
  `created_by`      INT UNSIGNED  DEFAULT NULL,
  `updated_by`      INT UNSIGNED  DEFAULT NULL,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY `idx_cfp_dept`    (`dept_id`),
  KEY `idx_cfp_program` (`program_id`),

  CONSTRAINT `fk_cfp_dept`    FOREIGN KEY (`dept_id`)    REFERENCES `dept_departments`(`id`)        ON DELETE SET NULL,
  CONSTRAINT `fk_cfp_program` FOREIGN KEY (`program_id`) REFERENCES `dept_academic_programs`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cfp_created` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)                  ON DELETE SET NULL,
  CONSTRAINT `fk_cfp_updated` FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`)                  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. cf_fixed_fees: additional fees attached to a program
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `cf_fixed_fees` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `cf_program_id`  INT UNSIGNED  NOT NULL,
  `fee_name`       VARCHAR(200)  NOT NULL,
  `amount`         INT UNSIGNED  NOT NULL DEFAULT 0,
  `fee_type`       ENUM('one_time','per_semester') NOT NULL DEFAULT 'one_time'
                     COMMENT 'one_time = paid once; per_semester = paid every semester',
  `sort_order`     SMALLINT      NOT NULL DEFAULT 0,
  `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

  KEY `idx_cff_program` (`cf_program_id`),
  CONSTRAINT `fk_cff_program` FOREIGN KEY (`cf_program_id`) REFERENCES `cf_programs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. cf_settings: global settings for the public calculator page
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `cf_settings` (
  `id`          TINYINT UNSIGNED NOT NULL DEFAULT 1 PRIMARY KEY,
  `page_title`  VARCHAR(300)  DEFAULT 'Course Fees Calculator',
  `page_subtitle` TEXT        DEFAULT NULL,
  `note_text`   TEXT          DEFAULT NULL    COMMENT 'Disclaimer shown below the calculator',
  `currency`    VARCHAR(10)   NOT NULL DEFAULT 'BDT',
  `is_published` TINYINT(1)  NOT NULL DEFAULT 1,
  `updated_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default settings row
INSERT IGNORE INTO `cf_settings` (`id`, `page_title`, `page_subtitle`, `note_text`, `currency`, `is_published`)
VALUES (
  1,
  'Course Fees Calculator',
  'Estimate your tuition and fees at Prime University — transparent, real-time, and personalised.',
  'The fee estimates provided here are for general informational purposes only and are subject to change without prior notice. Actual fees may vary based on the programme, semester, and university policy. Please contact the accounts office or admission office for the most up-to-date fee schedule.',
  'BDT',
  1
);

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. Register module in modules table
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `is_active`, `sort_order`)
VALUES ('Course Fees Calculator', 'course-fees', 'Manage public-facing course fee structures and calculator settings', 1, 95);

SET FOREIGN_KEY_CHECKS = 1;
