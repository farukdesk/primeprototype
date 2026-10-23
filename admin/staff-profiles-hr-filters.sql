-- ============================================================================
-- Employee Profiles – HR filter fields
-- ============================================================================
-- Adds the extra HR fields used by the Employee Profiles list filters:
--   • salary               – current monthly salary (BDT)
--   • last_promotion_date  – date of the most recent promotion
--   • last_designation     – designation held before the current one
-- and extends `employee_status` with all real-life statuses
-- (Resigned, Maternity Leave, Retired, Terminated, Suspended, …).
--
-- Existing values are preserved. Safe to run multiple times (MariaDB 10.x).
-- Because `.gitignore` ignores *.sql, this file is force-added to the repo.
-- The columns are also auto-added at runtime by sp_ensure_hr_columns().
-- ============================================================================

ALTER TABLE `staff_profiles`
    ADD COLUMN IF NOT EXISTS `salary`              decimal(12,2) DEFAULT NULL COMMENT 'Current monthly salary (BDT)' AFTER `joining_date`,
    ADD COLUMN IF NOT EXISTS `last_promotion_date` date          DEFAULT NULL COMMENT 'Date of most recent promotion' AFTER `salary`,
    ADD COLUMN IF NOT EXISTS `last_designation`    varchar(200)  DEFAULT NULL COMMENT 'Designation before the current one' AFTER `last_promotion_date`;

ALTER TABLE `staff_profiles`
    MODIFY COLUMN `employee_status`
        enum('Active','Inactive','On Leave','Study Leave','Maternity Leave',
             'Paternity Leave','Resigned','Retired','Terminated','Suspended','Closed')
        NOT NULL DEFAULT 'Active';
