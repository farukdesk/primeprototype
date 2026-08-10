-- ============================================================================
-- Exam Routine module
-- ============================================================================
-- Builds exam routines from an active exam (ei_exams) and a course offer
-- (co_offers → dept / program / batch / semester / section / shift).
-- Each routine row is one registered course (co_offer_subjects) with an exam
-- date, time, room and remarks. Student counts come from co_registrations.
--
-- Safe to run multiple times: CREATE TABLE IF NOT EXISTS + guarded INSERT.
-- Because `.gitignore` ignores *.sql, this file is force-added to the repo.
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `exam_routines` (
  `id`              INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `exam_id`         INT(10) UNSIGNED NOT NULL COMMENT 'FK → ei_exams.id',
  `offer_id`        INT(10) UNSIGNED NOT NULL COMMENT 'FK → co_offers.id',
  `dept_id`         INT(10) UNSIGNED NOT NULL COMMENT 'Snapshot from the offer',
  `program_id`      INT(10) UNSIGNED DEFAULT NULL,
  `batch_id`        INT(10) UNSIGNED DEFAULT NULL,
  `semester`        VARCHAR(100) DEFAULT NULL,
  `academic_intake` VARCHAR(100) DEFAULT NULL,
  `shift`           VARCHAR(100) DEFAULT NULL,
  `section`         VARCHAR(100) DEFAULT NULL,
  `notes`           TEXT DEFAULT NULL,
  `created_by`      INT(10) UNSIGNED DEFAULT NULL COMMENT 'users.id',
  `created_at`      TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  `updated_at`      TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_er_exam`  (`exam_id`),
  KEY `idx_er_offer` (`offer_id`),
  KEY `idx_er_dept`  (`dept_id`),
  CONSTRAINT `fk_er_exam` FOREIGN KEY (`exam_id`)
      REFERENCES `ei_exams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_er_offer` FOREIGN KEY (`offer_id`)
      REFERENCES `co_offers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_routine_items` (
  `id`               INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `routine_id`       INT(10) UNSIGNED NOT NULL COMMENT 'FK → exam_routines.id',
  `offer_subject_id` INT(10) UNSIGNED DEFAULT NULL COMMENT 'FK → co_offer_subjects.id',
  `course_code`      VARCHAR(100) DEFAULT NULL COMMENT 'Snapshot at save time',
  `course_title`     VARCHAR(300) NOT NULL,
  `student_count`    INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Active registered students at save time',
  `exam_date`        DATE NOT NULL,
  `start_time`       TIME DEFAULT NULL,
  `end_time`         TIME DEFAULT NULL,
  `room_number`      VARCHAR(100) DEFAULT NULL,
  `remarks`          VARCHAR(500) DEFAULT NULL,
  `sort_order`       INT(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_eri_routine` (`routine_id`),
  KEY `idx_eri_subject` (`offer_subject_id`),
  CONSTRAINT `fk_eri_routine` FOREIGN KEY (`routine_id`)
      REFERENCES `exam_routines` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_eri_subject` FOREIGN KEY (`offer_subject_id`)
      REFERENCES `co_offer_subjects` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Register the module (permissions are then managed from Access Control).
INSERT INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`, `is_active`)
SELECT 'Exam Routine', 'exam-routine',
       'Build exam routines from active exams and registered courses',
       'fas fa-calendar-alt', 0, 1
 WHERE NOT EXISTS (SELECT 1 FROM `modules` WHERE `slug` = 'exam-routine');
