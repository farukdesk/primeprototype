-- Student Verification Module – v2 Migration
-- Run AFTER student-verification.sql (initial schema)
-- Adds student_data_ok / student_data_issues columns introduced in the
-- multi-step wizard redesign (Step 1: student record confirmation).

SET NAMES utf8mb4;

ALTER TABLE `student_verifications`
    ADD COLUMN `student_data_ok`     TINYINT(1) NOT NULL DEFAULT 1
        COMMENT '1=details match presented documents, 0=data mismatch'
        AFTER `verified_by`,
    ADD COLUMN `student_data_issues` TEXT DEFAULT NULL
        COMMENT 'Description of student data mismatch (Step 1)'
        AFTER `student_data_ok`;
