-- ============================================================
-- Exam Invigilation – Remuneration & Attendance
-- Run after exam-invigilation.sql (and its patch files)
-- ============================================================

SET NAMES utf8mb4;

-- Per-slot remuneration amount stored on the faculty record
ALTER TABLE `ei_faculty`
    ADD COLUMN IF NOT EXISTS `remuneration_per_slot` decimal(10,2) UNSIGNED NOT NULL DEFAULT 0.00
        COMMENT 'Amount paid per attended invigilation slot'
        AFTER `contact_number`;

-- Slot-level attendance: one row per (slot, faculty) pair
CREATE TABLE IF NOT EXISTS `ei_slot_attendance` (
  `id`          int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `exam_id`     int(10) UNSIGNED NOT NULL,
  `slot_id`     int(10) UNSIGNED NOT NULL,
  `faculty_id`  int(10) UNSIGNED NOT NULL,
  `attended`    tinyint(1)       NOT NULL DEFAULT 0
                COMMENT '1 = attended, 0 = absent',
  `notes`       varchar(300)     DEFAULT NULL,
  `created_at`  datetime         NOT NULL DEFAULT current_timestamp(),
  `updated_at`  datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attendance` (`slot_id`, `faculty_id`),
  KEY `idx_eia_exam`    (`exam_id`),
  KEY `idx_eia_slot`    (`slot_id`),
  KEY `idx_eia_faculty` (`faculty_id`),
  CONSTRAINT `fk_eia_exam`    FOREIGN KEY (`exam_id`)    REFERENCES `ei_exams`   (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_eia_slot`    FOREIGN KEY (`slot_id`)    REFERENCES `ei_slots`   (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_eia_faculty` FOREIGN KEY (`faculty_id`) REFERENCES `ei_faculty` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
