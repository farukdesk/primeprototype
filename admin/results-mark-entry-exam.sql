-- ============================================================================
-- Mark Entry → Exam + Course-Offer integration
-- ============================================================================
-- Adds two columns to `result_mark_sheets` so a workflow mark sheet is tied to:
--
--   * `exam_id`          — the examination it belongs to (FK → ei_exams.id, the
--                          Exam Invigilation "exam" records surfaced in the
--                          Mark Entry "Select Exam" dropdown).
--   * `offer_subject_id` — the offered course/subject it grades (FK →
--                          co_offer_subjects.id). Selecting a subject in Mark
--                          Entry pulls the active registered students of that
--                          course from `co_registrations`.
--
-- Both columns are nullable so existing sheets keep working, and both use
-- ON DELETE SET NULL so removing an exam or an offer subject never deletes a
-- mark sheet.
--
-- Safe to run multiple times: guards check for the column/FK first.
-- Because `.gitignore` ignores *.sql, this file is force-added to the repo.
-- ----------------------------------------------------------------------------

-- ── 1. exam_id column ───────────────────────────────────────────────────────
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'result_mark_sheets'
       AND COLUMN_NAME  = 'exam_id'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE `result_mark_sheets`
        ADD COLUMN `exam_id` INT(10) UNSIGNED DEFAULT NULL
        COMMENT ''FK → ei_exams.id (the examination this sheet belongs to)''
        AFTER `program_id`',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 2. offer_subject_id column ──────────────────────────────────────────────
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'result_mark_sheets'
       AND COLUMN_NAME  = 'offer_subject_id'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE `result_mark_sheets`
        ADD COLUMN `offer_subject_id` INT(10) UNSIGNED DEFAULT NULL
        COMMENT ''FK → co_offer_subjects.id (the offered course being graded)''
        AFTER `curriculum_id`',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 3. Indexes ──────────────────────────────────────────────────────────────
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'result_mark_sheets'
       AND INDEX_NAME   = 'idx_rms_exam'
);
SET @ddl := IF(@idx_exists = 0,
    'ALTER TABLE `result_mark_sheets` ADD KEY `idx_rms_exam` (`exam_id`)',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'result_mark_sheets'
       AND INDEX_NAME   = 'idx_rms_offer_subject'
);
SET @ddl := IF(@idx_exists = 0,
    'ALTER TABLE `result_mark_sheets` ADD KEY `idx_rms_offer_subject` (`offer_subject_id`)',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 4. Foreign keys (ON DELETE SET NULL) ────────────────────────────────────
SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA    = DATABASE()
       AND TABLE_NAME      = 'result_mark_sheets'
       AND CONSTRAINT_NAME = 'fk_rms_exam'
);
SET @ddl := IF(@fk_exists = 0,
    'ALTER TABLE `result_mark_sheets`
        ADD CONSTRAINT `fk_rms_exam` FOREIGN KEY (`exam_id`)
        REFERENCES `ei_exams` (`id`) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA    = DATABASE()
       AND TABLE_NAME      = 'result_mark_sheets'
       AND CONSTRAINT_NAME = 'fk_rms_offer_subject'
);
SET @ddl := IF(@fk_exists = 0,
    'ALTER TABLE `result_mark_sheets`
        ADD CONSTRAINT `fk_rms_offer_subject` FOREIGN KEY (`offer_subject_id`)
        REFERENCES `co_offer_subjects` (`id`) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
