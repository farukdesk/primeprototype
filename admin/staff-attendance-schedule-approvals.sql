-- ============================================================================
-- Staff Attendance – schedule approvals migration
-- ============================================================================
-- 1. att_schedule_approval_flow
--    A DEDICATED, ordered, per-requester-group approval chain for schedule
--    changes (weekend + Thursday/Friday slots). Separate from the Leave
--    Management chain (leave_approval_flow). Configured on the new
--    "Schedule Approval Flow" admin page.
-- 2. att_weekly_off_history
--    Effective-dated weekly-off (weekend) history. When a weekend change is
--    finally approved it takes effect FROM the approval date; every earlier
--    day keeps the weekend that was approved for it at the time, so past
--    reports never change retroactively.
-- 3. att_slot_requests / att_slot_request_approvals
--    Thursday/Friday slot changes now go through the schedule approval chain
--    too. The requested slots are stored as JSON and applied (effective-dated)
--    on final approval. Slot days must cover at least 8 hours in total
--    (On Campus + Online Class combined) – enforced at submission.
-- 4. att_day_slots effective dating
--    effective_from / effective_to columns so approved slot changes apply
--    only from their effective date forward.
--
-- Safe to run multiple times (MariaDB 10.x IF NOT EXISTS).
-- ============================================================================

CREATE TABLE IF NOT EXISTS `att_schedule_approval_flow` (
    `id`                 int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `requester_group_id` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'FK user_groups.id whose members this flow applies to (the requester group / department)',
    `step_order`         smallint(5) UNSIGNED NOT NULL COMMENT 'Ascending approval step order (1 = first)',
    `group_id`           int(10) UNSIGNED NOT NULL COMMENT 'FK user_groups.id that approves this step',
    `label`              varchar(120)     DEFAULT NULL COMMENT 'Optional label shown on the request (e.g. "Head of Dept")',
    `is_active`          tinyint(1)       NOT NULL DEFAULT 1,
    `created_at`         datetime         NOT NULL DEFAULT current_timestamp(),
    `updated_at`         datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_asf_order`    (`step_order`),
    KEY `idx_asf_reqgroup` (`requester_group_id`, `step_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `att_weekly_off_history` (
    `id`              int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`         int(10) UNSIGNED NOT NULL COMMENT 'Staff user (FK users.id)',
    `weekly_off_days` varchar(20)      NOT NULL COMMENT 'Weekly-off days, comma-separated ISO day numbers (1=Mon … 7=Sun); empty = none',
    `effective_from`  date             NOT NULL COMMENT 'The days apply from this date (inclusive) until a newer row takes over',
    `created_at`      datetime         NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_awoh_user_from` (`user_id`, `effective_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `att_slot_requests` (
    `id`           int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`      int(10) UNSIGNED NOT NULL COMMENT 'Requesting staff user (FK users.id)',
    `weekday`      tinyint(3) UNSIGNED NOT NULL COMMENT 'ISO weekday: 4 = Thursday, 5 = Friday',
    `slots_json`   text             DEFAULT NULL COMMENT 'Requested slots as JSON [{location,start,end}]; empty / [] = remove the customisation',
    `status`       enum('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    `current_step` smallint(5) UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Next pending approval step',
    `created_at`   datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at`   datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_asr_user`   (`user_id`),
    KEY `idx_asr_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `att_slot_request_approvals` (
    `id`          int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `request_id`  int(10) UNSIGNED NOT NULL COMMENT 'FK att_slot_requests.id',
    `step_order`  smallint(5) UNSIGNED NOT NULL,
    `group_id`    int(10) UNSIGNED NOT NULL COMMENT 'Approving group (FK user_groups.id)',
    `label`       varchar(120) DEFAULT NULL,
    `status`      enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    `approver_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'User who acted (FK users.id)',
    `note`        text DEFAULT NULL,
    `acted_at`    datetime DEFAULT NULL,
    `created_at`  datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_asra_request_step` (`request_id`, `step_order`),
    KEY `idx_asra_group` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Effective dating for Thu/Fri slots: an approved change applies from its
-- effective date; rows for earlier periods are closed with effective_to.
ALTER TABLE `att_day_slots`
    ADD COLUMN IF NOT EXISTS `effective_from` date NOT NULL DEFAULT '1000-01-01' COMMENT 'Slots apply from this date (inclusive)',
    ADD COLUMN IF NOT EXISTS `effective_to`   date DEFAULT NULL COMMENT 'Last date the slots apply (NULL = open-ended)';
