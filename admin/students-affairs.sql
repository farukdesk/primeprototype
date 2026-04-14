-- ─────────────────────────────────────────────────────────────────────────────
-- Students' Affairs Module  –  students-affairs.sql
-- Run once against admin_primepnew2026
-- ─────────────────────────────────────────────────────────────────────────────

SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. sa_settings: key-value store for Students' Affairs content
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sa_settings` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_val` MEDIUMTEXT,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `sa_settings` (`setting_key`, `setting_val`) VALUES
-- Page / hero
('hero_title',        'Students\' Affairs'),
('hero_subtitle',     'Students\' Affairs – Prime University'),
('hero_intro',        'The Students\' Affairs office at Prime University is dedicated to supporting students throughout their academic journey, from admission to graduation, ensuring a smooth and enriching university experience.'),
('meta_description',  'Students\' Affairs – Prime University. Supporting students through admissions and campus life.'),
('is_published',      '1'),

-- Head profile
('head_name',    'Jamila Khatun'),
('head_title',   'Admission Officer (In-charge of Admission Office)'),
('head_email_1', 'admission@primeuniversity.edu.bd'),
('head_email_2', ''),
('head_phone',   '01687191986'),
('head_photo',   ''),
('head_bio',     ''),

-- Message
('message_title', 'Message from Students\' Affairs'),
('message_body',  '')

ON DUPLICATE KEY UPDATE `setting_val` = VALUES(`setting_val`);

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. sa_staff: office staff directory
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sa_staff` (
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

INSERT INTO `sa_staff` (`name`, `title`, `email_1`, `email_2`, `phone`, `sort_order`) VALUES
('Jamila Khatun', 'Admission Officer (In-charge of Admission Office)', 'admission@primeuniversity.edu.bd', '', '01687191986', 1),
('Md Jowel Rana',  'Digital Marketing Officer',                        'dmpu@primeuniversity.edu.bd',      '', '8801955395756', 2),
('Naznin Naher',   'Admission Officer',                                 'admission@primeuniversity.edu.bd', '', '01838781177', 3);

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. Module registration
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`, `is_active`) VALUES
('Students\' Affairs', 'students-affairs', 'Manage Students\' Affairs page content and staff directory', 'fas fa-user-graduate', 37, 1);

SET FOREIGN_KEY_CHECKS = 1;
