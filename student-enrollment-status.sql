-- =============================================================================
-- Student Enrollment Status – Standalone Table & Test Data
-- Database: admin_primepnew2026
-- Run this script once to create the table and insert the test student.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `student_enrollment_status` (
  `id`                  INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `student_id`          VARCHAR(30)      NOT NULL,
  `photo`               VARCHAR(255)         NULL DEFAULT NULL COMMENT 'Filename only; resolved via admin/uploads/students/photos/',
  `full_name`           VARCHAR(150)     NOT NULL,
  `department`          VARCHAR(150)     NOT NULL,
  `program`             VARCHAR(150)     NOT NULL,
  `batch`               VARCHAR(50)      NOT NULL,
  `enrollment_status`   ENUM('Active','On Leave','Completed','Dropped')
                                         NOT NULL DEFAULT 'Active',
  `current_semester`    TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `total_semesters`     TINYINT UNSIGNED NOT NULL DEFAULT 12,
  `cgpa`                DECIMAL(4,2)         NULL DEFAULT NULL COMMENT 'Calculated on completed credits only',
  `completed_credits`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `total_credits`       SMALLINT UNSIGNED NOT NULL DEFAULT 144,
  `created_at`          TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_student_id` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Standalone student enrollment status records (public-facing)';

-- =============================================================================
-- Test student record
-- =============================================================================

INSERT INTO `student_enrollment_status`
  (`student_id`, `photo`, `full_name`, `department`, `program`,
   `batch`, `enrollment_status`, `current_semester`, `total_semesters`,
   `cgpa`, `completed_credits`, `total_credits`)
VALUES
  ('230101010001', NULL, 'Md. Rafiqul Islam',
   'Computer Science & Engineering',
   'B.Sc. in Computer Science & Engineering',
   '23rd Batch', 'Active', 5, 12,
   3.72, 75, 144);
