-- Course Curriculum Module
-- Run this SQL after the departments tables have been created.

CREATE TABLE IF NOT EXISTS `course_curriculum` (
  `id`          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `program_id`  INT UNSIGNED     NOT NULL,
  `semester`    TINYINT UNSIGNED NOT NULL COMMENT '1–12  (e.g. 1 = 1st Year 1st Semester)',
  `sl_no`       TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `bnqf_code`   VARCHAR(50)      DEFAULT NULL,
  `course_code` VARCHAR(50)      DEFAULT NULL,
  `course_name` VARCHAR(300)     NOT NULL,
  `credit`      DECIMAL(4,2)     DEFAULT NULL,
  `sort_order`  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_program_semester` (`program_id`, `semester`),
  CONSTRAINT `fk_cc_program`
    FOREIGN KEY (`program_id`) REFERENCES `dept_academic_programs` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Register the module so it shows up in access control
INSERT IGNORE INTO `modules` (`name`, `slug`, `can_create`, `can_edit`, `can_delete`)
VALUES ('Course Curriculum', 'course-curriculum', 1, 1, 1);
