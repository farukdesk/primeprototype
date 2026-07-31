-- Remember-me tokens for the admin login ("Keep me signed in").
-- Run once. Tokens are stored hashed (SHA-256 of the validator); the plain
-- validator only ever lives in the user's cookie.

CREATE TABLE IF NOT EXISTS auth_remember_tokens (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        INT UNSIGNED NOT NULL,
    selector       CHAR(18)     NOT NULL,
    validator_hash CHAR(64)     NOT NULL,
    expires_at     DATETIME     NOT NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_selector (selector),
    KEY idx_user (user_id),
    KEY idx_expires (expires_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;
