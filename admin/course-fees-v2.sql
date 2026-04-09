-- Course Fees v2 – Monthly Billing + num_semesters
-- Run AFTER course-fees.sql on existing databases.
-- Safe to run multiple times.

SET NAMES utf8mb4;

-- 1. Add num_semesters to cf_programs (used to compute total per-semester costs)
ALTER TABLE `cf_programs`
  ADD COLUMN IF NOT EXISTS `num_semesters` SMALLINT UNSIGNED DEFAULT NULL
    COMMENT 'Total number of semesters (used to compute per-semester totals)'
    AFTER `duration_years`;

-- 2. Extend fee_type ENUM to include 'monthly' billing
ALTER TABLE `cf_fixed_fees`
  MODIFY COLUMN `fee_type`
    ENUM('one_time', 'per_semester', 'monthly') NOT NULL DEFAULT 'one_time'
    COMMENT 'one_time = paid once at admission; per_semester = paid every semester; monthly = total ÷ program months';
