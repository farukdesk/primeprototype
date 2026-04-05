-- ============================================================
-- Broadcast Module v3 – Student Recipient Filters
-- Run this file after broadcast-v2.sql (existing installations only)
-- ============================================================

-- Extend recipient_type enum to include 'students'
ALTER TABLE `broadcasts`
    MODIFY COLUMN `recipient_type`
        ENUM('individual','group','all','students') NOT NULL DEFAULT 'all';

-- Student filter columns (all nullable = "no filter applied")
ALTER TABLE `broadcasts`
    ADD COLUMN IF NOT EXISTS `student_dept_id`    INT UNSIGNED  DEFAULT NULL COMMENT 'FK dept_departments.id – NULL = all departments'  AFTER `recipient_group_id`,
    ADD COLUMN IF NOT EXISTS `student_program_id` INT UNSIGNED  DEFAULT NULL COMMENT 'FK dept_academic_programs.id – NULL = all programs' AFTER `student_dept_id`,
    ADD COLUMN IF NOT EXISTS `student_status`     VARCHAR(20)   DEFAULT NULL COMMENT 'Active|Inactive|Graduated|Dropped – NULL = all'    AFTER `student_program_id`,
    ADD COLUMN IF NOT EXISTS `student_semester`   VARCHAR(50)   DEFAULT NULL COMMENT 'e.g. Summer 2025 – NULL = all semesters'           AFTER `student_status`;

-- Add FKs for student filter columns if not already present
SET @fk1 = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'broadcasts'
      AND CONSTRAINT_NAME = 'fk_bc_student_dept'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql1 = IF(@fk1 = 0,
    'ALTER TABLE `broadcasts` ADD CONSTRAINT `fk_bc_student_dept` FOREIGN KEY (`student_dept_id`) REFERENCES `dept_departments`(`id`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt1 FROM @sql1; EXECUTE stmt1; DEALLOCATE PREPARE stmt1;

SET @fk2 = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'broadcasts'
      AND CONSTRAINT_NAME = 'fk_bc_student_program'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql2 = IF(@fk2 = 0,
    'ALTER TABLE `broadcasts` ADD CONSTRAINT `fk_bc_student_program` FOREIGN KEY (`student_program_id`) REFERENCES `dept_academic_programs`(`id`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;
