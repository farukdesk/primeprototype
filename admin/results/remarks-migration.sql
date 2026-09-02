-- Typed per-student remarks on workflow mark sheets
-- Adds the remarks entered in the Remarks column of the mark-entry form,
-- printed in the Remarks column of the mark sheet (sheet-print.php).
--
-- Run once, e.g.:  mysql -u <user> -p <database> < remarks-migration.sql

ALTER TABLE result_sheet_grades
    ADD COLUMN remarks VARCHAR(500) NULL DEFAULT NULL AFTER grade_point;
