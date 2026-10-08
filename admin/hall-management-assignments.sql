-- ============================================================================
-- Hall Management – student seat assignments
-- ============================================================================
-- Students (sourced from generated admit cards) are assigned to a hall's
-- seats for a specific exam date. The assignment keeps the selection
-- context (dept / program / batch / section / shift) so later audits can
-- tell which filter produced each seat.
--
-- Safe to run multiple times: CREATE TABLE IF NOT EXISTS.
-- Because `.gitignore` ignores *.sql, this file is force-added to the repo.
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `hm_hall_assignments` (
  `id`            INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `hall_id`       INT(10) UNSIGNED NOT NULL COMMENT 'FK → hm_halls.id',
  `exam_date`     DATE NOT NULL,
  `student_id`    INT(10) UNSIGNED NOT NULL COMMENT 'FK → students.id',
  `col_no`        INT(10) UNSIGNED NOT NULL COMMENT 'Hall column (1-based)',
  `seat_no`       INT(10) UNSIGNED NOT NULL COMMENT 'Seat within the column (1-based)',
  `dept_id`       INT(10) UNSIGNED DEFAULT NULL COMMENT 'Selection context',
  `program_id`    INT(10) UNSIGNED DEFAULT NULL COMMENT 'Selection context',
  `batch_id`      INT(10) UNSIGNED DEFAULT NULL COMMENT 'Selection context',
  `section`       VARCHAR(100) DEFAULT NULL COMMENT 'Selection context',
  `shift`         VARCHAR(25)  DEFAULT NULL COMMENT 'Selection context (Day / Evening / Morning)',
  `assigned_by`   INT(10) UNSIGNED DEFAULT NULL COMMENT 'users.id',
  `created_at`    TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hma_seat`    (`hall_id`, `exam_date`, `col_no`, `seat_no`),
  UNIQUE KEY `uq_hma_student` (`hall_id`, `exam_date`, `student_id`),
  KEY `idx_hma_date_student` (`exam_date`, `student_id`),
  CONSTRAINT `fk_hma_hall` FOREIGN KEY (`hall_id`)
      REFERENCES `hm_halls` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hma_student` FOREIGN KEY (`student_id`)
      REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
