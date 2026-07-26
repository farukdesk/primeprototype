-- ============================================================================
-- Staff Attendance – portal self-service migration
-- ============================================================================
-- 1. att_weekend_requests / att_weekend_request_approvals
--    Staff pick their own weekend (weekly-off) day(s) from their portal. Each
--    request is routed through the ordered approval chain configured for the
--    requester's user group (leave_approval_flow – shared with Leave
--    Management). On final approval the days are written to
--    att_staff_schedule.weekly_off_days automatically.
-- 2. att_day_status
--    Per-staff, per-day "Approved Leave / Day Off" marks. Set manually by
--    module admins / the "Registrar office" user group, or automatically when
--    a leave request receives its final approval. A marked day counts as On
--    Leave (never Absent) on every attendance report.
-- 3. att_day_slots
--    Custom Thursday / Friday slots (On Campus / Online Class). The combined
--    slot window (first slot start – last slot end) becomes the staff
--    member's expected clock-in/out time for that day.
--
-- Safe to run multiple times.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `att_weekend_requests` (
    `id`              int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`         int(10) UNSIGNED NOT NULL COMMENT 'Requesting staff user (FK users.id)',
    `weekly_off_days` varchar(20)      NOT NULL COMMENT 'Requested weekly-off days, comma-separated ISO day numbers (1=Mon … 7=Sun)',
    `reason`          text             DEFAULT NULL,
    `status`          enum('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    `current_step`    smallint(5) UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Next pending approval step',
    `created_at`      datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at`      datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_awr_user`   (`user_id`),
    KEY `idx_awr_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `att_weekend_request_approvals` (
    `id`          int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `request_id`  int(10) UNSIGNED NOT NULL COMMENT 'FK att_weekend_requests.id',
    `step_order`  smallint(5) UNSIGNED NOT NULL,
    `group_id`    int(10) UNSIGNED NOT NULL COMMENT 'Approving group (FK user_groups.id)',
    `label`       varchar(120) DEFAULT NULL,
    `status`      enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    `approver_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'User who acted (FK users.id)',
    `note`        text DEFAULT NULL,
    `acted_at`    datetime DEFAULT NULL,
    `created_at`  datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_awra_request_step` (`request_id`, `step_order`),
    KEY `idx_awra_group` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `att_day_status` (
    `id`               int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`          int(10) UNSIGNED NOT NULL COMMENT 'Staff user (FK users.id)',
    `status_date`      date NOT NULL,
    `status`           enum('approved_leave','day_off') NOT NULL DEFAULT 'approved_leave',
    `note`             varchar(255) DEFAULT NULL,
    `source`           enum('manual','leave') NOT NULL DEFAULT 'manual' COMMENT 'manual = admin/Registrar office; leave = auto-marked on final leave approval',
    `leave_request_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK leave_requests.id when source = leave',
    `created_by`       int(10) UNSIGNED DEFAULT NULL COMMENT 'User who marked the day (FK users.id)',
    `created_at`       datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_ads_user_date` (`user_id`, `status_date`),
    KEY `idx_ads_date` (`status_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `att_day_slots` (
    `id`         int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    int(10) UNSIGNED NOT NULL COMMENT 'Staff user (FK users.id)',
    `weekday`    tinyint(3) UNSIGNED NOT NULL COMMENT 'ISO weekday: 4 = Thursday, 5 = Friday',
    `slot_no`    smallint(5) UNSIGNED NOT NULL DEFAULT 1,
    `location`   enum('campus','online') NOT NULL DEFAULT 'campus' COMMENT 'campus = On Campus, online = Online Class',
    `start_time` time NOT NULL,
    `end_time`   time NOT NULL,
    `is_active`  tinyint(1) NOT NULL DEFAULT 1,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_adsl_user_day` (`user_id`, `weekday`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
