-- ---------------------------------------------------------------------------
-- Third-party Student API (v1) — API clients, request audit log, extra columns
--
-- Backs the endpoints under admin/api/v1/ that let external (partner)
-- applications create students directly in the `students` table.
--
--   * api_clients          — one row per partner application. Only a SHA-256
--                            hash of the key is stored; the plain key is shown
--                            once when issued (admin/api/v1/bin/create-client.php).
--   * api_client_requests  — every authenticated request. Used for the
--                            per-minute rate limit, X-Idempotency-Key replay
--                            and auditing which client created which student.
--   * students             — new optional columns requested by the API spec:
--                            permanent contact no / email, guardian email and
--                            yearly income, plus the originating api_client_id.
--
-- Run this once against the application database.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `api_clients` (
    `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`               VARCHAR(150) NOT NULL COMMENT 'Partner / application name',
    `key_prefix`         CHAR(12)     NOT NULL COMMENT 'First 12 chars of the key, for identification only',
    `key_hash`           CHAR(64)     NOT NULL COMMENT 'sha256(full API key)',
    `scopes`             VARCHAR(500) NOT NULL DEFAULT 'students:create,reference:read'
                                      COMMENT 'Comma-separated scopes; * = all',
    `ip_allowlist`       TEXT         DEFAULT NULL COMMENT 'Comma-separated IPs / CIDRs; NULL = any IP',
    `rate_limit_per_min` SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    `default_status`     ENUM('Active','Inactive','Graduated','Dropped','Not Admitted Yet')
                                      NOT NULL DEFAULT 'Not Admitted Yet'
                                      COMMENT 'students.status used when the client omits it',
    `contact_email`      VARCHAR(255) DEFAULT NULL,
    `is_active`          TINYINT(1)   NOT NULL DEFAULT 1,
    `expires_at`         DATETIME     DEFAULT NULL COMMENT 'NULL = never expires',
    `last_used_at`       DATETIME     DEFAULT NULL,
    `created_by`         INT          DEFAULT NULL COMMENT 'users.id of the admin who issued the key; becomes students.created_by',
    `created_at`         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_api_clients_key_hash` (`key_hash`),
    KEY `idx_api_clients_prefix` (`key_prefix`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `api_client_requests` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`       INT UNSIGNED  NOT NULL,
    `endpoint`        VARCHAR(190)  NOT NULL,
    `method`          VARCHAR(10)   NOT NULL,
    `idempotency_key` VARCHAR(100)  DEFAULT NULL,
    `status_code`     SMALLINT UNSIGNED DEFAULT NULL COMMENT 'NULL while the request is still running',
    `response_body`   MEDIUMTEXT    DEFAULT NULL COMMENT 'Stored only for 2xx responses that carried an idempotency key',
    `student_db_id`   INT           DEFAULT NULL COMMENT 'students.id created by this request, if any',
    `ip_address`      VARCHAR(45)   DEFAULT NULL,
    `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_api_client_requests_idem` (`client_id`, `idempotency_key`),
    KEY `idx_api_client_requests_rate` (`client_id`, `created_at`),
    CONSTRAINT `fk_api_client_requests_client`
        FOREIGN KEY (`client_id`) REFERENCES `api_clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `students`
    ADD COLUMN `permanent_phone`        VARCHAR(50)   DEFAULT NULL AFTER `permanent_address`,
    ADD COLUMN `permanent_email`        VARCHAR(255)  DEFAULT NULL AFTER `permanent_phone`,
    ADD COLUMN `guardian_email`         VARCHAR(255)  DEFAULT NULL AFTER `guardian_relationship`,
    ADD COLUMN `guardian_yearly_income` DECIMAL(12,2) DEFAULT NULL AFTER `guardian_email`,
    ADD COLUMN `api_client_id`          INT UNSIGNED  DEFAULT NULL
        COMMENT 'api_clients.id when the record was created through the third-party API'
        AFTER `created_by`,
    ADD INDEX `idx_students_api_client` (`api_client_id`);

-- Optional housekeeping (run from cron if the log grows large):
--   DELETE FROM api_client_requests WHERE created_at < NOW() - INTERVAL 90 DAY;
