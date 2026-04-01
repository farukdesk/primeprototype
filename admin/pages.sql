-- -------------------------------------------------------
-- Pages Module
-- Run after database.sql is applied.
-- -------------------------------------------------------

-- -------------------------------------------------------
-- Table: pages
-- Stores all managed pages (general / profile / policy)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pages` (
    `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `title`            VARCHAR(255)  NOT NULL,
    `slug`             VARCHAR(255)  NOT NULL,
    `category`         ENUM('general','profile','policy') NOT NULL DEFAULT 'general',
    `page_heading`     VARCHAR(255)  DEFAULT NULL COMMENT 'Optional banner/section heading',
    `page_intro`       TEXT          DEFAULT NULL COMMENT 'Introductory paragraph shown below heading',
    `meta_description` TEXT          DEFAULT NULL,
    `featured_image`   VARCHAR(500)  DEFAULT NULL,
    -- General pages: GrapesJS-generated HTML & CSS
    `gjs_html`         LONGTEXT      DEFAULT NULL,
    `gjs_css`          LONGTEXT      DEFAULT NULL,
    `gjs_data`         LONGTEXT      DEFAULT NULL COMMENT 'GrapesJS component JSON (for re-editing)',
    -- Policy / fallback rich-text content
    `content`          LONGTEXT      DEFAULT NULL COMMENT 'TinyMCE HTML content for policy pages',
    -- Policy meta
    `policy_type`      VARCHAR(100)  DEFAULT NULL COMMENT 'e.g. Privacy Policy, Terms of Use',
    `effective_date`   DATE          DEFAULT NULL,
    -- Profile page extra heading fields
    `profile_subtitle` VARCHAR(150)  DEFAULT NULL COMMENT 'Subtitle badge label e.g. "Leadership"',
    -- Publication
    `is_published`     TINYINT(1)    DEFAULT 0,
    `published_at`     DATETIME      DEFAULT NULL,
    `sort_order`       INT           DEFAULT 0,
    `created_at`       DATETIME      DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_pages_slug` (`slug`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Table: page_profiles
-- One row per person displayed on a Profile-category page.
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `page_profiles` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `page_id`     INT UNSIGNED  NOT NULL,
    `full_name`   VARCHAR(150)  NOT NULL,
    `designation` VARCHAR(150)  DEFAULT NULL,
    `bio`         TEXT          DEFAULT NULL,
    `photo`       VARCHAR(500)  DEFAULT NULL,
    `email`       VARCHAR(191)  DEFAULT NULL,
    `phone`       VARCHAR(30)   DEFAULT NULL,
    `is_featured` TINYINT(1)    DEFAULT 0 COMMENT '1 = highlighted card (chairman-style)',
    `sort_order`  INT           DEFAULT 0,
    `created_at`  DATETIME      DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_pp_page` (`page_id`),
    FOREIGN KEY (`page_id`) REFERENCES `pages`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Seed: Module entries
-- -------------------------------------------------------
INSERT INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`) VALUES
('Pages',          'pages',          'Manage website pages',                          'fas fa-file-alt',  20),
('Pages – General','pages-general',  'Manage general drag-and-drop pages',            'fas fa-columns',   21),
('Pages – Profile','pages-profile',  'Manage profile listing pages',                  'fas fa-id-card',   22),
('Pages – Policy', 'pages-policy',   'Manage policy & procedure pages',               'fas fa-file-contract', 23);
