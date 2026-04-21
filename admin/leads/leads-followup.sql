-- Lead Management – Follow-up & Status Enhancement Migration
-- Run AFTER leads.sql and leads-call-time.sql

SET NAMES utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. Add follow-up tracking columns to leads
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE `leads`
  ADD COLUMN IF NOT EXISTS `next_followup_date` DATE DEFAULT NULL
    COMMENT 'Scheduled next follow-up date'
    AFTER `preferred_call_time`,
  ADD COLUMN IF NOT EXISTS `followup_notes` VARCHAR(500) DEFAULT NULL
    COMMENT 'Notes about the next follow-up'
    AFTER `next_followup_date`;

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. Extend status ENUM with granular pipeline statuses
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE `leads`
  MODIFY COLUMN `status` ENUM(
    'fresh',
    '1st_call',
    '2nd_call',
    '3rd_call',
    'unable_to_reach',
    'interested',
    'not_interested',
    'will_visit',
    'converted'
  ) NOT NULL DEFAULT 'fresh';

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. Add facebook to source ENUM (if not already present)
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE `leads`
  MODIFY COLUMN `source` ENUM(
    'online',
    'campus_visit',
    'agent',
    'f2f_marketing',
    'facebook'
  ) NOT NULL DEFAULT 'online';

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. Index for fast follow-up date queries
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE `leads`
  ADD INDEX IF NOT EXISTS `idx_leads_followup` (`next_followup_date`);
