-- ============================================================
-- Broadcast Module v4 – Acknowledgment Support
-- Run this file after broadcast-v3.sql (existing installations only)
-- ============================================================

-- Add acknowledgment requirement flag to broadcasts
ALTER TABLE `broadcasts`
    ADD COLUMN IF NOT EXISTS `ack_required` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = broadcast requires recipient acknowledgment'
        AFTER `student_semester`;

-- Add per-recipient acknowledgment tracking columns
ALTER TABLE `broadcast_recipients`
    ADD COLUMN IF NOT EXISTS `ack_token`      VARCHAR(64)  DEFAULT NULL UNIQUE COMMENT 'One-time acknowledgment token',
    ADD COLUMN IF NOT EXISTS `acked_at`       DATETIME     DEFAULT NULL COMMENT 'When the recipient acknowledged',
    ADD COLUMN IF NOT EXISTS `ack_ip`         VARCHAR(45)  DEFAULT NULL COMMENT 'IP address at acknowledgment time',
    ADD COLUMN IF NOT EXISTS `ack_department` VARCHAR(150) DEFAULT NULL COMMENT 'Department/group of recipient at ack time';

-- Index for fast token lookups
ALTER TABLE `broadcast_recipients`
    ADD INDEX IF NOT EXISTS `idx_ack_token` (`ack_token`);
