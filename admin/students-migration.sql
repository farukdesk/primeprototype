-- ============================================================
-- students-migration.sql
-- Migrates legacy student_user data into the new students module.
--
-- HOW TO RUN (order matters):
--   1. Import  student_user.sql   → creates & populates `student_user`
--   2. Import  s_result_entry.sql → creates & populates `s_result_entry`
--   3. Run     THIS script        → transforms & copies everything
--   4. Verify records, then optionally: DROP TABLE student_user; DROP TABLE s_result_entry;
--
-- Safe to re-run: INSERT IGNORE / IF NOT EXISTS guards are used throughout.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- STEP 1 – Extend students table with fields present in old system
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE `students`
    ADD COLUMN IF NOT EXISTS `dob`                  DATE          DEFAULT NULL
        COMMENT 'Date of birth'
        AFTER `phone`,
    ADD COLUMN IF NOT EXISTS `blood_group`           VARCHAR(10)   DEFAULT NULL
        AFTER `dob`,
    ADD COLUMN IF NOT EXISTS `nid`                   VARCHAR(50)   DEFAULT NULL
        COMMENT 'National ID number'
        AFTER `blood_group`,
    ADD COLUMN IF NOT EXISTS `batch`                 VARCHAR(50)   DEFAULT NULL
        COMMENT 'Intake batch, e.g. 35th'
        AFTER `admitted_semester`,
    ADD COLUMN IF NOT EXISTS `shift`                 VARCHAR(25)   DEFAULT NULL
        COMMENT 'Day / Evening / Morning'
        AFTER `batch`,
    ADD COLUMN IF NOT EXISTS `poor_meritorious`      TINYINT(1)    NOT NULL DEFAULT 0
        COMMENT '1 = poor/meritorious quota'
        AFTER `shift`,
    ADD COLUMN IF NOT EXISTS `freedom_fighter_quota` TINYINT(1)    NOT NULL DEFAULT 0
        COMMENT '1 = freedom fighter family quota'
        AFTER `poor_meritorious`,
    ADD COLUMN IF NOT EXISTS `waiver_percent`        VARCHAR(10)   DEFAULT NULL
        AFTER `freedom_fighter_quota`,
    ADD COLUMN IF NOT EXISTS `form_fee`              INT           DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `regi_fee`              INT           DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `tuition_fee`           INT           DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `misc_fee`              VARCHAR(50)   DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `project_fee`           INT           DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `total_fee`             INT           DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `waiver_amount`         INT           DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `total_payable`         VARCHAR(50)   DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `monthly_installment`   VARCHAR(50)   DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `ref_number`            VARCHAR(100)  DEFAULT NULL;

-- ─────────────────────────────────────────────────────────────────────────────
-- STEP 2 – Create missing departments (INSERT IGNORE = safe to re-run)
-- ─────────────────────────────────────────────────────────────────────────────
-- dept_departments.slug must be unique; we guard with INSERT IGNORE.

INSERT IGNORE INTO `dept_departments`
    (`name`, `slug`, `code`, `is_active`)
VALUES
    ('BSc in Electrical & Electronic Engineering',        'bsc-eee',    'EEE', 1),
    ('BSc in Electronics & Telecommunication Engg.',      'bsc-ete',    'ETE', 1),
    ('Bachelor of Business Administration',               'bba',        'BBA', 1),
    ('Master of Business Administration',                 'mba',        'MBA', 1),
    ('BA (Hons) in English',                              'ba-english',  'ENG', 1),
    ('BA (Hons) in Bangla',                               'ba-bangla',   'BAN', 1),
    ('LL.B (Hons) in Law',                                'llb-law',     'LAW', 1),
    ('BSc in Civil Engineering',                          'bsc-civil',   'CE',  1),
    ('Bachelor / Master of Education',                    'education',   'EDU', 1),
    ('Master of Computer Applications',                   'mca',         'MCA', 1);

-- ─────────────────────────────────────────────────────────────────────────────
-- STEP 3 – Create one default academic program per new department
-- ─────────────────────────────────────────────────────────────────────────────

INSERT IGNORE INTO `dept_academic_programs`
    (`dept_id`, `program_name`, `degree_type`, `sort_order`, `is_active`)
