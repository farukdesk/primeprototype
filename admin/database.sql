-- Prime University Admin Panel Database Schema
-- Run this SQL to set up the required tables

CREATE DATABASE IF NOT EXISTS `prime_university`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `prime_university`;

-- -------------------------------------------------------
-- Table: modules
-- Stores all available admin modules / sections
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `modules` (
    `id`          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(100)     NOT NULL,
    `slug`        VARCHAR(100)     NOT NULL UNIQUE,
    `description` TEXT,
    `icon`        VARCHAR(100)     DEFAULT 'fas fa-circle',
    `parent_id`   INT UNSIGNED     DEFAULT NULL,
    `sort_order`  INT              DEFAULT 0,
    `is_active`   TINYINT(1)       DEFAULT 1,
    `created_at`  DATETIME         DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME         DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_slug` (`slug`),
    KEY `idx_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Table: user_groups
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_groups` (
    `id`          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(100)     NOT NULL,
    `description` TEXT,
    `is_super`    TINYINT(1)       DEFAULT 0 COMMENT '1 = super admin group',
    `is_active`   TINYINT(1)       DEFAULT 1,
    `created_at`  DATETIME         DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME         DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Table: group_module_access
-- Maps which modules each group can access
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `group_module_access` (
    `id`          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `group_id`    INT UNSIGNED     NOT NULL,
    `module_id`   INT UNSIGNED     NOT NULL,
    `can_view`    TINYINT(1)       DEFAULT 1,
    `can_create`  TINYINT(1)       DEFAULT 0,
    `can_edit`    TINYINT(1)       DEFAULT 0,
    `can_delete`  TINYINT(1)       DEFAULT 0,
    `created_at`  DATETIME         DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_group_module` (`group_id`, `module_id`),
    FOREIGN KEY (`group_id`)  REFERENCES `user_groups`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`module_id`) REFERENCES `modules`(`id`)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Table: users
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id`           INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `group_id`     INT UNSIGNED     NOT NULL,
    `username`     VARCHAR(60)      NOT NULL UNIQUE,
    `email`        VARCHAR(191)     NOT NULL UNIQUE,
    `password`     VARCHAR(255)     NOT NULL COMMENT 'bcrypt hash',
    `full_name`    VARCHAR(150)     NOT NULL,
    `phone`        VARCHAR(30)      DEFAULT NULL,
    `avatar`       VARCHAR(255)     DEFAULT NULL,
    `is_active`    TINYINT(1)       DEFAULT 1,
    `last_login`   DATETIME         DEFAULT NULL,
    `created_at`   DATETIME         DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME         DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_username` (`username`),
    KEY `idx_email`    (`email`),
    FOREIGN KEY (`group_id`) REFERENCES `user_groups`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Table: email_templates
-- Stores system email templates keyed by action/trigger
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_templates` (
    `id`          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(150)     NOT NULL,
    `action`      VARCHAR(100)     NOT NULL UNIQUE COMMENT 'trigger slug e.g. forgot_password',
    `subject`     VARCHAR(255)     NOT NULL,
    `body_html`   LONGTEXT         NOT NULL,
    `variables`   VARCHAR(500)     DEFAULT NULL COMMENT 'comma-separated available variables e.g. {{full_name}},{{reset_link}}',
    `is_active`   TINYINT(1)       DEFAULT 1,
    `created_at`  DATETIME         DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME         DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Table: password_resets
-- Stores one-time password reset tokens for admin users
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `password_resets` (
    `id`         INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `email`      VARCHAR(191)     NOT NULL,
    `token`      VARCHAR(100)     NOT NULL UNIQUE,
    `created_at` DATETIME         DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_email` (`email`),
    KEY `idx_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Seed: Super Admin group
-- -------------------------------------------------------
INSERT INTO `user_groups` (`name`, `description`, `is_super`, `is_active`)
VALUES ('Super Admin', 'Full system access – unrestricted.', 1, 1);

-- -------------------------------------------------------
-- Seed: Core modules
-- -------------------------------------------------------
INSERT INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`) VALUES
('Dashboard',      'dashboard',     'Admin dashboard overview',            'fas fa-tachometer-alt', 1),
('Users',          'users',         'Manage system users',                 'fas fa-users',          2),
('User Groups',    'user-groups',   'Manage user groups and permissions',  'fas fa-layer-group',    3),
('Modules',        'modules',       'Manage system modules',               'fas fa-cubes',          4),
('Module Access',  'access',        'Assign module access to groups',      'fas fa-shield-alt',     5),
('Email Templates','email-templates','Manage system email templates',      'fas fa-envelope-open-text', 6),
('CMS – Menus',   'cms-menus',   'Manage website navigation menus',     'fas fa-bars',              10),
('CMS – News',    'cms-news',    'Manage latest news articles',          'fas fa-newspaper',         11),
('CMS – Sliders', 'cms-sliders', 'Manage homepage slider images',        'fas fa-images',            12);

