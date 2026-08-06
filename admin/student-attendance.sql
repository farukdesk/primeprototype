-- ============================================================================
-- Student Attendance – module registration + tables
-- ============================================================================
-- Faculty take date-wise attendance for the subjects they are assigned to
-- teach (co_offer_subject_teachers). Student lists come from Course Offer
-- registrations (co_registrations).
--
--   * `student_att_sessions` — one row per (offered subject, class date).
--   * `student_att_records`  — one row per (session, student) with the status.
--
-- NOTE: table names are prefixed `student_att_` because the plain `att_`
-- prefix (att_sessions / att_records) is already used by the Staff Attendance
-- module and must not be reused here.
--
-- Safe to run multiple times: module insert is guarded, tables use
-- CREATE TABLE IF NOT EXISTS.
-- ----------------------------------------------------------------------------

-- ── 1. Module registration (grant access in Module Access afterwards) ──────
INSERT INTO modules (name, slug, description, icon, sort_order, is_active)
SELECT 'Student Attendance',
       'student-attendance',
       'Date-wise class attendance for course-offer subjects. Faculty see only the subjects they are assigned to teach; students are pulled from course offer registrations.',
       'fas fa-clipboard-user',
       0,
       1
WHERE NOT EXISTS (SELECT 1 FROM modules WHERE slug = 'student-attendance');

-- ── 2. Attendance sessions (one per offered subject per date) ──────────────
CREATE TABLE IF NOT EXISTS `student_att_sessions` (
  `id`               INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `offer_subject_id` INT(10) UNSIGNED NOT NULL COMMENT 'FK → co_offer_subjects.id',
  `class_date`       DATE NOT NULL,
  `taken_by`         INT(10) UNSIGNED DEFAULT NULL COMMENT 'users.id of the person who saved the session',
  `created_at`       TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  `updated_at`       TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_satt_subject_date` (`offer_subject_id`,`class_date`),
  KEY `idx_satt_sessions_subject` (`offer_subject_id`),
  CONSTRAINT `fk_satt_sessions_subject` FOREIGN KEY (`offer_subject_id`)
      REFERENCES `co_offer_subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Attendance records (one per session per student) ────────────────────
CREATE TABLE IF NOT EXISTS `student_att_records` (
  `id`         INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` INT(10) UNSIGNED NOT NULL COMMENT 'FK → student_att_sessions.id',
  `student_id` INT(10) UNSIGNED NOT NULL COMMENT 'FK → students.id',
  `status`     ENUM('present','absent','late','excused') NOT NULL DEFAULT 'present',
  `created_at` TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_satt_session_student` (`session_id`,`student_id`),
  KEY `idx_satt_records_student` (`student_id`),
  CONSTRAINT `fk_satt_records_session` FOREIGN KEY (`session_id`)
      REFERENCES `student_att_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_satt_records_student` FOREIGN KEY (`student_id`)
      REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
