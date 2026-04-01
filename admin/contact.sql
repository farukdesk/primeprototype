-- ============================================================
-- Contact Module – SQL Migration
-- ============================================================

-- Contact messages table
CREATE TABLE IF NOT EXISTS `contact_messages` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(200) NOT NULL,
    `email`      VARCHAR(200) NOT NULL,
    `phone`      VARCHAR(50)  NOT NULL DEFAULT '',
    `subject`    VARCHAR(500) NOT NULL DEFAULT '',
    `message`    TEXT         NOT NULL,
    `is_read`    TINYINT(1)   NOT NULL DEFAULT 0,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_contact_messages_is_read` (`is_read`),
    KEY `idx_contact_messages_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Register module
INSERT IGNORE INTO `modules` (`name`, `slug`, `icon`, `sort_order`, `is_active`)
VALUES ('Contact Messages', 'contact', 'fas fa-envelope-open-text', 95, 1);
