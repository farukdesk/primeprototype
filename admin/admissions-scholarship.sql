-- Admissions Scholarship
-- Adds scholarship fields to admissions_applications so an admissions officer
-- can record a scholarship/discount for the first semester of a new applicant.

ALTER TABLE `admissions_applications`
    ADD COLUMN `scholarship_label`  VARCHAR(255)  NULL DEFAULT NULL   AFTER `financial_form_id_fee`,
    ADD COLUMN `scholarship_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `scholarship_label`;
