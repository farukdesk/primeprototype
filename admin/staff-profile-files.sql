-- ============================================================================
-- Employee Profiles – titled file attachments (Issues/Complaints, Evaluations)
-- ============================================================================
-- Stores files uploaded against an employee profile, each with a title:
--   • category 'issue'      – Issues / Complaints file
--   • category 'evaluation' – Evaluation Record file
--
-- Files live under uploads/staff-profile-files/. Managed from the Employee
-- Profile view page (admin/staff-profiles/view.php).
--
-- Safe to run multiple times. Because `.gitignore` ignores *.sql, this file is
-- force-added to the repo. The table is also auto-created at runtime by
-- sp_ensure_files_table().
-- ============================================================================

CREATE TABLE IF NOT EXISTS `staff_profile_files` (
    `id`            int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`       int(10) UNSIGNED NOT NULL COMMENT 'FK users.id – the employee',
    `category`      enum('issue','evaluation') NOT NULL,
    `title`         varchar(255) NOT NULL,
    `file_name`     varchar(255) NOT NULL COMMENT 'Stored filename under uploads/staff-profile-files/',
    `original_name` varchar(255) DEFAULT NULL,
    `mime_type`     varchar(100) DEFAULT NULL,
    `file_size`     int(10) UNSIGNED DEFAULT NULL COMMENT 'Bytes',
    `uploaded_by`   int(10) UNSIGNED DEFAULT NULL COMMENT 'FK users.id – uploader',
    `created_at`    datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_spf_user_cat` (`user_id`, `category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
