-- ============================================================================
-- Staff Attendance – ADMS / iclock (ZKTeco Push) integration – DB migration
-- ============================================================================
-- Lets ZKTeco "Cloud Server (ADMS)" devices (e.g. ZKTeco ECO) push punches
-- straight into the Staff Attendance module. The device dials out to
-- http(s)://<server>:<port>/iclock/… and uploads attendance logs; the receiver
-- at /iclock/index.php authenticates the device by its serial number, folds the
-- punches into att_records and keeps a raw punch + request audit trail.
--
-- Security model (device only sends its serial number, so we layer controls):
--   • att_devices whitelists the exact serial number; unknown SNs are rejected.
--   • Optional per-device IP allow-list (att_devices.allowed_ips).
--   • Optional per-device shared secret (att_devices.secret_key), compared with
--     hash_equals(); useful when a reverse proxy / firmware can add ?key=…
--   • Every request is audited in att_device_log (ip, endpoint, counts, result).
--   • Punches are de-duplicated by a unique (device_id, pin, punch_time) key so
--     device re-sends are idempotent.
--
-- PIN → user mapping lives in att_device_users. A row with device_id = 0 applies
-- to every device; a row with a specific device_id overrides the global one.
--
-- Safe to run multiple times.
-- ============================================================================

-- ── Registered devices (serial-number whitelist) ────────────────────────────
CREATE TABLE IF NOT EXISTS `att_devices` (
    `id`           int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `serial_no`    varchar(64)      NOT NULL COMMENT 'Device serial number (SN) reported by the device',
    `name`         varchar(120)     NOT NULL DEFAULT '' COMMENT 'Friendly name, e.g. "Main Gate"',
    `location`     varchar(160)     NOT NULL DEFAULT '' COMMENT 'Physical location / building',
    `secret_key`   varchar(128)     DEFAULT NULL COMMENT 'Optional shared secret required as ?key= / Authorization; NULL disables',
    `allowed_ips`  varchar(255)     DEFAULT NULL COMMENT 'Optional CSV of allowed source IPs; NULL/empty allows any',
    `is_active`    tinyint(1)       NOT NULL DEFAULT 1 COMMENT 'Only active devices are accepted',
    `last_seen_at` datetime         DEFAULT NULL COMMENT 'Last time the device contacted the server',
    `last_ip`      varchar(45)      DEFAULT NULL COMMENT 'Source IP of the last request',
    `last_stamp`   varchar(32)      DEFAULT NULL COMMENT 'Last ATTLOG stamp cursor reported by the device',
    `last_push_at` datetime         DEFAULT NULL COMMENT 'Last time punches were uploaded',
    `created_at`   datetime         NOT NULL DEFAULT current_timestamp(),
    `updated_at`   datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_serial` (`serial_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── PIN → ERP user mapping ──────────────────────────────────────────────────
-- device_id = 0 → mapping applies to all devices; a device-specific row wins.
CREATE TABLE IF NOT EXISTS `att_device_users` (
    `id`         int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_id`  int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'FK att_devices.id, or 0 for all devices',
    `pin`        varchar(32)      NOT NULL COMMENT 'Enrollment ID / PIN configured on the device',
    `user_id`    int(10) UNSIGNED NOT NULL COMMENT 'FK users.id this PIN maps to',
    `is_active`  tinyint(1)       NOT NULL DEFAULT 1,
    `created_at` datetime         NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_device_pin` (`device_id`, `pin`),
    KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Raw punch log (source of truth for re-computation & auditing) ───────────
CREATE TABLE IF NOT EXISTS `att_punch_log` (
    `id`          bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_id`   int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'FK att_devices.id',
    `serial_no`   varchar(64)      NOT NULL DEFAULT '',
    `pin`         varchar(32)      NOT NULL COMMENT 'Raw PIN from the device',
    `user_id`     int(10) UNSIGNED DEFAULT NULL COMMENT 'Resolved users.id, or NULL when PIN is unmapped',
    `punch_time`  datetime         NOT NULL COMMENT 'Punch timestamp reported by the device',
    `work_date`   date             NOT NULL COMMENT 'Date part of punch_time (device timezone)',
    `status_code` varchar(8)       DEFAULT NULL COMMENT 'ZK status field (0=in,1=out,…)',
    `verify_mode` varchar(8)       DEFAULT NULL COMMENT 'ZK verify field (1=fp,15=face,…)',
    `raw_line`    varchar(255)     DEFAULT NULL COMMENT 'Original tab-delimited record',
    `created_at`  datetime         NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_punch` (`device_id`, `pin`, `punch_time`),
    KEY `idx_user_date` (`user_id`, `work_date`),
    KEY `idx_work_date` (`work_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Request audit log ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `att_device_log` (
    `id`         bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_id`  int(10) UNSIGNED DEFAULT NULL COMMENT 'FK att_devices.id when the SN is known',
    `serial_no`  varchar(64)      DEFAULT NULL COMMENT 'SN reported on the request',
    `ip`         varchar(45)      DEFAULT NULL COMMENT 'Source IP',
    `endpoint`   varchar(32)      DEFAULT NULL COMMENT 'cdata / getrequest / …',
    `method`     varchar(8)       DEFAULT NULL COMMENT 'HTTP method',
    `table_name` varchar(32)      DEFAULT NULL COMMENT 'ZK table param (ATTLOG, OPERLOG, OPTIONS…)',
    `received`     int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Records parsed from the body',
    `stored_count` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'New punches stored',
    `http_status` smallint(5) UNSIGNED NOT NULL DEFAULT 200,
    `result`     varchar(24)      DEFAULT NULL COMMENT 'ok / unknown_sn / inactive / ip_blocked / bad_secret / …',
    `note`       varchar(255)     DEFAULT NULL,
    `created_at` datetime         NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_device` (`device_id`, `created_at`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
