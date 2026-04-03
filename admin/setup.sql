-- ============================================================
-- Prime University Admin – Master Setup / Migration Script
-- ============================================================
-- Run this file after importing admin_primepnew2026.sql to
-- populate all default data, register modules and apply any
-- schema migrations that are not yet in the main export.
--
-- All statements are idempotent (safe to re-run).
-- ============================================================

SET NAMES utf8mb4;

-- ──────────────────────────────────────────────────────────
-- FIX: Remove duplicate homepage_stats rows
-- The live DB may have 8 rows (two sets of defaults).
-- Keep only the row with the lowest id for each sort_order.
-- ──────────────────────────────────────────────────────────
DELETE s1
FROM homepage_stats s1
INNER JOIN homepage_stats s2
  ON  s2.sort_order = s1.sort_order
  AND s2.id         < s1.id;

-- ──────────────────────────────────────────────────────────
-- SCHEMA MIGRATION: departments-v2
-- Add card background image column (safe: uses IF NOT EXISTS)
-- ──────────────────────────────────────────────────────────
ALTER TABLE `dept_departments`
    ADD COLUMN IF NOT EXISTS `image` VARCHAR(255) DEFAULT NULL
        COMMENT 'Filename in admin/uploads/departments/ used for homepage card background'
        AFTER `hero_icon`;

-- ──────────────────────────────────────────────────────────
-- MODULE REGISTRATIONS
-- Register every admin module so the permissions system works.
-- INSERT IGNORE skips rows whose (name,slug) already exist.
-- ──────────────────────────────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `icon`, `sort_order`, `is_active`) VALUES
('Homepage Management',  'homepage',        'fas fa-home',         95, 1),
('Dashboard',            'dashboard',       'fas fa-tachometer-alt', 5, 1),
('Users',                'users',           'fas fa-users',        10, 1),
('User Groups',          'user-groups',     'fas fa-layer-group',  15, 1),
('Modules',              'modules',         'fas fa-cubes',        20, 1),
('Faculty Profiles',     'faculty-profiles','fas fa-chalkboard-teacher', 25, 1),
('Departments',          'departments',     'fas fa-building',     30, 1),
('Pages',                'pages',           'fas fa-file-alt',     35, 1),
('Jobs',                 'jobs',            'fas fa-briefcase',    40, 1),
('Library',              'library',         'fas fa-book',         45, 1),
('Knowledge Base',       'knowledge-base',  'fas fa-lightbulb',    50, 1),
('Support Tickets',      'support-tickets', 'fas fa-headset',      55, 1),
('Students',             'students',        'fas fa-user-graduate',60, 1),
('Change Log',           'change-log',      'fas fa-history',      65, 1);

-- ──────────────────────────────────────────────────────────
-- DEFAULT HOMEPAGE STATS (only if table is empty)
-- ──────────────────────────────────────────────────────────
INSERT INTO `homepage_stats` (`icon`, `value`, `label`, `suffix`, `sort_order`, `is_active`)
SELECT 'fas fa-user-graduate','15000','Students Enrolled','+',1,1
WHERE NOT EXISTS (SELECT 1 FROM `homepage_stats` LIMIT 1);

INSERT INTO `homepage_stats` (`icon`, `value`, `label`, `suffix`, `sort_order`, `is_active`)
SELECT 'fas fa-chalkboard-teacher','250','Expert Faculty','+',2,1
WHERE NOT EXISTS (SELECT 1 FROM `homepage_stats` LIMIT 1);

INSERT INTO `homepage_stats` (`icon`, `value`, `label`, `suffix`, `sort_order`, `is_active`)
SELECT 'fas fa-book-open','35','Academic Programs','+',3,1
WHERE NOT EXISTS (SELECT 1 FROM `homepage_stats` LIMIT 1);

INSERT INTO `homepage_stats` (`icon`, `value`, `label`, `suffix`, `sort_order`, `is_active`)
SELECT 'fas fa-award','32','Years of Excellence','+',4,1
WHERE NOT EXISTS (SELECT 1 FROM `homepage_stats` LIMIT 1);

