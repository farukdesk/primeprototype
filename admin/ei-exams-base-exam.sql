-- ============================================================================
-- Exam Invigilation → Supplementary/Makeup (re-sit) primary-exam mapping
-- ============================================================================
-- Adds `base_exam_id` to `ei_exams`: for a Supplementary / Makeup /
-- Improvement re-sit exam, it points to the primary (regular) exam it
-- re-sits, so the system knows which main exam each re-sit belongs to.
--
-- Used by Results → Mark Entry: the teacher first selects the PRIMARY exam;
-- re-sit exams mapped to it (via this column) then appear in a "Sub Exam"
-- dropdown. Picking a sub exam pre-fills the primary exam's marks while the
-- sheet is still saved under the sub exam. The mapping is set from
-- Exam Invigilation → New/Edit Exam.
--
-- NULL = regular (primary) exam, or an unmapped re-sit (it then simply shows
-- in the primary exam list as before). No foreign key so exams can be deleted
-- freely; lookups treat a dangling id as unmapped.
--
-- Safe to run multiple times: guards check for the column first.
-- ----------------------------------------------------------------------------

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'ei_exams'
       AND COLUMN_NAME  = 'base_exam_id'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE `ei_exams`
        ADD COLUMN `base_exam_id` INT NULL DEFAULT NULL
        COMMENT ''Primary (regular) exam this supplementary/makeup re-sit is for (ei_exams.id)''
        AFTER `marks_entry_open`',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
