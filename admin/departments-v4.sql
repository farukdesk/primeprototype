-- ============================================================
-- Departments v4 Migration
-- Run after departments-v3.sql
-- Adds admission_content, fees_content, curriculum_content
-- to dept_academic_programs for structured section editing.
-- ============================================================

ALTER TABLE `dept_academic_programs`
    ADD COLUMN IF NOT EXISTS `admission_content` LONGTEXT DEFAULT NULL
        COMMENT 'Rich HTML (TinyMCE) – Admission Intake & Requirements section'
        AFTER `details_content`,
    ADD COLUMN IF NOT EXISTS `fees_content` LONGTEXT DEFAULT NULL
        COMMENT 'Rich HTML (TinyMCE) – Fees Structure section'
        AFTER `admission_content`,
    ADD COLUMN IF NOT EXISTS `curriculum_content` LONGTEXT DEFAULT NULL
        COMMENT 'Rich HTML (TinyMCE) – Course Curriculum section'
        AFTER `fees_content`;
