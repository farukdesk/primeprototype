-- ─────────────────────────────────────────────────────────────────────────────
-- Office of IT Module  –  office-of-it.sql
-- Run once against admin_primepnew2026
-- ─────────────────────────────────────────────────────────────────────────────

SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. it_settings: key-value store for Office of IT content
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `it_settings` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_val` MEDIUMTEXT,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `it_settings` (`setting_key`, `setting_val`) VALUES
-- Page / hero
('hero_title',        'Office of IT'),
('hero_subtitle',     'Information Technology – Prime University'),
('hero_intro',        'The Office of Information Technology at Prime University is responsible for managing and maintaining all IT infrastructure, digital services, and technological resources to support academic and administrative operations.'),
('meta_description',  'Office of IT – Prime University. Information Technology services and support.'),
('is_published',      '1'),

-- Head profile
('head_name',    'Md Omar Faruk'),
('head_title',   'Deputy Director'),
('head_email_1', 'dd.it@primeuniversity.ac.bd'),
('head_email_2', ''),
('head_phone',   '01871200851'),
('head_photo',   ''),
('head_bio',     ''),

-- Message
('message_title', 'Message from the Office of IT'),
('message_body',  '')

ON DUPLICATE KEY UPDATE `setting_val` = VALUES(`setting_val`);

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. it_staff: office staff directory
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `it_staff` (
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

INSERT INTO `it_staff` (`name`, `title`, `email_1`, `email_2`, `phone`, `sort_order`) VALUES
('Md Omar Faruk',      'Deputy Director',    'dd.it@primeuniversity.ac.bd', '',                              '01871200851', 1),
('Md Belayet Hossain', 'Assistant Director', 'dls2014@gmail.com',           'belayet@primeuniversity.edu.bd','01865073021', 2);

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. Module registration
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`, `is_active`) VALUES
('Office of IT', 'office-of-it', 'Manage Office of IT page content and staff directory', 'fas fa-laptop-code', 35, 1);

SET FOREIGN_KEY_CHECKS = 1;