-- ──────────────────────────────────────────────────────────
-- DEFAULT WHY CHOOSE US FEATURES (only if table is empty)
-- ──────────────────────────────────────────────────────────
INSERT INTO `cms_features` (`icon`, `title`, `description`, `sort_order`, `is_active`)
SELECT 'fas fa-graduation-cap','Bachelor Programs','Comprehensive undergraduate degrees across engineering, business, law, arts and social sciences to launch your professional career.',1,1
WHERE NOT EXISTS (SELECT 1 FROM `cms_features` LIMIT 1);

INSERT INTO `cms_features` (`icon`, `title`, `description`, `sort_order`, `is_active`)
SELECT 'fas fa-user-tie','Masters Degrees','Advance your expertise with postgraduate programs in business administration, education, law and English literature designed for professional growth.',2,1
WHERE NOT EXISTS (SELECT 1 FROM `cms_features` LIMIT 1);

INSERT INTO `cms_features` (`icon`, `title`, `description`, `sort_order`, `is_active`)
SELECT 'fas fa-flask','Research Excellence','State-of-the-art laboratories and dedicated research centres empowering faculty and students to drive innovation and publish globally.',3,1
WHERE NOT EXISTS (SELECT 1 FROM `cms_features` LIMIT 1);

INSERT INTO `cms_features` (`icon`, `title`, `description`, `sort_order`, `is_active`)
SELECT 'fas fa-handshake','Industry Placement','Strong industry ties and an active career centre connecting graduates with leading employers through internships and job placement programmes.',4,1
WHERE NOT EXISTS (SELECT 1 FROM `cms_features` LIMIT 1);

INSERT INTO `cms_features` (`icon`, `title`, `description`, `sort_order`, `is_active`)
SELECT 'fas fa-globe-asia','Global Network','International affiliations and exchange programmes giving students exposure to global academic communities and career opportunities abroad.',5,1
WHERE NOT EXISTS (SELECT 1 FROM `cms_features` LIMIT 1);

INSERT INTO `cms_features` (`icon`, `title`, `description`, `sort_order`, `is_active`)
SELECT 'fas fa-shield-alt','Accredited Quality','UGC-approved and internationally benchmarked programmes ensuring every degree meets the highest standards of academic quality and relevance.',6,1
WHERE NOT EXISTS (SELECT 1 FROM `cms_features` LIMIT 1);

-- ──────────────────────────────────────────────────────────
-- DEFAULT ABOUT SETTINGS
-- ──────────────────────────────────────────────────────────
INSERT IGNORE INTO `cms_about_settings` (`setting_key`, `setting_value`) VALUES
('subtitle',                'About Prime University'),
('title',                   'A University Built for Your Success'),
('description',             'Prime University offers a world-class learning environment with expert faculty, state-of-the-art facilities and an industry-connected curriculum designed to launch your career.'),
('youtube_url',             ''),
('view_program_url',        'admission.php'),
('mission_1_title',         'Academic Excellence'),
('mission_2_title',         'Industry Relevance'),
('stat_1_number',           '32+'),
('stat_1_label',            'Years of Excellence'),
('stat_2_number',           '250+'),
('stat_2_label',            'Expert Faculty'),
('stat_3_number',           '20000+'),
('stat_3_label',            'Students Enrolled'),
('list_item_1',             'UGC-approved with internationally recognised degree programs'),
('list_item_2',             '250+ highly qualified and research-active faculty members'),
('list_item_3',             'Modern libraries, labs and digital learning infrastructure'),
('list_item_4',             'Dedicated career centre with industry placement programmes'),
('list_item_5',             'Active student clubs, sports and cultural programmes'),
('badge_number',            '32+'),
('badge_text',              'Years of Excellence'),
('main_image',              ''),
('apply_url',               'admission.php'),
('contact_url',             'contact.php'),
('about_section_subtitle',  'About the University'),
('about_section_title',     'Shaping Leaders Since'),
('about_section_title_accent', '1993');

