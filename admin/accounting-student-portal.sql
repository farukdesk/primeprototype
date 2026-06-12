-- ============================================================
-- Student Accounts Portal
-- Adds student_sid to users table so admin accounts can be
-- linked to a student record, and registers the module.
-- Safe to re-run (uses IF NOT EXISTS / INSERT IGNORE).
-- ============================================================

SET NAMES utf8mb4;

-- Add student_sid to users (links an admin user to a students record)
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `student_sid` VARCHAR(50) DEFAULT NULL
        COMMENT 'Links this admin user to a student record by student_id'
        AFTER `phone`;

-- Register the student-facing Accounts module
INSERT IGNORE INTO `modules` (`name`, `slug`, `icon`, `sort_order`, `is_active`) VALUES
('Accounts', 'student-accounts-portal', 'fas fa-file-invoice-dollar', 61, 1);
