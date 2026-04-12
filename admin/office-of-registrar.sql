-- ─────────────────────────────────────────────────────────────────────────────
-- Office of Registrar Module  –  office-of-registrar.sql
-- Run once against admin_primepnew2026
-- ─────────────────────────────────────────────────────────────────────────────

SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. reg_settings: key-value store for Office of Registrar content
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `reg_settings` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_val` MEDIUMTEXT,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `reg_settings` (`setting_key`, `setting_val`) VALUES
-- Page / hero
('hero_title',        'Office of the Registrar'),
('hero_subtitle',     'Academic Administration – Prime University'),
('hero_intro',        'The Office of the Registrar is responsible for maintaining academic records, managing student enrolment, and ensuring the integrity of academic administration at Prime University.'),
('meta_description',  'Office of the Registrar – Prime University. Meet Prof. Dr. Md Mustafa Kamal, Registrar.'),
('is_published',      '1'),

-- Registrar profile
('reg_name',    'Prof. Dr. Md Mustafa Kamal'),
('reg_title',   'Registrar'),
('reg_email_1', 'registrar@primeuniversity.edu.bd'),
('reg_email_2', 'registrar@primeuniversity.ac.bd'),
('reg_phone',   ''),
('reg_photo',   ''),
('reg_bio',     ''),

-- Registrar message
('message_title', 'Message from the Registrar'),
('message_body',  '')

ON DUPLICATE KEY UPDATE `setting_val` = VALUES(`setting_val`);

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. reg_staff: office staff directory
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `reg_staff` (
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

INSERT INTO `reg_staff` (`name`, `title`, `email_1`, `email_2`, `phone`, `sort_order`) VALUES
('Md Fazlul Haque',           'PA to Registrar',                           'mdrovin07@gmail.com',         '', '01878406663',     1),
('Md Halimuzzaman Chowdhury', 'Deputy Registrar (HR)',                     'dr.hr@primeuniversity.edu.bd','', '01991-182900',    2),
('Dr Saida Ahmed',            'Medical Officer',                           '',                            '', '8801552406305',   3),
('Asma Khanom',               'Assistant Registrar (Admin)',               'asmakhanom48@gmail.com',      '', '01881841115',     4),
('Md Zaman Ibne Aziz',        'Administrative Officer',                    'mdzamanz84028@gmail.com',     '', '8801714238931',   5),
('Tasnia Nasrin',             'Section Officer',                           'tasnianasrin96@gmail.com',    '', '01798515626',     6),
('Md Shahid Hasan',           'Office Assistant cum Computer Operator',    '01917979590s@gmail.com',      '', '01320934884',     7);

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. Module registration
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`, `is_active`) VALUES
('Office of Registrar', 'office-of-registrar', 'Manage Office of the Registrar page content and staff directory', 'fas fa-stamp', 30, 1);

SET FOREIGN_KEY_CHECKS = 1;
