-- ---------------------------------------------------------------------------
-- Admissions — Student Source (Promoter & Prime Student)
--
-- Adds two optional "student source" declarations to each admission
-- application:
--   * Promoter source  — whether the student was referred by a promoter, and
--                        the promoter's contact details.
--   * Prime Student    — whether the applicant is an existing Prime student,
--                        and their existing Student ID / Department / Program.
-- A shared free-text note applies to the whole section.
--
-- Run this once against the application database.
-- ---------------------------------------------------------------------------

ALTER TABLE `admissions_applications`
    ADD COLUMN `promoter_source` enum('No','Yes') NOT NULL DEFAULT 'No'
        COMMENT 'Was the student referred by a source promoter?'
        AFTER `office_checked_by`,
    ADD COLUMN `promoter_name` varchar(255) DEFAULT NULL AFTER `promoter_source`,
    ADD COLUMN `promoter_address` varchar(255) DEFAULT NULL AFTER `promoter_name`,
    ADD COLUMN `promoter_contact` varchar(50) DEFAULT NULL AFTER `promoter_address`,
    ADD COLUMN `promoter_email` varchar(255) DEFAULT NULL AFTER `promoter_contact`,
    ADD COLUMN `prime_student` enum('No','Yes') NOT NULL DEFAULT 'No'
        COMMENT 'Is the applicant an existing Prime student?'
        AFTER `promoter_email`,
    ADD COLUMN `prime_student_id` varchar(50) DEFAULT NULL AFTER `prime_student`,
    ADD COLUMN `prime_department` varchar(255) DEFAULT NULL AFTER `prime_student_id`,
    ADD COLUMN `prime_program` varchar(255) DEFAULT NULL AFTER `prime_department`,
    ADD COLUMN `source_note` text DEFAULT NULL
        COMMENT 'Free-text note for the student source section'
        AFTER `prime_program`;
