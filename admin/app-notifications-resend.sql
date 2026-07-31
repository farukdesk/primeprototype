-- ============================================================================
-- App Notification module – resend support migration
-- ============================================================================
-- Stores the machine-readable audience code and its targets alongside the
-- human label so a previously sent notification can be re-delivered to the
-- exact same audience from the "Sent Notifications" list.
--
-- Safe to run multiple times (MariaDB 10.x IF NOT EXISTS).
-- ============================================================================

ALTER TABLE `app_notifications`
    ADD COLUMN IF NOT EXISTS `audience_code` varchar(30) DEFAULT NULL
        COMMENT 'Machine-readable audience (students, all_users, user, group, employee_type, everyone)'
        AFTER `audience`,
    ADD COLUMN IF NOT EXISTS `target_user_id` int(10) UNSIGNED DEFAULT NULL
        COMMENT 'users.id when audience_code = user'
        AFTER `audience_code`,
    ADD COLUMN IF NOT EXISTS `target_group_id` int(10) UNSIGNED DEFAULT NULL
        COMMENT 'user_groups.id when audience_code = group'
        AFTER `target_user_id`,
    ADD COLUMN IF NOT EXISTS `employee_type` varchar(20) DEFAULT NULL
        COMMENT 'staff_profiles.department_type when audience_code = employee_type'
        AFTER `target_group_id`;
