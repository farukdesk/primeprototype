-- =============================================================
-- students-v3.sql
-- Enhanced Student Registration: reference tables, location data,
-- and new columns on existing tables.
-- Run AFTER students.sql (and students-v2.sql if applicable).
-- =============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────
-- 1. student_batches
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `student_batches` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(100) NOT NULL,
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order` SMALLINT     NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 2. student_exam_titles
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `student_exam_titles` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(200) NOT NULL,
  `short_name` VARCHAR(50)  DEFAULT NULL,
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order` SMALLINT     NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 3. student_boards
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `student_boards` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(200) NOT NULL,
  `short_name` VARCHAR(50)  DEFAULT NULL,
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order` SMALLINT     NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 4. student_groups
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `student_groups` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(100) NOT NULL,
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order` SMALLINT     NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 5. bd_districts (all 64 Bangladesh districts)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `bd_districts` (
  `id`       INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name`     VARCHAR(100) NOT NULL,
  `division` VARCHAR(100) NOT NULL,
  `bn_name`  VARCHAR(100) DEFAULT NULL COMMENT 'Bengali name'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 6. bd_thanas / upazilas
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `bd_thanas` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `district_id` INT UNSIGNED NOT NULL,
  `name`        VARCHAR(100) NOT NULL,
  `bn_name`     VARCHAR(100) DEFAULT NULL,
  KEY `idx_thana_district` (`district_id`),
  CONSTRAINT `fk_thana_district` FOREIGN KEY (`district_id`) REFERENCES `bd_districts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- Alter: dept_academic_programs – add program_type
-- ─────────────────────────────────────────────────────────────
ALTER TABLE `dept_academic_programs`
    ADD COLUMN IF NOT EXISTS `program_type`
        ENUM('Bachelor','Masters','Diploma','Certificate','Other') DEFAULT NULL
        COMMENT 'Degree level – auto-detected from degree_type or manually set'
        AFTER `degree_type`;

-- Auto-populate program_type from existing degree_type values
UPDATE `dept_academic_programs`
SET `program_type` =
    CASE
        WHEN `degree_type` LIKE '%Bachelor%' OR `degree_type` LIKE '%B.Sc%' OR `degree_type` LIKE '%B.B.A%'
             OR `degree_type` LIKE '%B.A%'   OR `degree_type` LIKE '%LLB%' THEN 'Bachelor'
        WHEN `degree_type` LIKE '%Master%'   OR `degree_type` LIKE '%M.Sc%' OR `degree_type` LIKE '%M.B.A%'
             OR `degree_type` LIKE '%M.A%'   OR `degree_type` LIKE '%LLM%'  THEN 'Masters'
        WHEN `degree_type` LIKE '%Diploma%'  THEN 'Diploma'
        WHEN `degree_type` LIKE '%Certificate%' THEN 'Certificate'
        WHEN `degree_type` IS NOT NULL AND `degree_type` != '' THEN 'Other'
        ELSE NULL
    END
WHERE `program_type` IS NULL;

-- ─────────────────────────────────────────────────────────────
-- Alter: students – new columns
-- ─────────────────────────────────────────────────────────────
ALTER TABLE `students`
    ADD COLUMN IF NOT EXISTS `batch_id`       INT UNSIGNED DEFAULT NULL
        COMMENT 'FK student_batches.id'
        AFTER `batch`,
    ADD COLUMN IF NOT EXISTS `year`           VARCHAR(10)  DEFAULT NULL
        COMMENT 'Enrollment/academic year e.g. 2025'
        AFTER `batch_id`,
    ADD COLUMN IF NOT EXISTS `country`        VARCHAR(100) NOT NULL DEFAULT 'Bangladesh'
        AFTER `nationality`,
    ADD COLUMN IF NOT EXISTS `district_id`    INT UNSIGNED DEFAULT NULL
        COMMENT 'FK bd_districts.id'
        AFTER `permanent_address`,
    ADD COLUMN IF NOT EXISTS `thana_id`       INT UNSIGNED DEFAULT NULL
        COMMENT 'FK bd_thanas.id'
        AFTER `district_id`,
    ADD COLUMN IF NOT EXISTS `faculty_label`  VARCHAR(200) DEFAULT NULL
        COMMENT 'Cached dept faculty_label at time of registration'
        AFTER `thana_id`;

ALTER TABLE `students`
    ADD CONSTRAINT IF NOT EXISTS `fk_students_batch`    FOREIGN KEY (`batch_id`)    REFERENCES `student_batches`(`id`) ON DELETE SET NULL,
    ADD CONSTRAINT IF NOT EXISTS `fk_students_district` FOREIGN KEY (`district_id`) REFERENCES `bd_districts`(`id`)   ON DELETE SET NULL,
    ADD CONSTRAINT IF NOT EXISTS `fk_students_thana`    FOREIGN KEY (`thana_id`)    REFERENCES `bd_thanas`(`id`)      ON DELETE SET NULL;

-- ─────────────────────────────────────────────────────────────
-- Alter: student_academic_qualifications – new reference IDs
-- ─────────────────────────────────────────────────────────────
ALTER TABLE `student_academic_qualifications`
    ADD COLUMN IF NOT EXISTS `exam_title_id` INT UNSIGNED DEFAULT NULL
        COMMENT 'FK student_exam_titles.id'
        AFTER `exam_name`,
    ADD COLUMN IF NOT EXISTS `board_id`      INT UNSIGNED DEFAULT NULL
        COMMENT 'FK student_boards.id'
        AFTER `board_university`,
    ADD COLUMN IF NOT EXISTS `group_id`      INT UNSIGNED DEFAULT NULL
        COMMENT 'FK student_groups.id'
        AFTER `group_name`;

ALTER TABLE `student_academic_qualifications`
    ADD CONSTRAINT IF NOT EXISTS `fk_qual_exam_title` FOREIGN KEY (`exam_title_id`) REFERENCES `student_exam_titles`(`id`) ON DELETE SET NULL,
    ADD CONSTRAINT IF NOT EXISTS `fk_qual_board`      FOREIGN KEY (`board_id`)      REFERENCES `student_boards`(`id`)      ON DELETE SET NULL,
    ADD CONSTRAINT IF NOT EXISTS `fk_qual_group`      FOREIGN KEY (`group_id`)      REFERENCES `student_groups`(`id`)      ON DELETE SET NULL;

-- ─────────────────────────────────────────────────────────────
-- Register module
-- ─────────────────────────────────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`)
VALUES ('Student References', 'student-references',
        'Manage student reference data: batches, exam titles, boards, academic groups',
        'fas fa-list-alt', 52);

-- ═════════════════════════════════════════════════════════════
-- SEED DATA
-- ═════════════════════════════════════════════════════════════

-- ─── Batches ───────────────────────────────────────────────────
INSERT IGNORE INTO `student_batches` (`name`, `sort_order`) VALUES
('1st Batch', 1), ('2nd Batch', 2), ('3rd Batch', 3), ('4th Batch', 4),
('5th Batch', 5), ('6th Batch', 6), ('7th Batch', 7), ('8th Batch', 8),
('9th Batch', 9), ('10th Batch', 10), ('11th Batch', 11), ('12th Batch', 12),
('13th Batch', 13), ('14th Batch', 14), ('15th Batch', 15), ('16th Batch', 16),
('17th Batch', 17), ('18th Batch', 18), ('19th Batch', 19), ('20th Batch', 20),
('21st Batch', 21), ('22nd Batch', 22), ('23rd Batch', 23), ('24th Batch', 24),
('25th Batch', 25), ('26th Batch', 26), ('27th Batch', 27), ('28th Batch', 28),
('29th Batch', 29), ('30th Batch', 30), ('31st Batch', 31), ('32nd Batch', 32),
('33rd Batch', 33), ('34th Batch', 34), ('35th Batch', 35), ('36th Batch', 36),
('37th Batch', 37), ('38th Batch', 38), ('39th Batch', 39), ('40th Batch', 40),
('41st Batch', 41), ('42nd Batch', 42), ('43rd Batch', 43), ('44th Batch', 44),
('45th Batch', 45), ('46th Batch', 46), ('47th Batch', 47), ('48th Batch', 48),
('49th Batch', 49), ('50th Batch', 50);

-- ─── Exam Titles ───────────────────────────────────────────────
INSERT IGNORE INTO `student_exam_titles` (`name`, `short_name`, `sort_order`) VALUES
('Secondary School Certificate', 'SSC', 1),
('Higher Secondary Certificate', 'HSC', 2),
('Dakhil', 'Dakhil', 3),
('Alim', 'Alim', 4),
('O Level', 'O Level', 5),
('A Level', 'A Level', 6),
('Bachelor of Science', 'B.Sc', 7),
('Bachelor of Arts', 'B.A', 8),
('Bachelor of Business Administration', 'BBA', 9),
('Bachelor of Commerce', 'B.Com', 10),
('Bachelor of Laws', 'LLB', 11),
('Bachelor of Engineering', 'B.Eng', 12),
('Master of Science', 'M.Sc', 13),
('Master of Arts', 'M.A', 14),
('Master of Business Administration', 'MBA', 15),
('Master of Commerce', 'M.Com', 16),
('Master of Laws', 'LLM', 17),
('Doctor of Philosophy', 'PhD', 18),
('Diploma in Engineering', 'Diploma', 19),
('Vocational Certificate', 'Vocational', 20),
('Junior School Certificate', 'JSC', 21),
('Primary School Certificate', 'PSC', 22);

-- ─── Boards ───────────────────────────────────────────────────
INSERT IGNORE INTO `student_boards` (`name`, `short_name`, `sort_order`) VALUES
('Dhaka Education Board', 'Dhaka', 1),
('Rajshahi Education Board', 'Rajshahi', 2),
('Chittagong Education Board', 'Chittagong', 3),
('Sylhet Education Board', 'Sylhet', 4),
('Jessore Education Board', 'Jessore', 5),
('Comilla Education Board', 'Comilla', 6),
('Barisal Education Board', 'Barisal', 7),
('Dinajpur Education Board', 'Dinajpur', 8),
('Mymensingh Education Board', 'Mymensingh', 9),
('Bangladesh Madrasah Education Board', 'Madrasah Board', 10),
('Bangladesh Technical Education Board', 'Technical Board', 11),
('National University', 'NU', 12),
('University of Dhaka', 'DU', 13),
('Bangladesh University of Engineering and Technology', 'BUET', 14),
('University of Rajshahi', 'RU', 15),
('Chittagong University', 'CU', 16),
('Jahangirnagar University', 'JU', 17),
('Islamic University', 'IU', 18),
('Shah Jalal University of Science and Technology', 'SUST', 19),
('Bangladesh Agricultural University', 'BAU', 20),
('Khulna University', 'KU', 21),
('Independent University Bangladesh', 'IUB', 22),
('North South University', 'NSU', 23),
('BRAC University', 'BRACU', 24),
('American International University Bangladesh', 'AIUB', 25),
('East West University', 'EWU', 26),
('United International University', 'UIU', 27),
('Cambridge International (O/A Level)', 'Cambridge', 28),
('Edexcel International (O/A Level)', 'Edexcel', 29);

-- ─── Academic Groups ───────────────────────────────────────────
INSERT IGNORE INTO `student_groups` (`name`, `sort_order`) VALUES
('Science', 1),
('Arts', 2),
('Commerce', 3),
('Humanities', 4),
('Madrasha', 5),
('O Level', 6),
('A Level', 7),
('Vocational', 8),
('General', 9);

-- ─── Bangladesh Districts (all 64) ───────────────────────────
INSERT IGNORE INTO `bd_districts` (`id`, `name`, `division`) VALUES
-- Dhaka Division (13)
(1,  'Dhaka',          'Dhaka'),
(2,  'Faridpur',       'Dhaka'),
(3,  'Gazipur',        'Dhaka'),
(4,  'Gopalganj',      'Dhaka'),
(5,  'Kishoreganj',    'Dhaka'),
(6,  'Madaripur',      'Dhaka'),
(7,  'Manikganj',      'Dhaka'),
(8,  'Munshiganj',     'Dhaka'),
(9,  'Narayanganj',    'Dhaka'),
(10, 'Narsingdi',      'Dhaka'),
(11, 'Rajbari',        'Dhaka'),
(12, 'Shariatpur',     'Dhaka'),
(13, 'Tangail',        'Dhaka'),
-- Chittagong Division (11)
(14, 'Bandarban',      'Chittagong'),
(15, 'Brahmanbaria',   'Chittagong'),
(16, 'Chandpur',       'Chittagong'),
(17, 'Chittagong',     'Chittagong'),
(18, "Cox's Bazar",    'Chittagong'),
(19, 'Cumilla',        'Chittagong'),
(20, 'Feni',           'Chittagong'),
(21, 'Khagrachhari',   'Chittagong'),
(22, 'Lakshmipur',     'Chittagong'),
(23, 'Noakhali',       'Chittagong'),
(24, 'Rangamati',      'Chittagong'),
-- Rajshahi Division (8)
(25, 'Bogura',         'Rajshahi'),
(26, 'Joypurhat',      'Rajshahi'),
(27, 'Naogaon',        'Rajshahi'),
(28, 'Natore',         'Rajshahi'),
(29, 'Chapainawabganj','Rajshahi'),
(30, 'Pabna',          'Rajshahi'),
(31, 'Rajshahi',       'Rajshahi'),
(32, 'Sirajganj',      'Rajshahi'),
-- Khulna Division (10)
(33, 'Bagerhat',       'Khulna'),
(34, 'Chuadanga',      'Khulna'),
(35, 'Jashore',        'Khulna'),
(36, 'Jhenaidah',      'Khulna'),
(37, 'Khulna',         'Khulna'),
(38, 'Kushtia',        'Khulna'),
(39, 'Magura',         'Khulna'),
(40, 'Meherpur',       'Khulna'),
(41, 'Narail',         'Khulna'),
(42, 'Satkhira',       'Khulna'),
-- Barisal Division (6)
(43, 'Barguna',        'Barisal'),
(44, 'Barisal',        'Barisal'),
(45, 'Bhola',          'Barisal'),
(46, 'Jhalokati',      'Barisal'),
(47, 'Patuakhali',     'Barisal'),
(48, 'Pirojpur',       'Barisal'),
-- Sylhet Division (4)
(49, 'Habiganj',       'Sylhet'),
(50, 'Moulvibazar',    'Sylhet'),
(51, 'Sunamganj',      'Sylhet'),
(52, 'Sylhet',         'Sylhet'),
-- Rangpur Division (8)
(53, 'Dinajpur',       'Rangpur'),
(54, 'Gaibandha',      'Rangpur'),
(55, 'Kurigram',       'Rangpur'),
(56, 'Lalmonirhat',    'Rangpur'),
(57, 'Nilphamari',     'Rangpur'),
(58, 'Panchagarh',     'Rangpur'),
(59, 'Rangpur',        'Rangpur'),
(60, 'Thakurgaon',     'Rangpur'),
-- Mymensingh Division (4)
(61, 'Jamalpur',       'Mymensingh'),
(62, 'Mymensingh',     'Mymensingh'),
(63, 'Netrokona',      'Mymensingh'),
(64, 'Sherpur',        'Mymensingh');

-- ─── Thanas / Upazilas ────────────────────────────────────────
-- Dhaka District (id=1)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(1, 'Dhamrai'), (1, 'Dohar'), (1, 'Keraniganj'), (1, 'Nawabganj'), (1, 'Savar'),
(1, 'Demra'), (1, 'Uttara'), (1, 'Mirpur'), (1, 'Motijheel'), (1, 'Gulshan');
-- Faridpur (id=2)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(2, 'Faridpur Sadar'), (2, 'Alfadanga'), (2, 'Boalmari'), (2, 'Char Bhadrasan'),
(2, 'Madhukali'), (2, 'Nagarkanda'), (2, 'Sadarpur'), (2, 'Saltha');
-- Gazipur (id=3)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(3, 'Gazipur Sadar'), (3, 'Kaliakair'), (3, 'Kaliganj'), (3, 'Kapasia'), (3, 'Sreepur');
-- Gopalganj (id=4)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(4, 'Gopalganj Sadar'), (4, 'Kashiani'), (4, 'Kotalipara'), (4, 'Muksudpur'), (4, 'Tungipara');
-- Kishoreganj (id=5)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(5, 'Kishoreganj Sadar'), (5, 'Austagram'), (5, 'Bajitpur'), (5, 'Bhairab'),
(5, 'Hossainpur'), (5, 'Itna'), (5, 'Karimganj'), (5, 'Katiadi'), (5, 'Kuliarchar'),
(5, 'Mithamain'), (5, 'Nikli'), (5, 'Pakundia'), (5, 'Tarail');
-- Madaripur (id=6)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(6, 'Madaripur Sadar'), (6, 'Kalkini'), (6, 'Rajoir'), (6, 'Shibchar');
-- Manikganj (id=7)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(7, 'Manikganj Sadar'), (7, 'Daulatpur'), (7, 'Ghior'), (7, 'Harirampur'),
(7, 'Saturia'), (7, 'Shivalaya'), (7, 'Singair');
-- Munshiganj (id=8)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(8, 'Munshiganj Sadar'), (8, 'Gazaria'), (8, 'Lohajang'), (8, 'Sirajdikhan'),
(8, 'Sreenagar'), (8, 'Tongibari');
-- Narayanganj (id=9)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(9, 'Narayanganj Sadar'), (9, 'Araihazar'), (9, 'Bandar'), (9, 'Rupganj'), (9, 'Sonargaon');
-- Narsingdi (id=10)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(10, 'Narsingdi Sadar'), (10, 'Belabo'), (10, 'Monohardi'), (10, 'Palash'),
(10, 'Raipura'), (10, 'Shibpur');
-- Rajbari (id=11)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(11, 'Rajbari Sadar'), (11, 'Baliakandi'), (11, 'Goalanda'), (11, 'Kalukhali'), (11, 'Pangsha');
-- Shariatpur (id=12)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(12, 'Shariatpur Sadar'), (12, 'Bhedarganj'), (12, 'Damudya'), (12, 'Gosairhat'),
(12, 'Naria'), (12, 'Zanjira');
-- Tangail (id=13)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(13, 'Tangail Sadar'), (13, 'Basail'), (13, 'Bhuapur'), (13, 'Delduar'),
(13, 'Dhanbari'), (13, 'Ghatail'), (13, 'Gopalpur'), (13, 'Kalihati'),
(13, 'Madhupur'), (13, 'Mirzapur'), (13, 'Nagarpur'), (13, 'Sakhipur');
-- Bandarban (id=14)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(14, 'Bandarban Sadar'), (14, 'Alikadam'), (14, 'Lama'), (14, 'Naikhongchhari'),
(14, 'Rowangchhari'), (14, 'Ruma'), (14, 'Thanchi');
-- Brahmanbaria (id=15)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(15, 'Brahmanbaria Sadar'), (15, 'Akhaura'), (15, 'Ashuganj'), (15, 'Bancharampur'),
(15, 'Bijoynagar'), (15, 'Kasba'), (15, 'Nabinagar'), (15, 'Nasirnagar'), (15, 'Sarail');
-- Chandpur (id=16)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(16, 'Chandpur Sadar'), (16, 'Faridganj'), (16, 'Haimchar'), (16, 'Hajiganj'),
(16, 'Kachua'), (16, 'Matlab Dakshin'), (16, 'Matlab Uttar'), (16, 'Shahrasti');
-- Chittagong (id=17)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(17, 'Chittagong Sadar'), (17, 'Anwara'), (17, 'Banshkhali'), (17, 'Boalkhali'),
(17, 'Chandanaish'), (17, 'Fatikchhari'), (17, 'Hathazari'), (17, 'Karnaphuli'),
(17, 'Lohagara'), (17, 'Mirsharai'), (17, 'Patiya'), (17, 'Rangunia'),
(17, 'Raozan'), (17, 'Sandwip'), (17, 'Satkania'), (17, 'Sitakunda');
-- Cox's Bazar (id=18)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(18, "Cox's Bazar Sadar"), (18, 'Chakaria'), (18, 'Kutubdia'), (18, 'Maheshkhali'),
(18, 'Pekua'), (18, 'Ramu'), (18, 'Teknaf'), (18, 'Ukhia');
-- Cumilla (id=19)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(19, 'Cumilla Sadar'), (19, 'Barura'), (19, 'Brahmanpara'), (19, 'Burichang'),
(19, 'Chandina'), (19, 'Chauddagram'), (19, 'Daudkandi'), (19, 'Debidwar'),
(19, 'Homna'), (19, 'Laksam'), (19, 'Lalmai'), (19, 'Meghna'),
(19, 'Monohorgonj'), (19, 'Muradnagar'), (19, 'Nangalkot'), (19, 'Titas');
-- Feni (id=20)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(20, 'Feni Sadar'), (20, 'Chhagalnaiya'), (20, 'Daganbhuiyan'), (20, 'Parshuram'),
(20, 'Sonagazi'), (20, 'Fulgazi');
-- Khagrachhari (id=21)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(21, 'Khagrachhari Sadar'), (21, 'Dighinala'), (21, 'Lakshmichhari'), (21, 'Mahalchhari'),
(21, 'Manikchhari'), (21, 'Matiranga'), (21, 'Panchhari'), (21, 'Ramgarh');
-- Lakshmipur (id=22)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(22, 'Lakshmipur Sadar'), (22, 'Kamalnagar'), (22, 'Raipur'), (22, 'Ramganj'), (22, 'Ramgati');
-- Noakhali (id=23)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(23, 'Noakhali Sadar'), (23, 'Begumganj'), (23, 'Chatkhil'), (23, 'Companiganj'),
(23, 'Hatiya'), (23, 'Kabirchat'), (23, 'Senbagh'), (23, 'Sonaimuri'), (23, 'Subarnachar');
-- Rangamati (id=24)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(24, 'Rangamati Sadar'), (24, 'Baghaichhari'), (24, 'Barkal'), (24, 'Belaichhari'),
(24, 'Juraichhari'), (24, 'Kaptai'), (24, 'Kaukhali'), (24, 'Langadu'),
(24, 'Naniarchar'), (24, 'Rajasthali');
-- Bogura (id=25)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(25, 'Bogura Sadar'), (25, 'Adamdighi'), (25, 'Dhunat'), (25, 'Dhupchanchia'),
(25, 'Gabtali'), (25, 'Kahaloo'), (25, 'Nandigram'), (25, 'Sariakandi'),
(25, 'Shajahanpur'), (25, 'Sherpur'), (25, 'Shibganj'), (25, 'Sonatala');
-- Joypurhat (id=26)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(26, 'Joypurhat Sadar'), (26, 'Akkelpur'), (26, 'Kalai'), (26, 'Khetlal'), (26, 'Panchbibi');
-- Naogaon (id=27)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(27, 'Naogaon Sadar'), (27, 'Atrai'), (27, 'Badalgachhi'), (27, 'Dhamoirhat'),
(27, 'Mahadebpur'), (27, 'Manda'), (27, 'Niamatpur'), (27, 'Patnitala'),
(27, 'Porsha'), (27, 'Raninagar'), (27, 'Sapahar');
-- Natore (id=28)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(28, 'Natore Sadar'), (28, 'Bagatipara'), (28, 'Baraigram'), (28, 'Gurudaspur'),
(28, 'Lalpur'), (28, 'Singra');
-- Chapainawabganj (id=29)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(29, 'Chapainawabganj Sadar'), (29, 'Bholahat'), (29, 'Gomastapur'), (29, 'Nachole'), (29, 'Shibganj');
-- Pabna (id=30)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(30, 'Pabna Sadar'), (30, 'Atgharia'), (30, 'Bera'), (30, 'Bhangura'),
(30, 'Chatmohar'), (30, 'Faridpur'), (30, 'Ishwardi'), (30, 'Santhia'), (30, 'Sujanagar');
-- Rajshahi (id=31)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(31, 'Rajshahi Sadar'), (31, 'Bagha'), (31, 'Bagmara'), (31, 'Charghat'),
(31, 'Durgapur'), (31, 'Godagari'), (31, 'Mohanpur'), (31, 'Paba'), (31, 'Puthia'), (31, 'Tanore');
-- Sirajganj (id=32)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(32, 'Sirajganj Sadar'), (32, 'Belkuchi'), (32, 'Chauhali'), (32, 'Kamarkhanda'),
(32, 'Kazipur'), (32, 'Raiganj'), (32, 'Shahjadpur'), (32, 'Tarash'), (32, 'Ullahpara');
-- Bagerhat (id=33)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(33, 'Bagerhat Sadar'), (33, 'Chitalmari'), (33, 'Fakirhat'), (33, 'Kachua'),
(33, 'Mollahat'), (33, 'Mongla'), (33, 'Morrelganj'), (33, 'Rampal'), (33, 'Sarankhola');
-- Chuadanga (id=34)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(34, 'Chuadanga Sadar'), (34, 'Alamdanga'), (34, 'Damurhuda'), (34, 'Jibannagar');
-- Jashore (id=35)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(35, 'Jashore Sadar'), (35, 'Abhaynagar'), (35, 'Bagherpara'), (35, 'Chaugachha'),
(35, 'Jhikargacha'), (35, 'Keshabpur'), (35, 'Manirampur'), (35, 'Sharsha');
-- Jhenaidah (id=36)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(36, 'Jhenaidah Sadar'), (36, 'Harinakunda'), (36, 'Kaliganj'), (36, 'Kotchandpur'),
(36, 'Maheshpur'), (36, 'Shailkupa');
-- Khulna (id=37)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(37, 'Khulna Sadar'), (37, 'Batiaghata'), (37, 'Dacope'), (37, 'Dumuria'),
(37, 'Dighalia'), (37, 'Koyra'), (37, 'Paikgachha'), (37, 'Phultala'), (37, 'Rupsa'), (37, 'Terokhada');
-- Kushtia (id=38)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(38, 'Kushtia Sadar'), (38, 'Bheramara'), (38, 'Daulatpur'), (38, 'Khoksa'),
(38, 'Kumarkhali'), (38, 'Mirpur');
-- Magura (id=39)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(39, 'Magura Sadar'), (39, 'Mohammadpur'), (39, 'Shalikha'), (39, 'Sreepur');
-- Meherpur (id=40)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(40, 'Meherpur Sadar'), (40, 'Gangni'), (40, 'Mujibnagar');
-- Narail (id=41)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(41, 'Narail Sadar'), (41, 'Kalia'), (41, 'Lohagara');
-- Satkhira (id=42)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(42, 'Satkhira Sadar'), (42, 'Assasuni'), (42, 'Debhata'), (42, 'Kalaroa'),
(42, 'Kaliganj'), (42, 'Shyamnagar'), (42, 'Tala');
-- Barguna (id=43)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(43, 'Barguna Sadar'), (43, 'Amtali'), (43, 'Bamna'), (43, 'Betagi'), (43, 'Pathorghata'), (43, 'Taltali');
-- Barisal (id=44)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(44, 'Barisal Sadar'), (44, 'Agailjhara'), (44, 'Babuganj'), (44, 'Bakerganj'),
(44, 'Banaripara'), (44, 'Gaurnadi'), (44, 'Hizla'), (44, 'Mehendiganj'),
(44, 'Muladi'), (44, 'Wazirpur');
-- Bhola (id=45)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(45, 'Bhola Sadar'), (45, 'Borhanuddin'), (45, 'Char Fasson'), (45, 'Daulatkhan'),
(45, 'Lalmohan'), (45, 'Manpura'), (45, 'Tazumuddin');
-- Jhalokati (id=46)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(46, 'Jhalokati Sadar'), (46, 'Kanthalia'), (46, 'Nalchity'), (46, 'Rajapur');
-- Patuakhali (id=47)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(47, 'Patuakhali Sadar'), (47, 'Bauphal'), (47, 'Dashmina'), (47, 'Dumki'),
(47, 'Galachipa'), (47, 'Kalapara'), (47, 'Mirzaganj'), (47, 'Rangabali');
-- Pirojpur (id=48)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(48, 'Pirojpur Sadar'), (48, 'Bhandaria'), (48, 'Kawkhali'), (48, 'Mathbaria'),
(48, 'Nazirpur'), (48, 'Nesarabad'), (48, 'Zianagar');
-- Habiganj (id=49)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(49, 'Habiganj Sadar'), (49, 'Ajmiriganj'), (49, 'Bahubal'), (49, 'Baniachong'),
(49, 'Chunarughat'), (49, 'Lakhai'), (49, 'Madhabpur'), (49, 'Nabiganj'), (49, 'Shayestaganj');
-- Moulvibazar (id=50)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(50, 'Moulvibazar Sadar'), (50, 'Barlekha'), (50, 'Juri'), (50, 'Kamalganj'),
(50, 'Kulaura'), (50, 'Rajnagar'), (50, 'Sreemangal');
-- Sunamganj (id=51)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(51, 'Sunamganj Sadar'), (51, 'Bishwamvarpur'), (51, 'Chhatak'), (51, 'Derai'),
(51, 'Dharampasha'), (51, 'Dowarabazar'), (51, 'Jagannathpur'), (51, 'Jamalganj'),
(51, 'Shalla'), (51, 'South Sunamganj'), (51, 'Tahirpur');
-- Sylhet (id=52)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(52, 'Sylhet Sadar'), (52, 'Balaganj'), (52, 'Beanibazar'), (52, 'Bishwanath'),
(52, 'Companiganj'), (52, 'Fenchuganj'), (52, 'Golapganj'), (52, 'Gowainghat'),
(52, 'Jaintiapur'), (52, 'Kanaighat'), (52, 'Osmaninagar'), (52, 'South Surma'), (52, 'Zakiganj');
-- Dinajpur (id=53)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(53, 'Dinajpur Sadar'), (53, 'Birampur'), (53, 'Birganj'), (53, 'Biral'),
(53, 'Bochaganj'), (53, 'Chirirbandar'), (53, 'Fulbari'), (53, 'Ghoraghat'),
(53, 'Hakimpur'), (53, 'Kaharole'), (53, 'Khansama'), (53, 'Nawabganj'), (53, 'Parbatipur');
-- Gaibandha (id=54)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(54, 'Gaibandha Sadar'), (54, 'Fulchhari'), (54, 'Gobindaganj'), (54, 'Palashbari'),
(54, 'Sadullapur'), (54, 'Sughatta'), (54, 'Sundarganj');
-- Kurigram (id=55)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(55, 'Kurigram Sadar'), (55, 'Bhurungamari'), (55, 'Char Rajibpur'), (55, 'Chilmari'),
(55, 'Nageshwari'), (55, 'Phulbari'), (55, 'Rajarhat'), (55, 'Raumari'), (55, 'Ulipur');
-- Lalmonirhat (id=56)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(56, 'Lalmonirhat Sadar'), (56, 'Aditmari'), (56, 'Hatibandha'), (56, 'Kaliganj'), (56, 'Patgram');
-- Nilphamari (id=57)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(57, 'Nilphamari Sadar'), (57, 'Dimla'), (57, 'Domar'), (57, 'Jaldhaka'), (57, 'Kishoreganj'), (57, 'Saidpur');
-- Panchagarh (id=58)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(58, 'Panchagarh Sadar'), (58, 'Atwari'), (58, 'Boda'), (58, 'Debiganj'), (58, 'Tetulia');
-- Rangpur (id=59)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(59, 'Rangpur Sadar'), (59, 'Badarganj'), (59, 'Gangachhara'), (59, 'Kaunia'),
(59, 'Mithapukur'), (59, 'Pirgachha'), (59, 'Pirganj'), (59, 'Taraganj');
-- Thakurgaon (id=60)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(60, 'Thakurgaon Sadar'), (60, 'Baliadangi'), (60, 'Haripur'), (60, 'Pirganj'), (60, 'Ranisankail');
-- Jamalpur (id=61)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(61, 'Jamalpur Sadar'), (61, 'Baksiganj'), (61, 'Dewanganj'), (61, 'Islampur'),
(61, 'Madarganj'), (61, 'Melandaha'), (61, 'Sarishabari');
-- Mymensingh (id=62)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(62, 'Mymensingh Sadar'), (62, 'Bhaluka'), (62, 'Dhobaura'), (62, 'Fulbaria'),
(62, 'Gaffargaon'), (62, 'Gauripur'), (62, 'Haluaghat'), (62, 'Ishwarganj'),
(62, 'Muktagachha'), (62, 'Nandail'), (62, 'Phulpur'), (62, 'Trishal');
-- Netrokona (id=63)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(63, 'Netrokona Sadar'), (63, 'Atpara'), (63, 'Barhatta'), (63, 'Durgapur'),
(63, 'Kalmakanda'), (63, 'Kendua'), (63, 'Khaliajuri'), (63, 'Madan'), (63, 'Mohanganj'), (63, 'Purbadhala');
-- Sherpur (id=64)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(64, 'Sherpur Sadar'), (64, 'Jhenaigati'), (64, 'Nakla'), (64, 'Nalitabari'), (64, 'Sribordi');

SET FOREIGN_KEY_CHECKS = 1;
