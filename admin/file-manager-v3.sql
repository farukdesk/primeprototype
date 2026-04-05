-- ============================================================
-- File Manager v3 – Full feature upgrade
-- Run after file-manager.sql and file-manager-v2.sql
-- Adds: initiator info, transfer system, multi-page uploads,
--        tagged-user visibility, signature-on-notes,
--        and email notification templates.
-- ============================================================

-- ── 1. Add new columns to file_manager_files ─────────────────
ALTER TABLE `file_manager_files`
    ADD COLUMN IF NOT EXISTS `initiator_name`        VARCHAR(150)  DEFAULT NULL COMMENT 'Name of the person who initiated the file'        AFTER `page_number`,
    ADD COLUMN IF NOT EXISTS `initiator_department`  VARCHAR(200)  DEFAULT NULL COMMENT 'Department of the initiator'                       AFTER `initiator_name`,
    ADD COLUMN IF NOT EXISTS `initiator_designation` VARCHAR(200)  DEFAULT NULL COMMENT 'Designation / job title of the initiator'         AFTER `initiator_department`,
    ADD COLUMN IF NOT EXISTS `current_holder_id`     INT UNSIGNED  DEFAULT NULL COMMENT 'User currently holding / responsible for the file' AFTER `initiator_designation`;

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

-- ── 7. Email templates ────────────────────────────────────────

-- Transfer Request (to the recipient)
INSERT IGNORE INTO `email_templates` (`name`, `action`, `subject`, `body_html`, `variables`, `is_active`) VALUES (
  'File Transfer Request',
  'fm_transfer_request',
  'File Transfer Request: {{file_name}}',
  '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;background:#f4f4f4;padding:20px;">
<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1);">
  <div style="background:#4f8ef7;padding:24px 32px;">
    <img src="{{logo_url}}" alt="{{app_name}}" style="height:40px;"><br>
    <h2 style="color:#fff;margin:12px 0 0;">File Transfer Request</h2>
  </div>
  <div style="padding:32px;">
    <p>Dear <strong>{{recipient_name}}</strong>,</p>
    <p><strong>{{sender_name}}</strong> has requested to transfer the following file to you:</p>
    <div style="background:#f8f9ff;border-left:4px solid #4f8ef7;padding:16px;border-radius:0 8px 8px 0;margin:16px 0;">
      <strong>File:</strong> {{file_name}}<br>
      <strong>Category:</strong> {{file_category}}<br>
      <strong>Sent by:</strong> {{sender_name}} ({{sender_dept}})<br>
      <strong>Message:</strong> {{transfer_message}}
    </div>
    <p>Please log in to accept or reject this transfer:</p>
    <p style="text-align:center;margin:24px 0;">
      <a href="{{transfer_url}}" style="background:#4f8ef7;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:bold;">Review Transfer</a>
    </p>
    <p style="color:#888;font-size:.85rem;">This is an automated notification from {{app_name}}.</p>
  </div>
</div>
</body></html>',
  '{{recipient_name}},{{sender_name}},{{sender_dept}},{{file_name}},{{file_category}},{{transfer_message}},{{transfer_url}},{{app_name}},{{logo_url}}',
  1
);

-- Transfer Accepted (to the sender)
INSERT IGNORE INTO `email_templates` (`name`, `action`, `subject`, `body_html`, `variables`, `is_active`) VALUES (
  'File Transfer Accepted',
  'fm_transfer_accepted',
  'Transfer Accepted: {{file_name}}',
  '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;background:#f4f4f4;padding:20px;">
<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1);">
  <div style="background:#27ae60;padding:24px 32px;">
    <img src="{{logo_url}}" alt="{{app_name}}" style="height:40px;"><br>
    <h2 style="color:#fff;margin:12px 0 0;">Transfer Accepted</h2>
  </div>
  <div style="padding:32px;">
    <p>Dear <strong>{{sender_name}}</strong>,</p>
    <p><strong>{{recipient_name}}</strong> has <strong style="color:#27ae60;">accepted</strong> the file transfer for:</p>
    <div style="background:#f0fdf4;border-left:4px solid #27ae60;padding:16px;border-radius:0 8px 8px 0;margin:16px 0;">
      <strong>File:</strong> {{file_name}}<br>
      <strong>New Holder:</strong> {{recipient_name}}<br>
      <strong>Note:</strong> {{response_note}}
    </div>
    <p style="text-align:center;margin:24px 0;">
      <a href="{{file_url}}" style="background:#27ae60;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:bold;">View File</a>
    </p>
    <p style="color:#888;font-size:.85rem;">This is an automated notification from {{app_name}}.</p>
  </div>
</div>
</body></html>',
  '{{sender_name}},{{recipient_name}},{{file_name}},{{response_note}},{{file_url}},{{app_name}},{{logo_url}}',
  1
);

