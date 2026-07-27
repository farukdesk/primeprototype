-- ============================================================================
-- Course Offer → Shift & Section
-- ============================================================================
-- Adds optional `shift` and `section` columns to `co_offers`. Not every batch
-- has shifts/sections, so both are nullable and only filled when the selected
-- batch actually has them (derived from the students of that batch).
--
-- Safe to run multiple times: guards check for the column first.
-- Because `.gitignore` ignores *.sql, this file is force-added to the repo.
-- ----------------------------------------------------------------------------

-- ── 1. Shift ────────────────────────────────────────────────────────────────
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'co_offers'
       AND COLUMN_NAME  = 'shift'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE `co_offers`
        ADD COLUMN `shift` VARCHAR(100) DEFAULT NULL
        COMMENT ''Optional shift — only set when the batch has shifts''
        AFTER `batch_id`',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 2. Section ──────────────────────────────────────────────────────────────
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'co_offers'
       AND COLUMN_NAME  = 'section'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE `co_offers`
        ADD COLUMN `section` VARCHAR(100) DEFAULT NULL
        COMMENT ''Optional section — only set when the batch has sections''
        AFTER `shift`',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
