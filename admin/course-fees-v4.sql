-- Course Fees Calculator – v4 Complete Redesign
-- Run to fully replace the old module.
-- Drops old tables and creates new ones.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Drop old tables
DROP TABLE IF EXISTS `cf_fixed_fees`;
DROP TABLE IF EXISTS `cf_programs`;
DROP TABLE IF EXISTS `cf_settings`;

-- 1. Global settings
CREATE TABLE `cf_settings` (
  `id`            TINYINT UNSIGNED NOT NULL DEFAULT 1 PRIMARY KEY,
  `page_title`    VARCHAR(300)     NOT NULL DEFAULT 'Course Fee Calculator',
  `session_label` VARCHAR(100)     NOT NULL DEFAULT 'Summer 2026'
                  COMMENT 'Semester label shown on the public calculator, e.g. "Summer 2026"',
  `disclaimer`    TEXT             DEFAULT NULL,
  `is_published`  TINYINT(1)       NOT NULL DEFAULT 1,
  `updated_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `cf_settings` (`id`,`page_title`,`session_label`,`disclaimer`,`is_published`) VALUES
(1, 'Course Fee Calculator', 'Summer 2026',
 'Fee estimates provided here are for general informational purposes only and are subject to change without prior notice. Actual fees may vary based on the programme, semester, and university policy. Please contact the Accounts Office for the most up-to-date fee schedule.',
 1);

-- 2. Degree type categories
CREATE TABLE `cf_degree_types` (
  `id`          TINYINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `slug`        VARCHAR(50)  NOT NULL UNIQUE,
  `name`        VARCHAR(100) NOT NULL,
  `icon`        VARCHAR(20)  DEFAULT NULL  COMMENT 'Emoji icon',
  `description` VARCHAR(300) DEFAULT NULL,
  `sort_order`  SMALLINT     NOT NULL DEFAULT 0,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `cf_degree_types` (`slug`,`name`,`icon`,`description`,`sort_order`) VALUES
('regular-bachelor',      'Regular Bachelor',        '📚', 'BBA · CSE · EEE · LLB & more — 4-Year Program', 1),
('bachelor-from-diploma', 'Bachelor from Diploma',   '🔧', 'CSE · EEE · CE (Diploma entry) — 4-Year Program', 2),
('masters',               'Masters',                 '🏛',  'MBA · MA English · LLM & more — 1–2 Year Programs', 3);

-- 3. Programs (new structure with all fee constants)
CREATE TABLE `cf_programs` (
  `id`                      INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `degree_type_id`          TINYINT UNSIGNED NOT NULL,
  `program_slug`            VARCHAR(50)     NOT NULL UNIQUE
                            COMMENT 'JS-compatible slug, e.g. bba, cse, mba-regular',
  `program_name`            VARCHAR(200)    NOT NULL,
  `sort_order`              SMALLINT        NOT NULL DEFAULT 0,
  `is_active`               TINYINT(1)      NOT NULL DEFAULT 1,

  -- Common constants
  `total_credits`           DECIMAL(6,2)    DEFAULT NULL,
  `duration_years`          DECIMAL(4,1)    DEFAULT NULL,
  `total_semesters`         TINYINT UNSIGNED DEFAULT NULL,
  `total_months`            SMALLINT UNSIGNED DEFAULT NULL,

  -- Bachelor / Diploma fee constants
  `standard_tuition_full`   INT UNSIGNED    DEFAULT NULL,
  `tuition_per_semester`    DECIMAL(10,2)   DEFAULT NULL,
  `admission_fees`          INT UNSIGNED    DEFAULT NULL
                            COMMENT 'Total admission day payment (admission + 1st sem reg + form)',
  `fixed_institutional_fees` INT UNSIGNED   DEFAULT NULL,
  `english_course_fee`      INT UNSIGNED    DEFAULT 0,
  `safety_net_cap`          INT UNSIGNED    DEFAULT NULL,
  `safety_net_per_semester` DECIMAL(10,2)   DEFAULT NULL,

  -- Bachelor scholarship / safety net config
  `attendance_requirement`  TINYINT UNSIGNED DEFAULT 70
                            COMMENT '70, 60, or 50 percent attendance for safety net',
  `safety_net_gpa_threshold` DECIMAL(4,2)   DEFAULT 3.00,
  `scholarship_type`        VARCHAR(30)     DEFAULT 'regular_bachelor'
                            COMMENT 'One of: regular_bachelor, ba_bangla, llb, diploma',

  -- Scholarship tier JSON (initial = SSC+HSC based, merit = semester GPA based)
  `initial_waiver_tiers`    JSON            DEFAULT NULL,
  `merit_waiver_tiers`      JSON            DEFAULT NULL,

  -- Masters fee constants
  `tuition_full`            INT UNSIGNED    DEFAULT NULL,
  `admission_fee_m`         INT UNSIGNED    DEFAULT NULL,
  `registration_fee`        INT UNSIGNED    DEFAULT NULL,
  `institutional_fees`      INT UNSIGNED    DEFAULT NULL,
  `campaign_waiver`         INT UNSIGNED    DEFAULT NULL,
  `total_program_cost`      INT UNSIGNED    DEFAULT NULL,
  `total_after_waiver`      INT UNSIGNED    DEFAULT NULL,
  `monthly_fixed`           DECIMAL(10,2)   DEFAULT NULL,

  -- Dual-track masters (MA English, LLM-style)
  `external_waiver`         INT UNSIGNED    DEFAULT NULL,
  `external_final`          INT UNSIGNED    DEFAULT NULL,
  `external_monthly`        DECIMAL(10,2)   DEFAULT NULL,
  `internal_waiver`         INT UNSIGNED    DEFAULT NULL,
  `internal_final`          INT UNSIGNED    DEFAULT NULL,
  `internal_monthly`        DECIMAL(10,2)   DEFAULT NULL,

  `created_at`              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY `idx_cfp_dtype` (`degree_type_id`),
  CONSTRAINT `fk_cfp_dtype` FOREIGN KEY (`degree_type_id`)
    REFERENCES `cf_degree_types`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Admission requirements (one row per bullet point)
CREATE TABLE `cf_admission_requirements` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `program_id`       INT UNSIGNED NOT NULL,
  `requirement_text` VARCHAR(500) NOT NULL,
  `sort_order`       SMALLINT     NOT NULL DEFAULT 0,

  KEY `idx_cfar_prog` (`program_id`),
  CONSTRAINT `fk_cfar_prog` FOREIGN KEY (`program_id`)
    REFERENCES `cf_programs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Register module
INSERT IGNORE INTO `modules` (`name`,`slug`,`description`,`is_active`,`sort_order`)
VALUES ('Course Fees Calculator','course-fees','Manage public-facing course fee calculator and programme fee structures',1,95)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `description`=VALUES(`description`);

-- 6. Seed programs with all constants from reference data

-- Get degree type IDs
SET @rb = (SELECT id FROM cf_degree_types WHERE slug='regular-bachelor');
SET @dp = (SELECT id FROM cf_degree_types WHERE slug='bachelor-from-diploma');
SET @ms = (SELECT id FROM cf_degree_types WHERE slug='masters');

-- Regular Bachelor programs
INSERT IGNORE INTO `cf_programs`
(`degree_type_id`,`program_slug`,`program_name`,`sort_order`,
 `total_credits`,`duration_years`,`total_semesters`,`total_months`,
 `standard_tuition_full`,`tuition_per_semester`,`admission_fees`,
 `fixed_institutional_fees`,`english_course_fee`,
 `safety_net_cap`,`safety_net_per_semester`,
 `attendance_requirement`,`safety_net_gpa_threshold`,`scholarship_type`,
 `initial_waiver_tiers`,`merit_waiver_tiers`)
VALUES
-- BBA
(@rb,'bba','BBA - Bachelor of Business Administration',1,
 135,4.0,12,48,
 270000,22500,12000,
 198000,10000,
 140000,11666.67,
 70,3.00,'regular_bachelor',
 '[{"min":0,"max":4.99,"pct":0},{"min":5,"max":5.99,"pct":50},{"min":6,"max":6.99,"pct":60},{"min":7,"max":7.99,"pct":70},{"min":8,"max":9.99,"pct":80},{"min":10,"max":10,"pct":100}]',
 '[{"min":3.9,"max":4.0,"pct":100},{"min":3.8,"max":3.89,"pct":80},{"min":3.7,"max":3.79,"pct":70},{"min":3.5,"max":3.69,"pct":60},{"min":3.0,"max":3.49,"pct":55},{"min":0,"max":2.99,"pct":0}]'),
-- BA Bangla
(@rb,'ba-bangla','Bachelor of Arts in Bangla',2,
 141,4.0,12,48,
 125000,10416.67,12000,
 78000,10000,
 60000,5000,
 60,2.80,'ba_bangla',
 '[{"min":0,"max":4.99,"pct":0},{"min":5,"max":5.99,"pct":50},{"min":6,"max":6.99,"pct":60},{"min":7,"max":7.99,"pct":70},{"min":8,"max":9.99,"pct":80},{"min":10,"max":10,"pct":100}]',
 '[{"min":2.8,"max":3.99,"pct":72},{"min":4.0,"max":4.0,"pct":100},{"min":0,"max":2.79,"pct":0}]'),
-- BA English
(@rb,'ba-english','Bachelor of Arts in English',3,
 147,4.0,12,48,
 224000,18666.67,12000,
 144000,10000,
 114000,9500,
 60,3.00,'llb',
 '[{"min":0,"max":4.99,"pct":0},{"min":5,"max":5.99,"pct":50},{"min":6,"max":6.99,"pct":60},{"min":7,"max":7.99,"pct":70},{"min":8,"max":9.99,"pct":80},{"min":10,"max":10,"pct":100}]',
 '[{"min":3.9,"max":4.0,"pct":100},{"min":3.8,"max":3.89,"pct":80},{"min":3.7,"max":3.79,"pct":70},{"min":3.5,"max":3.69,"pct":60},{"min":3.0,"max":3.49,"pct":55},{"min":0,"max":2.99,"pct":0}]'),
-- EEE
(@rb,'eee','BSc in Electrical and Electronic Engineering (EEE)',4,
 158.5,4.0,12,48,
 317000,26416.67,12000,
 201000,10000,
 167000,13916.67,
 70,3.00,'regular_bachelor',
 '[{"min":0,"max":4.99,"pct":0},{"min":5,"max":5.99,"pct":50},{"min":6,"max":6.99,"pct":60},{"min":7,"max":7.99,"pct":70},{"min":8,"max":9.99,"pct":80},{"min":10,"max":10,"pct":100}]',
 '[{"min":3.9,"max":4.0,"pct":100},{"min":3.8,"max":3.89,"pct":80},{"min":3.7,"max":3.79,"pct":70},{"min":3.5,"max":3.69,"pct":60},{"min":3.0,"max":3.49,"pct":55},{"min":0,"max":2.99,"pct":0}]'),
-- CE
(@rb,'ce','BSc in Civil Engineering (CE)',5,
 158.5,4.0,12,48,
 317000,26416.67,12000,
 201000,10000,
 167000,13916.67,
 70,3.00,'regular_bachelor',
 '[{"min":0,"max":4.99,"pct":0},{"min":5,"max":5.99,"pct":50},{"min":6,"max":6.99,"pct":60},{"min":7,"max":7.99,"pct":70},{"min":8,"max":9.99,"pct":80},{"min":10,"max":10,"pct":100}]',
 '[{"min":3.9,"max":4.0,"pct":100},{"min":3.8,"max":3.89,"pct":80},{"min":3.7,"max":3.79,"pct":70},{"min":3.5,"max":3.69,"pct":60},{"min":3.0,"max":3.49,"pct":55},{"min":0,"max":2.99,"pct":0}]'),
-- CSE
(@rb,'cse','BSc in Computer Science and Engineering (CSE)',6,
 160.5,4.0,12,48,
 321000,26750,12000,
 197000,10000,
 171000,14250,
 70,3.00,'regular_bachelor',
 '[{"min":0,"max":4.99,"pct":0},{"min":5,"max":5.99,"pct":50},{"min":6,"max":6.99,"pct":60},{"min":7,"max":7.99,"pct":70},{"min":8,"max":9.99,"pct":80},{"min":10,"max":10,"pct":100}]',
 '[{"min":3.9,"max":4.0,"pct":100},{"min":3.8,"max":3.89,"pct":80},{"min":3.7,"max":3.79,"pct":70},{"min":3.5,"max":3.69,"pct":60},{"min":3.0,"max":3.49,"pct":55},{"min":0,"max":2.99,"pct":0}]'),
-- LLB
(@rb,'llb','Bachelor of Laws (LL.B. Hons.)',7,
 152,4.0,8,48,
 400000,50000,11000,
 222000,10000,
 130000,18571.43,
 60,3.00,'llb',
 '[{"min":0,"max":4.99,"pct":0},{"min":5,"max":5.99,"pct":50},{"min":6,"max":6.99,"pct":60},{"min":7,"max":7.99,"pct":70},{"min":8,"max":9.99,"pct":80},{"min":10,"max":10,"pct":100}]',
 '[{"min":3.9,"max":4.0,"pct":100},{"min":3.8,"max":3.89,"pct":80},{"min":3.7,"max":3.79,"pct":60},{"min":3.5,"max":3.69,"pct":50},{"min":3.0,"max":3.49,"pct":40},{"min":0,"max":2.99,"pct":0}]'),
-- FDAE
(@rb,'fdae','BSc in Fashion Design and Apparel Engineering',8,
 144,4.0,12,48,
 288000,24000,11000,
 180000,10000,
 138000,11500,
 70,3.00,'regular_bachelor',
 '[{"min":0,"max":4.99,"pct":0},{"min":5,"max":5.99,"pct":50},{"min":6,"max":6.99,"pct":60},{"min":7,"max":7.99,"pct":70},{"min":8,"max":9.99,"pct":80},{"min":10,"max":10,"pct":100}]',
 '[{"min":3.9,"max":4.0,"pct":100},{"min":3.8,"max":3.89,"pct":80},{"min":3.7,"max":3.79,"pct":70},{"min":3.5,"max":3.69,"pct":60},{"min":3.0,"max":3.49,"pct":55},{"min":0,"max":2.99,"pct":0}]');

-- Bachelor from Diploma programs
INSERT IGNORE INTO `cf_programs`
(`degree_type_id`,`program_slug`,`program_name`,`sort_order`,
 `total_credits`,`duration_years`,`total_semesters`,`total_months`,
 `standard_tuition_full`,`tuition_per_semester`,`admission_fees`,
 `fixed_institutional_fees`,`english_course_fee`,
 `safety_net_cap`,`safety_net_per_semester`,
 `attendance_requirement`,`safety_net_gpa_threshold`,`scholarship_type`,
 `initial_waiver_tiers`,`merit_waiver_tiers`)
VALUES
-- CSE Diploma
(@dp,'cse-diploma','BSc in Computer Science and Engineering (CSE - from Diploma)',1,
 160.5,4.0,12,48,
 300000,25000,12000,
 128000,0,
 100000,8333.33,
 50,2.50,'diploma',
 '[{"min":0,"max":4.99,"pct":50},{"min":5.01,"max":7.99,"pct":80},{"min":8,"max":10,"pct":100}]',
 '[{"min":3.9,"max":4.0,"pct":100},{"min":3.8,"max":3.89,"pct":90},{"min":3.5,"max":3.79,"pct":85},{"min":3.0,"max":3.49,"pct":80},{"min":2.5,"max":2.99,"pct":75},{"min":0,"max":2.49,"pct":0}]'),
-- EEE Diploma
(@dp,'eee-diploma','BSc in Electrical and Electronic Engineering (EEE - from Diploma)',2,
 158.5,4.0,12,48,
 300000,25000,12000,
 128000,0,
 100000,8333.33,
 50,2.50,'diploma',
 '[{"min":0,"max":4.99,"pct":50},{"min":5.01,"max":7.99,"pct":80},{"min":8,"max":10,"pct":100}]',
 '[{"min":3.9,"max":4.0,"pct":100},{"min":3.8,"max":3.89,"pct":90},{"min":3.7,"max":3.79,"pct":85},{"min":3.5,"max":3.69,"pct":80},{"min":3.0,"max":3.49,"pct":75},{"min":0,"max":2.99,"pct":0}]'),
-- CE Diploma
(@dp,'ce-diploma','BSc in Civil Engineering (CE - from Diploma)',3,
 158.5,4.0,12,48,
 300000,25000,12000,
 128000,0,
 100000,8333.33,
 50,2.50,'diploma',
 '[{"min":0,"max":4.99,"pct":50},{"min":5.01,"max":7.99,"pct":80},{"min":8,"max":10,"pct":100}]',
 '[{"min":3.9,"max":4.0,"pct":100},{"min":3.8,"max":3.89,"pct":90},{"min":3.7,"max":3.79,"pct":85},{"min":3.5,"max":3.69,"pct":80},{"min":3.0,"max":3.49,"pct":75},{"min":0,"max":2.99,"pct":0}]');

-- Masters programs
INSERT IGNORE INTO `cf_programs`
(`degree_type_id`,`program_slug`,`program_name`,`sort_order`,
 `total_credits`,`duration_years`,`total_semesters`,`total_months`,
 `tuition_full`,`admission_fee_m`,`registration_fee`,`institutional_fees`,
 `campaign_waiver`,`total_program_cost`,`total_after_waiver`,`monthly_fixed`,
 `external_waiver`,`external_final`,`external_monthly`,
 `internal_waiver`,`internal_final`,`internal_monthly`)
VALUES
-- MBA Regular
(@ms,'mba-regular','MBA - Master of Business Administration (Regular, 1 Year)',1,
 39,1.0,3,12,
 78000,10000,3000,29000,
 18000,120000,102000,7417,
 NULL,NULL,NULL,NULL,NULL,NULL),
-- E-MBA
(@ms,'emba','MBA - Executive MBA (E-MBA, 1.5 Years)',2,
 51,2.0,4,18,
 102000,10000,4000,34000,
 25000,150000,125000,6937.50,
 NULL,NULL,NULL,NULL,NULL,NULL),
-- MBA 3-Year
(@ms,'mba-3year','MBA for 3-Year Degree Holders (2 Years)',3,
 69,2.0,6,24,
 138000,10000,6000,36000,
 30000,190000,160000,6000,
 NULL,NULL,NULL,NULL,NULL,NULL),
-- MA Bangla Regular
(@ms,'ma-bangla-regular','MA in Bangla (Regular, 1 Year)',4,
 42,1.0,3,12,
 21000,10000,1500,4500,
 NULL,37000,NULL,2125,
 NULL,NULL,NULL,NULL,NULL,NULL),
-- MA Bangla 2yr
(@ms,'ma-bangla-2year','MA in Bangla (2 Year)',5,
 69,2.0,6,24,
 34500,10000,3000,12500,
 NULL,60000,NULL,1959,
 NULL,NULL,NULL,NULL,NULL,NULL),
-- MA English Regular (dual track)
(@ms,'ma-english-regular','MA in English (Regular, 1 Year)',6,
 42,1.0,3,12,
 42000,10000,3000,35000,
 NULL,90000,NULL,NULL,
 10000,80000,5583,
 20000,70000,4750),
-- MA English 2yr
(@ms,'ma-english-2year','MA in English (2 Year)',7,
 75,2.0,6,24,
 75000,10000,6000,59000,
 40000,150000,110000,3917,
 NULL,NULL,NULL,NULL,NULL,NULL),
-- LLM Regular (dual track)
(@ms,'llm-regular','LLM Regular (1 Year)',8,
 34,1.0,2,12,
 44200,10000,2000,33800,
 NULL,90000,NULL,NULL,
 10000,80000,5667,
 20000,70000,4833),
-- LLM 2yr
(@ms,'llm-2year','LLM Preli & Final (2 Year)',9,
 66,2.0,4,24,
 74250,10000,4000,31750,
 25000,120000,95000,3375,
 NULL,NULL,NULL,NULL,NULL,NULL),
-- B.Ed
(@ms,'bed','Bachelor of Education (B.Ed)',10,
 36,1.0,3,12,
 28800,10000,3000,8200,
 10000,50000,40000,2250,
 NULL,NULL,NULL,NULL,NULL,NULL),
-- M.Ed
(@ms,'med','Master of Education (M.Ed)',11,
 36,1.0,3,12,
 28800,10000,3000,8200,
 10000,50000,40000,2250,
 NULL,NULL,NULL,NULL,NULL,NULL);

-- Seed admission requirements
-- BBA
INSERT IGNORE INTO `cf_admission_requirements` (`program_id`,`requirement_text`,`sort_order`)
SELECT id, 'SSC &amp; HSC: Minimum GPA 2.5 in both or equivalent', 1 FROM cf_programs WHERE program_slug='bba';
INSERT IGNORE INTO `cf_admission_requirements` (`program_id`,`requirement_text`,`sort_order`)
SELECT id, 'O/A Levels: 5 subjects in \'O\' Level and 2 subjects in \'A\' Level', 2 FROM cf_programs WHERE program_slug='bba';
INSERT IGNORE INTO `cf_admission_requirements` (`program_id`,`requirement_text`,`sort_order`)
SELECT id, 'Required Grades: Minimum 4 \'B\' grades and 3 \'C\' grades across both levels', 3 FROM cf_programs WHERE program_slug='bba';

-- BA Bangla / BA English / LLB
INSERT IGNORE INTO `cf_admission_requirements` (`program_id`,`requirement_text`,`sort_order`)
SELECT id, 'SSC and HSC (or equivalent) passed from any recognised board', 1 FROM cf_programs WHERE program_slug IN ('ba-bangla','ba-english','llb');
INSERT IGNORE INTO `cf_admission_requirements` (`program_id`,`requirement_text`,`sort_order`)
SELECT id, 'Minimum GPA 2.0 in both SSC and HSC (without optional subject)', 2 FROM cf_programs WHERE program_slug IN ('ba-bangla','ba-english','llb');

-- EEE / CE / CSE / FDAE
INSERT IGNORE INTO `cf_admission_requirements` (`program_id`,`requirement_text`,`sort_order`)
SELECT id, 'SSC and HSC (Science group) passed from any recognised board', 1 FROM cf_programs WHERE program_slug IN ('eee','ce','cse','fdae');
INSERT IGNORE INTO `cf_admission_requirements` (`program_id`,`requirement_text`,`sort_order`)
SELECT id, 'Minimum GPA 2.5 in both SSC and HSC (without optional subject)', 2 FROM cf_programs WHERE program_slug IN ('eee','ce','cse','fdae');
INSERT IGNORE INTO `cf_admission_requirements` (`program_id`,`requirement_text`,`sort_order`)
SELECT id, 'Physics and Mathematics in HSC are required', 3 FROM cf_programs WHERE program_slug IN ('eee','ce');
INSERT IGNORE INTO `cf_admission_requirements` (`program_id`,`requirement_text`,`sort_order`)
SELECT id, 'Mathematics in HSC is required', 3 FROM cf_programs WHERE program_slug='cse';

-- Diploma programs
INSERT IGNORE INTO `cf_admission_requirements` (`program_id`,`requirement_text`,`sort_order`)
SELECT id, 'Diploma in relevant engineering field from a polytechnic institute', 1 FROM cf_programs WHERE program_slug IN ('cse-diploma','eee-diploma','ce-diploma');
INSERT IGNORE INTO `cf_admission_requirements` (`program_id`,`requirement_text`,`sort_order`)
SELECT id, 'Minimum CGPA 2.0 in Diploma (4-year program)', 2 FROM cf_programs WHERE program_slug IN ('cse-diploma','eee-diploma','ce-diploma');
INSERT IGNORE INTO `cf_admission_requirements` (`program_id`,`requirement_text`,`sort_order`)
SELECT id, 'SSC or equivalent passed', 3 FROM cf_programs WHERE program_slug IN ('cse-diploma','eee-diploma','ce-diploma');

-- Masters
INSERT IGNORE INTO `cf_admission_requirements` (`program_id`,`requirement_text`,`sort_order`)
SELECT id, 'SSC &amp; HSC: Total GPA points must be 5.0', 1 FROM cf_programs WHERE program_slug IN ('mba-regular','emba');
INSERT IGNORE INTO `cf_admission_requirements` (`program_id`,`requirement_text`,`sort_order`)
SELECT id, '4-year BBA degree from a recognised university with minimum CGPA 2.0', 2 FROM cf_programs WHERE program_slug='mba-regular';
INSERT IGNORE INTO `cf_admission_requirements` (`program_id`,`requirement_text`,`sort_order`)
SELECT id, 'Hons/Master degree from any recognised university with minimum CGPA 2.0', 2 FROM cf_programs WHERE program_slug='emba';
INSERT IGNORE INTO `cf_admission_requirements` (`program_id`,`requirement_text`,`sort_order`)
SELECT id, '3-year Bachelor degree (Pass/Honors) from a recognised university, minimum CGPA 2.0', 1 FROM cf_programs WHERE program_slug='mba-3year';

SET FOREIGN_KEY_CHECKS = 1;
