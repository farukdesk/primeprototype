-- ============================================================================
-- Staff Attendance – extend Type of Appointment (staff_profiles.job_type)
-- with the values used on the monthly attendance statement:
--   Regular, Probation (in addition to the existing options).
-- Existing values are preserved. Safe to run multiple times.
-- ============================================================================

ALTER TABLE `staff_profiles`
    MODIFY COLUMN `job_type`
        enum('Regular','Permanent','Contractual','Probation','Probationary',
             'Ad-hoc','Master Role','Daily Basis')
        DEFAULT NULL COMMENT 'Employment category / Type of Appointment';
