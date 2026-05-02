-- ─────────────────────────────────────────────────────────────────────────────
-- Per-Segment Absent Support
-- Adds absent_json column to result_sheet_grades to store per-distribution-
-- component absent flags as a JSON array of booleans, e.g. [false,false,true].
-- When null, the row uses the global is_absent flag only.
-- Run after results-dynamic-marks-migration.sql
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE `result_sheet_grades`
  ADD COLUMN `absent_json` JSON DEFAULT NULL
    COMMENT 'JSON boolean array of per-segment absent flags [false,false,true,...]; null means no per-segment absents'
  AFTER `marks_json`;
