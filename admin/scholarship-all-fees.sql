-- Scholarship Policy – All-Fee Discount Scope Migration
-- Run AFTER scholarship.sql (and scholarship-flat-type.sql if applicable)
--
-- Adds two flags to sc_policies so a policy can indicate its discount
-- should cover fixed institutional fees and/or the English course fee
-- in addition to tuition.

SET NAMES utf8mb4;

ALTER TABLE `sc_policies`
    ADD COLUMN `applies_to_fixed`   TINYINT(1) NOT NULL DEFAULT 0
                                    COMMENT '1 = policy discount also covers fixed institutional fees'
                                    AFTER `description`,
    ADD COLUMN `applies_to_english` TINYINT(1) NOT NULL DEFAULT 0
                                    COMMENT '1 = policy discount also covers English course fee'
                                    AFTER `applies_to_fixed`;
