-- ============================================================================
-- Migration v2: Snapshot programme start months on existing student packages
-- ============================================================================
-- Problem: sfp_packages rows that were created before bi/tri semester start
--          month columns existed (or when the programme had no start month
--          set) have NULL in those columns.  Because acc_package_start_month()
--          falls back to the *live* cf_programs value when the snapshot is
--          NULL/0, editing a course-fee programme retroactively shifts the
--          payment schedule of every student on that programme.
--
-- Solution: backfill NULL/0 snapshot values from the current (or most recent
--           known) cf_programs bi/tri start months.
--
-- Run this script:
--   1. BEFORE making any new start-month changes in course fees, OR
--   2. After restoring the correct start-month values to cf_programs.
--
-- The ALTER TABLE statements are idempotent (no-op if columns already exist).
-- The UPDATE only touches packages whose snapshot is NULL or 0; packages
-- that already have a valid snapshotted month (1–12) are NOT changed.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── Step 1: Add columns if missing ───────────────────────────────────────────

SET @dbname    = DATABASE();
SET @tbl       = 'sfp_packages';

-- bi_semester_start_month
SET @col = 'bi_semester_start_month';
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE table_schema = @dbname AND table_name = @tbl AND column_name = @col) > 0,
    'SELECT 1 -- bi_semester_start_month already exists',
    CONCAT(
        'ALTER TABLE `', @tbl, '`',
        ' ADD COLUMN `', @col, '`',
        ' TINYINT UNSIGNED DEFAULT NULL',
        ' COMMENT \'Snapshotted bi-semester start month from cf_programs\'',
        ' AFTER months_per_semester'
    )
));
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- tri_semester_start_month
SET @col = 'tri_semester_start_month';
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE table_schema = @dbname AND table_name = @tbl AND column_name = @col) > 0,
    'SELECT 1 -- tri_semester_start_month already exists',
    CONCAT(
        'ALTER TABLE `', @tbl, '`',
        ' ADD COLUMN `', @col, '`',
        ' TINYINT UNSIGNED DEFAULT NULL',
        ' COMMENT \'Snapshotted tri-semester start month from cf_programs\'',
        ' AFTER bi_semester_start_month'
    )
));
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- ── Step 2: Backfill NULL / 0 snapshots from linked cf_programs ───────────────
-- Only updates packages where the snapshot is genuinely absent (NULL or 0).
-- Packages with an already-set valid month (1–12) are left untouched.
-- Only copies the value from cf_programs when that value itself is valid (1–12).

UPDATE `sfp_packages` p
INNER JOIN `cf_programs` cp ON cp.`id` = p.`cf_program_id`
SET
    p.`bi_semester_start_month` = CASE
        WHEN (p.`bi_semester_start_month` IS NULL OR p.`bi_semester_start_month` = 0)
             AND (cp.`bi_semester_start_month` >= 1 AND cp.`bi_semester_start_month` <= 12)
        THEN cp.`bi_semester_start_month`
        ELSE p.`bi_semester_start_month`
    END,
    p.`tri_semester_start_month` = CASE
        WHEN (p.`tri_semester_start_month` IS NULL OR p.`tri_semester_start_month` = 0)
             AND (cp.`tri_semester_start_month` >= 1 AND cp.`tri_semester_start_month` <= 12)
        THEN cp.`tri_semester_start_month`
        ELSE p.`tri_semester_start_month`
    END
WHERE p.`cf_program_id` IS NOT NULL
  AND (
       p.`bi_semester_start_month`  IS NULL OR p.`bi_semester_start_month`  = 0
    OR p.`tri_semester_start_month` IS NULL OR p.`tri_semester_start_month` = 0
  );

SET FOREIGN_KEY_CHECKS = 1;

-- ── Verification query (run after to confirm results) ────────────────────────
-- SELECT
--     p.id, p.student_id, p.program_name,
--     p.bi_semester_start_month, p.tri_semester_start_month,
--     cp.bi_semester_start_month AS prog_bi, cp.tri_semester_start_month AS prog_tri
-- FROM sfp_packages p
-- LEFT JOIN cf_programs cp ON cp.id = p.cf_program_id
-- WHERE p.bi_semester_start_month IS NULL OR p.bi_semester_start_month = 0
--    OR p.tri_semester_start_month IS NULL OR p.tri_semester_start_month = 0
-- ORDER BY p.id;
