-- Student Fee Package v3 – Supporting Documents + All-Fee Scholarship Discounts
-- Run AFTER student-fee-package-v2.sql
--
-- Changes:
--   1. sfp_semester_scholarships: track policy origin, scope flags, and optional support doc
--   2. sfp_semester_fees:         store per-semester fixed-fee and English-fee discount amounts

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. Add new columns to sfp_semester_scholarships
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE `sfp_semester_scholarships`
    ADD COLUMN `is_from_policy`    TINYINT(1)   NOT NULL DEFAULT 0
                                   COMMENT '1 = created via sc_policies quick-fill; 0 = manual entry'
                                   AFTER `note`,
    ADD COLUMN `applies_to_fixed`  TINYINT(1)   NOT NULL DEFAULT 0
                                   COMMENT '1 = discount also applies to fixed institutional fee'
                                   AFTER `is_from_policy`,
    ADD COLUMN `applies_to_english` TINYINT(1)  NOT NULL DEFAULT 0
                                   COMMENT '1 = discount also applies to English course fee'
                                   AFTER `applies_to_fixed`,
    ADD COLUMN `support_doc_id`    INT UNSIGNED DEFAULT NULL
                                   COMMENT 'FK student_files.id – mandatory for manual (non-policy) scholarships'
                                   AFTER `applies_to_english`;

ALTER TABLE `sfp_semester_scholarships`
    ADD CONSTRAINT `fk_sfpss_doc`
        FOREIGN KEY (`support_doc_id`) REFERENCES `student_files`(`id`) ON DELETE SET NULL;

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. Add discount-amount columns for fixed/English fees to sfp_semester_fees
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE `sfp_semester_fees`
    ADD COLUMN `fixed_discount_amount`   DECIMAL(10,2) NOT NULL DEFAULT 0.00
                                         COMMENT 'Aggregate discount applied to fixed institutional fee this semester'
                                         AFTER `tuition_payable`,
    ADD COLUMN `english_discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00
                                         COMMENT 'Aggregate discount applied to English course fee this semester'
                                         AFTER `fixed_discount_amount`;

SET FOREIGN_KEY_CHECKS = 1;
