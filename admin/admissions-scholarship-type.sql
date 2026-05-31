-- Admissions Scholarship – Discount Type Support
-- Run AFTER admissions-scholarship.sql
--
-- Changes:
--   1. admissions_applications: add scholarship_discount_type (percentage | fixed)
--   2. admissions_applications: add scholarship_discount_pct  (for percentage-type)
--   3. admissions_applications: add scholarship_applies_to_fixed   (also discount inst. fees)
--   4. admissions_applications: add scholarship_applies_to_english (also discount English fee)

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE `admissions_applications`
    ADD COLUMN `scholarship_discount_type`     ENUM('percentage','fixed') NULL DEFAULT NULL
                                               COMMENT 'percentage = % of tuition; fixed = fixed BDT amount'
                                               AFTER `scholarship_amount`,
    ADD COLUMN `scholarship_discount_pct`      DECIMAL(8,4) NOT NULL DEFAULT 0.0000
                                               COMMENT 'Used when discount_type = percentage'
                                               AFTER `scholarship_discount_type`,
    ADD COLUMN `scholarship_applies_to_fixed`  TINYINT(1) NOT NULL DEFAULT 0
                                               COMMENT 'Also apply % discount to institutional fees'
                                               AFTER `scholarship_discount_pct`,
    ADD COLUMN `scholarship_applies_to_english` TINYINT(1) NOT NULL DEFAULT 0
                                               COMMENT 'Also apply % discount to English course fee'
                                               AFTER `scholarship_applies_to_fixed`;

-- Back-fill existing fixed-amount rows so discount_type is consistent
UPDATE `admissions_applications`
   SET `scholarship_discount_type` = 'fixed'
 WHERE `scholarship_amount` > 0 AND `scholarship_discount_type` IS NULL;

SET FOREIGN_KEY_CHECKS = 1;
