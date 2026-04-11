-- Lead Management – Facebook Messenger Integration
-- Run AFTER leads.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. lead_fb_settings: key-value store for Facebook App credentials
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `lead_fb_settings` (
  `key`        VARCHAR(100) NOT NULL PRIMARY KEY,
  `value`      TEXT         DEFAULT NULL,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default keys so the settings form always has rows to UPDATE
INSERT IGNORE INTO `lead_fb_settings` (`key`, `value`) VALUES
  ('page_id',           ''),
  ('page_access_token', ''),
  ('verify_token',      ''),
  ('app_secret',        '');

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. lead_fb_contacts: one row per Facebook user (identified by PSID)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `lead_fb_contacts` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `lead_id`         INT UNSIGNED  DEFAULT NULL COMMENT 'Linked lead record (nullable)',
  `psid`            VARCHAR(100)  NOT NULL UNIQUE COMMENT 'Facebook Page-Scoped User ID',
  `fb_name`         VARCHAR(300)  DEFAULT NULL,
  `fb_picture`      VARCHAR(500)  DEFAULT NULL,
  `first_seen`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_message_at` DATETIME      DEFAULT NULL,
  KEY `idx_fbc_lead` (`lead_id`),
  CONSTRAINT `fk_fbc_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. lead_fb_messages: all Messenger messages (incoming and outgoing)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `lead_fb_messages` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `contact_id`      INT UNSIGNED  NOT NULL,
  `direction`       ENUM('in','out') NOT NULL COMMENT 'in = from Facebook user, out = sent by staff',
  `message_text`    TEXT          DEFAULT NULL,
  `attachment_type` VARCHAR(50)   DEFAULT NULL COMMENT 'image/audio/video/file/sticker',
  `attachment_url`  VARCHAR(1000) DEFAULT NULL,
  `fb_mid`          VARCHAR(200)  DEFAULT NULL UNIQUE COMMENT 'Facebook message_id for deduplication',
  `sent_by`         INT UNSIGNED  DEFAULT NULL COMMENT 'users.id for outgoing messages',
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_fbm_contact`   (`contact_id`),
  KEY `idx_fbm_direction` (`direction`),
  KEY `idx_fbm_created`   (`created_at`),
  CONSTRAINT `fk_fbm_contact`  FOREIGN KEY (`contact_id`) REFERENCES `lead_fb_contacts`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fbm_sent_by` FOREIGN KEY (`sent_by`)    REFERENCES `users`(`id`)              ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. Add 'facebook' as a lead source
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE `leads`
  MODIFY COLUMN `source`
    ENUM('online','campus_visit','agent','f2f_marketing','facebook')
    NOT NULL DEFAULT 'online';

SET FOREIGN_KEY_CHECKS = 1;
