-- ============================================================================
-- Hall Management module (Controller of Examinations)
-- ============================================================================
-- Department-wise exam hall / room registry. Each hall has a room number and
-- a seat layout generated from columns (each column with its own seat
-- capacity) and a row count. Total seat capacity = sum of column capacities.
--
-- Safe to run multiple times: CREATE TABLE IF NOT EXISTS + guarded INSERT.
-- Because `.gitignore` ignores *.sql, this file is force-added to the repo.
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `hm_halls` (
  `id`             INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `dept_id`        INT(10) UNSIGNED NOT NULL COMMENT 'FK → dept_departments.id',
  `room_number`    VARCHAR(100) NOT NULL,
  `exam_date`      DATE DEFAULT NULL COMMENT 'Exam date this room is booked for',
  `exam_time`      TIME DEFAULT NULL COMMENT 'Exam start time this room is booked for',
  `num_columns`    INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of seat columns',
  `num_rows`       INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of seat rows (max seats per column)',
  `total_capacity` INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Sum of column seat capacities',
  `notes`          VARCHAR(500) DEFAULT NULL,
  `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
  `created_by`     INT(10) UNSIGNED DEFAULT NULL COMMENT 'users.id',
  `created_at`     TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  `updated_at`     TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hm_room_slot` (`dept_id`, `room_number`, `exam_date`, `exam_time`),
  KEY `idx_hm_dept` (`dept_id`),
  CONSTRAINT `fk_hm_dept` FOREIGN KEY (`dept_id`)
      REFERENCES `dept_departments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `hm_hall_columns` (
  `id`            INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `hall_id`       INT(10) UNSIGNED NOT NULL COMMENT 'FK → hm_halls.id',
  `col_no`        INT(10) UNSIGNED NOT NULL COMMENT '1-based column position',
  `seat_capacity` INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Seats in this column',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hmc_hall_col` (`hall_id`, `col_no`),
  CONSTRAINT `fk_hmc_hall` FOREIGN KEY (`hall_id`)
      REFERENCES `hm_halls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Register the module (permissions are then managed from Access Control).
INSERT INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`, `is_active`)
SELECT 'Hall Management', 'hall-management',
       'Department-wise exam hall / room registry with seat layout generator',
       'fas fa-door-open', 0, 1
 WHERE NOT EXISTS (SELECT 1 FROM `modules` WHERE `slug` = 'hall-management');
