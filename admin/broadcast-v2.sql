-- ============================================================
-- Broadcast Module v2 – Approval Workflow
-- Run this file after broadcast.sql (existing installations only)
-- ============================================================

-- Extend the status enum to include approval states
ALTER TABLE `broadcasts`
    MODIFY COLUMN `status` ENUM('draft','sent','partial','pending_approval','rejected') NOT NULL DEFAULT 'draft';

-- Add review/approval tracking columns
ALTER TABLE `broadcasts`
    ADD COLUMN IF NOT EXISTS `review_note`  VARCHAR(500)  DEFAULT NULL  COMMENT 'Rejection reason from reviewer'  AFTER `status`,
    ADD COLUMN IF NOT EXISTS `reviewed_by`  INT UNSIGNED  DEFAULT NULL  COMMENT 'FK users.id – who approved/rejected' AFTER `review_note`,
    ADD COLUMN IF NOT EXISTS `reviewed_at`  DATETIME      DEFAULT NULL  COMMENT 'When approval/rejection happened'  AFTER `reviewed_by`;

-- Add FK for reviewed_by if not already present
SET @fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'broadcasts'
      AND CONSTRAINT_NAME = 'fk_bc_reviewed_by'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `broadcasts` ADD CONSTRAINT `fk_bc_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
