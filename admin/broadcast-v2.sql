-- ============================================================
-- Broadcast Module v2 – Approval Workflow
-- Run this file after broadcast.sql
-- ============================================================

-- Extend the status enum to include approval states
ALTER TABLE `broadcasts`
    MODIFY COLUMN `status` ENUM('draft','sent','partial','pending_approval','rejected') NOT NULL DEFAULT 'draft';

-- Add review/approval tracking columns
ALTER TABLE `broadcasts`
    ADD COLUMN `review_note`  VARCHAR(500)  DEFAULT NULL  COMMENT 'Rejection reason from reviewer'  AFTER `status`,
    ADD COLUMN `reviewed_by`  INT UNSIGNED  DEFAULT NULL  COMMENT 'FK users.id – who approved/rejected' AFTER `review_note`,
    ADD COLUMN `reviewed_at`  DATETIME      DEFAULT NULL  COMMENT 'When approval/rejection happened'  AFTER `reviewed_by`,
    ADD CONSTRAINT `fk_bc_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;
