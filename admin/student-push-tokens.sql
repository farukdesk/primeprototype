-- ============================================================================
-- Student push tokens – database migration
-- ============================================================================
-- Creates the `student_push_tokens` table used by:
--   • admin/api/student/push/register.php  (the mobile app registers here)
--   • admin/app-notifications/devices.php  (Registered App Devices page)
--   • admin/app-notifications/helpers.php  (FCM delivery to students)
--
-- The App Notification migration (admin/app-notifications.sql) assumed this
-- table already existed; on fresh installs it never did, so student devices
-- were never stored and the "Student Devices" list stayed empty.
--
-- Safe to run multiple times.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `student_push_tokens` (
    `id`          int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     int(10) UNSIGNED NOT NULL COMMENT 'FK users.id (student portal account)',
    `fcm_token`   varchar(512)     NOT NULL,
    `device_id`   varchar(64)      DEFAULT NULL COMMENT 'Stable per-install id sent by the app',
    `platform`    enum('android','ios') NOT NULL DEFAULT 'android',
    `app_version` varchar(30)      DEFAULT NULL,
    `created_at`  datetime         NOT NULL DEFAULT current_timestamp(),
    `updated_at`  datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_student_push_user_device` (`user_id`, `device_id`),
    KEY `idx_student_push_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
