-- ============================================================
-- Departments v2 Migration
-- Run after admin_primepnew2026.sql (or after departments.sql)
-- Adds the `image` column to dept_departments so each
-- department card on the homepage can display its own photo.
-- ============================================================

-- Add card image column (safe to run multiple times – uses IF NOT EXISTS trick)
ALTER TABLE `dept_departments`
    ADD COLUMN IF NOT EXISTS `image` VARCHAR(255) DEFAULT NULL
        COMMENT 'Filename in admin/uploads/departments/ used for homepage card background'
        AFTER `hero_icon`;
