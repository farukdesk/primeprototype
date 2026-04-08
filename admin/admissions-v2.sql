-- ============================================================
-- Admissions Module v2 – Extended Field Mappings & Settings
-- Run this file after admissions.sql (existing installations only)
-- ============================================================

-- ── Settings store ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `admissions_settings` (
    `setting_key`   VARCHAR(100) NOT NULL,
    `setting_value` TEXT NOT NULL DEFAULT '',
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `admissions_settings` (`setting_key`, `setting_value`) VALUES
('next_form_number', '1');

-- ── New field seeds ───────────────────────────────────────────────────────────

-- Photo field
INSERT IGNORE INTO `admissions_fields` (`field_key`, `field_label`, `sort_order`) VALUES
('photo', 'Photo', 50);

-- Semester tick fields (one per semester value)
INSERT IGNORE INTO `admissions_fields` (`field_key`, `field_label`, `sort_order`) VALUES
('semester_spring', 'Semester: Spring (✓)', 51),
('semester_summer', 'Semester: Summer (✓)', 52),
('semester_fall',   'Semester: Fall (✓)',   53);

-- Sex tick fields
INSERT IGNORE INTO `admissions_fields` (`field_key`, `field_label`, `sort_order`) VALUES
('sex_male',   'Sex: Male (✓)',   54),
('sex_female', 'Sex: Female (✓)', 55);

-- Expelled tick field
INSERT IGNORE INTO `admissions_fields` (`field_key`, `field_label`, `sort_order`) VALUES
('expelled_yes', 'Expelled: Yes (✓)', 56),
('expelled_no',  'Expelled: No (✓)',  57);

-- Current date field
INSERT IGNORE INTO `admissions_fields` (`field_key`, `field_label`, `sort_order`) VALUES
('current_date', 'Current Date', 58);

-- Academic qualification rows (5 rows × 7 columns each)
INSERT IGNORE INTO `admissions_fields` (`field_key`, `field_label`, `sort_order`) VALUES
('qual_1_exam_name',        'Qualification 1: Exam Name',        60),
('qual_1_session',          'Qualification 1: Session',          61),
('qual_1_group',            'Qualification 1: Group',            62),
('qual_1_board',            'Qualification 1: Board/University', 63),
('qual_1_year',             'Qualification 1: Year',             64),
('qual_1_grade',            'Qualification 1: Division/Grade',   65),
('qual_1_marks',            'Qualification 1: Marks/CGPA',       66),

('qual_2_exam_name',        'Qualification 2: Exam Name',        70),
('qual_2_session',          'Qualification 2: Session',          71),
('qual_2_group',            'Qualification 2: Group',            72),
('qual_2_board',            'Qualification 2: Board/University', 73),
('qual_2_year',             'Qualification 2: Year',             74),
('qual_2_grade',            'Qualification 2: Division/Grade',   75),
('qual_2_marks',            'Qualification 2: Marks/CGPA',       76),

('qual_3_exam_name',        'Qualification 3: Exam Name',        80),
('qual_3_session',          'Qualification 3: Session',          81),
('qual_3_group',            'Qualification 3: Group',            82),
('qual_3_board',            'Qualification 3: Board/University', 83),
('qual_3_year',             'Qualification 3: Year',             84),
('qual_3_grade',            'Qualification 3: Division/Grade',   85),
('qual_3_marks',            'Qualification 3: Marks/CGPA',       86),

('qual_4_exam_name',        'Qualification 4: Exam Name',        90),
('qual_4_session',          'Qualification 4: Session',          91),
('qual_4_group',            'Qualification 4: Group',            92),
('qual_4_board',            'Qualification 4: Board/University', 93),
('qual_4_year',             'Qualification 4: Year',             94),
('qual_4_grade',            'Qualification 4: Division/Grade',   95),
('qual_4_marks',            'Qualification 4: Marks/CGPA',       96),

('qual_5_exam_name',        'Qualification 5: Exam Name',        100),
('qual_5_session',          'Qualification 5: Session',          101),
('qual_5_group',            'Qualification 5: Group',            102),
('qual_5_board',            'Qualification 5: Board/University', 103),
('qual_5_year',             'Qualification 5: Year',             104),
('qual_5_grade',            'Qualification 5: Division/Grade',   105),
('qual_5_marks',            'Qualification 5: Marks/CGPA',       106);
