-- ─────────────────────────────────────────────────────────────────────────────
-- Office of Treasurer Module  –  office-of-treasurer.sql
-- Run once against admin_primepnew2026
-- ─────────────────────────────────────────────────────────────────────────────

SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. tr_settings: key-value store for all Office of Treasurer content
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `tr_settings` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_val` MEDIUMTEXT,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tr_settings` (`setting_key`, `setting_val`) VALUES
-- Page / hero
('hero_title',        'Office of the Treasurer'),
('hero_subtitle',     'Finance & Accounts – Prime University'),
('hero_intro',        'The Office of the Treasurer oversees the financial management and accounts of Prime University, ensuring fiscal responsibility and transparency in support of the university\'s academic mission.'),
('meta_description',  'Office of the Treasurer – Prime University. Meet Prof. Dr. Abdur Rahman, Treasurer.'),
('is_published',      '1'),

-- Treasurer profile
('tr_name',    'Prof. Dr. Abdur Rahman'),
('tr_title',   'Treasurer'),
('tr_email_1', 'treasurer@primeuniversity.edu.bd'),
('tr_email_2', ''),
('tr_phone',   '01716010102'),
('tr_photo',   ''),
('tr_bio',     ''),

-- PA profile
('pa_name',    'Md Anwar Hossain'),
('pa_title',   'PA to Treasurer'),
('pa_email_1', 'anwar.com2667@gmail.com'),
('pa_email_2', ''),
('pa_phone',   '8031810, 48034888 Ext. 109'),
('pa_photo',   ''),

-- Treasurer message
('message_title', 'Message from the Treasurer'),
('message_body',  '')

ON DUPLICATE KEY UPDATE `setting_val` = VALUES(`setting_val`);

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. Module registration
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`, `is_active`) VALUES
('Office of Treasurer', 'office-of-treasurer', 'Manage Office of the Treasurer page content', 'fas fa-coins', 29, 1);

SET FOREIGN_KEY_CHECKS = 1;
