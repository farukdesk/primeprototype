-- ============================================================================
-- Fix: Snapshot program start months to student packages
-- ============================================================================
-- Problem: accounting month slots were still reading live cf_programs
--          bi/tri start months for assigned students.
--          Editing a program start month retroactively shifted existing
--          students in Collection Payment / Accounting.
--
-- Solution: store bi/tri semester start months directly on sfp_packages
--           and backfill existing rows from the current linked program.
--
-- IMPORTANT: Run this BEFORE changing course-fee start months in production
--            so currently assigned students keep their existing schedule.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

SET @dbname = DATABASE();
SET @tablename = 'sfp_packages';

SET @columnname = 'bi_semester_start_month';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  "SELECT 'bi_semester_start_month column already exists' AS status",
  CONCAT(
    "ALTER TABLE ", @tablename,
    " ADD COLUMN ", @columnname,
    " TINYINT UNSIGNED DEFAULT NULL COMMENT 'Snapshotted bi-semester start month from cf_programs' AFTER months_per_semester"
  )
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

SET @columnname = 'tri_semester_start_month';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  "SELECT 'tri_semester_start_month column already exists' AS status",
  CONCAT(
    "ALTER TABLE ", @tablename,
    " ADD COLUMN ", @columnname,
    " TINYINT UNSIGNED DEFAULT NULL COMMENT 'Snapshotted tri-semester start month from cf_programs' AFTER bi_semester_start_month"
  )
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

UPDATE `sfp_packages` p
LEFT JOIN `cf_programs` cp ON cp.`id` = p.`cf_program_id`
SET p.`bi_semester_start_month`  = COALESCE(NULLIF(p.`bi_semester_start_month`, 0), cp.`bi_semester_start_month`),
    p.`tri_semester_start_month` = COALESCE(NULLIF(p.`tri_semester_start_month`, 0), cp.`tri_semester_start_month`)
WHERE p.`cf_program_id` IS NOT NULL
  AND (
      p.`bi_semester_start_month` IS NULL OR p.`bi_semester_start_month` = 0
      OR p.`tri_semester_start_month` IS NULL OR p.`tri_semester_start_month` = 0
  );

SET FOREIGN_KEY_CHECKS = 1;
