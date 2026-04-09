-- Scholarship Module – SQL Schema
-- Run AFTER database.sql, departments.sql, students.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. sc_policies: scholarship policy definitions
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sc_policies` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(200) NOT NULL,
  `type`        ENUM('gpa_based','merit_based') NOT NULL DEFAULT 'gpa_based',
  `description` TEXT         DEFAULT NULL,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order`  SMALLINT     NOT NULL DEFAULT 0,
  `created_by`  INT UNSIGNED DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_scp_created` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. sc_tiers: GPA/CGPA brackets per policy
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sc_tiers` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `policy_id`        INT UNSIGNED  NOT NULL,
  `label`            VARCHAR(100)  DEFAULT NULL COMMENT 'e.g. Gold, Silver',
  `min_gpa`          DECIMAL(5,2)  NOT NULL     COMMENT 'Minimum GPA/CGPA to qualify (inclusive)',
  `max_gpa`          DECIMAL(5,2)  NOT NULL     COMMENT 'Maximum GPA/CGPA (inclusive)',
  `discount_percent` DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
  `sort_order`       SMALLINT      NOT NULL DEFAULT 0,
  `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_sct_policy` FOREIGN KEY (`policy_id`) REFERENCES `sc_policies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. sc_awards: scholarships awarded to students
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sc_awards` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `student_id`       INT UNSIGNED  NOT NULL,
  `policy_id`        INT UNSIGNED  NOT NULL,
  `tier_id`          INT UNSIGNED  DEFAULT NULL,
  `semester`         VARCHAR(50)   NOT NULL COMMENT 'Semester the award applies to, e.g. Fall 2025',
  `gpa_used`         DECIMAL(5,2)  DEFAULT NULL COMMENT 'The GPA value used to determine the tier',
  `discount_percent` DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
  `status`           ENUM('active','revoked') NOT NULL DEFAULT 'active',
  `note`             TEXT          DEFAULT NULL,
  `awarded_by`       INT UNSIGNED  DEFAULT NULL,
  `awarded_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `revoked_by`       INT UNSIGNED  DEFAULT NULL,
  `revoked_at`       DATETIME      DEFAULT NULL,
  `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_sca_student`  (`student_id`),
  KEY `idx_sca_semester` (`semester`),
  CONSTRAINT `fk_sca_student`    FOREIGN KEY (`student_id`) REFERENCES `students`(`id`)    ON DELETE CASCADE,
  CONSTRAINT `fk_sca_policy`     FOREIGN KEY (`policy_id`)  REFERENCES `sc_policies`(`id`),
  CONSTRAINT `fk_sca_tier`       FOREIGN KEY (`tier_id`)    REFERENCES `sc_tiers`(`id`)    ON DELETE SET NULL,
  CONSTRAINT `fk_sca_awarded_by` FOREIGN KEY (`awarded_by`) REFERENCES `users`(`id`)       ON DELETE SET NULL,
  CONSTRAINT `fk_sca_revoked_by` FOREIGN KEY (`revoked_by`) REFERENCES `users`(`id`)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. sc_settings: module-level settings
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sc_settings` (
  `id`               TINYINT UNSIGNED NOT NULL DEFAULT 1 PRIMARY KEY,
  `gpa_label`        VARCHAR(100)     NOT NULL DEFAULT 'SSC+HSC Combined GPA',
  `max_combined_gpa` DECIMAL(5,2)     NOT NULL DEFAULT 10.00 COMMENT 'Max possible combined GPA (e.g. 5+5=10)',
  `updated_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `sc_settings` (`id`, `gpa_label`, `max_combined_gpa`)
VALUES (1, 'SSC+HSC Combined GPA', 10.00);

-- ─────────────────────────────────────────────────────────────────────────────
-- 5. Register modules
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`, `is_active`)
VALUES ('Scholarships', 'scholarship', 'Manage scholarship policies, GPA tiers, and student awards', 'fas fa-graduation-cap', 60, 1);

INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`, `is_active`)
VALUES ('Scholarship Policies', 'scholarship-policies', 'Configure scholarship policies and GPA/CGPA tiers', 'fas fa-cog', 61, 1);

SET FOREIGN_KEY_CHECKS = 1;
