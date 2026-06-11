-- ============================================================
-- Admissions Top Sheet – Database Schema & Default Settings
-- ============================================================
-- Run after admissions.sql and admissions-v2.sql
-- All statements are idempotent (safe to re-run).
-- ============================================================

SET NAMES utf8mb4;

-- ── Program label mappings for the Top Sheet report ──────────────────────────
-- Maps a program_id to a short display label and full degree name.
-- Programs without a mapping fall back to the program_name from dept_academic_programs.
CREATE TABLE IF NOT EXISTS `admissions_top_sheet_programs` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `program_id`  INT UNSIGNED NOT NULL COMMENT 'FK to dept_academic_programs.id',
    `short_label` VARCHAR(100) NOT NULL  COMMENT 'Short label shown in report table (e.g. BBA, MBA 69 cr.)',
    `full_name`   VARCHAR(255) NULL      COMMENT 'Full degree name for the legend (e.g. Bachelor of Business Administration (BBA)- 4 Years)',
    `sort_order`  SMALLINT NOT NULL DEFAULT 0,
    `is_visible`  TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = show in report, 0 = hide',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ts_program_id` (`program_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Default Top Sheet settings stored in admissions_settings ─────────────────
INSERT IGNORE INTO `admissions_settings` (`setting_key`, `setting_value`) VALUES
('top_sheet_semester_label',   'Summer Semester 2026'),
('top_sheet_months',           '4'),
('top_sheet_admission_label',  'Admission in Summer 2026');

-- ── Register module so access-control works ───────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `icon`, `sort_order`, `is_active`) VALUES
('Admission Top Sheet', 'admissions-top-sheet', 'fas fa-table', 51, 1);
