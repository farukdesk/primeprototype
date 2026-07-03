-- ============================================================================
-- App Notification module – database migration
-- ============================================================================
-- Adds the push-notification history table and registers the "App Notification"
-- module so it appears on the Module Access page (admin/access/index.php) and
-- can be granted to user groups / users. The sidebar already gates the pages
-- with can_access('app-notifications').
--
-- Device tokens are stored in `student_push_tokens` (already created), populated
-- by the mobile app via admin/api/student/push/register.php. Publishing a
-- notification here sends an FCM HTTP v1 push to every registered device.
--
-- Safe to run multiple times.
-- ============================================================================

-- ── Notification history ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `app_notifications` (
    `id`           int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `title`        varchar(150)     NOT NULL,
    `body`         text             NOT NULL,
    `url`          varchar(500)     DEFAULT NULL COMMENT 'Optional deep link opened on tap',
    `sent_by`      int(10) UNSIGNED DEFAULT NULL COMMENT 'FK users.id',
    `status`       enum('sent','partial','failed') NOT NULL DEFAULT 'sent',
    `total_tokens` int(11)          NOT NULL DEFAULT 0,
    `sent_count`   int(11)          NOT NULL DEFAULT 0,
    `failed_count` int(11)          NOT NULL DEFAULT 0,
    `created_at`   datetime         NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_app_notifications_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Module registration (idempotent) ────────────────────────────────────────
INSERT INTO `modules`
    (`name`, `slug`, `description`, `icon`, `parent_id`, `sort_order`,
     `is_active`, `can_view`, `can_create`, `can_edit`, `can_delete`)
SELECT
    'App Notification',
    'app-notifications',
    'Send push notifications to students who have installed the mobile app.',
    'fas fa-mobile-alt',
    NULL,
    60,
    1, 1, 1, 1, 1
WHERE NOT EXISTS (
    SELECT 1 FROM `modules` WHERE `slug` = 'app-notifications'
);

-- ── FCM credential placeholder (settings table) ─────────────────────────────
-- The service-account JSON is added through the module's Settings page; this
-- just ensures the row exists so the settings screen can read/update it.
INSERT INTO `settings` (`key`, `value`, `label`, `group`)
SELECT 'fcm_service_account', '', 'FCM service account (HTTP v1)', 'push'
WHERE NOT EXISTS (
    SELECT 1 FROM `settings` WHERE `key` = 'fcm_service_account'
);
