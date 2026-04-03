-- ============================================================
-- Departments Alumni v2 Migration
-- Run after departments.sql
-- Adds `batch` and `linkedin_url` columns to dept_alumni so
-- the admin can record graduation batch and LinkedIn profile.
-- ============================================================

ALTER TABLE `dept_alumni`
    ADD COLUMN IF NOT EXISTS `batch`        VARCHAR(100) DEFAULT NULL
        COMMENT 'Graduation batch / year (e.g. 2018 or Spring 2018)'
        AFTER `name`,
    ADD COLUMN IF NOT EXISTS `linkedin_url` VARCHAR(500) DEFAULT NULL
        COMMENT 'LinkedIn profile URL'
        AFTER `company`;
