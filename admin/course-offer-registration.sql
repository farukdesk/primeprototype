-- ============================================================================
-- Course Offer → Student Registration
-- ============================================================================
-- Adds student self-registration + admin manual enrollment on top of the
-- existing Course Offer module.
--
--   * `co_offers.registration_open` — when 1, students whose batch matches the
--     offer may register/drop the offer's subjects from the student portal.
--   * `co_registrations` — one row per (offer subject, student). `source`
--     records whether the row was created by the student ('self') or by an
--     administrator ('admin'). Rows cascade-delete with their offer subject or
--     student, so cleaning up an offer/student also clears its registrations.
--
-- Safe to run multiple times: guards check for the column/table first.
-- Because `.gitignore` ignores *.sql, this file is force-added to the repo.
-- ----------------------------------------------------------------------------

-- ── 1. Self-registration toggle on offers ──────────────────────────────────
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'co_offers'
       AND COLUMN_NAME  = 'registration_open'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE `co_offers`
        ADD COLUMN `registration_open` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT ''1 = students of this batch may self-register the subjects''
        AFTER `status`',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 2. Registration table ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `co_registrations` (
  `id`               INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `offer_subject_id` INT(10) UNSIGNED NOT NULL COMMENT 'FK → co_offer_subjects.id',
  `student_id`       INT(10) UNSIGNED NOT NULL COMMENT 'FK → students.id',
  `source`           ENUM('self','admin') NOT NULL DEFAULT 'self'
                     COMMENT 'Who registered the student',
  `registered_by`    INT(10) UNSIGNED DEFAULT NULL COMMENT 'users.id of admin (NULL for self)',
  `created_at`       TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_coreg_subject_student` (`offer_subject_id`,`student_id`),
  KEY `idx_coreg_student` (`student_id`),
  KEY `idx_coreg_subject` (`offer_subject_id`),
  CONSTRAINT `fk_coreg_subject` FOREIGN KEY (`offer_subject_id`)
      REFERENCES `co_offer_subjects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_coreg_student` FOREIGN KEY (`student_id`)
      REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
