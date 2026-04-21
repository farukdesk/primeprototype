-- Lead Call Logs – Migration
-- Run AFTER leads.sql, leads-call-time.sql, leads-followup.sql

SET NAMES utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. Create lead_call_logs table
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `lead_call_logs` (
  `id`               INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `lead_id`          INT UNSIGNED     NOT NULL,
  `user_id`          INT UNSIGNED     NOT NULL COMMENT 'Staff who made/logged the call',
  `call_type`        ENUM('1st_call','2nd_call','3rd_call','followup_call','visit_confirmation','final_call') NOT NULL DEFAULT '1st_call',
  `previous_status`  VARCHAR(50)      DEFAULT NULL,
  `new_status`       VARCHAR(50)      DEFAULT NULL,
  `call_date`        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `call_duration`    SMALLINT UNSIGNED DEFAULT NULL COMMENT 'Duration in minutes',
  `call_notes`       TEXT             DEFAULT NULL,
  `call_outcome`     VARCHAR(100)     DEFAULT NULL,
  `call_converted`   TINYINT(1)       NOT NULL DEFAULT 0 COMMENT '1 if call led to conversion',
  `created_at`       TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_clog_lead`   (`lead_id`),
  KEY `idx_clog_user`   (`user_id`),
  KEY `idx_clog_date`   (`call_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
