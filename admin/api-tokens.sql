-- ============================================================
-- PUMIS Mobile App – API Token & Push Notification Tables
-- Run once against the admin_primepnew2026 database.
-- ============================================================

-- API tokens issued to mobile app sessions
CREATE TABLE IF NOT EXISTS api_tokens (
    id          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED     NOT NULL,
    token       CHAR(64)         NOT NULL,
    device_id   VARCHAR(255)     DEFAULT NULL,
    device_name VARCHAR(255)     DEFAULT NULL,
    created_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used   DATETIME         DEFAULT NULL,
    expires_at  DATETIME         NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_api_token (token),
    KEY idx_user_id (user_id),
    CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FCM device push tokens per user (one per device)
CREATE TABLE IF NOT EXISTS api_push_tokens (
    id         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED  NOT NULL,
    fcm_token  TEXT          NOT NULL,
    device_id  VARCHAR(255)  DEFAULT NULL,
    platform   VARCHAR(20)   NOT NULL DEFAULT 'android',
    created_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_push_device (user_id, device_id),
    KEY idx_push_user (user_id),
    CONSTRAINT fk_push_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FCM server key stored in existing settings table (insert if not present)
-- Replace 'YOUR_FCM_SERVER_KEY' with the actual key from Firebase Console.
INSERT IGNORE INTO settings (`key`, `value`, `label`, `group`)
VALUES ('fcm_server_key', '', 'FCM Server Key (Firebase)', 'api');
