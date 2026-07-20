-- Broadcast Module – "Employee Type" recipient option
-- Adds the employee_type recipient type (Administrative / Faculty, matched via
-- staff_profiles.department_type) and the employee_type filter column.
-- Run once against the admin database BEFORE using the new option.

ALTER TABLE `broadcasts`
  MODIFY `recipient_type` enum('individual','group','all','students','employee_type') NOT NULL DEFAULT 'all';

ALTER TABLE `broadcasts`
  ADD COLUMN `employee_type` varchar(20) DEFAULT NULL
    COMMENT 'administrative|educational – used when recipient_type=employee_type'
    AFTER `student_semester`;
