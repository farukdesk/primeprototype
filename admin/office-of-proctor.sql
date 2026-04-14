-- ─────────────────────────────────────────────────────────────────────────────
-- Office of the Proctor Module  –  office-of-proctor.sql
-- Run once against admin_primepnew2026
-- ─────────────────────────────────────────────────────────────────────────────

SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. op_settings: key-value store for Office of the Proctor content
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `op_settings` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_val` MEDIUMTEXT,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `op_settings` (`setting_key`, `setting_val`) VALUES
-- Page / hero
('hero_title',        'Office of the Proctor'),
('hero_subtitle',     'Proctor Office – Prime University'),
('hero_intro',        'The Office of the Proctor at Prime University is responsible for maintaining discipline, ensuring student welfare, and upholding the code of conduct across the university campus.'),
('meta_description',  'Office of the Proctor – Prime University. Student discipline and campus welfare services.'),
('is_published',      '1'),

-- Head profile
('head_name',    'Md Abdul Awal'),
('head_title',   'Proctor'),
('head_email_1', 'awalnanny@yahoo.com'),
('head_email_2', ''),
('head_phone',   '01925991078'),
('head_photo',   ''),
('head_bio',     ''),

-- Message
('message_title', 'Message from the Office of the Proctor'),
('message_body',  '')

ON DUPLICATE KEY UPDATE `setting_val` = VALUES(`setting_val`);

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. op_staff: office staff directory
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `op_staff` (
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

INSERT INTO `op_staff` (`name`, `title`, `email_1`, `email_2`, `phone`, `sort_order`) VALUES
('Nahid Farzana',          'Asst. Proctor', 'nahidfarzanaa@gmail.com',        '', '01714208940',   1),
('Golam Sarwar',           'Asst. Proctor', 'advocatesarowarnafi@gmail.com',   '', '8801711704837', 2),
('Md. Abdul Aziz',         'Asst. Proctor', 'maaziz17@gmail.com',              '', '01737412935',   3),
('Sabina Yesmin',          'Asst. Proctor', 'asabina786@yahoo.com',            '', '01838016263',   4),
('Ms. Khadiza Begum',      'Asst. Proctor', 'khadizadolly@gmail.com',          '', '',              5),
('Mohd Jasim Uddin Khan',  'Asst. Proctor', 'jasimkhanbd19@yahoo.com',         '', '01711531738',   6),
('Md Al-Amin',             'Asst. Proctor', 'amin.bsmrstu@gmail.com',          '', '01715136421',   7);

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. Module registration
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`, `is_active`) VALUES
('Office of the Proctor', 'office-of-proctor', 'Manage Office of the Proctor page content and staff directory', 'fas fa-user-shield', 37, 1);

SET FOREIGN_KEY_CHECKS = 1;
