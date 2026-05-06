-- =============================================================
-- course-fees-start-month-v2.sql
-- Adds separate start months for bi-semester and tri-semester programs.
-- Maintains backward compatibility with existing start_month field.
-- Run AFTER course-fees-start-month.sql.
-- =============================================================

SET NAMES utf8mb4;

-- Add bi_semester_start_month field (copy from existing start_month if available)
ALTER TABLE `cf_settings`
    ADD COLUMN IF NOT EXISTS `bi_semester_start_month` TINYINT UNSIGNED DEFAULT NULL
        COMMENT 'Starting month (1-12) for bi-semester programs (1=January, 6=June, etc.)'
        AFTER `start_month`;

-- Add tri_semester_start_month field
ALTER TABLE `cf_settings`
    ADD COLUMN IF NOT EXISTS `tri_semester_start_month` TINYINT UNSIGNED DEFAULT NULL
        COMMENT 'Starting month (1-12) for tri-semester programs (1=January, 5=May, 9=September, etc.)'
        AFTER `bi_semester_start_month`;

-- Copy existing start_month value to both new fields if they are NULL
UPDATE `cf_settings` 
SET `bi_semester_start_month` = COALESCE(`bi_semester_start_month`, `start_month`, 1),
    `tri_semester_start_month` = COALESCE(`tri_semester_start_month`, `start_month`, 1)
WHERE `id` = 1;