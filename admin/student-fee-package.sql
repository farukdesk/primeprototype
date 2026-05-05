-- Student Fee Package Module
-- Assigns a snapshot of course fee constants to each student.
-- Changes to cf_programs do NOT retroactively affect existing packages.
-- Run AFTER students.sql, course-fees-v4.sql, scholarship.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. sfp_packages: one fee package per student
--    Snapshots all fee constants from cf_programs at the time of assignment.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sfp_packages` (
  `id`                       INT UNSIGNED     NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `student_id`               INT UNSIGNED     NOT NULL,
  `cf_program_id`            INT UNSIGNED     DEFAULT NULL
                             COMMENT 'Source cf_programs.id for reference; nullable on program deletion',

  -- Snapshot of program metadata
  `program_name`             VARCHAR(200)     NOT NULL,
  `total_semesters`          TINYINT UNSIGNED NOT NULL,
  `total_months`             SMALLINT UNSIGNED NOT NULL,
  `months_per_semester`      DECIMAL(6,2)     NOT NULL
                             COMMENT 'total_months / total_semesters',

  -- Snapshot of fee constants
  `standard_tuition_full`    INT UNSIGNED     NOT NULL DEFAULT 0,
  `tuition_per_semester`     DECIMAL(10,2)    NOT NULL DEFAULT 0,
  `admission_fees`           INT UNSIGNED     NOT NULL DEFAULT 0
                             COMMENT 'One-time admission day cost; already paid separately – stored for reference',
  `fixed_institutional_fees` INT UNSIGNED     NOT NULL DEFAULT 0,
  `english_course_fee`       INT UNSIGNED     NOT NULL DEFAULT 0,

  -- Safety-net constants (snapshot)
  `safety_net_cap`           INT UNSIGNED     DEFAULT NULL,
  `safety_net_per_semester`  DECIMAL(10,2)    DEFAULT NULL,
  `attendance_requirement`   TINYINT UNSIGNED NOT NULL DEFAULT 70,
  `safety_net_gpa_threshold` DECIMAL(4,2)     NOT NULL DEFAULT 3.00,

  -- Derived monthly fees stored at creation time for convenience
  `monthly_fixed_fee`        DECIMAL(10,2)    NOT NULL DEFAULT 0
                             COMMENT 'fixed_institutional_fees / total_months',
  `monthly_english_fee`      DECIMAL(10,2)    NOT NULL DEFAULT 0
                             COMMENT 'english_course_fee / total_months',

  `note`                     TEXT             DEFAULT NULL,
  `assigned_by`              INT UNSIGNED     DEFAULT NULL,
  `created_at`               DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`               DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY `uq_sfp_student` (`student_id`)
                             COMMENT 'One active package per student',
  KEY `idx_sfp_cf_prog` (`cf_program_id`),
  CONSTRAINT `fk_sfp_student`    FOREIGN KEY (`student_id`)    REFERENCES `students`(`id`)    ON DELETE CASCADE,
  CONSTRAINT `fk_sfp_cf_program` FOREIGN KEY (`cf_program_id`) REFERENCES `cf_programs`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sfp_assigned`   FOREIGN KEY (`assigned_by`)   REFERENCES `users`(`id`)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'Snapshotted fee package assigned to a student';

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. sfp_semester_fees: one row per semester in a package
--    Auto-generated when a package is created (total_semesters rows).
--    Scholarship discount is applied per-row.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sfp_semester_fees` (
  `id`                       INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `package_id`               INT UNSIGNED  NOT NULL,
  `semester_number`          TINYINT UNSIGNED NOT NULL COMMENT '1-based index within the programme',
  `semester_label`           VARCHAR(50)   DEFAULT NULL COMMENT 'e.g. "Summer 2026" – set by admin',

  -- Tuition copied from package at generation time
  `tuition_fee`              DECIMAL(10,2) NOT NULL DEFAULT 0,

  -- Scholarship applied to this semester
  `scholarship_award_id`     INT UNSIGNED  DEFAULT NULL
                             COMMENT 'FK sc_awards.id – for traceability when linked to a formal award',
  `scholarship_discount_pct` DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
  `scholarship_amount`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `tuition_payable`          DECIMAL(10,2) NOT NULL DEFAULT 0.00
                             COMMENT 'tuition_fee − scholarship_amount',

  `note`                     TEXT          DEFAULT NULL,
  `updated_by`               INT UNSIGNED  DEFAULT NULL,
  `created_at`               DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`               DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY `idx_sfpsf_package` (`package_id`),
  CONSTRAINT `fk_sfpsf_package` FOREIGN KEY (`package_id`)           REFERENCES `sfp_packages`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sfpsf_award`   FOREIGN KEY (`scholarship_award_id`) REFERENCES `sc_awards`(`id`)    ON DELETE SET NULL,
  CONSTRAINT `fk_sfpsf_updated` FOREIGN KEY (`updated_by`)           REFERENCES `users`(`id`)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'Per-semester fee records for a student fee package';

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. Register module
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`, `is_active`)
VALUES ('Student Fee Package', 'student-fee-package',
        'Assign per-student fee packages snapshotted from course fee structures; apply semester-wise scholarships',
        'fas fa-file-invoice-dollar', 55, 1);

SET FOREIGN_KEY_CHECKS = 1;
