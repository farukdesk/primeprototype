-- Course Fees Calculator – Extra Fee Fields Migration
-- Adds reg_fee_total, id_card_fee, and admission_form_fee columns to cf_settings.
-- Run this AFTER course-fees-v4.sql.

ALTER TABLE `cf_settings`
  ADD COLUMN `reg_fee_total`      INT UNSIGNED NOT NULL DEFAULT 12000
      COMMENT 'Total registration fees across all semesters of the programme (BDT)'
      AFTER `reg_fee_per_semester`,
  ADD COLUMN `id_card_fee`        INT UNSIGNED NOT NULL DEFAULT 500
      COMMENT 'One-time ID card fee (BDT)'
      AFTER `form_id_fee`,
  ADD COLUMN `admission_form_fee` INT UNSIGNED NOT NULL DEFAULT 500
      COMMENT 'One-time admission form fee (BDT)'
      AFTER `id_card_fee`;

-- Seed default values for the new columns (row id=1 already exists)
UPDATE `cf_settings`
SET
  `reg_fee_total`      = 12000,
  `id_card_fee`        = 500,
  `admission_form_fee` = 500
WHERE `id` = 1;
