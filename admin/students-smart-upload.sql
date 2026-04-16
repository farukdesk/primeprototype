-- Smart PDF Upload – Student PDF Pending Queue
-- Run AFTER students.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Stores PDFs whose student ID could not be auto-detected or was ambiguous.
-- Admin assigns each pending PDF to a student via pending-assign.php.
CREATE TABLE IF NOT EXISTS `student_pdf_pending` (
  `id`                   INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `batch_token`          VARCHAR(64)   NOT NULL COMMENT 'Groups PDFs from one upload session',
  `original_name`        VARCHAR(300)  NOT NULL COMMENT 'Original filename as uploaded',
  `stored_name`          VARCHAR(300)  NOT NULL COMMENT 'Randomly-named file in uploads/students/pending/',
  `file_size`            INT UNSIGNED  NOT NULL DEFAULT 0,
  `extracted_text`       MEDIUMTEXT    DEFAULT NULL COMMENT 'Text extracted from PDF',
  `candidate_ids`        TEXT          DEFAULT NULL COMMENT 'JSON array of digit sequences found in text',
  `file_label`           VARCHAR(200)  NOT NULL DEFAULT 'Student Document',
  `description`          TEXT          DEFAULT NULL,
  `status`               ENUM('pending','assigned','dismissed') NOT NULL DEFAULT 'pending',
  `assigned_student_pk`  INT UNSIGNED  DEFAULT NULL COMMENT 'students.id after manual assignment',
  `uploaded_by`          INT UNSIGNED  NOT NULL,
  `created_at`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_spp_batch`    (`batch_token`),
  KEY `idx_spp_status`   (`status`),
  CONSTRAINT `fk_spp_student` FOREIGN KEY (`assigned_student_pk`)
      REFERENCES `students`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_spp_uploader` FOREIGN KEY (`uploaded_by`)
      REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'PDFs awaiting manual student assignment after Smart Upload';

SET FOREIGN_KEY_CHECKS = 1;
