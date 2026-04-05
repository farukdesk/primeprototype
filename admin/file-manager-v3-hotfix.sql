-- ============================================================
-- File Manager v3 – Hotfix
-- Fixes: SQLSTATE[42S22] Unknown column 'f.current_holder_id'
--
-- Safe to run on MySQL 5.7+ and MariaDB 10.0+.
-- Use this if file-manager-v3.sql could not be applied (e.g.
-- the server does not support ADD COLUMN IF NOT EXISTS).
-- ============================================================

-- ── 1. Add v3 columns to file_manager_files (if missing) ─────

DROP PROCEDURE IF EXISTS `_fm_hotfix_add_columns`;
DELIMITER $$
CREATE PROCEDURE `_fm_hotfix_add_columns`()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'file_manager_files'
          AND COLUMN_NAME  = 'initiator_name'
    ) THEN
        ALTER TABLE `file_manager_files`
            ADD COLUMN `initiator_name` VARCHAR(150) DEFAULT NULL
                COMMENT 'Name of the person who initiated the file'
                AFTER `page_number`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'file_manager_files'
          AND COLUMN_NAME  = 'initiator_department'
    ) THEN
        ALTER TABLE `file_manager_files`
            ADD COLUMN `initiator_department` VARCHAR(200) DEFAULT NULL
                COMMENT 'Department of the initiator'
                AFTER `initiator_name`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'file_manager_files'
          AND COLUMN_NAME  = 'initiator_designation'
    ) THEN
        ALTER TABLE `file_manager_files`
            ADD COLUMN `initiator_designation` VARCHAR(200) DEFAULT NULL
                COMMENT 'Designation / job title of the initiator'
                AFTER `initiator_department`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'file_manager_files'
          AND COLUMN_NAME  = 'current_holder_id'
    ) THEN
        ALTER TABLE `file_manager_files`
            ADD COLUMN `current_holder_id` INT UNSIGNED DEFAULT NULL
                COMMENT 'User currently holding / responsible for the file'
                AFTER `initiator_designation`;
    END IF;
END$$
DELIMITER ;

CALL `_fm_hotfix_add_columns`();
DROP PROCEDURE IF EXISTS `_fm_hotfix_add_columns`;

-- ── 2. Tagged users (visibility control) ─────────────────────

CREATE TABLE IF NOT EXISTS `file_manager_tagged_users` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `file_id`    INT UNSIGNED NOT NULL,
    `user_id`    INT UNSIGNED NOT NULL,
    `tagged_by`  INT UNSIGNED NOT NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_file_user` (`file_id`, `user_id`),
    KEY `idx_user`            (`user_id`),
    CONSTRAINT `fk_fmtu_file` FOREIGN KEY (`file_id`) REFERENCES `file_manager_files`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_fmtu_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)              ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Transfer requests ──────────────────────────────────────

CREATE TABLE IF NOT EXISTS `file_manager_transfers` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `file_id`       INT UNSIGNED NOT NULL,
    `from_user_id`  INT UNSIGNED NOT NULL,
    `to_user_id`    INT UNSIGNED NOT NULL,
    `status`        ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
    `message`       TEXT DEFAULT NULL          COMMENT 'Transfer request message',
    `response_note` TEXT DEFAULT NULL          COMMENT 'Reason when accepting/rejecting',
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `responded_at`  DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_file`    (`file_id`),
    KEY `idx_to_user` (`to_user_id`),
    KEY `idx_status`  (`status`),
    CONSTRAINT `fk_fmt_file`      FOREIGN KEY (`file_id`)      REFERENCES `file_manager_files`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_fmt_from_user` FOREIGN KEY (`from_user_id`) REFERENCES `users`(`id`)              ON DELETE CASCADE,
    CONSTRAINT `fk_fmt_to_user`   FOREIGN KEY (`to_user_id`)   REFERENCES `users`(`id`)              ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. Pages inside a file ────────────────────────────────────

CREATE TABLE IF NOT EXISTS `file_manager_pages` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `file_id`             INT UNSIGNED NOT NULL,
    `page_number`         INT UNSIGNED NOT NULL DEFAULT 1,
    `title`               VARCHAR(255) DEFAULT NULL,
    `category`            ENUM('Document','Notes') NOT NULL DEFAULT 'Document',
    `subject`             VARCHAR(300) DEFAULT NULL COMMENT 'Required when category = Notes',
    `uploaded_file`       VARCHAR(255) DEFAULT NULL,
    `original_name`       VARCHAR(255) DEFAULT NULL,
    `mime_type`           VARCHAR(100) DEFAULT NULL,
    `file_size`           INT UNSIGNED DEFAULT NULL,
    `requires_signature`  TINYINT(1)   NOT NULL DEFAULT 0,
    `created_by`          INT UNSIGNED DEFAULT NULL,
    `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_file`   (`file_id`),
    KEY `idx_cat`    (`category`),
    CONSTRAINT `fk_fmp_file` FOREIGN KEY (`file_id`) REFERENCES `file_manager_files`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 5. Sign positions on note pages ──────────────────────────

CREATE TABLE IF NOT EXISTS `file_manager_page_sign_positions` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `page_id`     INT UNSIGNED NOT NULL,
    `user_id`     INT UNSIGNED NOT NULL,
    `x_percent`   DECIMAL(5,2) NOT NULL DEFAULT 50.00,
    `y_percent`   DECIMAL(5,2) NOT NULL DEFAULT 80.00,
    `sort_order`  INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_page_user` (`page_id`, `user_id`),
    CONSTRAINT `fk_fmpsp_page` FOREIGN KEY (`page_id`) REFERENCES `file_manager_pages`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_fmpsp_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)              ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 6. Applied signatures on pages ───────────────────────────

CREATE TABLE IF NOT EXISTS `file_manager_page_signatures` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `page_id`     INT UNSIGNED NOT NULL,
    `user_id`     INT UNSIGNED NOT NULL,
    `position_id` INT UNSIGNED DEFAULT NULL,
    `ip_address`  VARCHAR(45)  DEFAULT NULL,
    `signed_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_page_user` (`page_id`, `user_id`),
    CONSTRAINT `fk_fmps_page` FOREIGN KEY (`page_id`) REFERENCES `file_manager_pages`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_fmps_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)              ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
