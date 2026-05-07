-- ============================================================================
-- Fix: Snapshot Registration Fee & Form/ID Fee to Student Packages
-- ============================================================================
-- Problem: reg_fee_per_semester and form_id_fee were read from cf_settings
--          (global) instead of being snapshotted to each student package.
--          This caused retroactive changes when global settings were updated.
--
-- Solution: Add these fields to sfp_packages table so each student retains
--           their originally assigned fees, consistent with other fee fields.
--
-- Run this migration AFTER student-fee-package.sql
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── Add reg_fee_per_semester column to sfp_packages ─────────────────────────
ALTER TABLE `sfp_packages`
    ADD COLUMN `reg_fee_per_semester` DECIMAL(10,2) NOT NULL DEFAULT 0.00
                                      COMMENT 'Per-semester registration fee (snapshotted from cf_settings)'
                                      AFTER `english_course_fee`;

-- ── Add form_id_fee column to sfp_packages ──────────────────────────────────
ALTER TABLE `sfp_packages`
    ADD COLUMN `form_id_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00
                             COMMENT 'One-time form & ID card fee (snapshotted from cf_settings)'
                             AFTER `reg_fee_per_semester`;

-- ── Backfill existing packages with current cf_settings values ──────────────
UPDATE `sfp_packages`
SET `reg_fee_per_semester` = (SELECT `reg_fee_per_semester` FROM `cf_settings` WHERE `id` = 1 LIMIT 1),
    `form_id_fee`          = (SELECT `form_id_fee`          FROM `cf_settings` WHERE `id` = 1 LIMIT 1)
WHERE `reg_fee_per_semester` = 0.00;

SET FOREIGN_KEY_CHECKS = 1;

-- ── Notes ───────────────────────────────────────────────────────────────────
-- After running this migration:
--
-- 1. All new student packages will snapshot these fees at creation time
--    (see admin/student-accounts/create.php)
--
-- 2. Fee calculations will read from sfp_packages, not cf_settings
--    (see admin/accounting/helpers.php)
--
-- 3. Changes to cf_settings will only affect NEW students, preserving
--    financial integrity for existing students
--
-- 4. Existing packages have been backfilled with current global values
--    to maintain backward compatibility
-- ============================================================================
