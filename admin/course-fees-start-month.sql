-- =============================================================
-- course-fees-start-month.sql
-- Adds start_month field to cf_settings table.
-- This allows configuring the starting month for displaying 
-- month names in the month-wise fee breakdown.
-- Run AFTER course-fees-v4.sql.
-- =============================================================

SET NAMES utf8mb4;

ALTER TABLE `cf_settings`
    ADD COLUMN IF NOT EXISTS `start_month` TINYINT UNSIGNED DEFAULT 1
        COMMENT 'Starting month (1-12) for the semester (1=January, 6=June, etc.)'
        AFTER `form_id_fee`;

-- Default to January if not set
UPDATE `cf_settings` SET `start_month` = 1 WHERE `id` = 1 AND `start_month` IS NULL;
