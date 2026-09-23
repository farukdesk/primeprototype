-- ---------------------------------------------------------------------------
-- Student Transfer — Revert support
--
-- Adds the ability to revert a Department Transfer or Batch Transfer back to
-- the student's exact pre-transfer state (department/program/Student ID, or
-- batch). A reverted transfer is marked, never deleted, so the history stays
-- intact — you can see both that the transfer happened AND that it was later
-- undone, by whom and when.
--
-- Already included in a fresh admin/student-transfer.sql for new installs;
-- this file only needs to be run against an EXISTING student_transfers table.
--
-- Idempotent: safe to run more than once (MariaDB IF NOT EXISTS).
-- ---------------------------------------------------------------------------

ALTER TABLE `student_transfers`
  ADD COLUMN IF NOT EXISTS `reverted_at`
      datetime DEFAULT NULL
      COMMENT 'When this transfer was reverted, if ever'
      AFTER `created_at`;

ALTER TABLE `student_transfers`
  ADD COLUMN IF NOT EXISTS `reverted_by`
      int(10) UNSIGNED DEFAULT NULL
      COMMENT 'users.id who reverted this transfer'
      AFTER `reverted_at`;
