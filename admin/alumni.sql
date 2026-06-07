-- ============================================================
-- Alumni Module – Schema
-- Run after admin_primepnew2026.sql
-- Creates the `alumni` table used by the dedicated Alumni module.
-- This is separate from `dept_alumni` (per-department alumni) and
-- `cms_alumni` (homepage notable alumni widget).
-- All statements are idempotent (safe to re-run).
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `alumni` (
  `id`           int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `dept_id`      int(10) UNSIGNED DEFAULT NULL
                 COMMENT 'FK to dept_departments; NULL = no department assigned',
  `name`         varchar(200)     NOT NULL,
  `batch`        varchar(100)     DEFAULT NULL
                 COMMENT 'Graduation batch label, e.g. 26th or Spring 2018',
  `company`      varchar(200)     DEFAULT NULL
                 COMMENT 'Current employer / organisation',
  `position`     varchar(200)     DEFAULT NULL
                 COMMENT 'Current role / job title',
  `linkedin_url` varchar(500)     DEFAULT NULL,
  `fb_url`       varchar(500)     DEFAULT NULL,
  `photo`        varchar(300)     DEFAULT NULL
                 COMMENT 'Filename stored under admin/uploads/alumni/',
  `status`       ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `admin_notes`  text             DEFAULT NULL
                 COMMENT 'Admin remarks (e.g. rejection reason)',
  `sort_order`   int(11)          NOT NULL DEFAULT 0,
  `is_active`    tinyint(1)       NOT NULL DEFAULT 1,
  `created_at`   datetime         NOT NULL DEFAULT current_timestamp(),
  `updated_at`   datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_alumni_dept`   (`dept_id`),
  KEY `idx_alumni_status` (`status`),
  CONSTRAINT `fk_alumni_module_dept` FOREIGN KEY (`dept_id`)
      REFERENCES `dept_departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Register module (idempotent)
INSERT IGNORE INTO `modules` (`name`, `slug`, `icon`, `sort_order`, `is_active`) VALUES
('Alumni',  'alumni',  'fas fa-user-graduate',  67, 1);
