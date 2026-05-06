-- Fix: Ensure admission_fees column exists in sfp_packages table
-- This migration adds the admission_fees column if it doesn't exist
-- Run this if "Admission Fee (one-time)" is not showing on student-accounts/view.php

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Add admission_fees column if it doesn't exist
-- Note: This uses a procedure because MySQL doesn't support IF NOT EXISTS for columns
SET @dbname = DATABASE();
SET @tablename = "sfp_packages";
SET @columnname = "admission_fees";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  "SELECT 1",
  CONCAT("ALTER TABLE ", @tablename, " ADD COLUMN ", @columnname, " INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'One-time admission day cost; already paid separately – stored for reference' AFTER tuition_per_semester")
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

SET FOREIGN_KEY_CHECKS = 1;
