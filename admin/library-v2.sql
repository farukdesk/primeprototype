-- =============================================================================
-- Prime University Library Management System — v2 Migration
-- Adds: library_dept_collections, library_facilities
-- Run AFTER library.sql
-- =============================================================================

-- Department Collections (manageable from admin settings)
CREATE TABLE IF NOT EXISTS `library_dept_collections` (
  `id`           INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `label`        VARCHAR(80)    NOT NULL,
  `sub_label`    VARCHAR(160)   NOT NULL DEFAULT '',
  `icon_class`   VARCHAR(80)    NOT NULL DEFAULT 'fas fa-book',
  `color_from`   VARCHAR(20)    NOT NULL DEFAULT '#0f2a6b',
  `color_to`     VARCHAR(20)    NOT NULL DEFAULT '#1e4db7',
  `image_file`   VARCHAR(255)   NOT NULL DEFAULT '',
  `sort_order`   SMALLINT       NOT NULL DEFAULT 0,
  `is_active`    TINYINT(1)     NOT NULL DEFAULT 1,
  `created_at`   TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default department collections
INSERT IGNORE INTO `library_dept_collections`
  (`id`, `label`, `sub_label`, `icon_class`, `color_from`, `color_to`, `sort_order`) VALUES
  (1, 'CSE',            'Computer Science & Eng.',  'fas fa-microchip',           '#0f2a6b', '#1e4db7', 1),
  (2, 'EEE',            'Electrical & Electronic',  'fas fa-bolt',                '#0a3d5c', '#0e7cb8', 2),
  (3, 'Civil',          'Civil Engineering',         'fas fa-hard-hat',            '#0c3325', '#1a7a52', 3),
  (4, 'Law',            'Department of Law',         'fas fa-balance-scale',       '#3d0e0e', '#a31c1c', 4),
  (5, 'Business',       'Business Administration',   'fas fa-briefcase',           '#2e1a00', '#9c5f0a', 5),
  (6, 'English',        'Department of English',     'fas fa-pen-nib',             '#0e2040', '#1d5490', 6),
  (7, 'Bangla',         'Department of Bangla',      'fas fa-language',            '#280a3d', '#7b22c4', 7),
  (8, 'Fashion Design', 'Fashion & Technology',      'fas fa-tshirt',              '#3d0a2a', '#c4227d', 8);

-- Library Facilities (manageable from admin settings)
CREATE TABLE IF NOT EXISTS `library_facilities` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `icon_class`      VARCHAR(80)   NOT NULL DEFAULT 'fas fa-star',
  `name`            VARCHAR(120)  NOT NULL,
  `description`     VARCHAR(400)  NOT NULL DEFAULT '',
  `icon_bg_color`   VARCHAR(20)   NOT NULL DEFAULT '#f9e8eb',
  `icon_text_color` VARCHAR(20)   NOT NULL DEFAULT '#b5182e',
  `sort_order`      SMALLINT      NOT NULL DEFAULT 0,
  `is_active`       TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default facilities
INSERT IGNORE INTO `library_facilities`
  (`id`, `icon_class`, `name`, `description`, `icon_bg_color`, `icon_text_color`, `sort_order`) VALUES
  (1, 'fas fa-exchange-alt',      'Circulation Area',   'Borrow, return and renew books at the main counter.',                '#f9e8eb', '#b5182e', 1),
  (2, 'fas fa-desktop',           'E-Resource Centre',  'Access digital databases, e-journals and online resources.',        '#e3f5f2', '#0d8a7a', 2),
  (3, 'fas fa-book-reader',       'Reading Room',       'A quiet space dedicated to focused study and reading.',              '#e8f0ff', '#3563e9', 3),
  (4, 'fas fa-chalkboard-teacher','Teacher''s Corner',  'Reserved section with faculty reference materials.',                '#fef3dc', '#d4930a', 4),
  (5, 'fas fa-graduation-cap',    'Thesis Area',        'Collection of student theses and research dissertations.',           '#f0e8ff', '#7c3aed', 5),
  (6, 'fas fa-wifi',              'Library Wi-Fi',      'High-speed wireless internet throughout the library.',               '#e8fff3', '#16a34a', 6);
