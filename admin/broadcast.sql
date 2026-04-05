-- ============================================================
-- Broadcast Module
-- Run this file after database.sql
-- ============================================================

-- -------------------------------------------------------
-- Table: broadcasts
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `broadcasts` (
    `id`                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `subject`             VARCHAR(255)  NOT NULL,
    `body_html`           LONGTEXT      NOT NULL,
    `recipient_type`      ENUM('individual','group','all') NOT NULL DEFAULT 'all',
    `recipient_user_id`   INT UNSIGNED  DEFAULT NULL COMMENT 'FK users.id – used when recipient_type=individual',
    `recipient_group_id`  INT UNSIGNED  DEFAULT NULL COMMENT 'FK user_groups.id – used when recipient_type=group',
    `sent_count`          INT UNSIGNED  NOT NULL DEFAULT 0,
    `failed_count`        INT UNSIGNED  NOT NULL DEFAULT 0,
    `status`              ENUM('draft','sent','partial') NOT NULL DEFAULT 'draft',
    `sent_by`             INT UNSIGNED  NOT NULL COMMENT 'FK users.id',
    `sent_at`             DATETIME      DEFAULT NULL,
    `created_at`          DATETIME      DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_status`       (`status`),
    KEY `idx_sent_by`      (`sent_by`),
    KEY `idx_recipient`    (`recipient_type`),
    FOREIGN KEY (`sent_by`)            REFERENCES `users`(`id`)        ON DELETE CASCADE,
    FOREIGN KEY (`recipient_user_id`)  REFERENCES `users`(`id`)        ON DELETE SET NULL,
    FOREIGN KEY (`recipient_group_id`) REFERENCES `user_groups`(`id`)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Table: broadcast_attachments
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `broadcast_attachments` (
    `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `broadcast_id`  INT UNSIGNED  NOT NULL,
    `original_name` VARCHAR(255)  NOT NULL,
    `stored_name`   VARCHAR(255)  NOT NULL,
    `mime_type`     VARCHAR(100)  NOT NULL,
    `file_size`     INT UNSIGNED  NOT NULL DEFAULT 0,
    `created_at`    DATETIME      DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_broadcast` (`broadcast_id`),
    FOREIGN KEY (`broadcast_id`) REFERENCES `broadcasts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Table: broadcast_recipients  (per-user delivery log)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `broadcast_recipients` (
    `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `broadcast_id`  INT UNSIGNED  NOT NULL,
    `user_id`       INT UNSIGNED  DEFAULT NULL,
    `email`         VARCHAR(191)  NOT NULL,
    `full_name`     VARCHAR(150)  NOT NULL,
    `status`        ENUM('sent','failed') NOT NULL DEFAULT 'sent',
    `sent_at`       DATETIME      DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_broadcast` (`broadcast_id`),
    KEY `idx_user`      (`user_id`),
    FOREIGN KEY (`broadcast_id`) REFERENCES `broadcasts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Register module
-- -------------------------------------------------------
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`, `is_active`)
VALUES ('Broadcast', 'broadcast', 'Send bulk emails to users or groups', 'fas fa-bullhorn', 55, 1);
