-- ============================================================================
-- App Notification module – audience targeting migration
-- ============================================================================
-- Records which audience a push notification was sent to (all students, all
-- users, individual user, user group, employee type, everyone). Employee/user
-- device tokens live in `api_push_tokens`, populated by the mobile app via
-- admin/api/push/register.php when an employee signs in.
--
-- Safe to run multiple times (MariaDB 10.x IF NOT EXISTS).
-- ============================================================================

ALTER TABLE `app_notifications`
    ADD COLUMN IF NOT EXISTS `audience` varchar(160) DEFAULT 'All students'
        COMMENT 'Human label of the audience the push was sent to'
        AFTER `sent_by`;
