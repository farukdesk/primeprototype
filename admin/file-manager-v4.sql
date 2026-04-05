-- ============================================================
-- File Manager v4 – Sign map improvements
-- Run after file-manager-v3.sql
-- Adds: show_datetime on sign positions, text notes table
-- ============================================================

-- ── 1. Add show_datetime to sign positions ────────────────────
ALTER TABLE `file_manager_page_sign_positions`
    ADD COLUMN IF NOT EXISTS `show_datetime` TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'Show signed date/time below signature';

-- ── 2. Draggable text note annotations ───────────────────────
CREATE TABLE IF NOT EXISTS `file_manager_page_text_notes` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `page_id`     INT UNSIGNED NOT NULL,
    `content`     TEXT         NOT NULL,
    `x_percent`   DECIMAL(5,2) NOT NULL DEFAULT 10.00,
    `y_percent`   DECIMAL(5,2) NOT NULL DEFAULT 10.00,
    `font_size`   TINYINT UNSIGNED NOT NULL DEFAULT 12,
    `color`       VARCHAR(7)   NOT NULL DEFAULT '#000000',
    `created_by`  INT UNSIGNED DEFAULT NULL,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_page` (`page_id`),
    CONSTRAINT `fk_fmptn_page` FOREIGN KEY (`page_id`)
        REFERENCES `file_manager_pages`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