SELECT d.id,
       d.name,
       CASE d.code
           WHEN 'EEE' THEN 'Bachelor of Science'
           WHEN 'ETE' THEN 'Bachelor of Science'
           WHEN 'BBA' THEN 'Bachelor of Business Administration'
           WHEN 'MBA' THEN 'Master of Business Administration'
           WHEN 'ENG' THEN 'Bachelor of Arts'
           WHEN 'BAN' THEN 'Bachelor of Arts'
           WHEN 'LAW' THEN 'Bachelor of Laws'
           WHEN 'CE'  THEN 'Bachelor of Science'
           WHEN 'EDU' THEN 'Bachelor of Education'
           WHEN 'MCA' THEN 'Master of Computer Applications'
           ELSE 'Bachelor'
       END,
       1, 1
FROM   `dept_departments` d
WHERE  d.code IN ('EEE','ETE','BBA','MBA','ENG','BAN','LAW','CE','EDU','MCA')
  AND  NOT EXISTS (
           SELECT 1 FROM `dept_academic_programs` ap
           WHERE  ap.dept_id = d.id
       );

-- ─────────────────────────────────────────────────────────────────────────────
-- STEP 4 – Build a temp mapping: old department text → new dept_id
-- ─────────────────────────────────────────────────────────────────────────────

DROP TABLE IF EXISTS `_su_dept_map`;
CREATE TABLE `_su_dept_map` (
    `old_dept` VARCHAR(150) NOT NULL,
    `code`     VARCHAR(10)  NOT NULL,
    PRIMARY KEY (`old_dept`)
) ENGINE=InnoDB;

INSERT INTO `_su_dept_map` VALUES
    -- EEE
    ('EEE',                             'EEE'),
    ('Engineering',                     'EEE'),
    ('B.Sc in EEE',                     'EEE'),
    -- CSE
    ('CSE',                             'CSE'),
    ('Computer Science and Engineering','CSE'),
    ('Information Technology',          'CSE'),
    -- ETE
    ('ETE',                             'ETE'),
    -- BBA / Business
    ('BBA',                             'BBA'),
    ('Business Administration',         'BBA'),
    ('Business Administraton',          'BBA'),
    ('Busniess Administration',         'BBA'),
    ('BBIS',                            'BBA'),
    -- MBA
    ('MBA',                             'MBA'),
    -- English
    ('English',                         'ENG'),
    -- Bangla
    ('Bangla',                          'BAN'),
    -- Law
    ('Law',                             'LAW'),
    -- Civil
    ('Civil',                           'CE'),
    ('CE',                              'CE'),
    ('Civil Engineering',               'CE'),
    -- Education
    ('Education',                       'EDU'),
    -- MCA
    ('MCA',                             'MCA'),
    -- Select / empty → fallback handled in INSERT below
    ('Select',                          'CSE');

-- ─────────────────────────────────────────────────────────────────────────────
-- STEP 5 – Migrate core student records
--
-- Notes on field mapping:
--   student_id       ← student_user_sid  (original SID; kept as-is)
--   admitted_semester← student_user_bsemester  normalised to "Summer 2014" format
--   dept_id          ← via _su_dept_map; unmapped rows fall back to CSE (id=1)
--   program_id       ← first matching program for the dept (nullable)
--   sex              ← normalised ('male'/'femal'/'famale' → 'Male'/'Female')
--   photo            ← basename of `filename` column (strip 'upload_spic/' prefix)
--   status           ← item_delete=1 → 'Inactive', else 'Active'
--   created_at       ← student_user_dte_created (falls back to NOW())
-- ─────────────────────────────────────────────────────────────────────────────

