-- ─────────────────────────────────────────────────────────────────────────────
-- Gallery Module  –  gallery.sql
-- Run once against admin_primepnew2026
-- ─────────────────────────────────────────────────────────────────────────────

SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. gallery_albums: an album groups photos for a specific event / occasion
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `gallery_albums` (
  `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `dept_id`      INT UNSIGNED  DEFAULT NULL COMMENT 'FK dept_departments; NULL = university-wide',
  `program_id`   INT UNSIGNED  DEFAULT NULL COMMENT 'FK dept_academic_programs; optional',
  `title`        VARCHAR(255)  NOT NULL,
  `description`  TEXT          DEFAULT NULL,
  `event_date`   DATE          DEFAULT NULL COMMENT 'Date of the event photographed',
  `cover_photo`  VARCHAR(300)  DEFAULT NULL COMMENT 'stored filename inside gallery/covers/',
  `is_active`    TINYINT(1)    NOT NULL DEFAULT 1,
  `sort_order`   INT           NOT NULL DEFAULT 0,
  `created_by`   INT UNSIGNED  DEFAULT NULL,
  `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_gal_albums_dept`    (`dept_id`),
  KEY `idx_gal_albums_program` (`program_id`),
  KEY `idx_gal_albums_active`  (`is_active`),
  CONSTRAINT `fk_gal_albums_dept`    FOREIGN KEY (`dept_id`)    REFERENCES `dept_departments`(`id`)        ON DELETE SET NULL,
  CONSTRAINT `fk_gal_albums_program` FOREIGN KEY (`program_id`) REFERENCES `dept_academic_programs`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. gallery_photos: individual photos inside an album
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `gallery_photos` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `album_id`      INT UNSIGNED  NOT NULL,
  `stored_name`   VARCHAR(300)  NOT NULL  COMMENT 'random filename on disk',
  `original_name` VARCHAR(300)  NOT NULL  COMMENT 'original filename from uploader',
  `caption`       VARCHAR(500)  DEFAULT NULL,
  `sort_order`    INT           NOT NULL DEFAULT 0,
  `status`        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `uploaded_by`   INT UNSIGNED  DEFAULT NULL,
  `reviewed_by`   INT UNSIGNED  DEFAULT NULL,
  `reviewed_at`   DATETIME      DEFAULT NULL,
  `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_gal_photos_album`  (`album_id`),
  KEY `idx_gal_photos_status` (`status`),
  CONSTRAINT `fk_gal_photos_album` FOREIGN KEY (`album_id`) REFERENCES `gallery_albums`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. Register module
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`)
VALUES ('Gallery', 'gallery', 'Manage photo gallery albums, event photos, captions, and approvals', 'fas fa-images', 58);
