-- ─────────────────────────────────────────────────────────────────────────────
-- PU At a Glance Module  –  pu-at-a-glance.sql
-- Run once against admin_primepnew2026
-- ─────────────────────────────────────────────────────────────────────────────

SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. glance_settings: key-value store for hero & about section text
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `glance_settings` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100)  NOT NULL UNIQUE,
  `setting_val` TEXT,
  `updated_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `glance_settings` (`setting_key`, `setting_val`) VALUES
('hero_tag',          'Est. 2002 · UGC Approved'),
('hero_title',        'Prime University'),
('hero_title_accent', 'At a Glance'),
('hero_subtitle',     'A comprehensive overview of Prime University — its vision, leadership, facilities, faculties, and achievements since its founding in 2002.'),
('hero_cta_primary_label', 'Apply Now'),
('hero_cta_primary_url',   '/apply-now.php'),
('hero_cta_secondary_label', 'Contact Us'),
('hero_cta_secondary_url',   '/contact.php'),
('about_section_tag',   'Who We Are'),
('about_section_title', 'A Legacy of Excellence in Higher Education'),
('about_description',   'Prime University is a University Grant Commission (UGC) approved private university established in 2002, located in Mirpur-1, Dhaka. It is committed to providing quality education in various disciplines including business, engineering, science, and arts — fostering academic excellence, research, and innovation.'),
('about_image',         ''),
('about_badge_text',    'Est. 2002 · Dhaka, Bangladesh'),
('cta_title',   'Begin Your Journey at Prime University'),
('cta_desc',    'Join thousands of students pursuing their dreams. Applications are open for all programs.'),
('cta_btn_label', 'Apply Now'),
('cta_btn_url',   '/apply-now.php'),
('cta_btn2_label', 'Contact Admissions'),
('cta_btn2_url',   '/contact.php')
ON DUPLICATE KEY UPDATE `setting_val` = VALUES(`setting_val`);

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. glance_stats: quick stats bar items
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `glance_stats` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `icon`       VARCHAR(120)  NOT NULL DEFAULT 'fas fa-star',
  `value`      VARCHAR(60)   NOT NULL COMMENT 'Display value e.g. 2002 or 30K+',
  `label`      VARCHAR(120)  NOT NULL,
  `sort_order` INT           NOT NULL DEFAULT 0,
  `is_active`  TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `glance_stats` (`icon`, `value`, `label`, `sort_order`) VALUES
('fas fa-calendar-alt',       '2002',  'Year Established',  1),
('fas fa-book-open',          '30K+',  'Library Books',     2),
('fas fa-users',              '5000+', 'Students',          3),
('fas fa-chalkboard-teacher', '200+',  'Faculty Members',   4),
('fas fa-award',              'UGC',   'Approved',          5),
('fas fa-map-marker-alt',     'Dhaka', 'Bangladesh',        6);

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. glance_leaders: leadership profile cards
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `glance_leaders` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(200)  NOT NULL,
  `role`       VARCHAR(120)  NOT NULL,
  `bio`        TEXT,
  `photo`      VARCHAR(300)  DEFAULT NULL,
  `sort_order` INT           NOT NULL DEFAULT 0,
  `is_active`  TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `glance_leaders` (`name`, `role`, `bio`, `sort_order`) VALUES
('Prof Dr. Abdur Rahman',       'Treasurer',  'The Treasurer oversees the financial affairs, budgeting, and fiscal management of Prime University, ensuring sound financial governance and transparency.', 1),
('Prof. Dr. Md. Mustafa Kamal', 'Registrar',  'The Registrar manages all academic and administrative records, oversees student enrollment, and ensures smooth functioning of university operations.', 2);

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. glance_messages: messages from Chairman and Vice Chancellor
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `glance_messages` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `msg_key`     VARCHAR(30)   NOT NULL UNIQUE COMMENT 'chairman or vc',
  `tab_label`   VARCHAR(100)  NOT NULL,
  `person_name` VARCHAR(200)  NOT NULL,
  `person_role` VARCHAR(200)  NOT NULL,
  `photo`       VARCHAR(300)  DEFAULT NULL,
  `body`        TEXT          NOT NULL,
  `sort_order`  INT           NOT NULL DEFAULT 0,
  `is_active`   TINYINT(1)    NOT NULL DEFAULT 1,
  `updated_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `glance_messages` (`msg_key`, `tab_label`, `person_name`, `person_role`, `body`, `sort_order`) VALUES
('chairman', 'Message from Chairman', 'Honorable Chairman', 'Board of Trustees',
 'Prime University has been a beacon of knowledge and opportunity since its establishment in 2002. Our vision has always been to create an inclusive academic environment where students can discover their potential, pursue their passions, and contribute meaningfully to society.\n\nWe are proud to offer world-class education through our diverse faculties and dedicated faculty members. Our commitment to research, innovation, and community engagement remains unwavering as we strive to make quality education accessible to all aspiring students of Bangladesh.\n\nI invite students, scholars, and stakeholders to join us in this journey of academic excellence. Together, we shall build a future enriched by knowledge, empathy, and integrity.',
 1),
