-- Course Fees Calculator – v3 Migration
-- Run AFTER course-fees.sql and course-fees-v2.sql
-- 1. Allows fractional total credits (e.g. 160.5, 160.25)
-- 2. Adds num_semesters column for accurate per-semester total calculation

SET NAMES utf8mb4;

-- Allow fractional total credits (e.g. 160.5, 160.25)
ALTER TABLE `cf_programs`
    MODIFY COLUMN `total_credits` DECIMAL(6,2) DEFAULT NULL
        COMMENT 'Total program credits, supports decimals e.g. 160.5';

-- Number of semesters in the program (e.g. 8 for semester-based bachelor, 12 for trimester-based)
ALTER TABLE `cf_programs`
    ADD COLUMN IF NOT EXISTS `num_semesters` TINYINT UNSIGNED DEFAULT NULL
        COMMENT 'Total semesters in program (used to calculate per-semester fee totals)'
        AFTER `duration_years`;
