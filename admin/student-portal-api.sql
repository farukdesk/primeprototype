-- ============================================================
-- PUMIS – Student Portal Mobile App – SQL Migration
-- Run once against your database: admin_primepnew2026
-- ============================================================

-- Student mobile push notification tokens.
-- Stores FCM device tokens for logged-in student portal users.
-- Each student can register multiple devices (phone + tablet etc.).
CREATE TABLE IF NOT EXISTS `student_push_tokens` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL COMMENT 'users.id (student portal account)',
    `fcm_token`  TEXT         NOT NULL,
    `device_id`  VARCHAR(255)          DEFAULT NULL,
    `platform`   ENUM('android','ios') NOT NULL DEFAULT 'android',
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_student_device` (`user_id`, `device_id`),
    KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Allow separate FCM server key for the student portal app
-- (or reuse the same key – your choice; the key must be set in the admin Settings page)
INSERT IGNORE INTO `settings` (`key`, `value`, `label`, `group`)
VALUES ('student_fcm_server_key', '', 'Student Portal FCM Server Key', 'mobile');

-- Track how many push notifications were sent for each notice (optional audit)
ALTER TABLE `cms_notices`
    ADD COLUMN IF NOT EXISTS `push_sent_at` DATETIME NULL DEFAULT NULL
        COMMENT 'Timestamp when push notification was sent to students';

ALTER TABLE `dept_notices`
    ADD COLUMN IF NOT EXISTS `push_sent_at` DATETIME NULL DEFAULT NULL
        COMMENT 'Timestamp when push notification was sent to students';
