-- ─────────────────────────────────────────────────────────────────────────────
-- Office of Chairman Module  –  office-of-chairman.sql
-- Run once against admin_primepnew2026
-- ─────────────────────────────────────────────────────────────────────────────

SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. ch_settings: key-value store for all Office of Chairman content
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `ch_settings` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_val` MEDIUMTEXT,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `ch_settings` (`setting_key`, `setting_val`) VALUES
-- Page / hero
('hero_title',        'Office of the Chairman'),
('hero_subtitle',     'Board of Trustees – Prime University'),
('hero_intro',        'The Office of the Chairman provides visionary leadership and governance for Prime University through the Board of Trustees, ensuring the institution upholds its commitment to academic excellence and national development.'),
('meta_description',  'Office of the Chairman – Prime University. Meet Anwar Kamal Pasha, Chairman, Board of Trustees.'),
('is_published',      '1'),

-- Chairman profile
('ch_name',    'Anwar Kamal Pasha'),
('ch_title',   'Chairman, BOT'),
('ch_email_1', 'akpasha@setaragroup.com'),
('ch_email_2', ''),
('ch_phone',   ''),
('ch_photo',   ''),
('ch_bio',     ''),

-- PS profile
('ps_name',    'Md. Abdur Rouf'),
('ps_title',   'PS to Chairman, BOT'),
('ps_email_1', 'manikpu61@gmail.com'),
('ps_email_2', ''),
('ps_phone',   '01721528089'),
('ps_photo',   ''),

-- Chairman message
('message_title', 'Message from the Chairman'),
('message_body',  'Welcome to Prime University.\nPrime University is the first venture of the Prime Foundation. Among others, establishment of a first class educational institution was one of the major agendas of the foundation.\n\nPrime University, we are interested in contributing to your character as well as your intellect. Wherever your interest lies, be it Arts and Social Science, Business Administration and Engineering Studies, Prime University is here to assist you on your career path. We offer a customized educational experience by meeting students where they are and taking them to where they want to go. Our faculty will engage and challenge you and we are ready to help you with support, from financial aid to career advising and much more.\n\nWe believe the key to gaining maximum benefit from University experience is involvement. I encourage all students to seek out all of the wonderful opportunities here at Prime University. Talk to faculty outside of the classroom, attend events on campus and connect to people and organizations in our community that keep you grounded while opening up possibilities for the future. By becoming involved a student may discover where their real passion lies and how to live out that passion in life beyond college. As each semester begins, we all share the anticipation and excitement of new experiences, new acquaintances and the knowledge that we will end the academic year as different individuals than we begin it. We will have mastered more subject material. At Prime University, you will meet professors and faculty members who kindle an interest or even a passion for further study of a discipline.\n\nFor all of these reasons and many more, a degree from Prime University is a valuable investment in your future. Since our inception, over 10,000 students have graduated from Prime University and we are proud to share that our graduates enjoy a 95 percent job placement rate!\n\nWe promise at Prime University that you will find an environment that is intellectually challenging. When our students, in turn, promise to challenge themselves intellectually and engage in our community, the result is a covenant relationship that creates an educational experience of enduring value.')

ON DUPLICATE KEY UPDATE `setting_val` = VALUES(`setting_val`);

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. Module registration
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`, `is_active`) VALUES
('Office of Chairman', 'office-of-chairman', 'Manage Office of the Chairman (BOT) page content', 'fas fa-gavel', 27, 1);

SET FOREIGN_KEY_CHECKS = 1;
