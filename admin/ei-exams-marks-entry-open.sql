-- ============================================================================
-- Exam Invigilation → Marks Entry window
-- ============================================================================
-- Adds `marks_entry_open` to `ei_exams` so the Controller of Examinations can
-- open/close marks entry per exam. Only exams with marks_entry_open = 1 (and
-- is_active = 1) are listed in Results → Mark Entry "Select Exam".
--
-- Defaults to 1 (open) so existing exams keep working after the migration;
-- close old exams from Exam Invigilation → Edit Exam.
--
-- Safe to run multiple times: guards check for the column first.
-- ----------------------------------------------------------------------------

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'ei_exams'
       AND COLUMN_NAME  = 'marks_entry_open'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE `ei_exams`
        ADD COLUMN `marks_entry_open` TINYINT(1) NOT NULL DEFAULT 1
        COMMENT ''1 = teachers can enter marks for this exam; 0 = marks entry closed''
        AFTER `is_active`',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
