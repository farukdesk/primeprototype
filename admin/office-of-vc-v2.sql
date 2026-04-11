-- ─────────────────────────────────────────────────────────────────────────────
-- Office of Vice Chancellor – v2 migration
-- Adds Former Vice Chancellors table
-- Run once against admin_primepnew2026
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `vc_former_vcs` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(200) NOT NULL,
  `title`       VARCHAR(200) NOT NULL DEFAULT 'Former Vice Chancellor',
  `tenure`      VARCHAR(100) NOT NULL DEFAULT '',
  `photo`       VARCHAR(500) DEFAULT NULL,
  `bio`         TEXT,
  `sort_order`  INT NOT NULL DEFAULT 0,
  `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
