-- Course Offer: approval workflow for student self-registrations
-- ================================================================
-- Adds an approval status to course registrations. Existing rows keep
-- working (default 'approved'); self-registrations submitted from the
-- student portal / mobile app are created as 'pending' and must be
-- approved in Course Offer -> Registrations by anyone with course-offer
-- access scoped to the offer's department.

ALTER TABLE `co_registrations`
    ADD COLUMN `status` ENUM('pending','approved') NOT NULL DEFAULT 'approved' AFTER `source`,
    ADD COLUMN `approved_by` INT UNSIGNED DEFAULT NULL AFTER `status`,
    ADD COLUMN `approved_at` DATETIME DEFAULT NULL AFTER `approved_by`;

ALTER TABLE `co_registrations`
    ADD KEY `idx_co_reg_status` (`status`);
