-- ============================================================================
-- Exam Invigilation → Supplementary/Makeup base exam mapping
-- ============================================================================
-- Adds `base_exam_id` to `ei_exams`: for a Supplementary / Makeup /
-- Improvement exam, it points to the regular (base) exam it re-sits, so the
-- system knows which exam each supplementary belongs to.
--
-- Used by Results → Mark Entry: when a supplementary/makeup exam is selected,
-- an optional "Base Exam" dropdown appears (pre-selected from this column) and
-- the chosen base exam's final marks are pre-filled. The mapping is saved back
-- here whenever a teacher picks a base exam, and can also be set from
-- Exam Invigilation → New/Edit Exam.
--
-- NULL = no base exam mapped (prefill simply stays empty — the field is
-- optional). No foreign key so exams can be deleted freely; lookups treat a
-- dangling id as unmapped.
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
        COMMENT ''Regular exam this supplementary/makeup exam is for (ei_exams.id)''
        AFTER `marks_entry_open`',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
