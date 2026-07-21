-- ============================================================================
-- FB Messenger Inbox Upgrade 2
-- Run ONCE against the application database (AFTER fb-inbox-upgrade.sql).
-- Adds: message "seen" receipts, manual read/unread flag,
--        one-time auto follow-up tracking ("আপনি কি আছেন?").
--
-- Also subscribe your Facebook App webhook to: message_reads
-- (App Dashboard > Messenger > Webhooks) so seen receipts arrive.
--
-- Optional: set an HTTP token so the follow-up cron can be called over HTTP
-- (calling it from CLI cron needs no token):
--   INSERT INTO lead_fb_settings (`key`,`value`) VALUES ('cron_token','CHANGE-ME')
--     ON DUPLICATE KEY UPDATE `value`='CHANGE-ME';
-- ============================================================================

ALTER TABLE lead_fb_messages
    ADD COLUMN is_auto TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN seen_at DATETIME NULL AFTER is_auto;

ALTER TABLE lead_fb_contacts
    ADD COLUMN marked_unread TINYINT(1) NOT NULL DEFAULT 0 AFTER last_read_at,
    ADD COLUMN followup_sent_at DATETIME NULL AFTER last_auto_reply_at;

CREATE INDEX idx_fb_msg_contact_dir ON lead_fb_messages (contact_id, direction, id);
