-- ─────────────────────────────────────────────────────────────────────────────
-- Office of the CRHP Module  –  office-of-crhp.sql
-- Run once against admin_primepnew2026
-- ─────────────────────────────────────────────────────────────────────────────

SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. crhp_settings: key-value store for Office of the CRHP content
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `crhp_settings` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_val` MEDIUMTEXT,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `crhp_settings` (`setting_key`, `setting_val`) VALUES
-- Page / hero
('hero_title',        'Office of the CRHP'),
('hero_subtitle',     'Office of the CRHP – Prime University'),
('hero_intro',        'The Office of the CRHP (Centre for Research, Higher learning & Publications) at Prime University promotes academic research, scholarly publications, and higher learning initiatives across all disciplines.'),
('meta_description',  'Office of the CRHP – Prime University. Centre for Research, Higher learning and Publications.'),
('is_published',      '1'),

-- Head profile
('head_name',    'Farkhunda Nahid Huq'),
('head_title',   'Deputy Director'),
('head_email_1', 'primeuniversity_crhp@yahoo.com'),
('head_email_2', ''),
('head_phone',   '01911407266'),
('head_photo',   ''),
('head_bio',     ''),

-- Message
('message_title', 'Message from the Office of the CRHP'),
('message_body',  '')

ON DUPLICATE KEY UPDATE `setting_val` = VALUES(`setting_val`);

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. crhp_staff: office staff directory
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `crhp_staff` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(255) NOT NULL,
  `title`      VARCHAR(255) NOT NULL DEFAULT '',
  `email_1`    VARCHAR(255) NOT NULL DEFAULT '',
  `email_2`    VARCHAR(255) NOT NULL DEFAULT '',
  `phone`      VARCHAR(255) NOT NULL DEFAULT '',
  `photo`      VARCHAR(255) NOT NULL DEFAULT '',
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `crhp_staff` (`name`, `title`, `email_1`, `email_2`, `phone`, `sort_order`) VALUES
('Farkhunda Nahid Huq',    'Deputy Director',    'primeuniversity_crhp@yahoo.com', '', '01911407266', 1),
('Syeda Julia Afroj Dipa', 'Assistant Director', 'pucrhp@gmail.com',               '', '01717871128', 2);

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. Module registration
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`, `is_active`) VALUES
('Office of the CRHP', 'office-of-crhp', 'Manage Office of the CRHP page content and staff directory', 'fas fa-flask', 38, 1);

SET FOREIGN_KEY_CHECKS = 1;
