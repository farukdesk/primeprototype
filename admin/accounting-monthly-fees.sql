-- ============================================================
-- Accounting: Monthly Fee Tracking Enhancement
-- Adds month_number column to sfp_payments for per-month
-- installment tracking within a semester.
-- Run AFTER accounting-student-payment.sql
-- ============================================================

ALTER TABLE `sfp_payments`
  ADD COLUMN `month_number` TINYINT UNSIGNED DEFAULT NULL
    COMMENT 'Month within the semester (1-based); NULL for non-monthly or legacy payments'
  AFTER `semester_number`;
