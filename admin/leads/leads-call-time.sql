-- Migration: Add preferred_call_time to leads table
-- Run AFTER leads.sql

ALTER TABLE `leads`
  ADD COLUMN `preferred_call_time` VARCHAR(50) DEFAULT NULL
    COMMENT 'e.g. Morning (9 AM – 12 PM)'
    AFTER `preferred_semester`;