('vc', 'Message from Vice Chancellor', 'Vice Chancellor', 'Prime University',
 'As Vice Chancellor of Prime University, I extend a warm welcome to all students, faculty, staff, and guests. Prime University has consistently upheld the highest standards of academic excellence and has emerged as a center of learning that bridges tradition with modernity.\n\nOur academic programs are meticulously designed to equip students with critical thinking, professional skills, and ethical values essential for success in the 21st century. The university's Innovation Hub, CRHP Research Center, and state-of-the-art Library demonstrate our commitment to fostering a culture of inquiry and discovery.\n\nWe believe in the transformative power of education and are dedicated to nurturing the next generation of leaders, innovators, and responsible citizens who will shape the future of Bangladesh and beyond.',
 2)
ON DUPLICATE KEY UPDATE `tab_label` = VALUES(`tab_label`);

-- ─────────────────────────────────────────────────────────────────────────────
-- 5. glance_highlights: campus highlights / facility cards
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `glance_highlights` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `title`       VARCHAR(200)  NOT NULL,
  `icon`        VARCHAR(120)  NOT NULL DEFAULT 'fas fa-star',
  `description` TEXT          NOT NULL,
  `color_theme` VARCHAR(30)   NOT NULL DEFAULT 'hc-blue' COMMENT 'CSS class: hc-blue hc-green hc-amber hc-purple hc-navy',
  `tag_label`   VARCHAR(100)  DEFAULT NULL,
  `sort_order`  INT           NOT NULL DEFAULT 0,
  `is_active`   TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `glance_highlights` (`title`, `icon`, `description`, `color_theme`, `tag_label`, `sort_order`) VALUES
('Innovation Hub',             'fas fa-lightbulb',    'A dynamic platform comprising university teachers, students, and researchers, formed under the supervision of Innovation Lab to expand technology research at the university level. The hub fosters collaboration, creativity, and entrepreneurial thinking among the university community.', 'hc-blue',   'Research & Technology', 1),
('Prime University Library',   'fas fa-book-reader',  'The library holds over 30,000 books covering liberal arts, social sciences, business, management, engineering, computer science, and language courses. It also features a Digital Library with text, images, audio, video, CD-ROMs, and specialized databases.', 'hc-green',  '30,000+ Books',         2),
('Research Center (CRHP)',     'fas fa-microscope',   "PU's dedicated research center — the Center for Research, Human Resource Development & Publications (CRHP) — drives scholarly research, human resource development initiatives, and academic publications across all disciplines.", 'hc-amber',  'CRHP',                  3),
('Alumni Association (PUALUMNI)', 'fas fa-user-graduate', 'The Prime University Alumni Association (PUALUMNI) is located on campus and connects graduates across generations. It facilitates networking, mentorship, and professional development opportunities for alumni and current students alike.', 'hc-purple', 'PUALUMNI',              4),
('Digital Library',            'fas fa-database',     'A focused digital collection of objects including text, images, audio, and video. The digital library ensures students and researchers have access to a wide range of academic resources available anytime, anywhere.', 'hc-navy',   'Digital Resources',     5),
('Modern Campus Infrastructure', 'fas fa-building',   'A purpose-built campus in Mirpur-1, Dhaka, equipped with modern classrooms, laboratories, seminar halls, and student amenities. The campus provides an inspiring environment that nurtures learning and personal growth.', 'hc-blue',   'Mirpur-1, Dhaka',       6);

-- ─────────────────────────────────────────────────────────────────────────────
-- 6. glance_milestones: historical timeline items
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `glance_milestones` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `year_label`  VARCHAR(60)   NOT NULL COMMENT 'e.g. "2002" or "Growth Phase"',
  `title`       VARCHAR(200)  NOT NULL,
  `description` TEXT          NOT NULL,
  `sort_order`  INT           NOT NULL DEFAULT 0,
  `is_active`   TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `glance_milestones` (`year_label`, `title`, `description`, `sort_order`) VALUES
('2002',        'Foundation & UGC Approval',          'Prime University was established in Mirpur-1, Dhaka, receiving UGC approval as a private university under the Private University Act of Bangladesh.', 1),
('Early Years', 'Library & Academic Infrastructure',  'Development of the university library with over 30,000 books, CD-ROMs, and databases covering diverse academic disciplines.', 2),
('Growth Phase','CRHP Research Center Established',   'Launch of the Center for Research, Human Resource Development & Publications (CRHP) to advance scholarly research and academic publications.', 3),
('Innovation Era','Innovation Hub Launched',          'Establishment of the Innovation Hub — a collaborative platform for teachers, students, and researchers to drive technology research and entrepreneurship.', 4),
('Community',   'Alumni Association (PUALUMNI)',       'Formation of the Prime University Alumni Association (PUALUMNI) to connect graduates and support current students through mentorship and networking.', 5),
('Today',       '20+ Years of Excellence',            'Continuing to grow with 5,000+ students, 200+ faculty members, and multiple faculties offering world-class programs recognized across Bangladesh.', 6);

-- ─────────────────────────────────────────────────────────────────────────────
-- 7. Register module in access control
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `icon`, `sort_order`, `is_active`)
VALUES ('PU At a Glance', 'cms-glance', 'fas fa-eye', 25, 1);

SET FOREIGN_KEY_CHECKS = 1;
