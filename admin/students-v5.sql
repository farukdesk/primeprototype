-- =============================================================
-- students-v5.sql
-- Extended student profile: guardian, reference, local guardian,
-- passport, marital status, waiver courses, and certificate map.
--
-- Run AFTER students.sql, students-v2.sql, students-v3.sql,
-- students-v4.sql on existing installations.
-- =============================================================

SET NAMES utf8mb4;

-- ─────────────────────────────────────────────────────────────
-- Personal detail additions
-- ─────────────────────────────────────────────────────────────
ALTER TABLE `students`
    ADD COLUMN IF NOT EXISTS `marital_status`  VARCHAR(50) DEFAULT NULL
        COMMENT 'e.g. Single, Married, Divorced, Widowed'
        AFTER `place_of_birth`,
    ADD COLUMN IF NOT EXISTS `passport_no`     VARCHAR(100) DEFAULT NULL
        COMMENT 'Passport number (optional)'
        AFTER `nid`;

-- ─────────────────────────────────────────────────────────────
-- Guardian
-- ─────────────────────────────────────────────────────────────
ALTER TABLE `students`
    ADD COLUMN IF NOT EXISTS `guardian_name`          VARCHAR(200) DEFAULT NULL
        AFTER `mother_yearly_income`,
    ADD COLUMN IF NOT EXISTS `guardian_profession`    VARCHAR(200) DEFAULT NULL
        AFTER `guardian_name`,
    ADD COLUMN IF NOT EXISTS `guardian_address`       TEXT         DEFAULT NULL
        AFTER `guardian_profession`,
    ADD COLUMN IF NOT EXISTS `guardian_phone`         VARCHAR(30)  DEFAULT NULL
        AFTER `guardian_address`,
    ADD COLUMN IF NOT EXISTS `guardian_relationship`  VARCHAR(100) DEFAULT NULL
        AFTER `guardian_phone`;

-- ─────────────────────────────────────────────────────────────
-- Reference person
-- ─────────────────────────────────────────────────────────────
ALTER TABLE `students`
    ADD COLUMN IF NOT EXISTS `reference_name`    VARCHAR(200) DEFAULT NULL
        AFTER `guardian_relationship`,
    ADD COLUMN IF NOT EXISTS `reference_address` TEXT         DEFAULT NULL
        AFTER `reference_name`,
    ADD COLUMN IF NOT EXISTS `reference_contact` VARCHAR(30)  DEFAULT NULL
        AFTER `reference_address`,
    ADD COLUMN IF NOT EXISTS `reference_email`   VARCHAR(200) DEFAULT NULL
        AFTER `reference_contact`;

-- ─────────────────────────────────────────────────────────────
-- Local guardian
-- ─────────────────────────────────────────────────────────────
ALTER TABLE `students`
    ADD COLUMN IF NOT EXISTS `local_guardian_name`    VARCHAR(200) DEFAULT NULL
        AFTER `reference_email`,
    ADD COLUMN IF NOT EXISTS `local_guardian_contact` VARCHAR(30)  DEFAULT NULL
        AFTER `local_guardian_name`,
    ADD COLUMN IF NOT EXISTS `local_guardian_address` TEXT         DEFAULT NULL
        AFTER `local_guardian_contact`,
    ADD COLUMN IF NOT EXISTS `local_guardian_email`   VARCHAR(200) DEFAULT NULL
        AFTER `local_guardian_address`;

-- ─────────────────────────────────────────────────────────────
-- Waiver courses and certificate map (from old system export)
-- ─────────────────────────────────────────────────────────────
ALTER TABLE `students`
    ADD COLUMN IF NOT EXISTS `waiver_courses`      TEXT         DEFAULT NULL
        COMMENT 'JSON array of waiver course objects imported from old system'
        AFTER `waiver_amount`,
    ADD COLUMN IF NOT EXISTS `total_waiver_credits` DECIMAL(5,2) DEFAULT NULL
        COMMENT 'Total credit hours waived'
        AFTER `waiver_courses`,
    ADD COLUMN IF NOT EXISTS `certificate_map`     TEXT         DEFAULT NULL
        COMMENT 'JSON array [{exam, filename}] – maps exam name to certificate file for bulk file import'
        AFTER `total_waiver_credits`;
