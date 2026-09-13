-- ---------------------------------------------------------------------------
-- Third-party Results API (v1) — audit column on student_results
--
-- POST admin/api/v1/results/create.php publishes final results (CGPA) into
-- `student_results`, the table read by certificate-verification.php and
-- written by the admin "Final Result Publish" import.  This migration only
-- adds a nullable column recording which partner application wrote a row.
--
-- Requires: admin/student-api-clients-v1.sql (api_clients table).
-- Run this once against the application database.
-- ---------------------------------------------------------------------------

ALTER TABLE `student_results`
    ADD COLUMN `api_client_id` INT UNSIGNED DEFAULT NULL
        COMMENT 'api_clients.id when the row was created/updated through the third-party API',
    ADD INDEX `idx_student_results_api_client` (`api_client_id`);
