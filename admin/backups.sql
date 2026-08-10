-- ───────────────────────────────────────────────────────────────────────
-- System Backup Manager – DB + files backups stored in Google Drive.
-- Run once. UI: admin/backups/ (super admin only).
-- Daily auto backup: point a cron job at admin/backups/cron.php.
-- ───────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS sys_backups (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    backup_type      VARCHAR(10)  NOT NULL DEFAULT 'manual',  -- manual / auto
    scope            VARCHAR(10)  NOT NULL DEFAULT 'full',    -- db / files / full
    retention_class  VARCHAR(10)  NOT NULL DEFAULT 'manual',  -- manual / daily (kept 7 days) / weekly (kept 30 days)
    db_filename      VARCHAR(255) DEFAULT NULL,
    db_drive_id      VARCHAR(128) DEFAULT NULL,               -- Google Drive file ID of the DB dump
    db_size_bytes    BIGINT UNSIGNED DEFAULT NULL,
    files_filename   VARCHAR(255) DEFAULT NULL,
    files_drive_id   VARCHAR(128) DEFAULT NULL,               -- Google Drive file ID of the files archive
    files_size_bytes BIGINT UNSIGNED DEFAULT NULL,
    status           VARCHAR(12)  NOT NULL DEFAULT 'running', -- running / completed / failed
    log              TEXT         DEFAULT NULL,
    created_by       INT          DEFAULT NULL,               -- NULL for cron/auto backups
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at     DATETIME     DEFAULT NULL,
    restored_at      DATETIME     DEFAULT NULL,
    restored_by      INT          DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_backup_created (created_at),
    KEY idx_backup_type (backup_type, retention_class)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sys_backup_settings (
    setting_key   VARCHAR(64) NOT NULL,
    setting_value MEDIUMTEXT  DEFAULT NULL,
    updated_at    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
