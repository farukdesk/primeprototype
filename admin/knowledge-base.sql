-- Knowledge Base Module
-- Run this file once to set up the knowledge_base tables and register the module.

CREATE TABLE IF NOT EXISTS `kb_articles` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `title`         VARCHAR(255)    NOT NULL,
    `type`          ENUM('video','document') NOT NULL DEFAULT 'document',
    `description`   TEXT,
    `content`       LONGTEXT,
    `video_url`     VARCHAR(500)    DEFAULT NULL,
    `thumbnail`     VARCHAR(255)    DEFAULT NULL,
    `file_name`     VARCHAR(255)    DEFAULT NULL,
    `file_original` VARCHAR(255)    DEFAULT NULL,
    `file_mime`     VARCHAR(100)    DEFAULT NULL,
    `file_size`     INT UNSIGNED    DEFAULT NULL,
    `created_by`    INT UNSIGNED    NOT NULL,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_type`       (`type`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Register the module so it appears in Module Access management
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`)
VALUES ('Knowledge Base', 'knowledge-base', 'Internal tutorials, guides, videos and documents', 'fas fa-book-open', 40);
