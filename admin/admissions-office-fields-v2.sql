-- Migration: replace office Program/Student ID/Batch No with
-- University Batch, Department Batch, Section, Shift
-- Run once against the live database.

ALTER TABLE `admissions_applications`
    ADD COLUMN `office_university_batch` VARCHAR(100) NULL AFTER `expelled_detail`,
    ADD COLUMN `office_dept_batch`       VARCHAR(100) NULL AFTER `office_university_batch`,
    ADD COLUMN `office_section`          VARCHAR(100) NULL AFTER `office_dept_batch`,
    ADD COLUMN `office_shift`            VARCHAR(100) NULL AFTER `office_section`;

-- Remove old columns (data will be lost – back up first if needed)
ALTER TABLE `admissions_applications`
    DROP COLUMN `office_program`,
    DROP COLUMN `office_student_id`,
    DROP COLUMN `office_batch_no`;
