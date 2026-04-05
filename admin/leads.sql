-- Lead Management Module – SQL Schema
-- Run AFTER database.sql, departments.sql, and access tables

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. leads: core lead record
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `leads` (
  `id`                INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `lead_number`       VARCHAR(30)   NOT NULL UNIQUE COMMENT 'e.g. LD-2025-0001',

  -- Personal
  `first_name`        VARCHAR(100)  NOT NULL,
  `last_name`         VARCHAR(100)  NOT NULL,
  `email`             VARCHAR(200)  DEFAULT NULL,
  `phone`             VARCHAR(30)   NOT NULL,
  `address`           TEXT          DEFAULT NULL,
  `current_city`      VARCHAR(200)  DEFAULT NULL,

  -- Education
  `degree_type`       ENUM('bachelor','master') NOT NULL DEFAULT 'bachelor',
  `dept_id`           INT UNSIGNED  DEFAULT NULL COMMENT 'Interested department',
  `program_id`        INT UNSIGNED  DEFAULT NULL COMMENT 'Interested program',
  `preferred_semester`VARCHAR(50)   DEFAULT NULL,

  -- Lead classification
  `status`            ENUM('fresh','unable_to_reach','converted') NOT NULL DEFAULT 'fresh',
  `source`            ENUM('online','campus_visit','agent','f2f_marketing') NOT NULL DEFAULT 'online',

  -- Tracking
  `assigned_to`       INT UNSIGNED  DEFAULT NULL COMMENT 'Primary assigned user',
  `created_by`        INT UNSIGNED  DEFAULT NULL,
  `updated_by`        INT UNSIGNED  DEFAULT NULL,
  `created_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY `idx_leads_status`   (`status`),
  KEY `idx_leads_source`   (`source`),
  KEY `idx_leads_dept`     (`dept_id`),
  KEY `idx_leads_program`  (`program_id`),
  KEY `idx_leads_assigned` (`assigned_to`),

  CONSTRAINT `fk_leads_dept`    FOREIGN KEY (`dept_id`)    REFERENCES `dept_departments`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_leads_program` FOREIGN KEY (`program_id`) REFERENCES `dept_academic_programs`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_leads_created` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_leads_updated` FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. lead_assignments: multiple users can be assigned to a lead
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `lead_assignments` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `lead_id`    INT UNSIGNED NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL,
  `assigned_by`INT UNSIGNED DEFAULT NULL,
  `assigned_at`DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_lead_user` (`lead_id`, `user_id`),
  CONSTRAINT `fk_la_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_la_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. lead_notes: staff notes on a lead
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `lead_notes` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `lead_id`    INT UNSIGNED NOT NULL,
  `user_id`    INT UNSIGNED DEFAULT NULL,
  `note`       TEXT         NOT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_ln_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ln_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. lead_history: automatic audit trail of all field changes
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `lead_history` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `lead_id`     INT UNSIGNED  NOT NULL,
  `user_id`     INT UNSIGNED  DEFAULT NULL,
  `action`      VARCHAR(50)   NOT NULL COMMENT 'created, updated, status_changed, assigned, note_added, appointment_set …',
  `field_name`  VARCHAR(100)  DEFAULT NULL,
  `old_value`   TEXT          DEFAULT NULL,
  `new_value`   TEXT          DEFAULT NULL,
  `description` TEXT          DEFAULT NULL,
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_lh_lead` (`lead_id`),
  CONSTRAINT `fk_lh_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lh_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 5. lead_appointments: campus visit appointments
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `lead_appointments` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `lead_id`         INT UNSIGNED  NOT NULL,
  `appointment_date`DATE          NOT NULL,
  `appointment_time`TIME          DEFAULT NULL,
  `purpose`         VARCHAR(300)  DEFAULT NULL,
  `notes`           TEXT          DEFAULT NULL,
  `status`          ENUM('scheduled','completed','cancelled','no_show') NOT NULL DEFAULT 'scheduled',
  `created_by`      INT UNSIGNED  DEFAULT NULL,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_appt_lead`   (`lead_id`),
  KEY `idx_appt_date`   (`appointment_date`),
  KEY `idx_appt_status` (`status`),
  CONSTRAINT `fk_appt_lead`    FOREIGN KEY (`lead_id`)    REFERENCES `leads`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_appt_created` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 6. Register module in modules table
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `is_active`, `sort_order`)
VALUES ('Lead Management', 'leads', 'Manage and track prospective student leads', 1, 90);

SET FOREIGN_KEY_CHECKS = 1;
