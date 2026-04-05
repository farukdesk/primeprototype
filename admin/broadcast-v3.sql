-- ============================================================
-- Broadcast Module v3 – Student Recipient Filters
-- Run this file after broadcast-v2.sql
-- ============================================================

-- Extend recipient_type enum to include 'students'
ALTER TABLE `broadcasts`
    MODIFY COLUMN `recipient_type`
        ENUM('individual','group','all','students') NOT NULL DEFAULT 'all';

-- Student filter columns (all nullable = "no filter applied")
ALTER TABLE `broadcasts`
    ADD COLUMN `student_dept_id`    INT UNSIGNED  DEFAULT NULL COMMENT 'FK dept_departments.id – NULL = all departments'  AFTER `recipient_group_id`,
    ADD COLUMN `student_program_id` INT UNSIGNED  DEFAULT NULL COMMENT 'FK dept_academic_programs.id – NULL = all programs' AFTER `student_dept_id`,
    ADD COLUMN `student_status`     VARCHAR(20)   DEFAULT NULL COMMENT 'Active|Inactive|Graduated|Dropped – NULL = all'    AFTER `student_program_id`,
    ADD COLUMN `student_semester`   VARCHAR(50)   DEFAULT NULL COMMENT 'e.g. Summer 2025 – NULL = all semesters'           AFTER `student_status`,
    ADD CONSTRAINT `fk_bc_student_dept`    FOREIGN KEY (`student_dept_id`)    REFERENCES `dept_departments`(`id`)       ON DELETE SET NULL,
    ADD CONSTRAINT `fk_bc_student_program` FOREIGN KEY (`student_program_id`) REFERENCES `dept_academic_programs`(`id`) ON DELETE SET NULL;
