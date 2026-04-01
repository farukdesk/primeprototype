-- Change Log Module – Database Schema
-- Records every create / update / delete action performed by admin users.

CREATE TABLE IF NOT EXISTS `change_log` (
    `id`           INT UNSIGNED                   NOT NULL AUTO_INCREMENT,
    `user_id`      INT UNSIGNED                   NOT NULL COMMENT 'User who made the change',
    `module`       VARCHAR(100)                   NOT NULL COMMENT 'Module / section (e.g. users, knowledge-base)',
    `record_id`    INT UNSIGNED                   DEFAULT NULL COMMENT 'PK of the affected record',
    `record_label` VARCHAR(255)                   DEFAULT NULL COMMENT 'Human-readable record identifier',
    `action`       ENUM('CREATE','UPDATE','DELETE') NOT NULL DEFAULT 'UPDATE',
    `field_name`   VARCHAR(150)                   DEFAULT NULL COMMENT 'Specific field that changed (NULL = whole record)',
    `old_value`    TEXT                           DEFAULT NULL,
    `new_value`    TEXT                           DEFAULT NULL,
    `description`  TEXT                           DEFAULT NULL COMMENT 'Optional free-text summary',
    `ip_address`   VARCHAR(45)                    NOT NULL DEFAULT '',
    `created_at`   DATETIME                       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_cl_user`    (`user_id`),
    KEY `idx_cl_module`  (`module`),
    KEY `idx_cl_created` (`created_at`),
    CONSTRAINT `fk_cl_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