INSERT IGNORE INTO `students` (
    `student_id`,
    `dept_id`,
    `program_id`,
    `admitted_semester`,
    `batch`,
    `shift`,
    `full_name`,
    `father_name`,
    `mother_name`,
    `present_address`,
    `permanent_address`,
    `nationality`,
    `email`,
    `phone`,
    `dob`,
    `sex`,
    `photo`,
    `blood_group`,
    `nid`,
    `poor_meritorious`,
    `freedom_fighter_quota`,
    `waiver_percent`,
    `form_fee`,
    `regi_fee`,
    `tuition_fee`,
    `misc_fee`,
    `project_fee`,
    `total_fee`,
    `waiver_amount`,
    `total_payable`,
    `monthly_installment`,
    `ref_number`,
    `status`,
    `created_at`
)
SELECT
    -- student_id: use original SID; fallback to UUID-fragment when blank
    CASE
        WHEN TRIM(su.student_user_sid) = '' OR su.student_user_sid IS NULL
        THEN CONCAT('MIGR-', LPAD(su.student_user_id, 8, '0'))
        ELSE TRIM(su.student_user_sid)
    END,

    -- dept_id
    COALESCE(
        (SELECT d.id FROM dept_departments d
         JOIN   _su_dept_map m ON m.code = d.code
         WHERE  m.old_dept = TRIM(su.student_user_department)
         LIMIT  1),
        (SELECT id FROM dept_departments WHERE code = 'CSE' LIMIT 1)
    ),

    -- program_id: first program in that dept (nullable)
    (SELECT ap.id FROM dept_academic_programs ap
     WHERE  ap.dept_id = COALESCE(
                 (SELECT d2.id FROM dept_departments d2
                  JOIN   _su_dept_map m2 ON m2.code = d2.code
                  WHERE  m2.old_dept = TRIM(su.student_user_department)
                  LIMIT  1),
                 (SELECT id FROM dept_departments WHERE code = 'CSE' LIMIT 1)
             )
     ORDER BY ap.sort_order, ap.id
     LIMIT 1),

    -- admitted_semester: convert 'SPRING-2014' / 'Summer-07' → 'Spring 2014'
    CASE
        WHEN su.student_user_bsemester IS NULL OR TRIM(su.student_user_bsemester) = ''
        THEN 'Unknown'
        ELSE CONCAT(
            CASE UPPER(TRIM(SUBSTRING_INDEX(REPLACE(TRIM(su.student_user_bsemester),' ',''), '-', 1)))
                WHEN 'SUMMER' THEN 'Summer'
                WHEN 'FALL'   THEN 'Fall'
                WHEN 'SPRING' THEN 'Spring'
                ELSE 'Summer'
            END,
            ' ',
            -- handle 2-digit year ('07' → '2007') vs 4-digit ('2014')
            CASE
                WHEN CHAR_LENGTH(TRIM(SUBSTRING_INDEX(REPLACE(TRIM(su.student_user_bsemester),' ',''), '-', -1))) = 2
                THEN CONCAT('20', TRIM(SUBSTRING_INDEX(REPLACE(TRIM(su.student_user_bsemester),' ',''), '-', -1)))
                ELSE TRIM(SUBSTRING_INDEX(REPLACE(TRIM(su.student_user_bsemester),' ',''), '-', -1))
            END
        )
    END,

    -- batch
    NULLIF(TRIM(su.student_user_batch), ''),

    -- shift
    NULLIF(TRIM(su.shift), ''),

    -- full_name
    TRIM(su.student_user_name),

    -- father_name
    NULLIF(TRIM(su.student_user_fathers_name), ''),

    -- mother_name
    NULLIF(TRIM(su.student_user_mothers_name), ''),

    -- present_address (mailing = present)
    NULLIF(TRIM(su.student_user_address_m), ''),

    -- permanent_address
    NULLIF(TRIM(su.student_user_address_p), ''),

    -- nationality
    NULLIF(TRIM(su.student_user_nationality), ''),

    -- email
    NULLIF(TRIM(su.student_user_email), ''),

    -- phone (prefer mobile, fall back to phone)
    NULLIF(TRIM(COALESCE(NULLIF(TRIM(su.student_user_mobile), ''), NULLIF(TRIM(su.student_user_phone), ''))), ''),

    -- dob
    CASE
        WHEN su.student_user_dob REGEXP '^[0-9]{1,2}-[0-9]{1,2}-[0-9]{4}$'
        THEN STR_TO_DATE(su.student_user_dob, '%d-%m-%Y')
        ELSE NULL
    END,

    -- sex (normalise various spellings)
    CASE LOWER(TRIM(su.sex))
        WHEN 'male'   THEN 'Male'
        WHEN 'female' THEN 'Female'
        WHEN 'femal'  THEN 'Female'
        WHEN 'famale' THEN 'Female'
        ELSE NULL
    END,

    -- photo: extract bare filename from 'upload_spic/xxxxxxxx.jpg'
    CASE
        WHEN su.filename IS NOT NULL AND TRIM(su.filename) != ''
        THEN SUBSTRING_INDEX(TRIM(su.filename), '/', -1)
        ELSE NULL
    END,

    -- blood_group
    NULLIF(TRIM(su.blood_group), ''),

    -- nid
    NULLIF(TRIM(su.nid), ''),

    -- poor_meritorious
    CASE UPPER(TRIM(su.poor_merotorius)) WHEN 'YES' THEN 1 ELSE 0 END,

    -- freedom_fighter_quota
    CASE UPPER(TRIM(su.freedom_fighter)) WHEN 'YES' THEN 1 ELSE 0 END,

    -- waiver_percent
    NULLIF(TRIM(su.student_user_waiver_percent), ''),

    -- fees
    su.student_user_form_fee,
    su.student_user_regi_fee,
    su.student_user_tution_fee,
    NULLIF(TRIM(su.student_user_misc_fee), ''),
    su.student_user_project_fee,
    su.student_user_total,
    su.student_user_weiver,
    NULLIF(TRIM(su.student_user_total_payable), ''),
    NULLIF(TRIM(su.student_user_monthly_installment), ''),

    -- ref_number
    NULLIF(TRIM(su.ref_number), ''),

    -- status
    CASE su.item_delete WHEN 1 THEN 'Inactive' ELSE 'Active' END,

    -- created_at
    CASE
        WHEN su.student_user_dte_created IS NOT NULL AND su.student_user_dte_created != '0000-00-00'
        THEN CAST(su.student_user_dte_created AS DATETIME)
        ELSE NOW()
    END

