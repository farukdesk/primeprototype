-- Fake / Invalid ID Verifications – SQL Migration
-- Run AFTER student-verification.sql

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `fake_id_verifications` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `student_id`   VARCHAR(100)  NOT NULL  COMMENT 'Presented (fake) student ID',
  `student_name` VARCHAR(200)  NOT NULL  COMMENT 'Presented name on fake document',
  `to_email`     VARCHAR(254)  DEFAULT NULL COMMENT 'Recipient email',
  `to_name`      VARCHAR(200)  DEFAULT NULL,
  `notes`        TEXT          DEFAULT NULL,
  `ref_no`       VARCHAR(100)  DEFAULT NULL,
  `email_sent`   TINYINT(1)    NOT NULL DEFAULT 0,
  `checked_by`   INT UNSIGNED  NOT NULL  COMMENT 'FK → users.id',
  `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_fiv_checker` (`checked_by`),
  KEY `idx_fiv_created` (`created_at`),
  CONSTRAINT `fk_fiv_user` FOREIGN KEY (`checked_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
