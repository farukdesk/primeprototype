-- ============================================================
-- Admissions – Form Sale Sub-Module
-- Run this file after admissions.sql and admissions-v2.sql
-- ============================================================

-- ── Form sale records ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `adm_form_sales` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `form_number`    VARCHAR(30)  NOT NULL,
    `buyer_name`     VARCHAR(255) NOT NULL,
    `buyer_email`    VARCHAR(255) NULL,
    `buyer_mobile`   VARCHAR(50)  NOT NULL,
    `form_price`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `status`         ENUM('pending','used','cancelled') NOT NULL DEFAULT 'pending',
    `application_id` INT UNSIGNED NULL,
    `sold_by`        INT UNSIGNED NULL,
    `sold_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_form_number` (`form_number`),
    CONSTRAINT `fk_fs_application` FOREIGN KEY (`application_id`)
        REFERENCES `admissions_applications` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Invoice template (single page) ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `adm_fs_templates` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `original_name` VARCHAR(255) NOT NULL,
    `stored_file`   VARCHAR(255) NOT NULL,
    `file_type`     ENUM('pdf','image') NOT NULL DEFAULT 'image',
    `uploaded_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `uploaded_by`   INT UNSIGNED NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Invoice field → position mappings ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `adm_fs_field_mappings` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `field_key`  VARCHAR(100) NOT NULL,
    `x_percent`  DECIMAL(6,3) NOT NULL DEFAULT 0,
    `y_percent`  DECIMAL(6,3) NOT NULL DEFAULT 0,
    `font_size`  TINYINT      NOT NULL DEFAULT 10,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_fs_field_key` (`field_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Settings ──────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `admissions_settings` (`setting_key`, `setting_value`) VALUES
('form_price',     '500'),
('next_fs_number', '1');
