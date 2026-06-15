-- VC Scholarship Approval Module
-- Creates the vc_scholarship_approvals table and registers the module.
-- Run AFTER student-fee-package.sql and student-accounts.sql.
--
-- Workflow:
--   1. Admin adds a scholarship on the student account view → creates a pending row here.
--   2. VC (Vice Chancellor user group) reviews in admin/vc-approval/ and approves or rejects.
--   3. On approval the scholarship is written into sfp_semester_scholarships and totals recalculated.
--   4. Approved records are locked; only a Super Admin can revoke (undo) an approved scholarship.

SET NAMES utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. Approval requests table
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `vc_scholarship_approvals` (
    `id`               INT UNSIGNED    NOT NULL AUTO_INCREMENT,

    -- Scholarship target
    `package_id`       INT UNSIGNED    NOT NULL COMMENT 'sfp_packages.id',
    `student_id`       INT UNSIGNED    NOT NULL COMMENT 'students.id',
    `sf_id`            INT UNSIGNED        NULL COMMENT 'sfp_semester_fees.id; NULL when apply_to_all=1',
    `apply_to_all`     TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = applies to every semester in the package',

    -- Scholarship details (mirrors sfp_semester_scholarships columns)
    `label`            VARCHAR(255)    NOT NULL,
    `discount_type`    ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',
    `discount_pct`     DECIMAL(7,4)    NOT NULL DEFAULT 0,
    `fixed_amount`     DECIMAL(10,2)       NULL DEFAULT NULL,
    `sc_note`          TEXT                NULL,
    `is_from_policy`   TINYINT(1)      NOT NULL DEFAULT 0,
    `applies_to_fixed`   TINYINT(1)    NOT NULL DEFAULT 0,
    `applies_to_english` TINYINT(1)    NOT NULL DEFAULT 0,
    `support_doc_id`   INT UNSIGNED        NULL COMMENT 'student_files.id for the supporting document',

    -- Workflow state
    `status`           ENUM('pending','approved','rejected','revoked') NOT NULL DEFAULT 'pending',
    `requested_by`     INT UNSIGNED    NOT NULL COMMENT 'users.id – admin who submitted the request',
    `reviewed_by`      INT UNSIGNED        NULL COMMENT 'users.id – VC who approved/rejected',
    `reviewed_at`      DATETIME            NULL,
    `review_note`      TEXT                NULL COMMENT 'VC note on approval or rejection',

    -- Revoke (super-admin undo of an approved scholarship)
    `revoked_by`       INT UNSIGNED        NULL COMMENT 'users.id – super-admin who revoked',
    `revoked_at`       DATETIME            NULL,
    `revoke_reason`    TEXT                NULL,

    `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_package`  (`package_id`),
    KEY `idx_student`  (`student_id`),
    KEY `idx_status`   (`status`),
    KEY `idx_requested_by` (`requested_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. Register the module
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`, `is_active`)
VALUES (
    'VC Approval',
    'vc-approval',
    'Vice Chancellor scholarship approval queue – review, approve or reject pending scholarship requests',
    'fas fa-user-check',
    57,
    1
);
