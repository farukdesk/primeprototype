-- ============================================================================
-- Student Due List module
-- ============================================================================
-- Exam-wise list of students with outstanding dues. The list is always built
-- from generated admit cards: pick an exam (exam_name + semester entered on
-- the admit-card Generate page) and every student covered by that exam's
-- admit cards is checked for a due as of today.
--
-- No module tables are needed — the report reads ac_admit_cards /
-- ac_admit_card_courses / co_registrations and the accounting fee packages.
--
-- Safe to run multiple times: guarded INSERT only.
-- Because `.gitignore` ignores *.sql, this file is force-added to the repo.
-- ----------------------------------------------------------------------------

-- Register the module (permissions are then managed from Access Control).
INSERT INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`, `is_active`)
SELECT 'Student Due List', 'student-due-list',
       'Exam-wise due list of admit-card students with print option',
       'fas fa-file-invoice-dollar', 0, 1
 WHERE NOT EXISTS (SELECT 1 FROM `modules` WHERE `slug` = 'student-due-list');
