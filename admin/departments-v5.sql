-- ============================================================
-- Departments v5 Migration
-- Run after departments-v4.sql
-- Adds Intake Periods and Eligibility Criteria modules
-- for individual academic programs.
-- ============================================================

-- Intake Periods: each program can have multiple intake windows
CREATE TABLE IF NOT EXISTS `program_intake_periods` (
  `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `program_id`    INT UNSIGNED    NOT NULL,
  `intake_name`   VARCHAR(200)    NOT NULL COMMENT 'e.g. Spring 2025',
  `open_date`     DATE            DEFAULT NULL,
  `close_date`    DATE            DEFAULT NULL,
  `intake_status` ENUM('open','upcoming','closed') NOT NULL DEFAULT 'upcoming',
  `notes`         TEXT            DEFAULT NULL,
  `sort_order`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pip_program` (`program_id`),
  CONSTRAINT `fk_pip_program`
    FOREIGN KEY (`program_id`) REFERENCES `dept_academic_programs` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Eligibility Criteria: structured list of requirements per program
CREATE TABLE IF NOT EXISTS `program_eligibility_criteria` (
  `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `program_id`  INT UNSIGNED    NOT NULL,
  `category`    VARCHAR(150)    NOT NULL DEFAULT 'General' COMMENT 'e.g. Academic, English Proficiency',
  `criterion`   TEXT            NOT NULL COMMENT 'The requirement text',
  `sort_order`  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `is_active`   TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pec_program` (`program_id`),
  CONSTRAINT `fk_pec_program`
    FOREIGN KEY (`program_id`) REFERENCES `dept_academic_programs` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
