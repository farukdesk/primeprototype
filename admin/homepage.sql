-- Homepage Management Module
-- Tables: homepage_stats, homepage_testimonials
-- Run this after database.sql

-- ── Homepage Stats/Counters ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `homepage_stats` (
  `id`          int          NOT NULL AUTO_INCREMENT,
  `icon`        varchar(100) NOT NULL DEFAULT 'fas fa-star'  COMMENT 'Font Awesome class e.g. fas fa-user-graduate',
  `value`       varchar(50)  NOT NULL                        COMMENT 'Numeric or text value e.g. 15000 or 32+',
  `label`       varchar(120) NOT NULL                        COMMENT 'Label shown below the number',
  `suffix`      varchar(20)           DEFAULT '+'            COMMENT 'Suffix appended after animated number e.g. +',
  `sort_order`  int          NOT NULL DEFAULT '0',
  `is_active`   tinyint(1)   NOT NULL DEFAULT '1',
  `created_at`  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default stats
INSERT INTO `homepage_stats` (`icon`, `value`, `label`, `suffix`, `sort_order`, `is_active`) VALUES
('fas fa-user-graduate', '15000', 'Students Enrolled',    '+', 1, 1),
('fas fa-chalkboard-teacher', '250', 'Expert Faculty',    '+', 2, 1),
('fas fa-book-open',     '35',    'Academic Programs',    '+', 3, 1),
('fas fa-award',         '32',    'Years of Excellence',  '+', 4, 1);

-- ── Homepage Testimonials ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `homepage_testimonials` (
  `id`          int          NOT NULL AUTO_INCREMENT,
  `name`        varchar(120) NOT NULL,
  `designation` varchar(200)          DEFAULT NULL,
  `quote`       text         NOT NULL,
  `photo`       varchar(255)          DEFAULT NULL  COMMENT 'Filename inside uploads/homepage/',
  `rating`      tinyint      NOT NULL DEFAULT '5'   COMMENT '1–5 stars',
  `sort_order`  int          NOT NULL DEFAULT '0',
  `is_active`   tinyint(1)   NOT NULL DEFAULT '1',
  `created_at`  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sample testimonials
INSERT INTO `homepage_testimonials` (`name`, `designation`, `quote`, `rating`, `sort_order`, `is_active`) VALUES
('Rahim Uddin',   'BBA Graduate, Batch 2023',       'Prime University gave me the tools and confidence to launch my career. The faculty are incredibly supportive and the campus environment is truly inspiring.',   5, 1, 1),
('Sumaya Khanam', 'CSE Graduate, Batch 2022',        'Studying Computer Science here was life-changing. The hands-on labs and industry connections helped me land my dream job right after graduation.',              5, 2, 1),
('Mehedi Hasan',  'LLB Student, 3rd Year',           'The law faculty at Prime University are exceptional. The moot court practice and internship placements are outstanding compared to any other university.',     5, 3, 1),
('Fatema Akter',  'MBA Graduate, Batch 2024',        'The MBA program is rigorous and practical. I was able to immediately apply what I learned to real business challenges. Highly recommend!',                      4, 4, 1),
('Tanvir Ahmed',  'Pharmacy Graduate, Batch 2021',   'World-class laboratories and a dedicated research environment made my pharmacy degree truly valuable. I am proud to be a Prime University alumnus.',           5, 5, 1);

-- ── Module registration ──────────────────────────────────────────────────
-- Insert the 'homepage' module into the modules table so it appears in permissions
INSERT IGNORE INTO `modules` (`name`, `slug`, `icon`, `sort_order`, `is_active`)
VALUES ('Homepage Management', 'homepage', 'fas fa-home', 95, 1);
