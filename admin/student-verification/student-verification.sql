-- Student Verification Module – SQL Schema
-- Run AFTER students.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. student_verifications: main verification record
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `student_verifications` (
  `id`                       INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `student_id`               INT UNSIGNED NOT NULL COMMENT 'FK → students.id',
  `verified_by`              INT UNSIGNED NOT NULL COMMENT 'FK → users.id',

  -- Check 1: Certificate & Transcript Visual Security Measures
  `cert_transcript_ok`       TINYINT(1)   NOT NULL DEFAULT 0,
  `cert_transcript_issues`   TEXT         DEFAULT NULL,

  -- Check 2: Admission Form scanned document
  `admission_form_ok`        TINYINT(1)   NOT NULL DEFAULT 0,
  `admission_form_issues`    TEXT         DEFAULT NULL,
  `admission_form_file_id`   INT UNSIGNED DEFAULT NULL COMMENT 'FK → student_files.id (Admission Form)',

  -- Check 3: Final Result Tabulation PDF
  `tabulation_ok`            TINYINT(1)   NOT NULL DEFAULT 0,
  `tabulation_issues`        TEXT         DEFAULT NULL,
  `tabulation_file_id`       INT UNSIGNED DEFAULT NULL COMMENT 'FK → student_files.id (Tabulation)',

  -- Overall result
  `overall_status`           ENUM('Verified','Failed') NOT NULL,

  -- Verified copy (uploaded after printing & signing)
  `verified_pdf`             VARCHAR(300) DEFAULT NULL COMMENT 'stored file name in uploads/student-verification/',

  -- Email delivery
  `verifier_email`           VARCHAR(200) DEFAULT NULL,
  `email_sent`               TINYINT(1)   NOT NULL DEFAULT 0,
  `email_sent_at`            DATETIME     DEFAULT NULL,

  `created_at`               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY `idx_sv_student`    (`student_id`),
  KEY `idx_sv_verifier`   (`verified_by`),
  KEY `idx_sv_status`     (`overall_status`),
  CONSTRAINT `fk_sv_student`  FOREIGN KEY (`student_id`)  REFERENCES `students`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sv_user`     FOREIGN KEY (`verified_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. Register module (insert only if slug not already present)
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `icon`, `sort_order`, `is_active`)
VALUES ('Student Verification', 'student-verification', 'fas fa-shield-alt', 55, 1);

SET FOREIGN_KEY_CHECKS = 1;
