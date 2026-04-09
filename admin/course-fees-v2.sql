-- Course Fees v2 – Monthly Billing Model
-- Run AFTER course-fees.sql
-- Adds 'monthly' fee type: fees whose total is divided by program months for monthly installments

SET NAMES utf8mb4;

ALTER TABLE `cf_fixed_fees`
  MODIFY COLUMN `fee_type`
    ENUM('one_time', 'per_semester', 'monthly') NOT NULL DEFAULT 'one_time'
    COMMENT 'one_time = paid once at admission; per_semester = paid every semester; monthly = total ÷ program months';