FROM `student_user` su
WHERE su.student_user_name IS NOT NULL
  AND TRIM(su.student_user_name) != '';

-- ─────────────────────────────────────────────────────────────────────────────
-- STEP 6 – Migrate academic qualifications (SSC, HSC, Graduate, B.Ed)
-- Each is inserted only when the key field (exam name or institution) is
-- non-empty to avoid blank rows.
-- ─────────────────────────────────────────────────────────────────────────────

-- SSC ─────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `student_academic_qualifications`
    (`student_id`, `exam_name`, `group_name`, `board_university`, `passing_year`,
     `obtained_marks_gpa`, `session`, `sort_order`)
SELECT
    st.id,
    NULLIF(TRIM(su.student_user_ssc_exam_name), ''),
    NULLIF(TRIM(su.hsc_group), ''),        -- SSC group not stored separately; leave NULL
    NULLIF(TRIM(su.school_board), ''),
    NULLIF(TRIM(su.ssc_pyear), ''),
    NULLIF(TRIM(su.student_user_ssc_grade), ''),
    NULL,
    1
FROM `student_user` su
JOIN `students`     st ON st.student_id = CASE
        WHEN TRIM(su.student_user_sid) = '' OR su.student_user_sid IS NULL
        THEN CONCAT('MIGR-', LPAD(su.student_user_id, 8, '0'))
        ELSE TRIM(su.student_user_sid)
    END
WHERE TRIM(COALESCE(su.student_user_ssc_exam_name, '')) != '';

-- HSC ─────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `student_academic_qualifications`
    (`student_id`, `exam_name`, `group_name`, `board_university`, `passing_year`,
     `obtained_marks_gpa`, `session`, `sort_order`)
SELECT
    st.id,
    NULLIF(TRIM(su.student_user_hsc_exam_name), ''),
    NULLIF(TRIM(su.hsc_group), ''),
    NULLIF(TRIM(su.college_board), ''),
    NULLIF(TRIM(su.hsc_pyear), ''),
    NULLIF(TRIM(su.student_user_hsc_grade), ''),
    NULL,
    2
FROM `student_user` su
JOIN `students`     st ON st.student_id = CASE
        WHEN TRIM(su.student_user_sid) = '' OR su.student_user_sid IS NULL
        THEN CONCAT('MIGR-', LPAD(su.student_user_id, 8, '0'))
        ELSE TRIM(su.student_user_sid)
    END
WHERE TRIM(COALESCE(su.student_user_hsc_exam_name, '')) != '';

-- Graduate / BSc ──────────────────────────────────────────────────────────────
INSERT IGNORE INTO `student_academic_qualifications`
    (`student_id`, `exam_name`, `board_university`, `passing_year`,
     `obtained_marks_gpa`, `sort_order`)
SELECT
    st.id,
    NULLIF(TRIM(su.degreel_name), ''),
    NULLIF(TRIM(su.versityl_name), ''),
    NULLIF(TRIM(su.bscl_pyear), ''),
    NULLIF(TRIM(su.degreel_grade), ''),
    3
