-- ============================================================
-- Alumni Module – Add phone and email columns
-- Run after admin/alumni.sql
-- Adds phone and email fields collected during registration.
-- These fields are admin-only and NOT shown on the public alumni page.
-- All statements are idempotent (safe to re-run).
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `alumni`
    ADD COLUMN IF NOT EXISTS `phone` varchar(30) DEFAULT NULL
        COMMENT 'Contact phone (admin-only, not shown publicly)'
        AFTER `batch`,
    ADD COLUMN IF NOT EXISTS `email` varchar(200) DEFAULT NULL
        COMMENT 'Contact email (admin-only, not shown publicly)'
        AFTER `phone`;
