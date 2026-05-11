-- ============================================================
-- Admissions Module – Database Schema
-- ============================================================

-- ── Templates (up to 2 pages) ─────────────────────────────
CREATE TABLE IF NOT EXISTS `admissions_templates` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `page_number`   TINYINT UNSIGNED NOT NULL,
    `original_name` VARCHAR(255) NOT NULL,
    `stored_file`   VARCHAR(255) NOT NULL,
    `file_type`     ENUM('pdf','image') NOT NULL DEFAULT 'image',
    `width`         INT NOT NULL DEFAULT 794,
    `height`        INT NOT NULL DEFAULT 1123,
    `uploaded_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `uploaded_by`   INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_page_number` (`page_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── System field definitions ──────────────────────────────
CREATE TABLE IF NOT EXISTS `admissions_fields` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `field_key`   VARCHAR(100) NOT NULL,
    `field_label` VARCHAR(255) NOT NULL,
    `sort_order`  SMALLINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_field_key` (`field_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Field → position mappings ─────────────────────────────
CREATE TABLE IF NOT EXISTS `admissions_field_mappings` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `field_key`   VARCHAR(100) NOT NULL,
    `page_number` TINYINT NOT NULL,
    `x_percent`   DECIMAL(6,3) NOT NULL DEFAULT 0,
    `y_percent`   DECIMAL(6,3) NOT NULL DEFAULT 0,
    `font_size`   TINYINT NOT NULL DEFAULT 10,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_field_page` (`field_key`, `page_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Main application record ───────────────────────────────
CREATE TABLE IF NOT EXISTS `admissions_applications` (
    `id`                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `app_number`             VARCHAR(30) NOT NULL,
    `status`                 ENUM('draft','submitted','approved','rejected') NOT NULL DEFAULT 'draft',
    `dept_id`                INT UNSIGNED NULL,
    `program_id`             INT UNSIGNED NULL,
    `year`                   YEAR NULL,
    `semester`               VARCHAR(50) NULL,
    `student_name`           VARCHAR(255) NOT NULL,
    `father_name`            VARCHAR(255) NULL,
    `mother_name`            VARCHAR(255) NULL,
    `present_address_1`      VARCHAR(255) NULL,
    `present_address_2`      VARCHAR(255) NULL,
    `present_contact`        VARCHAR(50) NULL,
    `present_email`          VARCHAR(255) NULL,
    `permanent_address_1`    VARCHAR(255) NULL,
    `permanent_address_2`    VARCHAR(255) NULL,
    `permanent_contact`      VARCHAR(50) NULL,
    `permanent_email`        VARCHAR(255) NULL,
    `nationality`            VARCHAR(100) NULL,
    `date_of_birth`          DATE NULL,
    `place_of_birth`         VARCHAR(255) NULL,
    `religion`               VARCHAR(100) NULL,
    `nid_birth_cert`         VARCHAR(100) NULL,
    `blood_group`            VARCHAR(10) NULL,
    `sex`                    ENUM('Male','Female','Other') NULL,
    `photo`                  VARCHAR(255) NULL,
    `experience`             TEXT NULL,
    `guardian_name`          VARCHAR(255) NULL,
    `guardian_profession`    VARCHAR(255) NULL,
    `guardian_address_1`     VARCHAR(255) NULL,
    `guardian_address_2`     VARCHAR(255) NULL,
    `guardian_phone`         VARCHAR(50) NULL,
    `guardian_email`         VARCHAR(255) NULL,
    `guardian_relationship`  VARCHAR(100) NULL,
    `guardian_monthly_income` VARCHAR(100) NULL,
    `local_guardian_name`    VARCHAR(255) NULL,
    `local_guardian_address_1` VARCHAR(255) NULL,
    `local_guardian_address_2` VARCHAR(255) NULL,
    `local_guardian_address_3` VARCHAR(255) NULL,
    `local_guardian_contact` VARCHAR(50) NULL,
    `reference_name`         VARCHAR(255) NULL,
    `reference_address_1`    VARCHAR(255) NULL,
    `reference_address_2`    VARCHAR(255) NULL,
    `reference_address_3`    VARCHAR(255) NULL,
    `reference_contact`      VARCHAR(50) NULL,
    `expelled_answer`        ENUM('No','Yes') NOT NULL DEFAULT 'No',
    `expelled_detail`        VARCHAR(255) NULL,
    `office_program`         VARCHAR(255) NULL,
    `office_student_id`      VARCHAR(100) NULL,
    `office_batch_no`        VARCHAR(100) NULL,
    `office_decision`        VARCHAR(255) NULL,
    `office_checked_by`      VARCHAR(255) NULL,
    `financial_package_id`   INT UNSIGNED NULL,
    `financial_package_name` VARCHAR(255) NULL,
    `financial_total_semesters` SMALLINT UNSIGNED NULL,
    `financial_total_months` SMALLINT UNSIGNED NULL,
    `financial_tuition_per_semester` DECIMAL(12,2) NULL,
    `financial_admission_fee` DECIMAL(12,2) NULL,
    `financial_registration_fee_per_semester` DECIMAL(12,2) NULL,
    `financial_fixed_institutional_fees` DECIMAL(12,2) NULL,
    `financial_english_course_fee` DECIMAL(12,2) NULL,
    `financial_form_id_fee` DECIMAL(12,2) NULL,
    `created_by`             INT UNSIGNED NOT NULL,
    `created_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_app_number` (`app_number`),
    KEY `idx_financial_package` (`financial_package_id`),
    CONSTRAINT `fk_adm_financial_package`
        FOREIGN KEY (`financial_package_id`) REFERENCES `cf_programs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Repeating academic qualification rows ─────────────────
CREATE TABLE IF NOT EXISTS `admissions_academic_records` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `application_id`   INT UNSIGNED NOT NULL,
    `exam_name`        VARCHAR(255) NULL,
    `session`          VARCHAR(50) NULL,
    `group_name`       VARCHAR(100) NULL,
    `board_university` VARCHAR(255) NULL,
    `year_of_passing`  VARCHAR(10) NULL,
    `division_grade`   VARCHAR(100) NULL,
    `total_marks_cgpa` VARCHAR(100) NULL,
    `sort_order`       SMALLINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_adm_app_id` FOREIGN KEY (`application_id`)
        REFERENCES `admissions_applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Module registration ───────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`, `is_active`)
VALUES ('Admissions', 'admissions', 'Manage student admission applications and print forms', 'fas fa-user-plus', 50, 1);

-- ── Seed field definitions ────────────────────────────────
INSERT IGNORE INTO `admissions_fields` (`field_key`, `field_label`, `sort_order`) VALUES
('app_number',              'Application Number',              1),
('department',              'Department',                       2),
('program',                 'Program',                          3),
('year',                    'Year',                             4),
('semester',                'Semester',                         5),
('student_name',            'Student Name',                     6),
('father_name',             'Father''s Name',                   7),
('mother_name',             'Mother''s Name',                   8),
('present_address_1',       'Present Address Line 1',           9),
('present_address_2',       'Present Address Line 2',          10),
('present_contact',         'Present Contact No',              11),
('present_email',           'Present Email',                   12),
('permanent_address_1',     'Permanent Address Line 1',        13),
('permanent_address_2',     'Permanent Address Line 2',        14),
('permanent_contact',       'Permanent Contact No',            15),
('permanent_email',         'Permanent Email',                 16),
('nationality',             'Nationality',                     17),
('date_of_birth',           'Date of Birth',                   18),
('place_of_birth',          'Place of Birth',                  19),
('religion',                'Religion',                        20),
('nid_birth_cert',          'NID/Birth Certificate No',        21),
('blood_group',             'Blood Group',                     22),
('sex',                     'Sex',                             23),
('experience',              'Experience',                      24),
('guardian_name',           'Guardian Name',                   25),
('guardian_profession',     'Guardian Profession',             26),
('guardian_address_1',      'Guardian Address Line 1',         27),
('guardian_address_2',      'Guardian Address Line 2',         28),
('guardian_phone',          'Guardian Phone',                  29),
('guardian_email',          'Guardian Email',                  30),
('guardian_relationship',   'Guardian Relationship',           31),
('guardian_monthly_income', 'Guardian Monthly Average Income', 32),
('local_guardian_name',     'Local Guardian Name',             33),
('local_guardian_address_1','Local Guardian Address 1',        34),
('local_guardian_address_2','Local Guardian Address 2',        35),
('local_guardian_address_3','Local Guardian Address 3',        36),
('local_guardian_contact',  'Local Guardian Contact',          37),
('reference_name',          'Reference Name',                  38),
('reference_address_1',     'Reference Address 1',             39),
('reference_address_2',     'Reference Address 2',             40),
('reference_address_3',     'Reference Address 3',             41),
('reference_contact',       'Reference Contact',               42),
('expelled_answer',         'Expelled Answer (Yes/No)',        43),
('expelled_detail',         'Expelled Detail',                 44),
('office_program',          'Office: Program',                 45),
('office_student_id',       'Office: Student ID No',           46),
('office_batch_no',         'Office: Batch No',                47),
('office_decision',         'Office: Decision',                48),
('office_checked_by',       'Office: Checked By',              49);
