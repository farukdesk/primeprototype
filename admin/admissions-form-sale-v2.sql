-- ============================================================
-- Admissions – Form Sale Token v2
-- Adds extra student details columns and academic records table.
-- Run this file after admissions-form-sale-token.sql
-- ============================================================

-- ── Add new columns to adm_form_sale_student_details ─────────────────────────
ALTER TABLE `adm_form_sale_student_details`
    ADD COLUMN IF NOT EXISTS `experience`              TEXT          NULL AFTER `present_post_code`,
    ADD COLUMN IF NOT EXISTS `guardian_name`           VARCHAR(255)  NULL AFTER `experience`,
    ADD COLUMN IF NOT EXISTS `guardian_profession`     VARCHAR(255)  NULL AFTER `guardian_name`,
    ADD COLUMN IF NOT EXISTS `guardian_relationship`   VARCHAR(100)  NULL AFTER `guardian_profession`,
    ADD COLUMN IF NOT EXISTS `guardian_monthly_income` VARCHAR(100)  NULL AFTER `guardian_relationship`,
    ADD COLUMN IF NOT EXISTS `guardian_address_1`      VARCHAR(255)  NULL AFTER `guardian_monthly_income`,
    ADD COLUMN IF NOT EXISTS `guardian_address_2`      VARCHAR(255)  NULL AFTER `guardian_address_1`,
    ADD COLUMN IF NOT EXISTS `guardian_phone`          VARCHAR(50)   NULL AFTER `guardian_address_2`,
    ADD COLUMN IF NOT EXISTS `guardian_email`          VARCHAR(255)  NULL AFTER `guardian_phone`,
    ADD COLUMN IF NOT EXISTS `local_guardian_name`     VARCHAR(255)  NULL AFTER `guardian_email`,
    ADD COLUMN IF NOT EXISTS `local_guardian_address_1` VARCHAR(255) NULL AFTER `local_guardian_name`,
    ADD COLUMN IF NOT EXISTS `local_guardian_address_2` VARCHAR(255) NULL AFTER `local_guardian_address_1`,
    ADD COLUMN IF NOT EXISTS `local_guardian_address_3` VARCHAR(255) NULL AFTER `local_guardian_address_2`,
    ADD COLUMN IF NOT EXISTS `local_guardian_contact`  VARCHAR(50)   NULL AFTER `local_guardian_address_3`,
    ADD COLUMN IF NOT EXISTS `reference_name`          VARCHAR(255)  NULL AFTER `local_guardian_contact`,
    ADD COLUMN IF NOT EXISTS `reference_address_1`     VARCHAR(255)  NULL AFTER `reference_name`,
    ADD COLUMN IF NOT EXISTS `reference_address_2`     VARCHAR(255)  NULL AFTER `reference_address_1`,
    ADD COLUMN IF NOT EXISTS `reference_address_3`     VARCHAR(255)  NULL AFTER `reference_address_2`,
    ADD COLUMN IF NOT EXISTS `reference_contact`       VARCHAR(50)   NULL AFTER `reference_address_3`;

-- ── Academic qualification rows linked to a form sale ─────────────────────────
CREATE TABLE IF NOT EXISTS `adm_form_sale_academic_records` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `form_sale_id`    INT UNSIGNED NOT NULL,
    `exam_name`       VARCHAR(255) NULL,
    `session`         VARCHAR(50)  NULL,
    `group_name`      VARCHAR(100) NULL,
    `board_university` VARCHAR(255) NULL,
    `year_of_passing` VARCHAR(10)  NULL,
    `division_grade`  VARCHAR(100) NULL,
    `total_marks_cgpa` VARCHAR(100) NULL,
    `sort_order`      SMALLINT     NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_fsar_form_sale` (`form_sale_id`),
    CONSTRAINT `fk_fsar_form_sale`
        FOREIGN KEY (`form_sale_id`) REFERENCES `adm_form_sales` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
