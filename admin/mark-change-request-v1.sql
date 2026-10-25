-- Mark Change Request (MCR) module — v1
--
-- Faculty module under the Controller of Examinations:
--  - Faculty search a student by Student ID and see the student's PUBLISHED
--    results of all semesters.
--  - They propose corrected marks for a course with a MANDATORY reason note.
--  - The request goes through the student's department mark approval chain
--    (wf_chains / wf_chain_steps — same flow as original mark sheets).
--  - When the final chain step approves, the change is applied to
--    result_sheet_grades and logged in the change log.
--
-- The schema below is also auto-migrated at runtime by mcr_ensure_schema()
-- in admin/mark-change-request/helpers.php. Safe to run multiple times.

-- 1. Requests
CREATE TABLE IF NOT EXISTS `mcr_requests` (
    `id`                 INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `grade_id`           INT(10) UNSIGNED NOT NULL COMMENT 'FK → result_sheet_grades.id',
    `sheet_id`           INT(10) UNSIGNED NOT NULL COMMENT 'FK → result_mark_sheets.id',
    `student_id`         INT(10) UNSIGNED DEFAULT NULL,
    `student_sid`        VARCHAR(25) NOT NULL,
    `student_name`       VARCHAR(200) DEFAULT NULL,
    `dept_id`            INT(10) UNSIGNED NOT NULL,
    `program_id`         INT(10) UNSIGNED DEFAULT NULL,
    `chain_id`           INT(10) UNSIGNED NOT NULL COMMENT 'FK → wf_chains.id',
    `current_step_order` TINYINT(3) UNSIGNED NOT NULL,
    `old_marks_json`     LONGTEXT DEFAULT NULL,
    `old_is_absent`      TINYINT(1) NOT NULL DEFAULT 0,
    `old_total`          DECIMAL(5,2) DEFAULT NULL,
    `old_letter`         VARCHAR(10) DEFAULT NULL,
    `old_point`          DECIMAL(4,2) DEFAULT NULL,
    `new_marks_json`     LONGTEXT DEFAULT NULL,
    `new_is_absent`      TINYINT(1) NOT NULL DEFAULT 0,
    `new_total`          DECIMAL(5,2) DEFAULT NULL,
    `new_letter`         VARCHAR(10) DEFAULT NULL,
    `new_point`          DECIMAL(4,2) DEFAULT NULL,
    `reason`             TEXT NOT NULL COMMENT 'Mandatory note from the faculty explaining the change',
    `status`             ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    `requested_by`       INT(10) UNSIGNED NOT NULL,
    `decided_at`         DATETIME DEFAULT NULL,
    `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_mcr_grade`     (`grade_id`),
    KEY `idx_mcr_status`    (`status`),
    KEY `idx_mcr_requester` (`requested_by`),
    KEY `idx_mcr_student`   (`student_sid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Mark change requests routed through the dept mark approval chain';

-- 2. History / audit trail
CREATE TABLE IF NOT EXISTS `mcr_history` (
    `id`         INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `request_id` INT(10) UNSIGNED NOT NULL,
    `step_order` TINYINT(3) UNSIGNED NOT NULL,
    `step_label` VARCHAR(200) DEFAULT NULL,
    `group_id`   INT(10) UNSIGNED DEFAULT NULL,
    `action`     ENUM('submitted','approved','applied','rejected') NOT NULL,
    `acted_by`   INT(10) UNSIGNED DEFAULT NULL,
    `acted_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `remarks`    TEXT DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_mcrh_req` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Audit trail of every action on a mark change request';

-- 3. Register the module (view + create; approvals are chain-based)
INSERT INTO `modules`
    (`name`, `slug`, `description`, `icon`, `parent_id`, `sort_order`,
     `is_active`, `can_view`, `can_create`, `can_edit`, `can_delete`)
SELECT
    'Mark Change Request',
    'mark-change-request',
    'Faculty request corrections to published student results; routed through the department mark approval chain.',
    'fas fa-file-pen',
    NULL,
    98,
    1, 1, 1, 0, 0
WHERE NOT EXISTS (
    SELECT 1 FROM `modules` WHERE `slug` = 'mark-change-request'
);

-- 4. Grant view + create to all active Faculty user groups
INSERT INTO `group_module_access` (`group_id`, `module_id`, `can_view`, `can_create`, `can_edit`, `can_delete`)
SELECT g.`id`, m.`id`, 1, 1, 0, 0
FROM `user_groups` g
JOIN `modules` m ON m.`slug` = 'mark-change-request'
WHERE g.`name` LIKE '%Faculty%'
  AND g.`is_active` = 1
  AND NOT EXISTS (
      SELECT 1 FROM `group_module_access` gma
      WHERE gma.`group_id` = g.`id` AND gma.`module_id` = m.`id`
  );
