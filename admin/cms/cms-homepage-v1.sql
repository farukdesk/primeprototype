-- ============================================================
-- CMS Homepage Modules – v1
-- Run this after database.sql to add the four homepage
-- content management tables and their seed data.
-- ============================================================

-- -------------------------------------------------------
-- Table: cms_programs
-- Homepage feature cards (Bachelor, Masters, Language School)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cms_programs` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `title`       VARCHAR(255)  NOT NULL,
    `description` TEXT          DEFAULT NULL,
    `link_url`    VARCHAR(500)  DEFAULT NULL,
    `link_text`   VARCHAR(150)  DEFAULT 'Read More',
    `sort_order`  INT           DEFAULT 0,
    `is_active`   TINYINT(1)    DEFAULT 1,
    `created_at`  DATETIME      DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `cms_programs` (`title`, `description`, `link_url`, `link_text`, `sort_order`, `is_active`) VALUES
('Bachelor Degree',  'Pursue undergraduate programs across diverse fields including engineering, business, law, and arts to build a strong foundation for your career.', 'about-us-v3.html', 'Read More', 1, 1),
('Masters Degree',   'Advance your expertise with postgraduate programs in business administration, education, law, and English literature designed for professional growth.',  'about-us-v3.html', 'Read More', 2, 1),
('Language School',  'Enhance your communication skills with comprehensive language programs including English and Bangla to excel in academic and professional environments.',  'about-us-v3.html', 'Read More', 3, 1);

-- -------------------------------------------------------
-- Table: cms_about_settings
-- Key-value store for the About Us section & stats
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cms_about_settings` (
    `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `setting_key`   VARCHAR(100)  NOT NULL,
    `setting_value` TEXT          DEFAULT NULL,
    `updated_at`    DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_about_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `cms_about_settings` (`setting_key`, `setting_value`) VALUES
('subtitle',          'About us'),
('title',             'Our Prime University System Truly Inspires You to Learn More'),
('description',       'Enhance your knowledge and grow professionally by learning new skills anytime, anywhere. Access expert-led courses designed to help you succeed in your career, all from the comfort of your home.'),
('youtube_url',       'https://www.youtube.com/embed/0Cx-Xk5i6SM?controls=1&rel=0&modestbranding=1'),
('view_program_url',  'event-grid.html'),
('mission_1_title',   'University Mission Statement'),
('mission_2_title',   'University Mission Statement'),
('stat_1_number',     '1'),
('stat_1_label',      'Modern Smart Campus'),
('stat_2_number',     '4+'),
('stat_2_label',      'Hostel Facilities'),
('stat_3_number',     '20+'),
('stat_3_label',      'Mentorship Programs');

-- -------------------------------------------------------
-- Table: cms_campus_items
-- Campus Life section cards (Student Life, Arts & Culture, …)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cms_campus_items` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `title`       VARCHAR(255)  NOT NULL,
    `image`       VARCHAR(500)  DEFAULT NULL,
    `link_url`    VARCHAR(500)  DEFAULT NULL,
    `sort_order`  INT           DEFAULT 0,
    `is_active`   TINYINT(1)    DEFAULT 1,
    `created_at`  DATETIME      DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `cms_campus_items` (`title`, `image`, `link_url`, `sort_order`, `is_active`) VALUES
('Student Life',   NULL, 'about-us-v3.html', 1, 1),
('Arts & Culture', NULL, 'about-us-v3.html', 2, 1),
('Sport & Fitness',NULL, 'about-us-v3.html', 3, 1);

-- -------------------------------------------------------
-- Table: cms_alumni
-- Notable Alumni section cards
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cms_alumni` (
    `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `name`         VARCHAR(255)  NOT NULL,
    `designation`  VARCHAR(255)  DEFAULT NULL,
    `organization` VARCHAR(255)  DEFAULT NULL,
    `photo`        VARCHAR(500)  DEFAULT NULL,
    `sort_order`   INT           DEFAULT 0,
    `is_active`    TINYINT(1)    DEFAULT 1,
    `created_at`   DATETIME      DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `cms_alumni` (`name`, `designation`, `organization`, `sort_order`, `is_active`) VALUES
('Mohammad Imran Hayat Khan', 'FAVP',                       'Al-Arafa Islami Bank',  1, 1),
('Dr Foisal Ahmed',           'Assistant Professor',        'Prime University',      2, 1),
('Syed Saidul Islam Khan',    'Captain Bangladesh Navy (Retd)', NULL,                3, 1),
('Kh Hasanuzzaman',           'Director',                   'Dania Mechinaries',     4, 1),
('Md Zane Alam',              'CEO',                        'Mec Tech Corporation',  5, 1);
