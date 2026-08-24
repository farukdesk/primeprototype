-- ============================================================================
-- App Notification – queued delivery + student batch targeting migration
-- ============================================================================
-- Sending thousands of pushes inside one web request exhausted PHP memory and
-- max_execution_time. Delivery is now queue-based:
--   send.php    → INSERTs recipients into app_notification_queue (pure SQL,
--                 no device tokens are ever loaded into PHP memory)
--   process.php → polled by index.php; delivers the queue in small,
--                 time-boxed chunks with a live progress bar
--
-- The helpers auto-create/upgrade this schema on first use, so running this
-- file manually is optional (kept for reference / restricted DB users).
-- ============================================================================

CREATE TABLE IF NOT EXISTS `app_notification_queue` (
    `id`                int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `notification_id`   int(10) UNSIGNED NOT NULL COMMENT 'FK app_notifications.id',
    `source`            enum('student','user') NOT NULL DEFAULT 'student',
    `recipient_user_id` int(10) UNSIGNED DEFAULT NULL,
    `fcm_token`         varchar(512) NOT NULL,
    `status`            enum('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
    `error`             varchar(120) DEFAULT NULL,
    `claimed_at`        datetime DEFAULT NULL,
    `processed_at`      datetime DEFAULT NULL,
    `created_at`        datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_apnq_pending` (`notification_id`, `status`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Track queued/sending states in the history table (run once).
ALTER TABLE `app_notifications`
    MODIFY `status` enum('queued','sending','sent','partial','failed') NOT NULL DEFAULT 'sent';

-- Store the batch targeting used by the new "Specific student batch" audience.
ALTER TABLE `app_notifications`
    ADD COLUMN `target_batch_id` int(10) UNSIGNED DEFAULT NULL;
