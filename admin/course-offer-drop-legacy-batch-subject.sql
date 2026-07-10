-- ============================================================================
-- Course Offer → Drop legacy per-subject offer schema
-- ============================================================================
-- Early versions of the Course Offer module stored a single subject per offer
-- directly on `co_offers.curriculum_id`, guarded by the unique key
-- `uq_co_batch_subject (batch_id, curriculum_id)`. The module has since moved
-- to multi-subject offers (subjects live in `co_offer_subjects`), and the
-- INSERT in `create.php` no longer sets `co_offers.curriculum_id`, so every new
-- offer is stored with `curriculum_id = 0`.
--
-- With the old unique key still in place this means only ONE offer can ever be
-- created per batch: the second attempt collides on `(batch_id, 0)` and throws
--   SQLSTATE[23000] ... Duplicate entry '<batch>-0' for key 'uq_co_batch_subject'
--
-- This migration removes the obsolete unique key and the now-unused legacy
-- `curriculum_id` column from `co_offers`.
--
-- Safe to run multiple times: guards check for the key/column first.
-- Because `.gitignore` ignores *.sql, this file is force-added to the repo.
-- ----------------------------------------------------------------------------

-- ── 1. Drop the legacy unique key (batch_id, curriculum_id) ─────────────────
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'co_offers'
       AND INDEX_NAME   = 'uq_co_batch_subject'
);
SET @ddl := IF(@idx_exists > 0,
    'ALTER TABLE `co_offers` DROP INDEX `uq_co_batch_subject`',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 2. Drop the now-unused legacy `curriculum_id` column ────────────────────
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'co_offers'
       AND COLUMN_NAME  = 'curriculum_id'
);
SET @ddl := IF(@col_exists > 0,
    'ALTER TABLE `co_offers` DROP COLUMN `curriculum_id`',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
