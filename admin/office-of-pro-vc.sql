-- ─────────────────────────────────────────────────────────────────────────────
-- Office of Pro Vice Chancellor Module  –  office-of-pro-vc.sql
-- Run once against admin_primepnew2026
-- ─────────────────────────────────────────────────────────────────────────────

SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. pvc_settings: key-value store for all Office of Pro VC content
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `pvc_settings` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_val` MEDIUMTEXT,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `pvc_settings` (`setting_key`, `setting_val`) VALUES
-- Page / hero
('hero_title',        'Office of the Pro Vice Chancellor'),
('hero_subtitle',     'Academic Leadership – Prime University'),
('hero_intro',        'The Office of the Pro Vice Chancellor supports the academic and administrative leadership of Prime University, working in close coordination with the Vice Chancellor to advance the university\'s mission of excellence.'),
('meta_description',  'Office of the Pro Vice Chancellor – Prime University. Meet Prof. Dr. Abdur Rahman, Pro Vice Chancellor (Acting).'),
('is_published',      '1'),

-- Pro VC profile
('pvc_name',    'Prof. Dr. Abdur Rahman'),
('pvc_title',   'Pro Vice Chancellor (Acting)'),
('pvc_email_1', 'provc@primeuniversity.edu.bd'),
('pvc_email_2', ''),
('pvc_phone',   '48038590'),
('pvc_photo',   ''),
('pvc_bio',     ''),

-- PS profile
('ps_name',    ''),
('ps_title',   'PS to Pro Vice Chancellor'),
('ps_email_1', ''),
('ps_email_2', ''),
('ps_phone',   ''),
('ps_photo',   ''),

-- Pro VC message
('message_title', 'Message from the Pro Vice Chancellor'),
('message_body',  '')

ON DUPLICATE KEY UPDATE `setting_val` = VALUES(`setting_val`);

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. Module registration
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`, `is_active`) VALUES
('Office of Pro VC', 'office-of-pro-vc', 'Manage Office of the Pro Vice Chancellor page content', 'fas fa-user-graduate', 28, 1);

SET FOREIGN_KEY_CHECKS = 1;
