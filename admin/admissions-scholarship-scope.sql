-- Admissions Scholarship – Semester Scope Support
-- Run AFTER admissions-scholarship-type.sql
--
-- Adds scholarship_scope so a scholarship can apply only to the first
-- semester or to every semester in the package.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE `admissions_applications`
    ADD COLUMN `scholarship_scope` ENUM('first_semester','all_semesters') NOT NULL DEFAULT 'first_semester'
                                   COMMENT 'first_semester = first semester only; all_semesters = every semester'
                                   AFTER `scholarship_discount_pct`;

SET FOREIGN_KEY_CHECKS = 1;
