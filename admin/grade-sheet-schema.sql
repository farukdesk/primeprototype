-- ============================================================================
-- Grade Sheet module (Controller of Examinations)
-- ============================================================================
-- Department / batch / semester tabulation grade sheet for the Semester Final
-- Examination. It reads published results from the existing result pipeline
-- (result_mark_sheets + result_sheet_grades, linked to ei_exams and course
-- offers) — no new data tables are required. This file only registers the
-- module so its permissions can be managed from Access Control.
--
-- Safe to run multiple times: guarded INSERT.
-- Because `.gitignore` ignores *.sql, this file is force-added to the repo.
-- ----------------------------------------------------------------------------

INSERT INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`, `is_active`)
SELECT 'Grade Sheet', 'grade-sheet',
       'Department/batch/semester tabulation grade sheet (Semester Final Examination)',
       'fas fa-table-list', 0, 1
 WHERE NOT EXISTS (SELECT 1 FROM `modules` WHERE `slug` = 'grade-sheet');