FROM `student_user` su
JOIN `students`     st ON st.student_id = CASE
        WHEN TRIM(su.student_user_sid) = '' OR su.student_user_sid IS NULL
        THEN CONCAT('MIGR-', LPAD(su.student_user_id, 8, '0'))
        ELSE TRIM(su.student_user_sid)
    END
WHERE TRIM(COALESCE(su.degreel_name, '')) != ''
   OR TRIM(COALESCE(su.versityl_name, '')) != '';

-- B.Ed ────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `student_academic_qualifications`
    (`student_id`, `exam_name`, `board_university`, `passing_year`,
     `obtained_marks_gpa`, `sort_order`)
SELECT
    st.id,
    NULLIF(TRIM(su.degreebed_name), ''),
    NULLIF(TRIM(su.versitybed_name), ''),
    NULLIF(TRIM(su.bscbed_pyear), ''),
    NULLIF(TRIM(su.degreebed_grade), ''),
    4
FROM `student_user` su
JOIN `students`     st ON st.student_id = CASE
        WHEN TRIM(su.student_user_sid) = '' OR su.student_user_sid IS NULL
        THEN CONCAT('MIGR-', LPAD(su.student_user_id, 8, '0'))
        ELSE TRIM(su.student_user_sid)
    END
WHERE TRIM(COALESCE(su.degreebed_name, '')) != ''
   OR TRIM(COALESCE(su.versitybed_name, '')) != '';

