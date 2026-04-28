-- Course Curriculum Intakes (Batch/Semester Intake grouping)
-- Run AFTER course-curriculum.sql (depends on course_curriculum table).

CREATE TABLE IF NOT EXISTS `course_curriculum_intakes` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `program_id`     INT UNSIGNED  NOT NULL,
  `batch_name`     VARCHAR(150)  NOT NULL COMMENT 'e.g. "Spring 2024 Intake", "Batch 30"',
  `intake_year`    SMALLINT UNSIGNED DEFAULT NULL COMMENT 'e.g. 2024',
  `intake_season`  VARCHAR(20)   DEFAULT NULL COMMENT 'Spring / Summer / Fall / Winter',
  `notes`          TEXT          DEFAULT NULL,
  `is_published`   TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1 = visible on public site',
  `created_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cci_program` (`program_id`),
  CONSTRAINT `fk_cci_program`
    FOREIGN KEY (`program_id`) REFERENCES `dept_academic_programs` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Link each course row to an intake.
-- Existing rows (before this migration) will have intake_id = NULL (legacy).
ALTER TABLE `course_curriculum`
  ADD COLUMN IF NOT EXISTS `intake_id` INT UNSIGNED DEFAULT NULL
    COMMENT 'FK to course_curriculum_intakes.id; NULL = legacy row'
    AFTER `program_id`,
  ADD KEY IF NOT EXISTS `idx_cc_intake` (`intake_id`),
  ADD CONSTRAINT IF NOT EXISTS `fk_cc_intake`
    FOREIGN KEY (`intake_id`) REFERENCES `course_curriculum_intakes` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE;
