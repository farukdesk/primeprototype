-- ───────────────────────────────────────────────────────────────────────
-- Database Snapshots – row-level copy of EVERY data change with restore.
-- Run this once. Snapshots are captured automatically by the PDO wrapper
-- (admin/includes/db-snapshot.php); browse/restore at admin/db-snapshots/
-- (super admin only).
-- ───────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS db_snapshots (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    table_name    VARCHAR(128)    NOT NULL,
    action        VARCHAR(16)     NOT NULL,     -- INSERT / UPDATE / DELETE / REPLACE
    pk_column     VARCHAR(128)    DEFAULT NULL, -- primary key column of the table (for restore)
    row_count     INT             NOT NULL DEFAULT 0,
    rows_before   LONGTEXT        DEFAULT NULL, -- JSON array of row images BEFORE the change
    rows_after    LONGTEXT        DEFAULT NULL, -- JSON array of row images AFTER the change
    query_snippet VARCHAR(500)    DEFAULT NULL, -- first 500 chars of the SQL that caused it
    user_id       INT             DEFAULT NULL, -- admin user who made the change
    ip_address    VARCHAR(45)     DEFAULT NULL,
    request_uri   VARCHAR(500)    DEFAULT NULL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    restored_at   DATETIME        DEFAULT NULL, -- set when a super admin restores this snapshot
    restored_by   INT             DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_snap_table_created (table_name, created_at),
    KEY idx_snap_created (created_at),
    KEY idx_snap_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
