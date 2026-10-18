-- ============================================================================
-- Staff Attendance module – database migration
-- ============================================================================
-- Adds daily staff attendance tracking. Attendance is stored in att_records
-- (one row per staff per day) holding the in/out times. Whether an entry is
-- "late in" or "early out" is decided by an effective office schedule:
--   1. a per-staff override (att_staff_schedule), when present and active; else
--   2. the global settings (att_settings): office start/close time, the
--      in-time / out-time grace buffers and the weekly-off days.
-- Dated holidays live in att_holidays and are excluded from absence counts.
--
-- The module was originally deployed without this migration, which caused
-- "Base table or view not found: att_settings" errors when saving settings.
-- This file (re)creates every table the module needs and registers the module
-- row so it appears on the Module Access page (admin/access/index.php).
--
-- Safe to run multiple times.
-- ============================================================================

-- ── Daily attendance records (one row per staff per day) ────────────────────
CREATE TABLE IF NOT EXISTS `att_records` (
    `id`         int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    int(10) UNSIGNED NOT NULL COMMENT 'FK users.id (staff member)',
    `work_date`  date             NOT NULL COMMENT 'The day this record is for',
    `in_time`    time             DEFAULT NULL COMMENT 'Clock-in time (NULL when absent)',
    `out_time`   time             DEFAULT NULL COMMENT 'Clock-out time (NULL when not recorded)',
    `remarks`    varchar(255)     DEFAULT NULL,
    `created_by` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK users.id who entered the record',
    `created_at` datetime         NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_user_date` (`user_id`, `work_date`),
    KEY `idx_att_date` (`work_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Global office-schedule settings (simple key / value store) ──────────────
-- Keys used by the module: office_start_time, office_close_time,
-- in_buffer_minutes, out_buffer_minutes, weekly_off_days.
CREATE TABLE IF NOT EXISTS `att_settings` (
    `setting_key` varchar(64) NOT NULL,
    `setting_val` text        DEFAULT NULL,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Per-staff schedule overrides ────────────────────────────────────────────
-- Any NULL column falls back to the corresponding global setting.
CREATE TABLE IF NOT EXISTS `att_staff_schedule` (
    `id`                 int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`            int(10) UNSIGNED NOT NULL COMMENT 'FK users.id (staff member)',
    `start_time`         time             DEFAULT NULL,
    `close_time`         time             DEFAULT NULL,
    `in_buffer_minutes`  smallint(5) UNSIGNED DEFAULT NULL,
    `out_buffer_minutes` smallint(5) UNSIGNED DEFAULT NULL,
    `is_active`          tinyint(1)       NOT NULL DEFAULT 1,
    `created_at`         datetime         NOT NULL DEFAULT current_timestamp(),
    `updated_at`         datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Dated holidays (excluded from absence counts) ───────────────────────────
CREATE TABLE IF NOT EXISTS `att_holidays` (
    `id`           int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `holiday_date` date             NOT NULL,
    `title`        varchar(150)     NOT NULL,
    `created_at`   datetime         NOT NULL DEFAULT current_timestamp(),
    `updated_at`   datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_holiday_date` (`holiday_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Module registration (idempotent) ────────────────────────────────────────
INSERT INTO `modules`
    (`name`, `slug`, `description`, `icon`, `parent_id`, `sort_order`,
     `is_active`, `can_view`, `can_create`, `can_edit`, `can_delete`)
SELECT
    'Staff Attendance',
    'staff-attendance',
    'Daily staff attendance with a configurable office schedule, per-staff overrides and holidays; derives late-in / early-out / absent status and integrates approved leave.',
    'fas fa-user-clock',
    NULL,
    61,
    1, 1, 1, 1, 1
WHERE NOT EXISTS (
    SELECT 1 FROM `modules` WHERE `slug` = 'staff-attendance'
);
