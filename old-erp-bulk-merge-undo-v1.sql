-- Old ERP Bulk Merge — Undo support (v1)
--
-- Every confirmed bulk merge on admin/accounting/old-erp-bulk-merge.php is
-- recorded as one batch: the memo vouchers it created and the student
-- statuses it changed. Undoing a batch soft-deletes those vouchers and
-- restores the statuses.
--
-- The application also creates this table automatically on first use
-- (CREATE TABLE IF NOT EXISTS), so running this file is optional but keeps
-- the schema history consistent with the other *-v1.sql migrations.

CREATE TABLE IF NOT EXISTS oebm_merge_batches (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    merged_count INT UNSIGNED NOT NULL DEFAULT 0,
    voucher_ids MEDIUMTEXT NOT NULL,
    status_changes MEDIUMTEXT NOT NULL,
    undone_by INT UNSIGNED NULL DEFAULT NULL,
    undone_at DATETIME NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
