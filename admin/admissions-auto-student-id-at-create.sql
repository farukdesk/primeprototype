-- ============================================================
-- Admissions – Auto-generate student ID at form creation
-- Run this file after admissions-student-id.sql
-- ============================================================

-- ── Add assigned_student_id to application record ──────────────────────────────
ALTER TABLE `admissions_applications`
    ADD COLUMN IF NOT EXISTS `assigned_student_id` VARCHAR(50) NULL DEFAULT NULL
        AFTER `app_number`;

-- ── Extend students status ENUM to include Not Admitted Yet ───────────────────
ALTER TABLE `students`
    MODIFY `status` ENUM('Active','Inactive','Graduated','Dropped','Not Admitted Yet')
        NOT NULL DEFAULT 'Active';

-- ── Field seed for assigned_student_id in print templates ─────────────────────
INSERT IGNORE INTO `admissions_fields` (`field_key`, `field_label`, `sort_order`)
VALUES ('assigned_student_id', 'Assigned Student ID', 59);
