-- ─────────────────────────────────────────────────────────────────────────────
-- Marking Distribution per Course Curriculum Subject
-- Run once to add the cc_mark_distributions table.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `cc_mark_distributions` (
  `id`                INT UNSIGNED   NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `curriculum_id`     INT UNSIGNED   NOT NULL               COMMENT 'FK → course_curriculum.id',
  `distribution_name` VARCHAR(100)   NOT NULL               COMMENT 'e.g. Attendance, Class Test, Mid Term, Final',
  `max_marks`         DECIMAL(5,2)   NOT NULL DEFAULT 100   COMMENT 'Maximum marks allocated to this distribution',
  `sort_order`        SMALLINT       NOT NULL DEFAULT 0,
  `created_at`        DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_ccmd_curriculum` (`curriculum_id`),
  CONSTRAINT `fk_ccmd_curriculum` FOREIGN KEY (`curriculum_id`)
    REFERENCES `course_curriculum`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'Marking distribution breakdown per course curriculum subject';