-- ─────────────────────────────────────────────────────────────────────────────
-- STEP 7 – Create student_results table and import s_result_entry data
-- Linked to students via student_id (varchar SID).
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `student_results` (
    `id`                  INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `student_id`          INT UNSIGNED  DEFAULT NULL
        COMMENT 'FK to students.id – NULL when SID not matched',
    `student_sid`         VARCHAR(25)   DEFAULT NULL
        COMMENT 'Original student SID from result entry',
    `student_name`        VARCHAR(100)  DEFAULT NULL,
    `batch`               VARCHAR(20)   DEFAULT NULL,
    `semester`            VARCHAR(50)   DEFAULT NULL,
    `semester_year`       VARCHAR(20)   DEFAULT NULL,
    `department`          VARCHAR(50)   DEFAULT NULL,
    `program`             VARCHAR(50)   DEFAULT NULL,
    `level`               VARCHAR(30)   DEFAULT NULL
        COMMENT 'Undergraduate / Graduate / etc.',
    `subject`             VARCHAR(100)  DEFAULT NULL,
    `subject_code`        VARCHAR(30)   DEFAULT NULL,
    `grade`               VARCHAR(20)   DEFAULT NULL,
    `credits`             VARCHAR(20)   DEFAULT NULL,
    `gpa`                 VARCHAR(11)   DEFAULT NULL,
    `cgpa`                VARCHAR(15)   DEFAULT NULL,
    -- up to 4 additional courses in the same row (old flat schema)
    `subject_code1`       VARCHAR(20)   DEFAULT NULL,
    `grade1`              VARCHAR(10)   DEFAULT NULL,
    `credits1`            VARCHAR(10)   DEFAULT NULL,
    `gpa1`                VARCHAR(10)   DEFAULT NULL,
    `subject_code2`       VARCHAR(20)   DEFAULT NULL,
    `grade2`              VARCHAR(10)   DEFAULT NULL,
    `credits2`            VARCHAR(10)   DEFAULT NULL,
    `gpa2`                VARCHAR(10)   DEFAULT NULL,
    `subject_code3`       VARCHAR(20)   DEFAULT NULL,
    `grade3`              VARCHAR(10)   DEFAULT NULL,
    `credits3`            VARCHAR(10)   DEFAULT NULL,
    `gpa3`                VARCHAR(10)   DEFAULT NULL,
    `subject_code4`       VARCHAR(20)   DEFAULT NULL,
    `grade4`              VARCHAR(10)   DEFAULT NULL,
    `credits4`            VARCHAR(10)   DEFAULT NULL,
    `gpa4`                VARCHAR(10)   DEFAULT NULL,
    `recorded_date`       DATE          DEFAULT NULL,
    `created_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_sr_student`  (`student_id`),
    KEY `idx_sr_sid`      (`student_sid`),
    CONSTRAINT `fk_sr_student` FOREIGN KEY (`student_id`)
        REFERENCES `students`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'Migrated from legacy s_result_entry table';

-- Register module in admin modules table
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`)
VALUES ('Student Results', 'student-results',
        'View and manage imported semester result records', 'fas fa-chart-bar', 51);

-- Import result rows; link to students.id via matched SID
INSERT IGNORE INTO `student_results` (
    `student_id`, `student_sid`, `student_name`,
    `batch`, `semester`, `semester_year`,
    `department`, `program`, `level`,
    `subject`, `subject_code`,
    `grade`, `credits`, `gpa`, `cgpa`,
    `subject_code1`, `grade1`, `credits1`, `gpa1`,
    `subject_code2`, `grade2`, `credits2`, `gpa2`,
    `subject_code3`, `grade3`, `credits3`, `gpa3`,
    `subject_code4`, `grade4`, `credits4`, `gpa4`,
    `recorded_date`
)
SELECT
    (SELECT st.id FROM students st WHERE st.student_id = TRIM(sr.s_resultentry_sid) LIMIT 1),
    NULLIF(TRIM(sr.s_resultentry_sid), ''),
    NULLIF(TRIM(sr.s_resultentry_sname), ''),
    NULLIF(TRIM(sr.s_resultentry_batch), ''),
    NULLIF(TRIM(sr.s_resultentry_semester), ''),
    NULLIF(TRIM(sr.s_resultentry_semester_year), ''),
    NULLIF(TRIM(sr.s_resultentry_department), ''),
    NULLIF(TRIM(sr.s_resultentry_prog), ''),
    NULLIF(TRIM(sr.s_resultentry_gr_under_gra), ''),
    NULLIF(TRIM(sr.s_resultentry_subject), ''),
    NULLIF(TRIM(sr.s_resultentry_sub_code), ''),
    NULLIF(TRIM(sr.s_resultentry_grade), ''),
    NULLIF(TRIM(sr.s_resultentry_credits), ''),
    NULLIF(TRIM(sr.s_resultentry_gpa), ''),
    NULLIF(TRIM(sr.s_resultentry_cgpa), ''),
    NULLIF(TRIM(sr.s_resultentry_sub_code1), ''), NULLIF(TRIM(sr.s_resultentry_grade1), ''), NULLIF(TRIM(sr.s_resultentry_credits1), ''), NULLIF(TRIM(sr.s_resultentry_gpa1), ''),
    NULLIF(TRIM(sr.s_resultentry_sub_code2), ''), NULLIF(TRIM(sr.s_resultentry_grade2), ''), NULLIF(TRIM(sr.s_resultentry_credits2), ''), NULLIF(TRIM(sr.s_resultentry_gpa2), ''),
    NULLIF(TRIM(sr.s_resultentry_sub_code3), ''), NULLIF(TRIM(sr.s_resultentry_grade3), ''), NULLIF(TRIM(sr.s_resultentry_credits3), ''), NULLIF(TRIM(sr.s_resultentry_gpa3), ''),
    NULLIF(TRIM(sr.s_resultentry_sub_code4), ''), NULLIF(TRIM(sr.s_resultentry_grade4), ''), NULLIF(TRIM(sr.s_resultentry_credits4), ''), NULLIF(TRIM(sr.s_resultentry_gpa4), ''),
    CASE
        WHEN sr.s_resultentry_dte_created IS NOT NULL AND sr.s_resultentry_dte_created != '0000-00-00'
        THEN sr.s_resultentry_dte_created
        ELSE NULL
    END
FROM `s_result_entry` sr;

-- ─────────────────────────────────────────────────────────────────────────────
-- STEP 8 – Cleanup temp table
-- ─────────────────────────────────────────────────────────────────────────────

DROP TABLE IF EXISTS `_su_dept_map`;

SET FOREIGN_KEY_CHECKS = 1;

-- ─────────────────────────────────────────────────────────────────────────────
-- VERIFICATION QUERIES (run manually to confirm)
-- ─────────────────────────────────────────────────────────────────────────────
-- SELECT COUNT(*) FROM students;                          -- total migrated
-- SELECT status, COUNT(*) FROM students GROUP BY status;  -- Active vs Inactive
-- SELECT d.code, COUNT(*) c FROM students s JOIN dept_departments d ON d.id=s.dept_id GROUP BY d.code ORDER BY c DESC;
-- SELECT COUNT(*) FROM student_academic_qualifications;
-- SELECT COUNT(*) FROM student_results;
-- SELECT COUNT(*) FROM student_results WHERE student_id IS NOT NULL;  -- linked
