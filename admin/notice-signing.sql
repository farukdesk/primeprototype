-- ============================================================
-- Admin Notice Signing Module
-- Run this script once to install the module.
-- ============================================================

-- ── 1. Add signature column to users ────────────────────────
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `signature_file` VARCHAR(255) DEFAULT NULL
        COMMENT 'PNG signature image uploaded by the user';

-- ── 2. Notice documents table ────────────────────────────────
CREATE TABLE IF NOT EXISTS `notice_documents` (
    `id`              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `title`           VARCHAR(255)     NOT NULL,
    `description`     TEXT,
    `document_file`   VARCHAR(255)     NOT NULL COMMENT 'Stored filename of the uploaded PDF or image',
    `original_name`   VARCHAR(255)     NOT NULL,
    `document_type`   ENUM('pdf','image') NOT NULL DEFAULT 'pdf',
    `created_by`      INT UNSIGNED     NOT NULL,
    `status`          ENUM('draft','active','completed') NOT NULL DEFAULT 'draft',
    `completed_at`    DATETIME         DEFAULT NULL,
    `fm_file_id`      INT UNSIGNED     DEFAULT NULL COMMENT 'Link to file_manager_files once completed',
    `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_created_by` (`created_by`),
    KEY `idx_status`     (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Sign positions table ───────────────────────────────────
CREATE TABLE IF NOT EXISTS `notice_sign_positions` (
    `id`              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `document_id`     INT UNSIGNED     NOT NULL,
    `user_id`         INT UNSIGNED     NOT NULL,
    `page_num`        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `x_percent`       DECIMAL(6,3)     NOT NULL DEFAULT 0 COMMENT 'Left offset as % of document width',
    `y_percent`       DECIMAL(6,3)     NOT NULL DEFAULT 0 COMMENT 'Top offset as % of document height',
    `sort_order`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_doc_user` (`document_id`, `user_id`),
    KEY `idx_document` (`document_id`),
    KEY `idx_user`     (`user_id`),
    CONSTRAINT `fk_nsp_doc`  FOREIGN KEY (`document_id`) REFERENCES `notice_documents`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_nsp_user` FOREIGN KEY (`user_id`)     REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. Signatures table ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS `notice_signatures` (
    `id`              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `document_id`     INT UNSIGNED     NOT NULL,
    `user_id`         INT UNSIGNED     NOT NULL,
    `position_id`     INT UNSIGNED     DEFAULT NULL,
    `signed_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ip_address`      VARCHAR(45)      DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_doc_signer` (`document_id`, `user_id`),
    KEY `idx_document` (`document_id`),
    KEY `idx_user`     (`user_id`),
    CONSTRAINT `fk_ns_doc`  FOREIGN KEY (`document_id`) REFERENCES `notice_documents`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ns_user` FOREIGN KEY (`user_id`)     REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ns_pos`  FOREIGN KEY (`position_id`) REFERENCES `notice_sign_positions`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 5. Module registrations ───────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`, `is_active`)
VALUES
    ('Notice Signing', 'notice-signing', 'Create admin notices and collect digital signatures', 'fas fa-file-signature', 81, 1),
    ('My Signature',   'my-signature',   'Upload and manage your personal signature image',     'fas fa-pen-nib',        82, 1);
