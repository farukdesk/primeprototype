-- ─────────────────────────────────────────────────────────────────────────────
-- Office of the Estate & Store Module  –  office-of-estate-store.sql
-- Run once against admin_primepnew2026
-- ─────────────────────────────────────────────────────────────────────────────

SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. es_settings: key-value store for Office of the Estate & Store content
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `es_settings` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_val` MEDIUMTEXT,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `es_settings` (`setting_key`, `setting_val`) VALUES
-- Page / hero
('hero_title',        'Office of the Estate & Store'),
('hero_subtitle',     'Estate & Store – Prime University'),
('hero_intro',        'The Office of the Estate & Store at Prime University is responsible for managing university estate affairs, procurement, and store operations to support the smooth functioning of all university activities.'),
('meta_description',  'Office of the Estate & Store – Prime University. Estate and store management services.'),
('is_published',      '1'),

-- Head profile
('head_name',    'Atiar Rahman'),
('head_title',   'Assistant Registrar (Estate)'),
('head_email_1', 'atiarrahman52@gmail.com'),
('head_email_2', ''),
('head_phone',   '01747196343'),
('head_photo',   ''),
('head_bio',     ''),

-- Message
('message_title', 'Message from the Office of the Estate & Store'),
('message_body',  '')

ON DUPLICATE KEY UPDATE `setting_val` = VALUES(`setting_val`);

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. es_staff: office staff directory
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `es_staff` (
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

INSERT INTO `es_staff` (`name`, `title`, `email_1`, `email_2`, `phone`, `sort_order`) VALUES
('Atiar Rahman',         'Assistant Registrar (Estate)', 'atiarrahman52@gmail.com',    '',                       '01747196343',   1),
('Serajul Islam',        'Store Officer',                'siraj@primeuniversity.edu.bd','shirajulpu@gmail.com',  '01756187061',   2),
('Gobindra Chandra Dash','Sub Assistant Engineer (Civil)','',                          '',                       '8801580998861', 3);

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. Module registration
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`, `is_active`) VALUES
('Office of the Estate & Store', 'office-of-estate-store', 'Manage Office of the Estate & Store page content and staff directory', 'fas fa-building', 37, 1);

SET FOREIGN_KEY_CHECKS = 1;
