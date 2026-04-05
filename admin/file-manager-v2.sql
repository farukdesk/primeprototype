-- ============================================================
-- File Manager v2 – Additional fields
-- Run this script once after file-manager.sql.
-- ============================================================

ALTER TABLE `file_manager_files`
    ADD COLUMN IF NOT EXISTS `proposal`    TEXT          DEFAULT NULL COMMENT 'Proposal or purpose of the file'     AFTER `notes`,
    ADD COLUMN IF NOT EXISTS `page_number` VARCHAR(50)   DEFAULT NULL COMMENT 'Page / reference number'             AFTER `proposal`;
