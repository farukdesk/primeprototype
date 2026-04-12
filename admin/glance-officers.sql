-- Migration: Add glance_officers table for manually-managed Key Administrative Officers
-- on the PU At a Glance page (replaces the governing_body_members glance_officer toggle
-- with a dedicated, fully-editable table).

CREATE TABLE IF NOT EXISTS `glance_officers` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `full_name`   VARCHAR(200)  NOT NULL,
  `designation` VARCHAR(200)  NOT NULL,
  `bio`         TEXT,
  `photo`       VARCHAR(300)  DEFAULT NULL,
  `glance_link` VARCHAR(255)  DEFAULT NULL
                COMMENT 'Optional URL for the officer card on PU At a Glance',
  `sort_order`  INT           NOT NULL DEFAULT 0,
  `is_active`   TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
