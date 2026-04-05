-- ============================================================
-- File Manager Module
-- Run this script once to install the module.
-- ============================================================

-- ── 1. Main files table ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS `file_manager_files` (
    `id`              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `file_name`       VARCHAR(255)     NOT NULL,
    `description`     TEXT,
    `category`        VARCHAR(100)     DEFAULT NULL,
    `creator_id`      INT UNSIGNED     NOT NULL,
    `file_location`   VARCHAR(500)     DEFAULT NULL COMMENT 'Physical / cabinet location of the real document',
    `uploaded_file`   VARCHAR(255)     DEFAULT NULL COMMENT 'Optional digital copy stored on disk',
    `original_name`   VARCHAR(255)     DEFAULT NULL,
    `mime_type`       VARCHAR(100)     DEFAULT NULL,
    `file_size`       INT UNSIGNED     DEFAULT NULL,
    `notes`           TEXT,
    `status`          ENUM('active','archived') NOT NULL DEFAULT 'active',
    `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_creator`    (`creator_id`),
    KEY `idx_status`     (`status`),
    KEY `idx_category`   (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Module registration ────────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`, `is_active`)
VALUES ('File Manager', 'file-manager', 'Track and manage physical and digital files', 'fas fa-folder-open', 80, 1);
