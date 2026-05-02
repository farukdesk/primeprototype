-- ─────────────────────────────────────────────────────────────────────────────
-- Dynamic Marks Distribution Support
-- Adds marks_json column to result_sheet_grades to store all mark components
-- (beyond the 4 legacy fixed columns) as a JSON array.
-- Run after results-workflow-migration.sql
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE `result_sheet_grades`
  ADD COLUMN IF NOT EXISTS `marks_json` JSON DEFAULT NULL
    COMMENT 'JSON array of mark values by distribution index [val0, val1, ...]; takes precedence over legacy columns when set'
  AFTER `final_exam`;
