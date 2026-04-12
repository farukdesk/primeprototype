-- ─────────────────────────────────────────────────────────────────────────────
-- Office of Vice Chancellor – v3 migration
-- Adds ps_photo key to vc_settings
-- Run once against admin_primepnew2026
-- ─────────────────────────────────────────────────────────────────────────────

INSERT INTO `vc_settings` (`setting_key`, `setting_val`) VALUES
('ps_photo', '')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
