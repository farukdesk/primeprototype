-- ─────────────────────────────────────────────────────────────────────────────
-- Results Workflow Migration
-- 4-stage approval: Teacher Entry → Reviewer → Department Head → Controller
-- Run AFTER results.sql
-- ─────────────────────────────────────────────────────────────────────────────

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. result_mark_sheets
--    One sheet per dept + program + semester + subject (the core workflow unit)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `result_mark_sheets` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `dept_id`          INT UNSIGNED  NOT NULL                    COMMENT 'FK → dept_departments.id',
  `program_id`       INT UNSIGNED  DEFAULT NULL                COMMENT 'FK → dept_academic_programs.id',
  `semester`         VARCHAR(100)  NOT NULL                    COMMENT 'Academic semester label e.g. Fall-2025',
  `academic_year`    VARCHAR(20)   DEFAULT NULL                COMMENT 'e.g. 2025-2026',
  `curriculum_id`    INT UNSIGNED  DEFAULT NULL                COMMENT 'FK → course_curriculum.id (optional link)',
  `subject_code`     VARCHAR(50)   DEFAULT NULL,
  `subject_title`    VARCHAR(300)  NOT NULL,
  `credits`          DECIMAL(4,2)  DEFAULT NULL,
  `workflow_status`  ENUM('draft','submitted','under_review','hod_approved','published','returned')
                                   NOT NULL DEFAULT 'draft',
  -- Teacher (creator)
  `created_by`       INT UNSIGNED  DEFAULT NULL                COMMENT 'FK → users.id',
  `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `submitted_at`     DATETIME      DEFAULT NULL,
  -- Reviewer stage
  `reviewed_by`      INT UNSIGNED  DEFAULT NULL                COMMENT 'FK → users.id',
  `reviewed_at`      DATETIME      DEFAULT NULL,
  `reviewer_remarks` TEXT          DEFAULT NULL,
  -- HOD stage
  `hod_approved_by`  INT UNSIGNED  DEFAULT NULL                COMMENT 'FK → users.id',
  `hod_approved_at`  DATETIME      DEFAULT NULL,
  `hod_remarks`      TEXT          DEFAULT NULL,
  -- Controller / publish stage
  `published_by`     INT UNSIGNED  DEFAULT NULL                COMMENT 'FK → users.id',
  `published_at`     DATETIME      DEFAULT NULL,
  -- Return / rejection
  `returned_by`      INT UNSIGNED  DEFAULT NULL                COMMENT 'FK → users.id',
  `returned_at`      DATETIME      DEFAULT NULL,
  `return_remarks`   TEXT          DEFAULT NULL,
  `returned_to_step` VARCHAR(30)   DEFAULT NULL                COMMENT 'draft | under_review',
  KEY `idx_rms_dept`     (`dept_id`),
  KEY `idx_rms_program`  (`program_id`),
  KEY `idx_rms_status`   (`workflow_status`),
  KEY `idx_rms_creator`  (`created_by`),
  CONSTRAINT `fk_rms_dept`       FOREIGN KEY (`dept_id`)      REFERENCES `dept_departments`(`id`)        ON DELETE CASCADE,
  CONSTRAINT `fk_rms_program`    FOREIGN KEY (`program_id`)   REFERENCES `dept_academic_programs`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_rms_curriculum` FOREIGN KEY (`curriculum_id`) REFERENCES `course_curriculum`(`id`)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'Workflow mark sheet header (one per subject per semester)';

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. result_sheet_grades
--    One row per student within a mark sheet
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `result_sheet_grades` (
  `id`           INT UNSIGNED   NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `sheet_id`     INT UNSIGNED   NOT NULL                COMMENT 'FK → result_mark_sheets.id',
  `student_id`   INT UNSIGNED   DEFAULT NULL            COMMENT 'FK → students.id',
  `student_sid`  VARCHAR(25)    NOT NULL                COMMENT 'Student ID string (e.g. 193020101021)',
  `student_name` VARCHAR(200)   DEFAULT NULL,
  `is_absent`    TINYINT(1)     NOT NULL DEFAULT 0      COMMENT '1 = absent; overrides all marks',
  `attendance`   DECIMAL(5,2)   DEFAULT NULL            COMMENT 'Max 10',
  `class_test`   DECIMAL(5,2)   DEFAULT NULL            COMMENT 'Max 10',
  `mid_term`     DECIMAL(5,2)   DEFAULT NULL            COMMENT 'Max 30',
  `final_exam`   DECIMAL(5,2)   DEFAULT NULL            COMMENT 'Max 50',
  `total_marks`  DECIMAL(5,2)   DEFAULT NULL            COMMENT 'Computed: sum of components',
  `letter_grade` VARCHAR(10)    DEFAULT NULL,
  `grade_point`  DECIMAL(4,2)   DEFAULT NULL,
  `created_at`   DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_sheet_student` (`sheet_id`, `student_sid`),
  KEY `idx_rsg_sheet`   (`sheet_id`),
  KEY `idx_rsg_student` (`student_id`),
  CONSTRAINT `fk_rsg_sheet`   FOREIGN KEY (`sheet_id`)   REFERENCES `result_mark_sheets`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rsg_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`)           ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'Student mark entries within a workflow mark sheet';

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. Register the 4 new workflow module slugs
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`, `is_active`)
VALUES
  ('Result Entry',      'results-entry',      'Class teacher mark entry (workflow)',        'fas fa-pen-nib',        55, 1),
  ('Result Review',     'results-review',     'Reviewer: verify and forward mark sheets',  'fas fa-search',         56, 1),
  ('Result HOD',        'results-hod',        'Department Head approval of mark sheets',   'fas fa-user-tie',       57, 1),
  ('Result Controller', 'results-controller', 'Controller of Examinations – publish',      'fas fa-check-double',   58, 1);

SET FOREIGN_KEY_CHECKS = 1;
