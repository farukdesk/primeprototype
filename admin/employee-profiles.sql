-- ============================================================================
-- Employee Profiles – extend staff_profiles with HR/personal fields and add
-- child tables for academic qualifications and work experiences.
-- ============================================================================
-- The "Staff Profiles" module was renamed to "Employee Profiles" in the UI.
-- The underlying tables keep the historical `staff_*` names for backward
-- compatibility (module slug `staff-profile`, table `staff_profiles`).
--
-- This migration adds the extra profile fields requested for administrative
-- (non-academic) employees:
--   Finger ID, Father/Mother name, Job Type (Category), Gender, Religion,
--   Blood Group, National ID, Date of Birth, Joining Date, Nationality,
--   Birth Place and Employee Status.
--
-- Faculty (academic) employees keep their extended details in faculty_profiles.
--
-- Safe to run multiple times (uses IF NOT EXISTS – MariaDB 10.x).
-- ============================================================================

-- ── Extra columns on staff_profiles ─────────────────────────────────────────
ALTER TABLE `staff_profiles`
    ADD COLUMN IF NOT EXISTS `finger_id`       varchar(100) DEFAULT NULL COMMENT 'Biometric / finger device ID',
    ADD COLUMN IF NOT EXISTS `father_name`     varchar(200) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `mother_name`     varchar(200) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `job_type`        enum('Permanent','Contractual','Ad-hoc','Master Role','Daily Basis','Probationary') DEFAULT NULL COMMENT 'Employment category',
    ADD COLUMN IF NOT EXISTS `gender`          enum('Male','Female','Other') DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `religion`        varchar(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `blood_group`     varchar(10)  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `national_id`     varchar(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `date_of_birth`   date         DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `joining_date`    date         DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `nationality`     varchar(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `birth_place`     varchar(200) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `employee_status` enum('Active','Inactive','On Leave','Study Leave','Closed') NOT NULL DEFAULT 'Active';

-- ── Academic qualifications (Degree / Group / Board-University / …) ──────────
CREATE TABLE IF NOT EXISTS `staff_qualifications` (
    `id`               int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`          int(10) UNSIGNED NOT NULL,
    `degree`           varchar(200) DEFAULT NULL,
    `group_name`       varchar(200) DEFAULT NULL COMMENT 'Group / Major / Subject',
    `board_university` varchar(200) DEFAULT NULL,
    `passing_year`     varchar(20)  DEFAULT NULL,
    `grade`            varchar(50)  DEFAULT NULL,
    `gpa_result`       varchar(50)  DEFAULT NULL COMMENT 'GPA / CGPA / Result',
    `sort_order`       smallint(5) UNSIGNED NOT NULL DEFAULT 0,
    `created_at`       datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_sq_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── Work experiences (Position / Organization / Department / dates) ─────────
CREATE TABLE IF NOT EXISTS `staff_experiences` (
    `id`            int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`       int(10) UNSIGNED NOT NULL,
    `position`      varchar(200) DEFAULT NULL,
    `organization`  varchar(200) DEFAULT NULL,
    `department`    varchar(200) DEFAULT NULL,
    `joining_date`  date DEFAULT NULL,
    `resign_date`   date DEFAULT NULL,
    `sort_order`    smallint(5) UNSIGNED NOT NULL DEFAULT 0,
    `created_at`    datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_se_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
