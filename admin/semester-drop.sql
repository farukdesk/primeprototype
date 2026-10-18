-- =============================================================================
-- Semester Drop
-- =============================================================================
-- Records that a student is taking a fixed-length study break (semester drop).
-- During the blocked window the student's monthly tuition is deferred (pushed
-- forward past the drop) rather than waived.
--
--   BI  semester drop -> 6 months blocked
--   TRI semester drop -> 4 months blocked
--
-- This is the migration for the `semester_drops` table used by the
-- admin/semester-drop/ module (index.php, create.php, cancel.php, view.php,
-- helpers.php).
--
-- Idempotent: safe to run more than once.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `semester_drops` (
  `id`               int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_id`       int(10) UNSIGNED NOT NULL,
  `semester_type`    enum('bi','tri') NOT NULL DEFAULT 'bi',
  `block_months`     tinyint(3) UNSIGNED NOT NULL DEFAULT 6 COMMENT 'Blocked months: bi=6, tri=4',
  `drop_start`       date NOT NULL COMMENT 'First blocked day (Y-m-d)',
  `drop_end`         date NOT NULL COMMENT 'Inclusive last blocked day (Y-m-d)',
  `reason`           text DEFAULT NULL,
  `evidence_file_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK student_files.id',
  `status`           enum('active','cancelled') NOT NULL DEFAULT 'active',
  `created_by`       int(10) UNSIGNED DEFAULT NULL COMMENT 'FK users.id',
  `created_at`       datetime NOT NULL DEFAULT current_timestamp(),
  `cancelled_by`     int(10) UNSIGNED DEFAULT NULL COMMENT 'FK users.id',
  `cancelled_at`     datetime DEFAULT NULL,
  `cancel_reason`    text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sd_student` (`student_id`),
  KEY `idx_sd_status`  (`status`),
  KEY `idx_sd_window`  (`student_id`, `status`, `drop_start`, `drop_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
