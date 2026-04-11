-- ─────────────────────────────────────────────────────────────────────────────
-- Office of Vice Chancellor Module  –  office-of-vc.sql
-- Run once against admin_primepnew2026
-- ─────────────────────────────────────────────────────────────────────────────

SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. vc_settings: key-value store for all Office of VC content
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `vc_settings` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_val` MEDIUMTEXT,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `vc_settings` (`setting_key`, `setting_val`) VALUES
-- Page / hero
('hero_title',        'Office of the Vice Chancellor'),
('hero_subtitle',     'Academic & Administrative Leadership'),
('hero_intro',        'The Office of the Vice Chancellor is the apex academic and administrative authority of Prime University, providing visionary leadership for the university\'s mission of excellence in higher education.'),
('meta_description',  'Office of the Vice Chancellor – Prime University. Meet Prof. Dr. Quazi Deen Mohd Khosru, Vice Chancellor.'),
('is_published',      '1'),

-- VC profile
('vc_name',        'Prof. Dr. Quazi Deen Mohd Khosru'),
('vc_title',       'Vice Chancellor'),
('vc_email_1',     'vc@primeuniversity.edu.bd'),
('vc_email_2',     'qdkhosru@eee.buet.ac.bd'),
('vc_phone',       '41002437'),
('vc_scholar_url', 'https://scholar.google.com/citations?user=UK2_rNsAAAAJ'),
('vc_photo',       ''),
('vc_bio',         'Prof. Dr. Quazi Deen Mohd Khosru is a renowned scholar with an international reputation in the field of Electrical and Electronic Engineering. He completed his BSc from Aligarh Muslim University, India; MSc from BUET; and PhD from Osaka University, Japan. He has also held various important positions throughout his distinguished career. Prof. Dr. Khosru has carried out significant research in multiple areas of Electrical and Electronic Engineering. He currently serves as Professor in the Department of Electrical and Electronic Engineering, Bangladesh University of Engineering and Technology (BUET), and as Vice-Chancellor of Prime University.'),

-- PS profile
('ps_name',    'Md Abu Bakar Siddique'),
('ps_title',   'PS to Vice Chancellor'),
('ps_email_1', 'pstovc.pu@gmail.com'),
('ps_email_2', 'absiddique1998@gmail.com'),
('ps_phone',   '01301077922'),

-- VC message
('message_title', 'Message from the Vice Chancellor'),
('message_body',  'It gives me immense pleasure to extend warm greetings to all. Prime University is a center of excellence committed to nurturing knowledge, character, and innovation. The University takes pride in its journey as one of the leading private universities in Bangladesh - a place where ideas flourish, dreams take shape, and values are instilled for life.\n\nPrime University has always stood for excellence in higher education, innovation in teaching, and integrity in service to the nation. Our esteemed alumni spread across the world are the torchbearers of these values, making us proud through their achievements in diverse fields. Their continued engagement, encouragement, and contribution to the university\'s growth remain invaluable. I take this opportunity to express my heartfelt gratitude to our alumni community for their unwavering support and for upholding the good name of Prime University wherever they go.\n\nAs we move forward, Prime University is embracing a new era of digital transformation. We are integrating advanced technologies into academic management, learning systems, and administrative services to create a smarter, more efficient, and globally connected campus. We have reset and modernized the university\'s operations by establishing State-of-the-Art classrooms and labs, facilitating interactive learning and hands-on practice. This digitalization initiative will enhance academic delivery, strengthen research capacity, and foster a collaborative environment aligned with the needs of the Fourth Industrial Revolution.\n\nTo our students, parents, faculty, staff, alumni, and all well-wishers, I extend my sincere appreciation for your trust, collaboration, and continued support. Together, we will uphold the legacy of Prime University as a dynamic, inclusive, and forward-looking institution shaping the leaders of tomorrow. Let us move ahead with shared vision, integrity, and innovation - toward a future where Prime University continues to enlighten minds and empower generations.')

ON DUPLICATE KEY UPDATE `setting_val` = VALUES(`setting_val`);

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. Module registration
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`, `is_active`) VALUES
('Office of VC', 'office-of-vc', 'Manage Office of the Vice Chancellor page content', 'fas fa-user-tie', 26, 1);

SET FOREIGN_KEY_CHECKS = 1;
