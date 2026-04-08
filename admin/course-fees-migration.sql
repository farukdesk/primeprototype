-- ============================================================
-- Course Fees Migration Script
-- Migrates data from the OLD Laravel-based system
-- (tables: departments, aca_programs, fees_paymens)
-- into the NEW system
-- (tables: dept_departments, dept_academic_programs, cf_programs, cf_fixed_fees)
--
-- HOW TO USE
-- ----------
-- 1. Restore the old database backup into the SAME MySQL/MariaDB server
--    under a DIFFERENT database name, e.g. prime_old
--       $ mysql -u root -p prime_old < admin_primeweb_32343.sql
-- 2. Run this script against the NEW database (the one used by this site):
--       $ mysql -u root -p prime_new < admin/course-fees-migration.sql
--    (replace prime_old / prime_new with your actual database names)
-- 3. Review any rows inserted into cf_fixed_fees with fee_type='one_time'
--    that came from the p_amount or m_fee TEXT columns – those may contain
--    JSON or rich text that needs manual cleanup.
-- 4. After verifying data on staging, run on production.
--
-- SAFE TO RE-RUN: all inserts use INSERT IGNORE or duplicate-key checks.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- STEP 1 – Import missing departments from the old system
-- ============================================================
-- Only inserts departments whose name does not already exist in
-- dept_departments.  The old `departments` table is read from
-- the old database (prime_old).  Adjust the database prefix below.

INSERT IGNORE INTO `dept_departments`
    (`name`, `slug`, `code`, `is_active`, `sort_order`)
SELECT
    od.`name`,
    -- Build a URL-safe slug from the name
    LOWER(REPLACE(REPLACE(REPLACE(TRIM(od.`name`), ' ', '-'), '&', 'and'), '.', '')),
    -- Use the first word of the name as a short code (max 20 chars)
    UPPER(LEFT(SUBSTRING_INDEX(od.`name`, ' ', 1), 20)),
    IF(od.`status` = 1, 1, 0),
    IFNULL(od.`priority`, 0)
FROM `prime_old`.`departments` od
WHERE NOT EXISTS (
    SELECT 1 FROM `dept_departments` nd
    WHERE nd.`name` = od.`name`
);

-- ============================================================
-- STEP 2 – Import missing programmes from the old system
-- ============================================================
-- Maps old aca_programs → dept_academic_programs.
-- Department is matched by name across the two databases.

INSERT IGNORE INTO `dept_academic_programs`
    (`dept_id`, `program_name`, `degree_type`, `duration`, `total_credit`, `is_active`, `sort_order`)
SELECT
    nd.`id`                                   AS dept_id,
    op.`p_name`                               AS program_name,
    -- Derive a degree_type label from the program name
    CASE
        WHEN op.`p_name` LIKE '%M.Sc%'  OR op.`p_name` LIKE '%MSc%'  OR op.`p_name` LIKE '%Master%' THEN 'Master of Science'
        WHEN op.`p_name` LIKE '%MBA%'                                                                THEN 'Master of Business Administration'
        WHEN op.`p_name` LIKE '%M.B.A%'                                                             THEN 'Master of Business Administration'
        WHEN op.`p_name` LIKE '%B.Sc%'  OR op.`p_name` LIKE '%BSc%'                                THEN 'Bachelor of Science'
        WHEN op.`p_name` LIKE '%B.B.A%' OR op.`p_name` LIKE '%BBA%'                                THEN 'Bachelor of Business Administration'
        WHEN op.`p_name` LIKE '%Diploma%'                                                           THEN 'Diploma'
        WHEN op.`p_name` LIKE '%LLB%'   OR op.`p_name` LIKE '%L.L.B%'                              THEN 'Bachelor of Laws'
        WHEN op.`p_name` LIKE '%LLM%'   OR op.`p_name` LIKE '%L.L.M%'                              THEN 'Master of Laws'
        WHEN op.`p_name` LIKE '%Pharm%'                                                             THEN 'Bachelor of Pharmacy'
        ELSE 'Bachelor'
    END                                       AS degree_type,
    -- m_year holds duration like "4" (years)
    CONCAT(TRIM(op.`m_year`), ' Years')       AS duration,
    -- credits holds total credits like "160"
    CONCAT(TRIM(op.`credits`), ' Credits')    AS total_credit,
    IF(op.`status` = 1, 1, 0)                 AS is_active,
    0                                         AS sort_order
