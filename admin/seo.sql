-- -------------------------------------------------------
-- SEO Module
-- Run after database.sql is applied.
-- -------------------------------------------------------

-- -------------------------------------------------------
-- Table: seo_entries
-- Stores per-page SEO data for every indexable URL.
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `seo_entries` (
    `id`                INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `page_type`         VARCHAR(50)    NOT NULL COMMENT 'home|page|department|faculty|news|notice|job|club|gallery|static',
    `page_id`           INT UNSIGNED   DEFAULT NULL COMMENT 'FK to the referenced record (nullable for home/static)',
    `page_url`          VARCHAR(500)   NOT NULL COMMENT 'Relative URL e.g. /index.php or /department.php?slug=cse',
    `page_label`        VARCHAR(255)   NOT NULL COMMENT 'Human-readable name e.g. "Home Page", "CSE Department"',
    -- Basic SEO
    `meta_title`        VARCHAR(255)   DEFAULT NULL,
    `meta_description`  TEXT           DEFAULT NULL,
    `meta_keywords`     VARCHAR(500)   DEFAULT NULL,
    -- Open Graph
    `og_title`          VARCHAR(255)   DEFAULT NULL,
    `og_description`    TEXT           DEFAULT NULL,
    `og_image`          VARCHAR(500)   DEFAULT NULL COMMENT 'Relative path or full URL',
    -- Advanced
    `canonical_url`     VARCHAR(500)   DEFAULT NULL,
    `robots`            VARCHAR(100)   DEFAULT 'index,follow',
    `schema_json`       LONGTEXT       DEFAULT NULL COMMENT 'Structured data JSON-LD',
    `custom_head_code`  TEXT           DEFAULT NULL COMMENT 'Raw HTML injected into <head>',
    -- Sitemap
    `sitemap_include`   TINYINT(1)     NOT NULL DEFAULT 1,
    `sitemap_priority`  DECIMAL(2,1)   NOT NULL DEFAULT 0.5,
    `sitemap_changefreq` ENUM('always','hourly','daily','weekly','monthly','yearly','never') NOT NULL DEFAULT 'weekly',
    -- Meta
    `is_active`         TINYINT(1)     NOT NULL DEFAULT 1,
    `updated_by`        INT            DEFAULT NULL,
    `created_at`        DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_seo_url` (`page_url`(191)),
    KEY `idx_seo_type_id` (`page_type`, `page_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Table: seo_settings
-- Global SEO configuration stored as key/value pairs.
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `seo_settings` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `setting_key`   VARCHAR(100) NOT NULL,
    `setting_value` TEXT         DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_seo_setting` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Default settings
-- -------------------------------------------------------
INSERT INTO `seo_settings` (`setting_key`, `setting_value`) VALUES
('site_name',               'Prime University'),
('title_separator',         '–'),
('title_format',            '{page_title} {sep} {site_name}'),
('default_meta_description','Prime University Bangladesh – Quality higher education with expert faculty, modern facilities and industry-focused programs.'),
('default_meta_keywords',   'Prime University, higher education, Bangladesh, university, undergraduate, graduate'),
('default_og_image',        '/assets/img/logo/logo-black.png'),
('google_analytics_id',     ''),
('google_search_console',   ''),
('twitter_site',            ''),
('fb_app_id',               ''),
('robots_txt',              "User-agent: *\nAllow: /\nDisallow: /admin/\nSitemap: https://primeuniversity.ac.bd/sitemap.php"),
('sitemap_base_url',        'https://primeuniversity.ac.bd'),
('og_type_default',         'website'),
('twitter_card_type',       'summary_large_image')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

-- -------------------------------------------------------
-- Module registration
-- -------------------------------------------------------
INSERT INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`) VALUES
('SEO Manager',         'seo',          'Manage SEO for all website pages',     'fas fa-search',   90),
('SEO – Settings',      'seo-settings', 'Manage global SEO and sitemap settings','fas fa-cog',      91)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);
