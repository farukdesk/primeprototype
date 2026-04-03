-- -------------------------------------------------------
-- Migration: dept_hero_slides
-- Per-department hero section image slider
-- -------------------------------------------------------

CREATE TABLE IF NOT EXISTS `dept_hero_slides` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `dept_id`     INT UNSIGNED  NOT NULL,
    `image`       VARCHAR(500)  NOT NULL,
    `caption`     VARCHAR(300)  DEFAULT NULL,
    `sort_order`  INT           NOT NULL DEFAULT 0,
    `is_active`   TINYINT(1)    NOT NULL DEFAULT 1,
    `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_dept_hero_slides_dept` (`dept_id`),
    CONSTRAINT `fk_hero_slides_dept`
        FOREIGN KEY (`dept_id`) REFERENCES `dept_departments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