FROM `prime_old`.`aca_programs` op
-- Join to old departments to get the name
JOIN `prime_old`.`departments`   od ON od.`id` = op.`dept_id`
-- Match to new dept_departments by name
JOIN `dept_departments`          nd ON nd.`name` = od.`name`
-- Skip if this program already exists for that department
WHERE NOT EXISTS (
    SELECT 1 FROM `dept_academic_programs` ap
    WHERE ap.`dept_id`      = nd.`id`
      AND ap.`program_name` = op.`p_name`
);

-- ============================================================
-- STEP 3 – Migrate fees_paymens → cf_programs
-- ============================================================
-- For each fees_paymens row we resolve:
--   dept_id    via old departments.name  → dept_departments.id
--   program_id via old aca_programs.p_name → dept_academic_programs.id
--   credit_fee from old course_fee VARCHAR  → INT (strip non-numeric chars)
--   degree_type derived from program name (same logic as Step 2)

INSERT IGNORE INTO `cf_programs`
    (`dept_id`, `program_id`, `degree_type`, `credit_fee`, `total_credits`,
     `duration_years`, `is_active`, `sort_order`)
SELECT
    nd.`id`                                       AS dept_id,
    nap.`id`                                      AS program_id,
    CASE
        WHEN op.`p_name` LIKE '%M.Sc%'  OR op.`p_name` LIKE '%MSc%'  OR op.`p_name` LIKE '%Master%' THEN 'master'
        WHEN op.`p_name` LIKE '%MBA%'   OR op.`p_name` LIKE '%M.B.A%'                               THEN 'master'
        WHEN op.`p_name` LIKE '%Diploma%'                                                            THEN 'diploma'
        WHEN op.`p_name` LIKE '%Certificate%'                                                        THEN 'certificate'
        ELSE 'bachelor'
    END                                           AS degree_type,
    -- Strip anything that is not a digit and cast to UNSIGNED
    CAST(REGEXP_REPLACE(fp.`course_fee`, '[^0-9]', '') AS UNSIGNED) AS credit_fee,
    -- total_credits from aca_programs.credits (strip non-digits)
    NULLIF(CAST(REGEXP_REPLACE(op.`credits`, '[^0-9]', '') AS UNSIGNED), 0) AS total_credits,
    -- duration_years from aca_programs.m_year
    NULLIF(CAST(REGEXP_REPLACE(op.`m_year`, '[^0-9.]', '') AS DECIMAL(4,1)), 0) AS duration_years,
    IF(fp.`status` = 1, 1, 0)                    AS is_active,
    0                                             AS sort_order
FROM `prime_old`.`fees_paymens`   fp
JOIN `prime_old`.`departments`    od  ON od.`id`  = fp.`dept_id`
JOIN `prime_old`.`aca_programs`   op  ON op.`id`  = fp.`program_id`
JOIN `dept_departments`           nd  ON nd.`name` = od.`name`
JOIN `dept_academic_programs`     nap ON nap.`dept_id` = nd.`id`
                                      AND nap.`program_name` = op.`p_name`
-- Skip if an identical (program_id, degree_type) entry already exists
WHERE NOT EXISTS (
    SELECT 1 FROM `cf_programs` cp
    WHERE cp.`program_id` = nap.`id`
      AND cp.`degree_type` = CASE
          WHEN op.`p_name` LIKE '%M.Sc%'  OR op.`p_name` LIKE '%MSc%'  OR op.`p_name` LIKE '%Master%' THEN 'master'
          WHEN op.`p_name` LIKE '%MBA%'   OR op.`p_name` LIKE '%M.B.A%'                               THEN 'master'
          WHEN op.`p_name` LIKE '%Diploma%'                                                            THEN 'diploma'
          WHEN op.`p_name` LIKE '%Certificate%'                                                        THEN 'certificate'
          ELSE 'bachelor'
      END
);

-- ============================================================
-- STEP 4 – Migrate fixed fees from fees_paymens text columns
-- ============================================================
-- The old system stored additional fee details in TEXT columns.
-- We map them to cf_fixed_fees rows as best-effort:
--
--   p_amount  → inserted as "Programme Fee" (one_time) if it looks like
--               a pure number; otherwise inserted as raw text in fee_name
--               so you can review it.
--   m_fee     → inserted as "Miscellaneous Fee" (per_semester) if numeric;
--               otherwise inserted with raw text for review.
--
-- Any row where both columns are blank / zero is skipped.

