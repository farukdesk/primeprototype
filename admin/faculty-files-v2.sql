-- Faculty Files v2 Migration
-- Run once to add internal-file visibility flag and delete-request workflow.

-- 1. Add is_internal flag to faculty_files
--    When 1, only the uploader, super admin, and Register Office can see the file.
ALTER TABLE `faculty_files`
    ADD COLUMN IF NOT EXISTS `is_internal` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = hidden from the faculty member; visible only to uploader/admin/register-office'
    AFTER `description`;

-- 2. Table for pending delete requests
CREATE TABLE IF NOT EXISTS `faculty_file_delete_requests` (
    `id`             INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `file_id`        INT UNSIGNED      NOT NULL,
    `file_name`      VARCHAR(255)      NOT NULL COMMENT 'Snapshot of file label at request time',
    `faculty_user_id` INT UNSIGNED     NOT NULL COMMENT 'Owning faculty user',
    `faculty_name`   VARCHAR(255)      NOT NULL,
    `stored_name`    VARCHAR(255)      NOT NULL COMMENT 'Snapshot of stored filename for later cleanup',
    `requested_by`   INT UNSIGNED      NOT NULL,
    `request_note`   TEXT              DEFAULT NULL,
    `status`         ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    `reviewed_by`    INT UNSIGNED      DEFAULT NULL,
    `reviewed_at`    DATETIME          DEFAULT NULL,
    `review_note`    TEXT              DEFAULT NULL,
    `created_at`     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_fdr_file`      (`file_id`),
    KEY `idx_fdr_requester` (`requested_by`),
    KEY `idx_fdr_status`    (`status`),
    CONSTRAINT `fk_fdr_file`      FOREIGN KEY (`file_id`)      REFERENCES `faculty_files`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_fdr_requester` FOREIGN KEY (`requested_by`) REFERENCES `users`(`id`)         ON DELETE CASCADE,
    CONSTRAINT `fk_fdr_reviewer`  FOREIGN KEY (`reviewed_by`)  REFERENCES `users`(`id`)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Register a module for the pending-deletes review page (super-admin-only)
INSERT IGNORE INTO `modules` (`name`, `slug`, `is_active`, `created_at`)
SELECT 'Faculty File Delete Requests', 'faculty-file-deletes', 1, NOW()
WHERE NOT EXISTS (SELECT 1 FROM `modules` WHERE `slug` = 'faculty-file-deletes');
