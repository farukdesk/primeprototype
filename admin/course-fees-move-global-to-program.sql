-- Course Fees: Move Global Settings to Per-Program Settings
-- This migration moves fee constants from cf_settings (global) to cf_programs (per-program)
-- Each program can now have different admission, registration, and form fees

SET NAMES utf8mb4;

-- Add new columns to cf_programs for per-program fee constants
ALTER TABLE `cf_programs`
ADD COLUMN `admission_fee_base` INT UNSIGNED DEFAULT NULL 
  COMMENT 'One-time admission fee (BDT) - was global in cf_settings' AFTER `is_active`,
ADD COLUMN `reg_fee_per_semester` INT UNSIGNED DEFAULT NULL 
  COMMENT 'Registration fee per semester (BDT) - was global in cf_settings' AFTER `admission_fee_base`,
ADD COLUMN `reg_fee_total` INT UNSIGNED DEFAULT NULL 
  COMMENT 'Total registration fees across all semesters (BDT) - was global in cf_settings' AFTER `reg_fee_per_semester`,
ADD COLUMN `form_id_fee` INT UNSIGNED DEFAULT NULL 
  COMMENT 'Admission form + ID card fee (BDT) - was global in cf_settings' AFTER `reg_fee_total`,
ADD COLUMN `id_card_fee` INT UNSIGNED DEFAULT NULL 
  COMMENT 'ID card fee only (BDT) - was global in cf_settings' AFTER `form_id_fee`,
ADD COLUMN `admission_form_fee` INT UNSIGNED DEFAULT NULL 
  COMMENT 'Admission form fee only (BDT) - was global in cf_settings' AFTER `id_card_fee`,
ADD COLUMN `bi_semester_start_month` TINYINT UNSIGNED DEFAULT NULL 
  COMMENT 'Starting month for bi-semester programs (1-12) - was global in cf_settings' AFTER `admission_form_fee`,
ADD COLUMN `tri_semester_start_month` TINYINT UNSIGNED DEFAULT NULL 
  COMMENT 'Starting month for tri-semester programs (1-12) - was global in cf_settings' AFTER `bi_semester_start_month`;

-- Copy current global settings to all existing programs
-- This ensures backward compatibility
UPDATE `cf_programs` p
CROSS JOIN `cf_settings` s
SET 
  p.admission_fee_base       = s.admission_fee_base,
  p.reg_fee_per_semester     = s.reg_fee_per_semester,
  p.reg_fee_total            = COALESCE(s.reg_fee_total, 12000),
  p.form_id_fee              = s.form_id_fee,
  p.id_card_fee              = COALESCE(s.id_card_fee, 500),
  p.admission_form_fee       = COALESCE(s.admission_form_fee, 500),
  p.bi_semester_start_month  = COALESCE(s.bi_semester_start_month, 1),
  p.tri_semester_start_month = COALESCE(s.tri_semester_start_month, 1)
WHERE s.id = 1;

-- Note: We keep the cf_settings table for now for backward compatibility
-- The page_title, session_label, disclaimer, and is_published fields are still global
-- Only the fee constants are now per-program
