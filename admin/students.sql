-- Student Management Module – SQL Schema
-- Run AFTER database.sql and departments.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. students: core student record
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `students` (
  `id`                   INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `student_id`           VARCHAR(20)   NOT NULL UNIQUE COMMENT '12-digit auto-generated or manual ID',
  `dept_id`              INT UNSIGNED  NOT NULL,
  `program_id`           INT UNSIGNED  DEFAULT NULL,
  `admitted_semester`    VARCHAR(50)   NOT NULL COMMENT 'e.g. Summer 2025',
  `full_name`            VARCHAR(200)  NOT NULL,
  `father_name`          VARCHAR(200)  DEFAULT NULL,
  `father_phone`         VARCHAR(30)   DEFAULT NULL,
  `father_occupation`    VARCHAR(200)  DEFAULT NULL,
  `father_yearly_income` DECIMAL(15,2) DEFAULT NULL,
  `mother_name`          VARCHAR(200)  DEFAULT NULL,
  `mother_phone`         VARCHAR(30)   DEFAULT NULL,
  `mother_occupation`    VARCHAR(200)  DEFAULT NULL,
  `mother_yearly_income` DECIMAL(15,2) DEFAULT NULL,
  `present_address`      TEXT          DEFAULT NULL,
  `permanent_address`    TEXT          DEFAULT NULL,
  `nationality`          VARCHAR(100)  DEFAULT NULL,
  `email`                VARCHAR(200)  DEFAULT NULL,
  `phone`                VARCHAR(30)   DEFAULT NULL,
  `place_of_birth`       VARCHAR(200)  DEFAULT NULL,
  `sex`                  ENUM('Male','Female','Other') DEFAULT NULL,
  `religion`             VARCHAR(100)  DEFAULT NULL,
  `photo`                VARCHAR(300)  DEFAULT NULL,
  `status`               ENUM('Active','Inactive','Graduated','Dropped') NOT NULL DEFAULT 'Active',
  `created_by`           INT UNSIGNED  DEFAULT NULL,
  `created_at`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_students_dept`     (`dept_id`),
  KEY `idx_students_program`  (`program_id`),
  KEY `idx_students_status`   (`status`),
  CONSTRAINT `fk_students_dept`    FOREIGN KEY (`dept_id`)    REFERENCES `dept_departments`(`id`),
  CONSTRAINT `fk_students_program` FOREIGN KEY (`program_id`) REFERENCES `dept_academic_programs`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. student_academic_qualifications
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `student_academic_qualifications` (
  `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `student_id`           INT UNSIGNED NOT NULL,
  `exam_name`            VARCHAR(200) DEFAULT NULL COMMENT 'e.g. SSC, HSC, B.Sc.',
  `session`              VARCHAR(100) DEFAULT NULL,
  `group_name`           VARCHAR(100) DEFAULT NULL,
  `board_university`     VARCHAR(200) DEFAULT NULL,
  `passing_year`         VARCHAR(20)  DEFAULT NULL,
  `division_class_grade` VARCHAR(100) DEFAULT NULL,
  `obtained_marks_gpa`   VARCHAR(100) DEFAULT NULL,
  `sort_order`           INT          NOT NULL DEFAULT 0,
  `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_qual_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. student_files: attached documents
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `student_files` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `student_id`    INT UNSIGNED  NOT NULL,
  `file_name`     VARCHAR(200)  NOT NULL,
  `description`   TEXT          DEFAULT NULL,
  `stored_name`   VARCHAR(300)  NOT NULL,
  `original_name` VARCHAR(300)  NOT NULL,
  `mime_type`     VARCHAR(100)  DEFAULT NULL,
  `file_size`     INT UNSIGNED  DEFAULT NULL,
  `uploaded_by`   INT UNSIGNED  DEFAULT NULL,
  `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_files_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. student_comments
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `student_comments` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT UNSIGNED NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL,
  `comment`    TEXT         NOT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_comments_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ─────────────────────────────────────────────────────────────────────────────
-- 5. Register module
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`)
VALUES ('Student Management', 'students', 'Manage student records, files, qualifications and comments', 'fas fa-user-graduate', 50);
