-- ─────────────────────────────────────────────────────────────────────────────
-- Law & Legal Affairs Module  –  law-legal.sql
-- Run once against admin_primepnew2026
-- ─────────────────────────────────────────────────────────────────────────────

SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. ll_settings: key-value store for all Law & Legal Affairs page content
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `ll_settings` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_val` MEDIUMTEXT,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `ll_settings` (`setting_key`, `setting_val`) VALUES
-- Page / hero
('hero_title',       'Law & Legal Affairs'),
('hero_subtitle',    'Legal Counsel & Estate Management – Prime University'),
('hero_intro',       'The Law & Legal Affairs office of Prime University provides expert legal guidance, handles institutional legal matters, manages estate affairs, and ensures the university operates in full compliance with the laws of Bangladesh.'),
('meta_description', 'Law & Legal Affairs – Prime University. Expert legal counsel and estate management services.'),
('is_published',     '1'),

-- Adviser profile
('adviser_name',    'Md. Ashraf Ali'),
('adviser_title',   'Adviser'),
('adviser_email_1', 'md.aliashraf45@gmail.com'),
('adviser_email_2', ''),
('adviser_phone',   ''),
('adviser_photo',   ''),
('adviser_bio',     'Advocate, District & Session Judge Court, Dhaka.'),

-- Assistant Adviser profile
('assistant_name',    'Md. Yasin'),
('assistant_title',   'Assistant Adviser (Legal & Estate)'),
('assistant_email_1', 'adv.yasin@primeuniversity.ac.bd'),
('assistant_email_2', ''),
('assistant_phone',   '01705-502190'),
('assistant_photo',   ''),
('assistant_bio',     ''),

-- Message
('message_title', 'Message from the Adviser'),
('message_body',  '')

ON DUPLICATE KEY UPDATE `setting_val` = VALUES(`setting_val`);

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. ll_staff: additional legal staff members
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `ll_staff` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(200) NOT NULL,
  `designation` VARCHAR(200) DEFAULT NULL,
  `email`       VARCHAR(200) DEFAULT NULL,
  `phone`       VARCHAR(50)  DEFAULT NULL,
  `photo`       VARCHAR(255) DEFAULT NULL,
  `bio`         TEXT         DEFAULT NULL,
  `sort_order`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. ll_notices: legal notices and circulars
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `ll_notices` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `title`       VARCHAR(400) NOT NULL,
  `body`        MEDIUMTEXT   DEFAULT NULL,
  `notice_date` DATE         DEFAULT NULL,
  `category`    ENUM('notice','circular','policy','announcement') NOT NULL DEFAULT 'notice',
  `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. ll_services: legal services offered
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `ll_services` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `title`       VARCHAR(300) NOT NULL,
  `description` TEXT         DEFAULT NULL,
  `icon`        VARCHAR(100) NOT NULL DEFAULT 'fas fa-gavel',
  `sort_order`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default services
INSERT IGNORE INTO `ll_services` (`title`, `description`, `icon`, `sort_order`, `is_active`) VALUES
('Legal Consultation',        'Providing expert legal advice on matters relating to university administration, contracts, and institutional obligations.',                         'fas fa-balance-scale',  1, 1),
('Contract Review',           'Review, drafting, and vetting of all contracts, agreements, MOUs, and other legal documents entered into by the University.',                    'fas fa-file-contract',  2, 1),
('Estate Management',         'Oversight and management of university property, land records, lease agreements, and real estate affairs.',                                      'fas fa-building',       3, 1),
('Dispute Resolution',        'Handling legal disputes, arbitrations, and liaising with courts and regulatory bodies on behalf of the University.',                             'fas fa-handshake',      4, 1),
('Regulatory Compliance',     'Ensuring university policies and operations comply with UGC regulations, national laws, and relevant legal frameworks.',                         'fas fa-shield-alt',     5, 1),
('Legal Documentation',       'Preparation and maintenance of affidavits, power of attorney, notarized documents, and all official legal records of the University.',          'fas fa-folder-open',    6, 1);

-- ─────────────────────────────────────────────────────────────────────────────
-- 5. Module registration
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`, `is_active`) VALUES
('Law & Legal Affairs', 'law-legal', 'Manage the Law & Legal Affairs office page, staff, notices, and services', 'fas fa-gavel', 35, 1);

SET FOREIGN_KEY_CHECKS = 1;
