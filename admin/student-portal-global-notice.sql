-- ============================================================
-- Student Portal – Global Notice Banner
-- Creates a single-row table for the admin-controlled
-- global notice shown to ALL students on the portal.
--
-- Run this migration once.
-- ============================================================

CREATE TABLE IF NOT EXISTS `portal_global_notice` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `is_active`   TINYINT(1)   NOT NULL DEFAULT 0,
    `notice_type` ENUM('info','warning','danger','success') NOT NULL DEFAULT 'warning',
    `title`       VARCHAR(255) DEFAULT NULL,
    `message`     TEXT         NOT NULL DEFAULT '',
    `created_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed the single control row (only if the table is empty)
INSERT IGNORE INTO `portal_global_notice` (`id`, `is_active`, `notice_type`, `title`, `message`)
VALUES (1, 0, 'warning', '', '');
