-- ============================================================================
-- FB Messenger Inbox Upgrade 4
-- Run ONCE against the application database (AFTER fb-inbox-upgrade.sql).
-- Adds the "Converted to Lead" tag used by the automatic tagging rules:
--   * every new contact automatically gets the "Follow Up" tag
--   * when a contact shares a phone number, "Follow Up" is removed and
--     "Converted to Lead" is applied
-- (Both tags are also created on demand by fb-webhook.php if missing.)
-- ============================================================================

INSERT IGNORE INTO lead_fb_tags (name, color) VALUES
    ('Converted to Lead', '#198754');
