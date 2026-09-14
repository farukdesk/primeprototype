-- SS Portal 1.0 -> 1.1
-- Adds edit-after-registration and delete support.
-- Fresh installs: schema.sql already contains these columns; do NOT run this file.

ALTER TABLE ssp_students
  MODIFY sync_status ENUM('draft','pending','synced','failed','deleted') NOT NULL DEFAULT 'draft',
  ADD COLUMN pending_update TINYINT(1)   NOT NULL DEFAULT 0 AFTER sync_attempts,   -- local edits not yet sent to the university
  ADD COLUMN deleted_at     DATETIME     DEFAULT NULL AFTER synced_at,
  ADD COLUMN deleted_by     INT UNSIGNED DEFAULT NULL AFTER deleted_at,
  ADD COLUMN delete_reason  VARCHAR(500) DEFAULT NULL AFTER deleted_by;
