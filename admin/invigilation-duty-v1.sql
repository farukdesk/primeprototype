-- Invigilation Duty (faculty self-service) module — v1
--
-- Adds a self-service module where faculty members can see their own
-- invigilation duty slots assigned via the Exam Invigilation module.
--
-- 1. Links ei_faculty pool rows to login accounts via a nullable user_id
--    column (also auto-added at runtime by ivd_ensure_user_column()).
-- 2. Registers the module so it appears on the Module Access page
--    (admin/access/index.php) and can be granted to user groups / users.
-- 3. Grants view-only access to every user group named like "Faculty".
--
-- Safe to run multiple times: all statements are idempotent.

-- 1. Link column on ei_faculty (MariaDB supports IF NOT EXISTS here)
ALTER TABLE `ei_faculty`
    ADD COLUMN IF NOT EXISTS `user_id` INT(10) UNSIGNED DEFAULT NULL
        COMMENT 'Linked login account (users.id)' AFTER `dept_id`,
    ADD KEY IF NOT EXISTS `idx_ei_faculty_user` (`user_id`);

-- 2. Register the module (view-only; it has no create/edit/delete actions)
INSERT INTO `modules`
    (`name`, `slug`, `description`, `icon`, `parent_id`, `sort_order`,
     `is_active`, `can_view`, `can_create`, `can_edit`, `can_delete`)
SELECT
    'Invigilation Duty',
    'invigilation-duty',
    'Faculty self-service view of their own exam invigilation duty slots.',
    'fas fa-user-clock',
    NULL,
    97,
    1, 1, 0, 0, 0
WHERE NOT EXISTS (
    SELECT 1 FROM `modules` WHERE `slug` = 'invigilation-duty'
);

-- 3. Grant view access to all Faculty user groups
INSERT INTO `group_module_access` (`group_id`, `module_id`, `can_view`, `can_create`, `can_edit`, `can_delete`)
SELECT g.`id`, m.`id`, 1, 0, 0, 0
FROM `user_groups` g
JOIN `modules` m ON m.`slug` = 'invigilation-duty'
WHERE g.`name` LIKE '%Faculty%'
  AND g.`is_active` = 1
  AND NOT EXISTS (
      SELECT 1 FROM `group_module_access` gma
      WHERE gma.`group_id` = g.`id` AND gma.`module_id` = m.`id`
  );