-- 4a. p_amount column
INSERT IGNORE INTO `cf_fixed_fees`
    (`cf_program_id`, `fee_name`, `amount`, `fee_type`, `sort_order`)
SELECT
    cp.`id`                                           AS cf_program_id,
    CASE
        WHEN fp.`p_amount` REGEXP '^[0-9,. ]+$'
        THEN 'Programme Fee'
        ELSE CONCAT('Programme Fee (review: ', LEFT(fp.`p_amount`, 100), ')')
    END                                               AS fee_name,
    CAST(REGEXP_REPLACE(fp.`p_amount`, '[^0-9]', '') AS UNSIGNED) AS amount,
    'one_time'                                        AS fee_type,
    1                                                 AS sort_order
FROM `prime_old`.`fees_paymens` fp
JOIN `prime_old`.`departments`        od  ON od.`id`  = fp.`dept_id`
JOIN `prime_old`.`aca_programs`       op  ON op.`id`  = fp.`program_id`
JOIN `dept_departments`               nd  ON nd.`name` = od.`name`
JOIN `dept_academic_programs`         nap ON nap.`dept_id` = nd.`id`
                                          AND nap.`program_name` = op.`p_name`
JOIN `cf_programs`                    cp  ON cp.`program_id` = nap.`id`
WHERE TRIM(fp.`p_amount`) != ''
  AND TRIM(fp.`p_amount`) != '0'
  AND NOT EXISTS (
      SELECT 1 FROM `cf_fixed_fees` f
      WHERE f.`cf_program_id` = cp.`id`
        AND f.`fee_name` LIKE 'Programme Fee%'
  );

-- 4b. m_fee column
INSERT IGNORE INTO `cf_fixed_fees`
    (`cf_program_id`, `fee_name`, `amount`, `fee_type`, `sort_order`)
SELECT
    cp.`id`                                           AS cf_program_id,
    CASE
        WHEN fp.`m_fee` REGEXP '^[0-9,. ]+$'
        THEN 'Miscellaneous Fee'
        ELSE CONCAT('Miscellaneous Fee (review: ', LEFT(fp.`m_fee`, 100), ')')
    END                                               AS fee_name,
    CAST(REGEXP_REPLACE(fp.`m_fee`, '[^0-9]', '') AS UNSIGNED) AS amount,
    'per_semester'                                    AS fee_type,
    2                                                 AS sort_order
FROM `prime_old`.`fees_paymens` fp
JOIN `prime_old`.`departments`        od  ON od.`id`  = fp.`dept_id`
JOIN `prime_old`.`aca_programs`       op  ON op.`id`  = fp.`program_id`
JOIN `dept_departments`               nd  ON nd.`name` = od.`name`
JOIN `dept_academic_programs`         nap ON nap.`dept_id` = nd.`id`
                                          AND nap.`program_name` = op.`p_name`
JOIN `cf_programs`                    cp  ON cp.`program_id` = nap.`id`
WHERE TRIM(fp.`m_fee`) != ''
  AND TRIM(fp.`m_fee`) != '0'
  AND NOT EXISTS (
      SELECT 1 FROM `cf_fixed_fees` f
      WHERE f.`cf_program_id` = cp.`id`
        AND f.`fee_name` LIKE 'Miscellaneous Fee%'
  );

-- ============================================================
-- STEP 5 – Verification queries (run manually to review results)
-- ============================================================
-- Uncomment and run these SELECT statements to sanity-check:

-- SELECT COUNT(*) AS migrated_programs  FROM cf_programs;
-- SELECT COUNT(*) AS migrated_fixed_fees FROM cf_fixed_fees;
-- SELECT cp.id, nd.name AS dept, nap.program_name, cp.degree_type,
--        cp.credit_fee, cp.total_credits, cp.duration_years, cp.is_active
-- FROM cf_programs cp
-- LEFT JOIN dept_departments      nd  ON nd.id  = cp.dept_id
-- LEFT JOIN dept_academic_programs nap ON nap.id = cp.program_id
-- ORDER BY nd.name, nap.program_name;

-- SELECT cf.*, cp.id AS cf_program_id_val
-- FROM cf_fixed_fees cf
-- JOIN cf_programs cp ON cp.id = cf.cf_program_id
-- WHERE cf.fee_name LIKE '%(review:%'
-- ORDER BY cf.cf_program_id, cf.sort_order;

SET FOREIGN_KEY_CHECKS = 1;
