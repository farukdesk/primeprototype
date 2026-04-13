-- ─────────────────────────────────────────────────────────────────────────────
-- Office of Accounts & Audit Module  –  office-of-accounts-audit.sql
-- Run once against admin_primepnew2026
-- ─────────────────────────────────────────────────────────────────────────────

SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. aa_settings: key-value store for Office of Accounts & Audit content
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `aa_settings` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_val` MEDIUMTEXT,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `aa_settings` (`setting_key`, `setting_val`) VALUES
-- Page / hero
('hero_title',        'Office of Accounts & Audit'),
('hero_subtitle',     'Accounts & Audit – Prime University'),
('hero_intro',        'The Office of Accounts & Audit at Prime University is responsible for managing financial accounts, conducting audits, and ensuring fiscal transparency and accountability across all university operations.'),
('meta_description',  'Office of Accounts & Audit – Prime University. Financial accounts and audit services.'),
('is_published',      '1'),

-- Head profile
('head_name',    'Md Masud Karim'),
('head_title',   'Deputy Director (Accounts)'),
('head_email_1', 'mk_raz@yahoo.com'),
('head_email_2', ''),
('head_phone',   '8801736733222'),
('head_photo',   ''),
('head_bio',     ''),

-- Message
('message_title', 'Message from the Office of Accounts & Audit'),
('message_body',  '')

ON DUPLICATE KEY UPDATE `setting_val` = VALUES(`setting_val`);

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. aa_staff: office staff directory
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `aa_staff` (
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

INSERT INTO `aa_staff` (`name`, `title`, `email_1`, `email_2`, `phone`, `sort_order`) VALUES
('Md Masud Karim',      'Deputy Director (Accounts)', 'mk_raz@yahoo.com',      '', '8801736733222', 1),
('Md Nasirul Islam',    'Accounts Officer',           'nasirul303@gmail.com',  '', '8801755468233', 2),
('Ashish Kumar Debnath','Accounts Officer',           'ashish.am00@gmail.com', '', '01710276980',   3),
('Md Raihan Shikder',   'Accounts Assistant',         'raihansr.jes@gmail.com','', '01711790513',   4);

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. Module registration
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`, `is_active`) VALUES
('Office of Accounts & Audit', 'office-of-accounts-audit', 'Manage Office of Accounts & Audit page content and staff directory', 'fas fa-file-invoice-dollar', 36, 1);

SET FOREIGN_KEY_CHECKS = 1;
