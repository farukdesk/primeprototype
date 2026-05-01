-- ============================================================
-- Subject / Faculty Assignment Migration
-- Run AFTER course-curriculum.sql and intake-migration.sql
-- ============================================================

-- 1. Add assigned_faculty_id to course_curriculum
--    (FK → dept_faculty.id; NULL = no faculty assigned yet)
ALTER TABLE `course_curriculum`
  ADD COLUMN IF NOT EXISTS `assigned_faculty_id` INT UNSIGNED DEFAULT NULL
    COMMENT 'FK to dept_faculty.id; faculty responsible for teaching this subject'
    AFTER `credit`,
  ADD KEY IF NOT EXISTS `idx_cc_assigned_faculty` (`assigned_faculty_id`),
  ADD CONSTRAINT IF NOT EXISTS `fk_cc_assigned_faculty`
    FOREIGN KEY (`assigned_faculty_id`) REFERENCES `dept_faculty` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE;

-- 2. Faculty subject self-assignment requests (pending approval)
CREATE TABLE IF NOT EXISTS `faculty_subject_assignments` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `faculty_user_id` INT UNSIGNED NOT NULL  COMMENT 'FK → users.id (the requesting faculty)',
  `course_id`       INT UNSIGNED NOT NULL  COMMENT 'FK → course_curriculum.id',
  `status`          ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by`     INT UNSIGNED DEFAULT NULL COMMENT 'FK → users.id (admin/HoD who reviewed)',
  `reviewed_at`     DATETIME     DEFAULT NULL,
  `notes`           TEXT         DEFAULT NULL COMMENT 'Reviewer notes / rejection reason',
  `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fsa_faculty_course` (`faculty_user_id`, `course_id`),
  KEY `idx_fsa_faculty` (`faculty_user_id`),
  KEY `idx_fsa_course`  (`course_id`),
  KEY `idx_fsa_status`  (`status`),
  CONSTRAINT `fk_fsa_faculty`
    FOREIGN KEY (`faculty_user_id`) REFERENCES `users` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_fsa_course`
    FOREIGN KEY (`course_id`) REFERENCES `course_curriculum` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Register faculty-subject-assignments module for access control
INSERT IGNORE INTO `modules` (`name`, `slug`, `can_create`, `can_edit`, `can_delete`)
VALUES ('Faculty Subject Assignments', 'faculty-subject-assignments', 1, 1, 1);
