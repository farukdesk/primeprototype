-- ============================================================
-- News & Notice – v2 migration
-- Adds approval workflow + pending change queue + change log
-- Run after database.sql and cms-homepage-v2.sql
-- ============================================================

-- -------------------------------------------------------
-- 1. Add creator & approval columns to cms_news
-- -------------------------------------------------------
ALTER TABLE `cms_news`
    ADD COLUMN IF NOT EXISTS `created_by`  INT UNSIGNED DEFAULT NULL COMMENT 'FK users.id'    AFTER `is_published`,
    ADD COLUMN IF NOT EXISTS `is_approved` TINYINT(1)   NOT NULL DEFAULT 1
                             COMMENT '0=pending super-admin approval, 1=approved' AFTER `created_by`,
    ADD COLUMN IF NOT EXISTS `approved_by` INT UNSIGNED DEFAULT NULL AFTER `is_approved`,
    ADD COLUMN IF NOT EXISTS `approved_at` DATETIME     DEFAULT NULL AFTER `approved_by`;

ALTER TABLE `cms_news`
    ADD KEY IF NOT EXISTS `idx_news_approved` (`is_approved`),
    ADD KEY IF NOT EXISTS `idx_news_creator`  (`created_by`);

-- -------------------------------------------------------
-- 2. Add creator & approval columns to cms_notices
-- -------------------------------------------------------
ALTER TABLE `cms_notices`
    ADD COLUMN IF NOT EXISTS `created_by`  INT UNSIGNED DEFAULT NULL COMMENT 'FK users.id'    AFTER `is_published`,
    ADD COLUMN IF NOT EXISTS `is_approved` TINYINT(1)   NOT NULL DEFAULT 1
                             COMMENT '0=pending super-admin approval, 1=approved' AFTER `created_by`,
    ADD COLUMN IF NOT EXISTS `approved_by` INT UNSIGNED DEFAULT NULL AFTER `is_approved`,
    ADD COLUMN IF NOT EXISTS `approved_at` DATETIME     DEFAULT NULL AFTER `approved_by`;

ALTER TABLE `cms_notices`
    ADD KEY IF NOT EXISTS `idx_notice_approved` (`is_approved`),
    ADD KEY IF NOT EXISTS `idx_notice_creator`  (`created_by`);

-- -------------------------------------------------------
-- 3. Pending change requests (edit / delete workflows)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cms_pending_changes` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `module`       ENUM('news','notice') NOT NULL COMMENT 'Which table the change targets',
    `record_id`    INT UNSIGNED NOT NULL,
    `record_title` VARCHAR(500) NOT NULL,
    `action`       ENUM('EDIT','DELETE') NOT NULL,
    `requested_by` INT UNSIGNED NOT NULL COMMENT 'FK users.id',
    `payload`      LONGTEXT DEFAULT NULL COMMENT 'JSON of proposed new field values (EDIT only)',
    `status`       ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    `reviewed_by`  INT UNSIGNED DEFAULT NULL,
    `reviewed_at`  DATETIME DEFAULT NULL,
    `review_note`  TEXT DEFAULT NULL,
    `created_at`   DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_pch_module_status` (`module`, `status`),
    KEY `idx_pch_record`        (`module`, `record_id`),
    KEY `idx_pch_requester`     (`requested_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- 4. Register modules (INSERT IGNORE = safe to re-run)
-- -------------------------------------------------------
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`)
VALUES
    ('Notice Board',      'cms-notice-board',   'Manage public notices',                        'fas fa-bullhorn',     12),
    ('Pending Approvals', 'cms-pending-changes', 'Review & approve pending news/notice changes', 'fas fa-clock',        13);
