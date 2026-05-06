-- =============================================================
-- course-fees-start-month-v2.sql
-- Updates start_month configuration to support separate
-- start months for bi-semester and tri-semester programs.
-- Run AFTER course-fees-start-month.sql.
-- =============================================================

SET NAMES utf8mb4;

-- Rename existing start_month to bi_semester_start_month for clarity
ALTER TABLE `cf_settings`
    CHANGE COLUMN `start_month` `bi_semester_start_month` TINYINT UNSIGNED DEFAULT 1
        COMMENT 'Starting month (1-12) for bi-semester programs (1=January, 6=June, etc.)';

-- Add new tri_semester_start_month field
ALTER TABLE `cf_settings`
    ADD COLUMN IF NOT EXISTS `tri_semester_start_month` TINYINT UNSIGNED DEFAULT 1
        COMMENT 'Starting month (1-12) for tri-semester programs (1=January, 5=May, 9=September, etc.)'
        AFTER `bi_semester_start_month`;

-- Default both to January if not set
UPDATE `cf_settings` 
SET `bi_semester_start_month` = 1 
WHERE `id` = 1 AND `bi_semester_start_month` IS NULL;

UPDATE `cf_settings` 
SET `tri_semester_start_month` = 1 
WHERE `id` = 1 AND `tri_semester_start_month` IS NULL;
