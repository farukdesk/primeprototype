-- Faculty Profiles v2 Migration
-- Run once to add department linking, dashboard module, and access grants.

-- 1. Add dept_id to faculty_profiles (faculty self-declares their primary department)
ALTER TABLE `faculty_profiles`
    ADD COLUMN IF NOT EXISTS `dept_id` INT UNSIGNED DEFAULT NULL AFTER `user_id`;

-- Add FK only if dept_departments table exists (safe pattern)
ALTER TABLE `faculty_profiles`
    ADD CONSTRAINT IF NOT EXISTS `fk_fp_dept`
    FOREIGN KEY (`dept_id`) REFERENCES `dept_departments`(`id`) ON DELETE SET NULL;

-- 2. Insert the Dashboard module (so access can be controlled per user group)
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `parent_id`, `sort_order`, `is_active`)
VALUES ('Dashboard', 'dashboard', 'Admin dashboard overview', 'fas fa-tachometer-alt', NULL, 1, 1);

-- 3. Grant dashboard access to every existing non-Faculty user group
--    (Super admins bypass all checks; Faculty users go to their profile page instead)
INSERT IGNORE INTO `group_module_access` (`group_id`, `module_id`, `can_view`, `can_create`, `can_edit`, `can_delete`)
SELECT ug.id, m.id, 1, 0, 0, 0
FROM `user_groups` ug
JOIN `modules` m ON m.slug = 'dashboard'
WHERE ug.name != 'Faculty';
