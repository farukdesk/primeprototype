-- ============================================================
-- Departments v3 Migration
-- Run after departments-v2.sql
-- Adds semester_type to dept_academic_programs.
-- ============================================================

ALTER TABLE `dept_academic_programs`
    ADD COLUMN IF NOT EXISTS `semester_type` VARCHAR(50) DEFAULT NULL
        COMMENT 'Semester system: trimester, semester, annual, etc.'
        AFTER `total_credit`;
