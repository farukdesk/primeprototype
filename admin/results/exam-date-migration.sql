-- Exam Date for workflow mark sheets
-- Adds the per-sheet Exam Date entered on the mark-entry form and printed
-- as "Exam Date" on the mark sheet printout (sheet-print.php).
--
-- Run once, e.g.:  mysql -u <user> -p <database> < exam-date-migration.sql

ALTER TABLE result_mark_sheets
    ADD COLUMN exam_date DATE NULL DEFAULT NULL AFTER exam_id;
