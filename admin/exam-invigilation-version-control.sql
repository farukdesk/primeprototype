-- ============================================================
-- Exam Invigilation – Version Control / Assignment History
-- Run after exam-invigilation.sql
-- ============================================================

SET NAMES utf8mb4;

-- Snapshot header: one row per saved state (captured after each operation)
CREATE TABLE IF NOT EXISTS `ei_assignment_snapshots` (
  `id`               int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `exam_id`          int(10) UNSIGNED NOT NULL,
  `version_number`   int(10) UNSIGNED NOT NULL  COMMENT 'Sequential per-exam version number',
  `change_type`      varchar(50)      NOT NULL   COMMENT 'auto_assign, manual_edit, clear_slot, revert',
  `change_summary`   varchar(500)     DEFAULT NULL,
  `changed_by_id`    int(10) UNSIGNED DEFAULT NULL,
  `changed_by_name`  varchar(200)     DEFAULT NULL,
  `slots_count`      int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Total slots in snapshot',
  `created_at`       datetime         NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ei_snap_exam_ver` (`exam_id`, `version_number`),
  KEY `idx_ei_snap_exam` (`exam_id`),
  CONSTRAINT `fk_ei_snap_exam` FOREIGN KEY (`exam_id`)
      REFERENCES `ei_exams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-slot state captured inside a snapshot
CREATE TABLE IF NOT EXISTS `ei_assignment_snapshot_slots` (
  `id`           int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `snapshot_id`  int(10) UNSIGNED NOT NULL,
  `slot_id`      int(10) UNSIGNED NOT NULL,
  `faculty1_id`  int(10) UNSIGNED DEFAULT NULL,
  `faculty2_id`  int(10) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ei_ss_snap_slot` (`snapshot_id`, `slot_id`),
  KEY `idx_ei_ss_snapshot` (`snapshot_id`),
  KEY `idx_ei_ss_slot` (`slot_id`),
  CONSTRAINT `fk_ei_ss_snapshot` FOREIGN KEY (`snapshot_id`)
      REFERENCES `ei_assignment_snapshots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
