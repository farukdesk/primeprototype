-- Student Accounts: project fee
-- Adds a project_fee column to student fee packages.
-- Run once BEFORE using the bulk edit "Project Fee" field on
-- admin/student-accounts/index.php.

ALTER TABLE sfp_packages
    ADD COLUMN project_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER english_course_fee;
