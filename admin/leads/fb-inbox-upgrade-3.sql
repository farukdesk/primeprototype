-- ============================================================================
-- FB Messenger Inbox Upgrade 3
-- Run ONCE against the application database (AFTER fb-inbox-upgrade.sql and
-- fb-inbox-upgrade-2.sql).
-- Adds: smart answer suggestions (saved Q&A), broadcast system,
--        extra default tags (Dead, Follow Up, Potential).
-- ============================================================================

CREATE TABLE IF NOT EXISTS lead_fb_qa (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question   VARCHAR(255) NOT NULL,
    keywords   VARCHAR(255) NULL,
    answer     TEXT NOT NULL,
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    use_count  INT UNSIGNED NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lead_fb_broadcasts (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message          TEXT NOT NULL,
    audience         VARCHAR(10) NOT NULL DEFAULT 'all',     -- all | tags
    tag_names        VARCHAR(255) NULL,
    total_recipients INT UNSIGNED NOT NULL DEFAULT 0,
    sent_count       INT UNSIGNED NOT NULL DEFAULT 0,
    failed_count     INT UNSIGNED NOT NULL DEFAULT 0,
    status           VARCHAR(12) NOT NULL DEFAULT 'sending', -- sending | completed
    created_by       INT NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at     DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lead_fb_broadcast_recipients (
    broadcast_id INT UNSIGNED NOT NULL,
    contact_id   INT UNSIGNED NOT NULL,
    status       VARCHAR(10) NOT NULL DEFAULT 'pending',     -- pending | sending | sent | failed
    sent_at      DATETIME NULL,
    PRIMARY KEY (broadcast_id, contact_id),
    KEY idx_bc_status (broadcast_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Extra default tags for lead stage tracking
INSERT IGNORE INTO lead_fb_tags (name, color) VALUES
    ('Dead',      '#343a40'),
    ('Follow Up', '#fd7e14'),
    ('Potential', '#0d6efd');

-- Seed a few starter Q&A suggestions (edit/delete freely in the conversation sidebar)
INSERT IGNORE INTO lead_fb_qa (question, keywords, answer) VALUES
    ('What is the tuition fee?', 'fees, cost, tuition, খরচ, ফি, টাকা', 'Thank you for your interest in Prime University! You can view detailed tuition fees and use our fee calculator here: https://primeuniversity.ac.bd/course-fees-calculator.php'),
    ('Where is the campus located?', 'address, location, campus, ঠিকানা, কোথায়', 'Prime University campus: 2A/1 North East of Darus Salam Road, Section-1, Mirpur, Dhaka-1216. Open Sunday–Thursday, 9:00 AM–5:00 PM.'),
    ('How can I apply for admission?', 'admission, apply, ভর্তি, এডমিশন', 'Thank you for contacting Prime University! You can apply online here: https://primeuniversity.ac.bd/apply-now.php — our admission team will guide you through every step.');
