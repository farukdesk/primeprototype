-- ============================================================================
-- Leave Management module – database migration
-- ============================================================================
-- Adds staff leave management: per-user leave balances (Casual / Sick), leave
-- requests (Casual, Sick, Additional, Short, Maternity, Paternity, Study) and a
-- configurable, ordered, multi-user-group approval workflow that is scoped per
-- requester user group, so different departments / groups can have different
-- approval systems. Each approver applies their uploaded signature.
--
-- Leave types:
--   Casual / Sick        – consume the yearly balance (default 10 days each)
--   Additional           – paid/unpaid (chosen), no balance
--   Short                – always paid; single day with a start/end time
--   Maternity            – entitlement of 120 days, paid
--   Paternity            – entitlement of 7 days, paid
--   Study                – always unpaid, no balance
--
-- The sidebar and feature pages gate access with can_access('leave-management').
-- This migration also registers the module row so it appears on the Module
-- Access page (admin/access/index.php) and can be granted to groups / users.
--
-- Safe to run multiple times.
-- ============================================================================

-- ── Per-user leave balances (Casual / Sick only) ────────────────────────────
-- Additional and Short leave have no balance; they are just paid/unpaid.
CREATE TABLE IF NOT EXISTS `leave_balances` (
    `id`           int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`      int(10) UNSIGNED NOT NULL COMMENT 'FK users.id',
    `year`         smallint(5) UNSIGNED NOT NULL COMMENT 'Calendar year the balance applies to',
    `casual_total` decimal(5,1)     NOT NULL DEFAULT 10.0 COMMENT 'Total casual-leave days for the year',
    `sick_total`   decimal(5,1)     NOT NULL DEFAULT 10.0 COMMENT 'Total sick-leave days for the year',
    `created_at`   datetime         NOT NULL DEFAULT current_timestamp(),
    `updated_at`   datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_user_year` (`user_id`, `year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Ordered, group-based approval flow (per requester user group) ───────────
-- Admins first pick a requester user group (the department / staff group) and
-- then assign which user group approves at each step, in order. Different
-- requester groups can therefore have completely different approval systems.
CREATE TABLE IF NOT EXISTS `leave_approval_flow` (
    `id`                 int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `requester_group_id` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'FK user_groups.id whose members this flow applies to (the requester group / department)',
    `step_order`         smallint(5) UNSIGNED NOT NULL COMMENT 'Ascending approval step order (1 = first)',
    `group_id`           int(10) UNSIGNED NOT NULL COMMENT 'FK user_groups.id that approves this step',
    `label`              varchar(120)     DEFAULT NULL COMMENT 'Optional label shown on the request (e.g. "Head of Dept")',
    `is_active`          tinyint(1)       NOT NULL DEFAULT 1,
    `created_at`         datetime         NOT NULL DEFAULT current_timestamp(),
    `updated_at`         datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_flow_order` (`step_order`),
    KEY `idx_flow_reqgroup` (`requester_group_id`, `step_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Upgrade older installs: add the requester-group scoping column + index.
ALTER TABLE `leave_approval_flow`
    ADD COLUMN IF NOT EXISTS `requester_group_id` int(10) UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'FK user_groups.id whose members this flow applies to (the requester group / department)'
        AFTER `id`;
ALTER TABLE `leave_approval_flow`
    ADD INDEX IF NOT EXISTS `idx_flow_reqgroup` (`requester_group_id`, `step_order`);

-- ── Leave requests ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `leave_requests` (
    `id`           int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`      int(10) UNSIGNED NOT NULL COMMENT 'Requesting staff user (FK users.id)',
    `category`     enum('casual','sick','additional','short','maternity','paternity','study') NOT NULL,
    `pay_type`     enum('paid','unpaid') DEFAULT NULL COMMENT 'Paid/unpaid marker (fixed for short/study/maternity/paternity, chosen for additional)',
    `start_date`   date             NOT NULL,
    `end_date`     date             NOT NULL,
    `start_time`   time             DEFAULT NULL COMMENT 'Short leave only',
    `end_time`     time             DEFAULT NULL COMMENT 'Short leave only',
    `days`         decimal(5,1)     NOT NULL DEFAULT 0.0 COMMENT 'Whole days consumed (0 for short leave)',
    `reason`       text             NOT NULL,
    `status`       enum('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    `current_step` smallint(5) UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Next pending approval step',
    `created_at`   datetime         NOT NULL DEFAULT current_timestamp(),
    `updated_at`   datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_lr_user`   (`user_id`),
    KEY `idx_lr_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Upgrade older installs: widen the category enum with the new leave types.
ALTER TABLE `leave_requests`
    MODIFY COLUMN `category`
        enum('casual','sick','additional','short','maternity','paternity','study') NOT NULL;

-- ── Per-request approval steps (snapshot of the flow at request time) ───────
CREATE TABLE IF NOT EXISTS `leave_request_approvals` (
    `id`             int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `request_id`     int(10) UNSIGNED NOT NULL COMMENT 'FK leave_requests.id',
    `step_order`     smallint(5) UNSIGNED NOT NULL,
    `group_id`       int(10) UNSIGNED NOT NULL COMMENT 'Approving group (FK user_groups.id)',
    `label`          varchar(120)     DEFAULT NULL,
    `status`         enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    `approver_id`    int(10) UNSIGNED DEFAULT NULL COMMENT 'User who acted (FK users.id)',
    `signature_file` varchar(255)     DEFAULT NULL COMMENT 'Snapshot of approver signature image',
    `note`           text             DEFAULT NULL,
    `acted_at`       datetime         DEFAULT NULL,
    `created_at`     datetime         NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_request_step` (`request_id`, `step_order`),
    KEY `idx_lra_request` (`request_id`),
    KEY `idx_lra_group`   (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Module registration (idempotent) ────────────────────────────────────────
INSERT INTO `modules`
    (`name`, `slug`, `description`, `icon`, `parent_id`, `sort_order`,
     `is_active`, `can_view`, `can_create`, `can_edit`, `can_delete`)
SELECT
    'Leave Management',
    'leave-management',
    'Staff leave requests (Casual, Sick, Additional, Short, Maternity, Paternity, Study) with per-user balances and a per-group, step-by-step signed approval workflow.',
    'fas fa-plane-departure',
    NULL,
    62,
    1, 1, 1, 1, 1
WHERE NOT EXISTS (
    SELECT 1 FROM `modules` WHERE `slug` = 'leave-management'
);
