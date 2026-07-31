-- ============================================================================
-- App Notification module – recipient log migration
-- ============================================================================
-- Records every device delivery per recipient so the notification view page
-- (admin/app-notifications/view.php) can list exactly who received a push:
-- students resolve via students.portal_user_id, employees/users via users +
-- staff_profiles.department_type (Administrative / Faculty).
--
-- Safe to run multiple times.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `app_notification_recipients` (
    `id`                int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `notification_id`   int(10) UNSIGNED NOT NULL COMMENT 'FK app_notifications.id',
    `source`            enum('student','user') NOT NULL COMMENT 'Token table the device came from',
    `recipient_user_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'users.id (students: portal account, see students.portal_user_id)',
    `fcm_status`        enum('sent','failed') NOT NULL DEFAULT 'sent',
    `created_at`        datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_anr_notification` (`notification_id`),
    KEY `idx_anr_recipient` (`recipient_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
