-- ---------------------------------------------------------------------------
-- GPA Scholarship module
-- ---------------------------------------------------------------------------
-- Configurable GPA + Attendance scholarship setup that evaluates invigilation
-- exams (ei_exams) and writes idempotent, module-owned scholarship rows against
-- target sfp_semester_fees rows.
--
-- NOTE: *.sql is gitignored in this repository. Commit with: git add -f
-- admin/gpa-scholarship-v1.sql
-- ---------------------------------------------------------------------------

INSERT INTO modules (name, slug, description, icon, sort_order, is_active, can_view, can_create, can_edit, can_delete)
SELECT 'GPA Scholarship',
       'gpa-scholarship',
       'Invigilation-exam GPA scholarship setup with attendance rules and idempotent semester fee application.',
       'fas fa-award',
       0,
       1,
       1,
       1,
       1,
       1
WHERE NOT EXISTS (SELECT 1 FROM modules WHERE slug = 'gpa-scholarship');

CREATE TABLE IF NOT EXISTS `gpa_scholarship_configs` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(180) NOT NULL,
  `exam_id` int(10) UNSIGNED NOT NULL,
  `dept_id` int(10) UNSIGNED NOT NULL,
  `program_id` int(10) UNSIGNED DEFAULT NULL,
  `batch_id` int(10) UNSIGNED DEFAULT NULL,
  `require_merit_payment_type` tinyint(1) NOT NULL DEFAULT 1,
  `combine_mode` enum('gpa_only','attendance_only','min','max') NOT NULL DEFAULT 'gpa_only',
  `require_gpa_tier` tinyint(1) NOT NULL DEFAULT 1,
  `require_attendance_tier` tinyint(1) NOT NULL DEFAULT 0,
  `attendance_missing_policy` enum('exclude','ignore') NOT NULL DEFAULT 'exclude',
  `attendance_semester` varchar(80) DEFAULT NULL,
  `target_semester_number` tinyint(3) UNSIGNED NOT NULL DEFAULT 2,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `updated_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_gps_exam` (`exam_id`),
  KEY `idx_gps_scope` (`dept_id`,`program_id`,`batch_id`),
  KEY `idx_gps_active` (`is_active`),
  CONSTRAINT `fk_gps_exam` FOREIGN KEY (`exam_id`) REFERENCES `ei_exams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_gps_dept` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_gps_program` FOREIGN KEY (`program_id`) REFERENCES `dept_academic_programs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_gps_batch` FOREIGN KEY (`batch_id`) REFERENCES `student_batches` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gpa_scholarship_tiers` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `config_id` int(10) UNSIGNED NOT NULL,
  `metric` enum('gpa','attendance') NOT NULL,
  `label` varchar(120) DEFAULT NULL,
  `min_value` decimal(6,2) NOT NULL,
  `max_value` decimal(6,2) NOT NULL,
  `scholarship_percent` decimal(5,2) NOT NULL,
  `priority` smallint(5) UNSIGNED NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_gpst_config_metric` (`config_id`,`metric`),
  KEY `idx_gpst_priority` (`priority`),
  CONSTRAINT `fk_gpst_config` FOREIGN KEY (`config_id`) REFERENCES `gpa_scholarship_configs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gpa_scholarship_awards` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `config_id` int(10) UNSIGNED NOT NULL,
  `exam_id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL,
  `package_id` int(10) UNSIGNED DEFAULT NULL,
  `target_sf_id` int(10) UNSIGNED DEFAULT NULL,
  `target_semester_number` tinyint(3) UNSIGNED NOT NULL,
  `payment_type` varchar(20) DEFAULT NULL,
  `semester_gpa` decimal(5,2) DEFAULT NULL,
  `attendance_pct` decimal(5,2) DEFAULT NULL,
  `matched_gpa_tier_id` int(10) UNSIGNED DEFAULT NULL,
  `matched_attendance_tier_id` int(10) UNSIGNED DEFAULT NULL,
  `final_percent` decimal(5,2) DEFAULT NULL,
  `final_amount` decimal(10,2) DEFAULT NULL,
  `status` enum('previewed','applied','skipped','failed','reversed') NOT NULL DEFAULT 'previewed',
  `exclusion_reason` varchar(255) DEFAULT NULL,
  `source_key` varchar(255) NOT NULL,
  `scholarship_row_id` int(10) UNSIGNED DEFAULT NULL,
  `rule_snapshot_json` longtext DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `previewed_at` datetime DEFAULT NULL,
  `applied_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gpsa_scope` (`config_id`,`exam_id`,`student_id`,`target_semester_number`),
  UNIQUE KEY `uq_gpsa_source_key` (`source_key`),
  KEY `idx_gpsa_status` (`status`),
  KEY `idx_gpsa_student` (`student_id`),
  KEY `idx_gpsa_target_sf` (`target_sf_id`),
  CONSTRAINT `fk_gpsa_config` FOREIGN KEY (`config_id`) REFERENCES `gpa_scholarship_configs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_gpsa_exam` FOREIGN KEY (`exam_id`) REFERENCES `ei_exams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_gpsa_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_gpsa_package` FOREIGN KEY (`package_id`) REFERENCES `sfp_packages` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_gpsa_target_sf` FOREIGN KEY (`target_sf_id`) REFERENCES `sfp_semester_fees` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_gpsa_gpa_tier` FOREIGN KEY (`matched_gpa_tier_id`) REFERENCES `gpa_scholarship_tiers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_gpsa_att_tier` FOREIGN KEY (`matched_attendance_tier_id`) REFERENCES `gpa_scholarship_tiers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_gpsa_sfp_row` FOREIGN KEY (`scholarship_row_id`) REFERENCES `sfp_semester_scholarships` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
