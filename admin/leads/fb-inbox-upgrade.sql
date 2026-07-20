-- ============================================================================
-- FB Messenger Inbox Upgrade
-- Run ONCE against the application database.
-- Adds: message delivery status, unread tracking, auto-responder throttle,
--        canned responses, internal contact notes, conversation tags.
-- ============================================================================

ALTER TABLE lead_fb_messages
    ADD COLUMN status VARCHAR(10) NOT NULL DEFAULT 'sent' AFTER fb_mid;

ALTER TABLE lead_fb_contacts
    ADD COLUMN last_read_at DATETIME NULL AFTER last_message_at,
    ADD COLUMN last_auto_reply_at DATETIME NULL AFTER last_read_at;

CREATE TABLE IF NOT EXISTS lead_fb_canned_responses (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shortcut    VARCHAR(30)  NOT NULL UNIQUE,
    title       VARCHAR(100) NOT NULL,
    body        TEXT         NOT NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_by  INT          NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lead_fb_contact_notes (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contact_id  INT UNSIGNED NOT NULL,
    user_id     INT          NULL,
    note        TEXT         NOT NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_contact (contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lead_fb_tags (
    id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name   VARCHAR(50) NOT NULL UNIQUE,
    color  VARCHAR(7)  NOT NULL DEFAULT '#6c757d'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lead_fb_contact_tags (
    contact_id INT UNSIGNED NOT NULL,
    tag_id     INT UNSIGNED NOT NULL,
    PRIMARY KEY (contact_id, tag_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed default tags
INSERT IGNORE INTO lead_fb_tags (name, color) VALUES
    ('High Priority', '#dc3545'),
    ('Follow Up',     '#fd7e14'),
    ('Admitted',      '#198754'),
    ('Pending',       '#6f42c1');

-- Seed starter canned responses (edit freely in the conversation sidebar)
INSERT IGNORE INTO lead_fb_canned_responses (shortcut, title, body) VALUES
    ('/fees',      'Tuition Fees',   'Thank you for your interest in Prime University! You can view detailed tuition fees and use our fee calculator here: https://primeuniversity.ac.bd/course-fees-calculator.php'),
    ('/address',   'Campus Address', 'Prime University campus: 2A/1 North East of Darus Salam Road, Section-1, Mirpur, Dhaka-1216. Open Sunday–Thursday, 9:00 AM–5:00 PM.'),
    ('/admission', 'Admission Info', 'Thank you for contacting Prime University! You can apply online here: https://primeuniversity.ac.bd/apply-now.php — our admission team will guide you through every step.');
