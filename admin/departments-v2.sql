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

-- Academic Programs: rich details content + downloadable attachment brochure
ALTER TABLE `dept_academic_programs`
    ADD COLUMN IF NOT EXISTS `details_content` LONGTEXT DEFAULT NULL
        COMMENT 'Rich HTML content (TinyMCE) – admission info, fees, curriculum etc.'
        AFTER `description`,
    ADD COLUMN IF NOT EXISTS `attachment` VARCHAR(300) DEFAULT NULL
        COMMENT 'Downloadable brochure/PDF filename in admin/uploads/departments/'
        AFTER `details_content`;

-- Prime Pride: professional profile fields for alumni-style listing
ALTER TABLE `dept_prime_pride`
    ADD COLUMN IF NOT EXISTS `company`      VARCHAR(200) DEFAULT NULL
        COMMENT 'Current employer / company' AFTER `position`,
    ADD COLUMN IF NOT EXISTS `linkedin_url` VARCHAR(500) DEFAULT NULL
        COMMENT 'LinkedIn profile URL'       AFTER `company`;
