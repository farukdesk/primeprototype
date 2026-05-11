-- Admissions Financial Package Snapshot
-- Adds financial package assignment fields to admissions_applications

ALTER TABLE `admissions_applications`
    ADD COLUMN `financial_package_id` INT UNSIGNED NULL AFTER `office_checked_by`,
    ADD COLUMN `financial_package_name` VARCHAR(255) NULL AFTER `financial_package_id`,
    ADD COLUMN `financial_total_semesters` SMALLINT UNSIGNED NULL AFTER `financial_package_name`,
    ADD COLUMN `financial_total_months` SMALLINT UNSIGNED NULL AFTER `financial_total_semesters`,
    ADD COLUMN `financial_tuition_per_semester` DECIMAL(12,2) NULL AFTER `financial_total_months`,
    ADD COLUMN `financial_admission_fee` DECIMAL(12,2) NULL AFTER `financial_tuition_per_semester`,
    ADD COLUMN `financial_registration_fee_per_semester` DECIMAL(12,2) NULL AFTER `financial_admission_fee`,
    ADD COLUMN `financial_fixed_institutional_fees` DECIMAL(12,2) NULL AFTER `financial_registration_fee_per_semester`,
    ADD COLUMN `financial_english_course_fee` DECIMAL(12,2) NULL AFTER `financial_fixed_institutional_fees`,
    ADD COLUMN `financial_form_id_fee` DECIMAL(12,2) NULL AFTER `financial_english_course_fee`,
    ADD KEY `idx_financial_package` (`financial_package_id`),
    ADD CONSTRAINT `fk_adm_financial_package`
        FOREIGN KEY (`financial_package_id`) REFERENCES `cf_programs` (`id`) ON DELETE SET NULL;
