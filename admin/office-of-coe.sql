-- ─────────────────────────────────────────────────────────────────────────────
-- Office of COE Module  –  office-of-coe.sql
-- Run once against admin_primepnew2026
-- ─────────────────────────────────────────────────────────────────────────────

SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. coe_settings: key-value store for Office of COE content
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `coe_settings` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_val` MEDIUMTEXT,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `coe_settings` (`setting_key`, `setting_val`) VALUES
-- Page / hero
('hero_title',        'Controller of Examinations'),
('hero_subtitle',     'Examination Administration – Prime University'),
('hero_intro',        'The Controller of Examinations office is responsible for conducting, managing, and maintaining the integrity of all examinations at Prime University.'),
('meta_description',  'Controller of Examinations – Prime University. Meet Md Iftekhar Alam, Controller of Examinations.'),
('is_published',      '1'),

-- COE profile
('coe_name',    'Md Iftekhar Alam'),
('coe_title',   'Controller of Examinations'),
('coe_email_1', 'coe@primeuniversity.edu.bd'),
('coe_email_2', ''),
('coe_phone',   ''),
('coe_photo',   ''),
('coe_bio',     ''),

-- COE message
('message_title', 'Message from the Controller of Examinations'),
('message_body',  '')

ON DUPLICATE KEY UPDATE `setting_val` = VALUES(`setting_val`);

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. coe_staff: office staff directory
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `coe_staff` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(255) NOT NULL,
  `title`      VARCHAR(255) NOT NULL DEFAULT '',
  `email_1`    VARCHAR(255) NOT NULL DEFAULT '',
  `email_2`    VARCHAR(255) NOT NULL DEFAULT '',
  `phone`      VARCHAR(255) NOT NULL DEFAULT '',
  `phone_2`    VARCHAR(255) NOT NULL DEFAULT '',
  `photo`      VARCHAR(255) NOT NULL DEFAULT '',
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `coe_staff` (`name`, `title`, `email_1`, `email_2`, `phone`, `phone_2`, `sort_order`) VALUES
('Md. Salim Uddin',        'Deputy Controller of Examinations',          'arpu44@yahoo.com',                    '', '8801865073000', '8801715658485', 1),
('Shirin Sultana',         'Assistant Controller of Examinations',       'primeuniversity_crhp@yahoo.com',       '', '01714921193',   '',              2),
('Md. Monayem Hossain',    'Assistant Controller of Examinations',       'kmonayempu@gmail.com',                '', '01680977222',   '',              3),
('Md. Abdullah Al Mamun',  'Office Assistant cum Computer Operator',     'mamun.primeuniversity@gmail.com',      '', '01762656467',   '',              4),
('Sarowar Hossain',        'Office Assistant cum Computer Operator',     '',                                    '', '',              '',              5);

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. Module registration
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`, `is_active`) VALUES
('Office of COE', 'office-of-coe', 'Manage Controller of Examinations page content and staff directory', 'fas fa-scroll', 31, 1);

SET FOREIGN_KEY_CHECKS = 1;
