-- Student Accounts – Fixed-Amount Scholarship Support
-- Run AFTER student-fee-package-v3.sql
--
-- Changes:
--   1. sfp_semester_scholarships: add discount_type (percentage | fixed)
--                                 and fixed_amount for fixed-type scholarships

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. Add scholarship type and fixed-amount columns
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE `sfp_semester_scholarships`
    ADD COLUMN `discount_type`  ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage'
                                COMMENT 'percentage = percentage-based; fixed = fixed BDT amount'
                                AFTER `discount_pct`,
    ADD COLUMN `fixed_amount`   DECIMAL(10,2) DEFAULT NULL
                                COMMENT 'For fixed-type scholarships: the BDT amount entered by admin'
                                AFTER `discount_type`;

SET FOREIGN_KEY_CHECKS = 1;
