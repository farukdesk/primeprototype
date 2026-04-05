-- Staff Profiles v2 Migration
-- Adds dept_id FK to staff_departments so educational staff departments
-- can be linked to their corresponding academic department (dept_departments).
-- Run once after staff-profiles.sql.

ALTER TABLE `staff_departments`
    ADD COLUMN `dept_id` INT UNSIGNED DEFAULT NULL
        COMMENT 'For educational type: links to dept_departments.id',
    ADD CONSTRAINT `fk_sd_dept` FOREIGN KEY (`dept_id`)
        REFERENCES `dept_departments`(`id`) ON DELETE SET NULL;