-- ──────────────────────────────────────────────────────────
-- DEFAULT ADMISSIONS SETTINGS
-- ──────────────────────────────────────────────────────────
INSERT IGNORE INTO `cms_admission_settings` (`setting_key`, `setting_value`) VALUES
('badge_text',    'Admissions Open'),
('title',         'Begin Your Journey at'),
('title_accent',  'Prime University'),
('description',   'Applications are now open. Secure your place in one of our prestigious programmes.'),
('btn1_text',     'Apply Now'),
('btn1_url',      'admission.php'),
('btn2_text',     'Scholarships'),
('btn2_url',      'scholarships-waivers.php'),
('info_1_icon',   'fas fa-calendar-alt'),
('info_1_title',  'Application Deadline'),
('info_1_text',   'Rolling admissions – apply early'),
('info_2_icon',   'fas fa-graduation-cap'),
('info_2_title',  '35+ Programs Available'),
('info_2_text',   'Undergraduate, Postgraduate & Diploma'),
('info_3_icon',   'fas fa-award'),
('info_3_title',  'Scholarships Available'),
('info_3_text',   'Merit-based & need-based financial aid');

-- ──────────────────────────────────────────────────────────
-- DEFAULT CAMPUS LIFE ITEMS (only if table is empty)
-- ──────────────────────────────────────────────────────────
INSERT INTO `cms_campus_items` (`title`, `link_url`, `sort_order`, `is_active`)
SELECT 'Student Life','#',1,1
WHERE NOT EXISTS (SELECT 1 FROM `cms_campus_items` LIMIT 1);

INSERT INTO `cms_campus_items` (`title`, `link_url`, `sort_order`, `is_active`)
SELECT 'Arts & Culture','#',2,1
WHERE NOT EXISTS (SELECT 1 FROM `cms_campus_items` LIMIT 1);

INSERT INTO `cms_campus_items` (`title`, `link_url`, `sort_order`, `is_active`)
SELECT 'Sports & Fitness','#',3,1
WHERE NOT EXISTS (SELECT 1 FROM `cms_campus_items` LIMIT 1);

-- ──────────────────────────────────────────────────────────
-- DEFAULT CONTACT SETTINGS
-- ──────────────────────────────────────────────────────────
INSERT IGNORE INTO `cms_contact_settings` (`setting_key`, `setting_value`) VALUES
('section_subtitle',    'Get In Touch'),
('section_title',       'We\'re Here to Help You'),
('section_description', 'Reach out to our admissions team or visit us on campus.'),
('card_1_icon',         'fas fa-phone-alt'),
('card_1_title',        'Call Us'),
('card_1_value',        '+880-1710-996196'),
('card_1_href',         'tel:+8801710996196'),
('card_1_sub',          'Mon – Fri, 9am – 5pm'),
('card_2_icon',         'fas fa-envelope'),
('card_2_title',        'Email Us'),
('card_2_value',        'info@primeuniversity.edu.bd'),
('card_2_href',         'mailto:info@primeuniversity.edu.bd'),
('card_2_sub',          'We reply within 24 hours'),
('card_3_icon',         'fas fa-map-marker-alt'),
('card_3_title',        'Visit Campus'),
('card_3_value',        '114/116, Mazar Rd, Dhaka'),
('card_3_href',         'https://maps.google.com/?q=Prime+University+Dhaka'),
('card_3_sub',          'View on Google Maps'),
('card_4_icon',         'fas fa-clock'),
('card_4_title',        'Office Hours'),
('card_4_value',        'Sunday – Thursday'),
('card_4_href',         '#'),
('card_4_sub',          '9:00 AM – 5:00 PM'),
('btn1_text',           'Send a Message'),
('btn1_url',            'contact.php'),
('btn2_text',           'Apply Online'),
('btn2_url',            'admission.php');