-- -------------------------------------------------------
-- Seed: Default super admin user
-- Password: Admin@123  (change immediately after first login!)
-- -------------------------------------------------------
INSERT INTO `users` (`group_id`, `username`, `email`, `password`, `full_name`, `is_active`)
VALUES (
    1,
    'superadmin',
    'admin@primeuniversity.edu',
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'System Super Admin',
    1
);
-- NOTE: The hash above corresponds to the plain-text password: password
-- Run the following PHP snippet to generate a hash for your own password:
--   echo password_hash('YourNewPassword', PASSWORD_BCRYPT, ['cost' => 12]);
-- Then UPDATE the users table with the new hash before going live.

-- -------------------------------------------------------
-- Seed: Forgot Password email template
-- Variables: {{full_name}}, {{reset_link}}, {{app_name}}, {{expire_minutes}}
-- -------------------------------------------------------
INSERT INTO `email_templates` (`name`, `action`, `subject`, `body_html`, `variables`, `is_active`) VALUES (
  'Forgot Password',
  'forgot_password',
  'Reset Your Password – {{app_name}}',
  '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Reset Your Password</title>
<style>
  body { margin:0; padding:0; background:#f4f6fb; font-family:''Inter'',Arial,sans-serif; }
  .wrapper { max-width:580px; margin:40px auto; background:#fff; border-radius:16px; box-shadow:0 4px 24px rgba(0,0,0,.08); overflow:hidden; }
  .header { background:linear-gradient(135deg,#1a1f36 0%,#2d3561 100%); padding:36px 40px; text-align:center; }
  .header h1 { color:#fff; margin:0; font-size:1.5rem; font-weight:700; }
  .header p  { color:rgba(255,255,255,.7); margin:8px 0 0; font-size:.9rem; }
  .body { padding:36px 40px; color:#374151; }
  .body p  { margin:0 0 16px; line-height:1.7; font-size:.925rem; }
  .btn-wrap { text-align:center; margin:28px 0; }
  .btn { display:inline-block; padding:14px 36px; background:linear-gradient(135deg,#4f8ef7,#2d63e8); color:#fff !important;
         text-decoration:none; border-radius:10px; font-weight:600; font-size:.95rem; }
  .expire { background:#fff8e1; border-left:4px solid #f5a623; padding:12px 16px; border-radius:6px; font-size:.85rem; color:#7a5c00; margin:20px 0; }
  .footer { background:#f4f6fb; padding:20px 40px; text-align:center; font-size:.78rem; color:#9ca3af; }
</style>
</head>
<body>
<div class="wrapper">
  <div class="header">
    <h1>Password Reset Request</h1>
    <p>{{app_name}}</p>
  </div>
  <div class="body">
    <p>Hi <strong>{{full_name}}</strong>,</p>
    <p>We received a request to reset the password for your admin account. Click the button below to choose a new password:</p>
    <div class="btn-wrap">
      <a href="{{reset_link}}" class="btn">Reset My Password</a>
    </div>
    <div class="expire">
      <strong>⏰ This link expires in {{expire_minutes}} minutes.</strong><br>
      If you did not request a password reset, please ignore this email – your account remains secure.
    </div>
    <p>If the button above does not work, copy and paste the following link into your browser:</p>
    <p style="word-break:break-all;font-size:.82rem;color:#6b7280;">{{reset_link}}</p>
  </div>
  <div class="footer">
    &copy; {{app_name}} &mdash; This is an automated message, please do not reply.
  </div>
</div>
</body>
</html>',
  '{{full_name}},{{reset_link}},{{app_name}},{{expire_minutes}}',
  1
);

-- -------------------------------------------------------
-- CMS Tables
-- -------------------------------------------------------

-- Table: cms_menus
-- Stores navigation menu items in a self-referencing hierarchy
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cms_menus` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `parent_id`   INT UNSIGNED  DEFAULT NULL,
    `label`       VARCHAR(150)  NOT NULL,
    `url`         VARCHAR(500)  DEFAULT '#',
    `target`      ENUM('_self','_blank') DEFAULT '_self',
    `type`        ENUM('link','dropdown','megamenu') DEFAULT 'link',
    `icon`        VARCHAR(100)  DEFAULT NULL,
    `sort_order`  INT           DEFAULT 0,
    `is_active`   TINYINT(1)    DEFAULT 1,
    `created_at`  DATETIME      DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_parent` (`parent_id`),
    FOREIGN KEY (`parent_id`) REFERENCES `cms_menus`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Table: cms_news
-- Stores news articles with optional HTML or plain-text content
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cms_news` (
    `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `title`           VARCHAR(500)  NOT NULL,
    `slug`            VARCHAR(500)  NOT NULL,
    `content`         LONGTEXT,
    `content_type`    ENUM('html','text') DEFAULT 'html',
    `featured_image`  VARCHAR(500)  DEFAULT NULL,
    `is_published`    TINYINT(1)    DEFAULT 0,
    `published_at`    DATETIME      DEFAULT NULL,
    `created_at`      DATETIME      DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_slug` (`slug`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Table: cms_news_attachments
-- Stores files / images attached to a news article
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cms_news_attachments` (
    `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `news_id`       INT UNSIGNED  NOT NULL,
    `original_name` VARCHAR(255)  NOT NULL,
    `stored_name`   VARCHAR(255)  NOT NULL,
    `mime_type`     VARCHAR(100)  DEFAULT NULL,
    `size`          INT UNSIGNED  DEFAULT 0,
    `created_at`    DATETIME      DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_news` (`news_id`),
    FOREIGN KEY (`news_id`) REFERENCES `cms_news`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Table: cms_sliders
-- Stores homepage/section slider images
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cms_sliders` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `title`       VARCHAR(255)  DEFAULT NULL,
    `subtitle`    VARCHAR(500)  DEFAULT NULL,
    `image`       VARCHAR(500)  NOT NULL,
    `link_url`    VARCHAR(500)  DEFAULT NULL,
    `link_text`   VARCHAR(150)  DEFAULT NULL,
    `sort_order`  INT           DEFAULT 0,
    `is_active`   TINYINT(1)    DEFAULT 1,
    `created_at`  DATETIME      DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Table: cms_header_settings
-- Key-value store for front-page header top bar settings
-- (phone, email, portal links, social media URLs, etc.)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cms_header_settings` (
    `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `setting_key`   VARCHAR(100)  NOT NULL,
    `setting_value` TEXT          DEFAULT NULL,
    `updated_at`    DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Seed: Default header settings
-- -------------------------------------------------------
INSERT INTO `cms_header_settings` (`setting_key`, `setting_value`) VALUES
('phone',               '01710996196'),
('email',               'info@primeuniversity.edu.bd'),
('student_portal_url',  '#'),
('student_portal_text', 'Student Portal'),
('find_result_url',     '#'),
('find_result_text',    'Find Result'),
('facebook_url',        '#'),
('twitter_url',         '#'),
('instagram_url',       '#'),
('linkedin_url',        '#');

-- -------------------------------------------------------
-- Seed: Module for Header Settings
-- -------------------------------------------------------
INSERT INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`) VALUES
('CMS – Header', 'cms-header', 'Manage header top bar settings', 'fas fa-heading', 9);

-- -------------------------------------------------------
-- Homepage Management Module
-- Tables: homepage_stats, homepage_testimonials
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `homepage_stats` (
  `id`          INT           NOT NULL AUTO_INCREMENT,
  `icon`        VARCHAR(100)  NOT NULL DEFAULT 'fas fa-star'  COMMENT 'Font Awesome class e.g. fas fa-user-graduate',
  `value`       VARCHAR(50)   NOT NULL                        COMMENT 'Numeric or text value e.g. 15000',
  `label`       VARCHAR(120)  NOT NULL                        COMMENT 'Label shown below the number',
  `suffix`      VARCHAR(20)            DEFAULT '+'            COMMENT 'Suffix appended after animated number e.g. +',
  `sort_order`  INT           NOT NULL DEFAULT 0,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `homepage_stats` (`icon`, `value`, `label`, `suffix`, `sort_order`, `is_active`) VALUES
('fas fa-user-graduate',      '15000', 'Students Enrolled',   '+', 1, 1),
('fas fa-chalkboard-teacher', '250',   'Expert Faculty',      '+', 2, 1),
('fas fa-book-open',          '35',    'Academic Programs',   '+', 3, 1),
('fas fa-award',              '32',    'Years of Excellence', '+', 4, 1);

CREATE TABLE IF NOT EXISTS `homepage_testimonials` (
  `id`          INT           NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(120)  NOT NULL,
  `designation` VARCHAR(200)           DEFAULT NULL,
  `quote`       TEXT          NOT NULL,
  `photo`       VARCHAR(255)           DEFAULT NULL COMMENT 'Filename inside uploads/homepage/',
  `rating`      TINYINT       NOT NULL DEFAULT 5    COMMENT '1-5 stars',
  `sort_order`  INT           NOT NULL DEFAULT 0,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- Seed: Module for Homepage Management
-- -------------------------------------------------------
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`) VALUES
('Homepage Management', 'homepage', 'Manage homepage stats and testimonials', 'fas fa-home', 13);

-- ============================================================
-- CMS Homepage Modules – v1
-- Tables: cms_programs, cms_about_settings, cms_campus_items,
--         cms_alumni
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
('stat_3_label',      'Mentorship Programs'),
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
('Student Life',    NULL, 'about-us-v3.html', 1, 1),
('Arts & Culture',  NULL, 'about-us-v3.html', 2, 1),
('Sport & Fitness', NULL, 'about-us-v3.html', 3, 1);

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

-- ============================================================
-- CMS Homepage Modules – v2
-- Tables: cms_features, cms_admission_settings,
--         cms_contact_settings, cms_notices
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
('section_title',     'We''re Here to Help You'),
('section_description', 'Reach out to our admissions team or visit us on campus. We''re happy to answer any questions about programmes, fees, scholarships or campus life.'),
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
