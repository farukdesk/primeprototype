-- ============================================================================
-- Staff Profiles → Section
-- ============================================================================
-- Adds an optional `section` column to `staff_profiles` for ADMINISTRATIVE
-- employees (Accounts, Admin, Admission, COE, CRHP, IQAC, IT). Faculty do not
-- use sections, so the column is nullable.
--
-- Safe to run multiple times: the guard checks for the column first.
-- Because `.gitignore` ignores *.sql, this file is force-added to the repo.
-- ----------------------------------------------------------------------------

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'staff_profiles'
       AND COLUMN_NAME  = 'section'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE `staff_profiles`
        ADD COLUMN `section` VARCHAR(100) DEFAULT NULL
        COMMENT ''Administrative section (Accounts, Admin, Admission, COE, CRHP, IQAC, IT)''
        AFTER `staff_dept_id`',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
