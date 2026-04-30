-- Result Module SQL Schema
-- Run AFTER database.sql, departments.sql, students.sql, and course-curriculum.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. result_exams: one record per result sheet
--    (e.g. "BBA Batch 52 – Foundation Courses – Fall 2023")
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `result_exams` (
  `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `dept_id`              INT UNSIGNED NOT NULL                    COMMENT 'FK → dept_departments.id',
  `program_id`           INT UNSIGNED DEFAULT NULL               COMMENT 'FK → dept_academic_programs.id',
  `batch`                VARCHAR(50)  DEFAULT NULL               COMMENT 'e.g. 52nd',
  `enrollment_semester`  VARCHAR(50)  DEFAULT NULL               COMMENT 'e.g. Fall-2019',
  `completion_semester`  VARCHAR(50)  DEFAULT NULL               COMMENT 'e.g. Summer-2023',
  `exam_title`           VARCHAR(300) NOT NULL                   COMMENT 'Display title, e.g. Foundation Courses Result',
  `exam_level`           VARCHAR(100) DEFAULT NULL               COMMENT 'e.g. Foundation Courses, Year 1',
  `notes`                TEXT         DEFAULT NULL,
  `is_published`         TINYINT(1)   NOT NULL DEFAULT 0         COMMENT '1 = visible to students',
  `created_by`           INT UNSIGNED DEFAULT NULL,
  `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_re_dept`    (`dept_id`),
  KEY `idx_re_program` (`program_id`),
  CONSTRAINT `fk_re_dept`    FOREIGN KEY (`dept_id`)    REFERENCES `dept_departments`(`id`)        ON DELETE CASCADE,
  CONSTRAINT `fk_re_program` FOREIGN KEY (`program_id`) REFERENCES `dept_academic_programs`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'Result exam/session header';

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. result_subjects: courses/subjects included in a result exam
--    Optionally links to course_curriculum for auto-fill.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `result_subjects` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `exam_id`        INT UNSIGNED  NOT NULL                  COMMENT 'FK → result_exams.id',
  `curriculum_id`  INT UNSIGNED  DEFAULT NULL              COMMENT 'FK → course_curriculum.id (optional)',
  `course_code`    VARCHAR(50)   DEFAULT NULL              COMMENT 'e.g. BEL-111',
  `course_title`   VARCHAR(300)  NOT NULL                  COMMENT 'e.g. English Reading Skills',
  `credits`        DECIMAL(4,2)  DEFAULT NULL,
  `sort_order`     SMALLINT      NOT NULL DEFAULT 0,
  `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_rs_exam`       (`exam_id`),
  KEY `idx_rs_curriculum` (`curriculum_id`),
  CONSTRAINT `fk_rs_exam`       FOREIGN KEY (`exam_id`)       REFERENCES `result_exams`(`id`)       ON DELETE CASCADE,
  CONSTRAINT `fk_rs_curriculum` FOREIGN KEY (`curriculum_id`) REFERENCES `course_curriculum`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'Subjects/courses in a result exam';

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. result_grades: one row per student × subject
--    student_id is optional so grades can be added before a student record exists.
--    marks → letter_grade and grade_point are normally auto-calculated.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `result_grades` (
  `id`           INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `exam_id`      INT UNSIGNED    NOT NULL                COMMENT 'FK → result_exams.id',
  `subject_id`   INT UNSIGNED    NOT NULL                COMMENT 'FK → result_subjects.id',
  `student_id`   INT UNSIGNED    DEFAULT NULL            COMMENT 'FK → students.id',
  `student_sid`  VARCHAR(25)     NOT NULL                COMMENT 'Student ID string (e.g. 193020101021)',
  `student_name` VARCHAR(200)    DEFAULT NULL            COMMENT 'Snapshot from students.full_name',
  `marks`        DECIMAL(5,2)    DEFAULT NULL            COMMENT 'Numerical marks (0–100)',
  `letter_grade` VARCHAR(10)     DEFAULT NULL            COMMENT 'e.g. A+, B-',
  `grade_point`  DECIMAL(4,2)    DEFAULT NULL            COMMENT 'e.g. 4.00, 3.25',
  `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_grade` (`exam_id`, `subject_id`, `student_sid`),
  KEY `idx_rg_exam`    (`exam_id`),
  KEY `idx_rg_subject` (`subject_id`),
  KEY `idx_rg_student` (`student_id`),
  CONSTRAINT `fk_rg_exam`    FOREIGN KEY (`exam_id`)    REFERENCES `result_exams`(`id`)    ON DELETE CASCADE,
  CONSTRAINT `fk_rg_subject` FOREIGN KEY (`subject_id`) REFERENCES `result_subjects`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rg_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'Student grades per subject per exam';

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. result_mark_categories: per-subject marking breakdown
--    e.g. Attendance 10%, Class Test 10%, Mid Term 30%, Final 50%
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `result_mark_categories` (
  `id`            INT UNSIGNED   NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `subject_id`    INT UNSIGNED   NOT NULL               COMMENT 'FK → result_subjects.id',
  `category_name` VARCHAR(100)   NOT NULL               COMMENT 'e.g. Attendance, Class Test, Mid Term, Final',
  `max_marks`     DECIMAL(5,2)   NOT NULL DEFAULT 100   COMMENT 'Maximum marks allocated to this category',
  `sort_order`    SMALLINT       NOT NULL DEFAULT 0,
  `created_at`    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_rmc_subject` (`subject_id`),
  CONSTRAINT `fk_rmc_subject` FOREIGN KEY (`subject_id`) REFERENCES `result_subjects`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'Marking category breakdown per subject';

-- ─────────────────────────────────────────────────────────────────────────────
-- 5. result_grade_details: per-category marks for each grade row
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `result_grade_details` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `grade_id`       INT UNSIGNED  NOT NULL               COMMENT 'FK → result_grades.id',
  `category_id`    INT UNSIGNED  NOT NULL               COMMENT 'FK → result_mark_categories.id',
  `marks_obtained` DECIMAL(5,2)  NOT NULL DEFAULT 0     COMMENT 'Marks obtained in this category',
  `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_grade_cat` (`grade_id`, `category_id`),
  KEY `idx_rgd_grade`    (`grade_id`),
  KEY `idx_rgd_category` (`category_id`),
  CONSTRAINT `fk_rgd_grade`    FOREIGN KEY (`grade_id`)    REFERENCES `result_grades`(`id`)         ON DELETE CASCADE,
  CONSTRAINT `fk_rgd_category` FOREIGN KEY (`category_id`) REFERENCES `result_mark_categories`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'Per-category marks for each student grade row';

-- Add signoff columns to result_grades (idempotent)
ALTER TABLE `result_grades`
  ADD COLUMN IF NOT EXISTS `marked_by`   VARCHAR(200) DEFAULT NULL COMMENT 'Name of person who entered the marks',
  ADD COLUMN IF NOT EXISTS `reviewed_by` VARCHAR(200) DEFAULT NULL COMMENT 'Name of reviewer',
  ADD COLUMN IF NOT EXISTS `approved_by` VARCHAR(200) DEFAULT NULL COMMENT 'Name of approver';

SET FOREIGN_KEY_CHECKS = 1;

-- ─────────────────────────────────────────────────────────────────────────────
-- Register modules in the access-control system
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`, `is_active`)
VALUES
  ('Results',          'results',          'Manage result exams and grade sheets',        'fas fa-chart-bar',   52, 1),
  ('Result Subjects',  'result-subjects',  'Manage subjects/courses within result exams', 'fas fa-list-ol',     53, 1),
  ('Result Grades',    'result-grades',    'Enter and manage student result grades',      'fas fa-star-half-alt', 54, 1);
