-- ============================================================
-- CMS Homepage Modules – v2
-- Run this after cms-homepage-v1.sql to add tables for the
-- Why Choose Us, Admissions CTA, Contact, and Notice Board
-- sections, plus extra keys for the About section.
-- ============================================================

-- -------------------------------------------------------
-- Table: cms_features
-- "Why Choose Us" feature cards
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cms_features` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `icon`        VARCHAR(100)  NOT NULL DEFAULT 'fas fa-star',
    `title`       VARCHAR(255)  NOT NULL,
    `description` TEXT          DEFAULT NULL,
    `sort_order`  INT           DEFAULT 0,
    `is_active`   TINYINT(1)    DEFAULT 1,
    `created_at`  DATETIME      DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `cms_features` (`icon`, `title`, `description`, `sort_order`, `is_active`) VALUES
('fas fa-graduation-cap', 'Bachelor Programs',   'Comprehensive undergraduate degrees across engineering, business, law, arts and social sciences to launch your professional career.',   1, 1),
('fas fa-user-tie',       'Masters Degrees',     'Advance your expertise with postgraduate programs in business administration, education, law and English literature designed for professional growth.', 2, 1),
('fas fa-flask',          'Research Excellence', 'State-of-the-art laboratories and dedicated research centres empowering faculty and students to drive innovation and publish globally.',  3, 1),
('fas fa-handshake',      'Industry Placement',  'Strong industry ties and an active career centre connecting graduates with leading employers through internships and job placement programmes.', 4, 1),
('fas fa-globe-asia',     'Global Network',      'International affiliations and exchange programmes giving students exposure to global academic communities and career opportunities abroad.',    5, 1),
('fas fa-shield-alt',     'Accredited Quality',  'UGC-approved and internationally benchmarked programmes ensuring every degree meets the highest standards of academic quality and relevance.',  6, 1);

-- -------------------------------------------------------
-- Table: cms_admission_settings
-- Key-value store for the Admissions CTA section
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cms_admission_settings` (
    `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `setting_key`   VARCHAR(100)  NOT NULL,
    `setting_value` TEXT          DEFAULT NULL,
    `updated_at`    DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_admission_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `cms_admission_settings` (`setting_key`, `setting_value`) VALUES
('badge_text',       'Admissions Open'),
('title',            'Begin Your Journey at'),
('title_accent',     'Prime University'),
('description',      'Applications for Summer 2026 are now open. Join thousands of students who have transformed their futures at Prime University — where academic excellence meets real-world opportunity.'),
('btn1_text',        'Apply Now'),
('btn1_url',         'admission.php'),
('btn2_text',        'Scholarships'),
('btn2_url',         'scholarships-waivers.php'),
('info_1_icon',      'fas fa-calendar-alt'),
('info_1_title',     'Application Deadline'),
('info_1_text',      'Summer Semester 2026 – Rolling admissions'),
('info_2_icon',      'fas fa-graduation-cap'),
('info_2_title',     '35+ Programs Available'),
('info_2_text',      'Undergraduate, Postgraduate & Diploma'),
('info_3_icon',      'fas fa-award'),
('info_3_title',     'Scholarships Available'),
('info_3_text',      'Merit-based & need-based financial aid');

-- -------------------------------------------------------
-- Table: cms_contact_settings
-- Key-value store for the Get In Touch section
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cms_contact_settings` (
    `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `setting_key`   VARCHAR(100)  NOT NULL,
    `setting_value` TEXT          DEFAULT NULL,
    `updated_at`    DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_contact_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `cms_contact_settings` (`setting_key`, `setting_value`) VALUES
('section_subtitle',  'Get In Touch'),
('section_title',     'We\'re Here to Help You'),
('section_description', 'Reach out to our admissions team or visit us on campus. We\'re happy to answer any questions about programmes, fees, scholarships or campus life.'),
('card_1_icon',       'fas fa-phone-alt'),
('card_1_title',      'Call Us'),
('card_1_value',      '+880-1710-996196'),
('card_1_href',       'tel:+8801710996196'),
('card_1_sub',        'Mon – Fri, 9am – 5pm'),
('card_2_icon',       'fas fa-envelope'),
('card_2_title',      'Email Us'),
('card_2_value',      'info@primeuniversity.edu.bd'),
('card_2_href',       'mailto:info@primeuniversity.edu.bd'),
('card_2_sub',        'We reply within 24 hours'),
('card_3_icon',       'fas fa-map-marker-alt'),
('card_3_title',      'Visit Campus'),
('card_3_value',      '114/116, Mazar Rd, Dhaka'),
('card_3_href',       'https://maps.google.com/?q=Prime+University+Dhaka'),
('card_3_sub',        'View on Google Maps'),
('card_4_icon',       'fas fa-clock'),
('card_4_title',      'Office Hours'),
('card_4_value',      'Sunday – Thursday'),
('card_4_href',       '#'),
('card_4_sub',        '9:00 AM – 5:00 PM'),
('btn1_text',         'Send a Message'),
('btn1_url',          'contact.php'),
('btn2_text',         'Apply Online'),
('btn2_url',          'admission.php');

-- -------------------------------------------------------
-- Table: cms_notices
-- Notice Board entries (can optionally publish as news)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cms_notices` (
    `id`                       INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `title`                    VARCHAR(255)  NOT NULL,
    `slug`                     VARCHAR(300)  NOT NULL,
    `content`                  LONGTEXT      DEFAULT NULL,
    `content_type`             ENUM('html','text') NOT NULL DEFAULT 'html',
    `attachment`               VARCHAR(500)  DEFAULT NULL,
    `attachment_original_name` VARCHAR(255)  DEFAULT NULL,
    `attachment_mime`          VARCHAR(100)  DEFAULT NULL,
    `attachment_size`          INT           DEFAULT NULL,
    `publish_as_news`          TINYINT(1)    NOT NULL DEFAULT 0 COMMENT 'Also appear in cms_news',
    `news_id`                  INT           DEFAULT NULL COMMENT 'FK to cms_news when published as news',
    `is_published`             TINYINT(1)    NOT NULL DEFAULT 0,
    `published_at`             DATETIME      DEFAULT NULL,
    `created_at`               DATETIME      DEFAULT CURRENT_TIMESTAMP,
    `updated_at`               DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_notice_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Extra keys for cms_about_settings (added in v2)
-- INSERT IGNORE preserves any values already customised.
-- -------------------------------------------------------
INSERT IGNORE INTO `cms_about_settings` (`setting_key`, `setting_value`) VALUES
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
