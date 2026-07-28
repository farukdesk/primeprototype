-- ============================================================================
-- In-app notifications – database migration
-- ============================================================================
-- Personal notifications shown under the bell icon in the admin topbar.
-- Rows are created by feature modules (e.g. Leave Management) through the
-- helpers in admin/includes/notifications.php.
--
-- Safe to run multiple times.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `notifications` (
    `id`         int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    int(10) UNSIGNED NOT NULL COMMENT 'Recipient (FK users.id)',
    `type`       varchar(60)      NOT NULL DEFAULT 'general' COMMENT 'Source slug, e.g. leave-request / leave-approval / leave-status',
    `title`      varchar(190)     NOT NULL,
    `body`       text             DEFAULT NULL,
    `link`       varchar(255)     DEFAULT NULL COMMENT 'Same-site URL opened when the notification is clicked',
    `is_read`    tinyint(1)       NOT NULL DEFAULT 0,
    `read_at`    datetime         DEFAULT NULL,
    `created_at` datetime         NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_notif_user` (`user_id`, `is_read`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
