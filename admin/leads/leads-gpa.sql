-- Lead Management – GPA/Education Fields Migration
-- Run AFTER leads-followup.sql

SET NAMES utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- Add SSC/HSC GPA and Bachelor fields (matching admin_67crm schema)
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE `leads`
  ADD COLUMN IF NOT EXISTS `ssc_gpa`          DECIMAL(3,2) DEFAULT NULL
    COMMENT 'SSC / O-Level GPA'
    AFTER `preferred_semester`,
  ADD COLUMN IF NOT EXISTS `hsc_gpa`          DECIMAL(3,2) DEFAULT NULL
    COMMENT 'HSC / A-Level GPA'
    AFTER `ssc_gpa`,
  ADD COLUMN IF NOT EXISTS `bachelor_subject`  VARCHAR(255) DEFAULT NULL
    COMMENT 'Bachelor degree subject (for Master applicants)'
    AFTER `hsc_gpa`,
  ADD COLUMN IF NOT EXISTS `bachelor_cgpa`    DECIMAL(3,2) DEFAULT NULL
    COMMENT 'Bachelor CGPA (for Master applicants)'
    AFTER `bachelor_subject`;
