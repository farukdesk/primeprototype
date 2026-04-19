-- Certificate Verification Log – SQL Schema
-- Stores records of every public certificate-verification request.
-- Run AFTER students.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. cert_verification_log: one row per public verification request
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `cert_verification_log` (
  `id`                   INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,

  -- What was searched
  `queried_student_id`   VARCHAR(50)   NOT NULL               COMMENT 'Student ID string entered by the verifier',
  `student_id`           INT UNSIGNED  DEFAULT NULL           COMMENT 'FK → students.id (NULL if student not found)',
  `student_found`        TINYINT(1)    NOT NULL DEFAULT 0     COMMENT '1 if student record was located',

  -- Who is verifying
  `verifier_type`        ENUM('student','company') NOT NULL   COMMENT 'student or company',
  `verifier_name`        VARCHAR(200)  NOT NULL,
  `verifier_email`       VARCHAR(200)  NOT NULL,
  `verifier_phone`       VARCHAR(50)   NOT NULL,

  -- Company-specific fields (NULL for student verifiers)
  `company_name`         VARCHAR(300)  DEFAULT NULL,
  `company_address`      TEXT          DEFAULT NULL,
  `verifier_designation` VARCHAR(200)  DEFAULT NULL,

  -- Request metadata
  `ip_address`           VARCHAR(45)   DEFAULT NULL           COMMENT 'IPv4 or IPv6 of the requester',

  `created_at`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

  KEY `idx_cvl_student`      (`student_id`),
  KEY `idx_cvl_queried`      (`queried_student_id`),
  KEY `idx_cvl_type`         (`verifier_type`),
  KEY `idx_cvl_found`        (`student_found`),
  KEY `idx_cvl_created`      (`created_at`),

  CONSTRAINT `fk_cvl_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'Log of all public certificate-verification requests';

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. Register module in the access-control system
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `icon`, `sort_order`, `is_active`)
VALUES ('Cert. Verifiers', 'cert-verifiers', 'fas fa-search-plus', 56, 1);

SET FOREIGN_KEY_CHECKS = 1;
