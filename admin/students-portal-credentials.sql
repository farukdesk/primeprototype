-- =============================================================
-- students-portal-credentials.sql
-- Adds portal account linkage to students and ensures the
-- "Students" user group exists.
-- Run AFTER students.sql (and migration files).
-- =============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────
-- 1. Add user_id FK column to students
-- ─────────────────────────────────────────────────────────────
ALTER TABLE `students`
    ADD COLUMN IF NOT EXISTS `user_id` INT UNSIGNED DEFAULT NULL
        COMMENT 'FK → users.id – portal account for this student'
        AFTER `created_by`;

-- Drop the constraint first (IF EXISTS) so this script is safe to re-run.
-- MariaDB does not support ADD CONSTRAINT IF NOT EXISTS for FOREIGN KEY.
ALTER TABLE `students`
    DROP FOREIGN KEY IF EXISTS `fk_students_portal_user`;

ALTER TABLE `students`
    ADD CONSTRAINT `fk_students_portal_user`
        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL;

-- ─────────────────────────────────────────────────────────────
-- 2. Ensure "Students" user group exists
-- ─────────────────────────────────────────────────────────────
INSERT IGNORE INTO `user_groups` (`name`, `description`, `is_super`, `is_active`)
VALUES ('Students', 'Student portal user group', 0, 1);

SET FOREIGN_KEY_CHECKS = 1;
