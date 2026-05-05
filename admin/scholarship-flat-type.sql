-- Scholarship Module – Flat Discount Type Migration
-- Run AFTER scholarship.sql

SET NAMES utf8mb4;

-- Add 'flat' to the policy type enum
ALTER TABLE `sc_policies`
    MODIFY `type` ENUM('gpa_based','merit_based','flat') NOT NULL DEFAULT 'gpa_based';

-- Allow NULL min_gpa / max_gpa for flat-type tiers (no GPA range needed)
ALTER TABLE `sc_tiers`
    MODIFY `min_gpa` DECIMAL(5,2) DEFAULT NULL COMMENT 'NULL for flat-type policies',
    MODIFY `max_gpa` DECIMAL(5,2) DEFAULT NULL COMMENT 'NULL for flat-type policies';