-- Transfer Rejected (to the sender)
INSERT IGNORE INTO `email_templates` (`name`, `action`, `subject`, `body_html`, `variables`, `is_active`) VALUES (
  'File Transfer Rejected',
  'fm_transfer_rejected',
  'Transfer Declined: {{file_name}}',
  '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;background:#f4f4f4;padding:20px;">
<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1);">
  <div style="background:#e74c3c;padding:24px 32px;">
    <img src="{{logo_url}}" alt="{{app_name}}" style="height:40px;"><br>
    <h2 style="color:#fff;margin:12px 0 0;">Transfer Declined</h2>
  </div>
  <div style="padding:32px;">
    <p>Dear <strong>{{sender_name}}</strong>,</p>
    <p><strong>{{recipient_name}}</strong> has <strong style="color:#e74c3c;">declined</strong> the file transfer for:</p>
    <div style="background:#fff5f5;border-left:4px solid #e74c3c;padding:16px;border-radius:0 8px 8px 0;margin:16px 0;">
      <strong>File:</strong> {{file_name}}<br>
      <strong>Declined by:</strong> {{recipient_name}}<br>
      <strong>Reason:</strong> {{response_note}}
    </div>
    <p>The file remains with you. You may assign it to someone else if needed.</p>
    <p style="text-align:center;margin:24px 0;">
      <a href="{{file_url}}" style="background:#4f8ef7;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:bold;">View File</a>
    </p>
    <p style="color:#888;font-size:.85rem;">This is an automated notification from {{app_name}}.</p>
  </div>
</div>
</body></html>',
  '{{sender_name}},{{recipient_name}},{{file_name}},{{response_note}},{{file_url}},{{app_name}},{{logo_url}}',
  1
);

-- Tagged on file notification
INSERT IGNORE INTO `email_templates` (`name`, `action`, `subject`, `body_html`, `variables`, `is_active`) VALUES (
  'File Tagged Notification',
  'fm_file_tagged',
  'You have been given access to: {{file_name}}',
  '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;background:#f4f4f4;padding:20px;">
<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1);">
  <div style="background:#8e44ad;padding:24px 32px;">
    <img src="{{logo_url}}" alt="{{app_name}}" style="height:40px;"><br>
    <h2 style="color:#fff;margin:12px 0 0;">File Access Granted</h2>
  </div>
  <div style="padding:32px;">
    <p>Dear <strong>{{tagged_user_name}}</strong>,</p>
    <p><strong>{{tagged_by_name}}</strong> has granted you access to a file:</p>
    <div style="background:#f9f0ff;border-left:4px solid #8e44ad;padding:16px;border-radius:0 8px 8px 0;margin:16px 0;">
      <strong>File:</strong> {{file_name}}<br>
      <strong>Category:</strong> {{file_category}}<br>
      <strong>Added by:</strong> {{tagged_by_name}}
    </div>
    <p style="text-align:center;margin:24px 0;">
      <a href="{{file_url}}" style="background:#8e44ad;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:bold;">View File</a>
    </p>
    <p style="color:#888;font-size:.85rem;">This is an automated notification from {{app_name}}.</p>
  </div>
</div>
</body></html>',
  '{{tagged_user_name}},{{tagged_by_name}},{{file_name}},{{file_category}},{{file_url}},{{app_name}},{{logo_url}}',
  1
);

-- Note Signing Request notification
INSERT IGNORE INTO `email_templates` (`name`, `action`, `subject`, `body_html`, `variables`, `is_active`) VALUES (
  'Note Signing Request',
  'fm_sign_request',
  'Your Signature is Required: {{file_name}} – {{page_subject}}',
  '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;background:#f4f4f4;padding:20px;">
<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1);">
  <div style="background:#e67e22;padding:24px 32px;">
    <img src="{{logo_url}}" alt="{{app_name}}" style="height:40px;"><br>
    <h2 style="color:#fff;margin:12px 0 0;">Signature Required</h2>
  </div>
  <div style="padding:32px;">
    <p>Dear <strong>{{signer_name}}</strong>,</p>
    <p>Your signature is required on the following note:</p>
    <div style="background:#fff8f0;border-left:4px solid #e67e22;padding:16px;border-radius:0 8px 8px 0;margin:16px 0;">
      <strong>File:</strong> {{file_name}}<br>
      <strong>Note Subject:</strong> {{page_subject}}<br>
      <strong>Page:</strong> Page {{page_number}}<br>
      <strong>Requested by:</strong> {{requester_name}}
    </div>
    <p style="text-align:center;margin:24px 0;">
      <a href="{{sign_url}}" style="background:#e67e22;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:bold;">Review &amp; Sign</a>
    </p>
    <p style="color:#888;font-size:.85rem;">This is an automated notification from {{app_name}}.</p>
  </div>
</div>
</body></html>',
  '{{signer_name}},{{requester_name}},{{file_name}},{{page_subject}},{{page_number}},{{sign_url}},{{app_name}},{{logo_url}}',
  1
);
