-- ═══════════════════════════════════════════════════════════════════════════
--  IT Support Tickets Module
--  Run AFTER admin/database.sql
-- ═══════════════════════════════════════════════════════════════════════════

-- ── Main tickets table ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `support_tickets` (
  `id`            int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_number` varchar(25)      NOT NULL,
  `title`         varchar(500)     NOT NULL,
  `description`   text             NOT NULL,
  `category`      enum('Hardware','Software','Network','Email','Other') NOT NULL DEFAULT 'Other',
  `priority`      enum('Low','Medium','High','Critical')                NOT NULL DEFAULT 'Medium',
  `status`        enum('Open','In Progress','Pending','Resolved','Closed','Reopened') NOT NULL DEFAULT 'Open',
  `department`    varchar(200)     DEFAULT NULL,
  `deadline`      datetime         DEFAULT NULL,
  `created_by`    int(10) UNSIGNED NOT NULL,
  `assigned_to`   int(10) UNSIGNED DEFAULT NULL,
  `created_at`    datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `resolved_at`   datetime         DEFAULT NULL,
  `closed_at`     datetime         DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ticket_number` (`ticket_number`),
  KEY `idx_st_created_by`  (`created_by`),
  KEY `idx_st_assigned_to` (`assigned_to`),
  KEY `idx_st_status`      (`status`),
  KEY `idx_st_priority`    (`priority`),
  CONSTRAINT `fk_st_created_by`  FOREIGN KEY (`created_by`)  REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_st_assigned_to` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Ticket attachments ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `support_ticket_attachments` (
  `id`            int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id`     int(10) UNSIGNED NOT NULL,
  `original_name` varchar(500)     NOT NULL,
  `stored_name`   varchar(120)     NOT NULL,
  `mime_type`     varchar(200)     NOT NULL,
  `file_size`     int(10) UNSIGNED NOT NULL DEFAULT 0,
  `uploaded_by`   int(10) UNSIGNED NOT NULL,
  `uploaded_at`   datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sta_ticket` (`ticket_id`),
  CONSTRAINT `fk_sta_ticket` FOREIGN KEY (`ticket_id`)   REFERENCES `support_tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sta_user`   FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)           ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Comments ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `support_ticket_comments` (
  `id`          int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id`   int(10) UNSIGNED NOT NULL,
  `comment`     text             NOT NULL,
  `is_internal` tinyint(1)       NOT NULL DEFAULT 0,
  `created_by`  int(10) UNSIGNED NOT NULL,
  `created_at`  datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_stc_ticket` (`ticket_id`),
  CONSTRAINT `fk_stc_ticket` FOREIGN KEY (`ticket_id`)  REFERENCES `support_tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_stc_user`   FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)           ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Comment attachments ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `support_ticket_comment_attachments` (
  `id`            int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `comment_id`    int(10) UNSIGNED NOT NULL,
  `original_name` varchar(500)     NOT NULL,
  `stored_name`   varchar(120)     NOT NULL,
  `mime_type`     varchar(200)     NOT NULL,
  `file_size`     int(10) UNSIGNED NOT NULL DEFAULT 0,
  `uploaded_at`   datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_stca_comment` (`comment_id`),
  CONSTRAINT `fk_stca_comment` FOREIGN KEY (`comment_id`) REFERENCES `support_ticket_comments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Tagged users (email notifications) ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS `support_ticket_user_tags` (
  `id`        int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` int(10) UNSIGNED NOT NULL,
  `user_id`   int(10) UNSIGNED NOT NULL,
  `tagged_by` int(10) UNSIGNED NOT NULL,
  `tagged_at` datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ticket_user_tag` (`ticket_id`,`user_id`),
  CONSTRAINT `fk_stut_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_stut_user`   FOREIGN KEY (`user_id`)   REFERENCES `users` (`id`)           ON DELETE CASCADE,
  CONSTRAINT `fk_stut_tagger` FOREIGN KEY (`tagged_by`) REFERENCES `users` (`id`)           ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── SLA rules ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `support_sla_rules` (
  `id`       int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `priority` enum('Low','Medium','High','Critical') NOT NULL,
  `hours`    int(10) UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sla_priority` (`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `support_sla_rules` (`priority`, `hours`) VALUES
  ('Critical', 4),
  ('High',     24),
  ('Medium',   72),
  ('Low',      120)
ON DUPLICATE KEY UPDATE `hours` = VALUES(`hours`);

-- ── Register module ───────────────────────────────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `icon`, `sort_order`, `is_active`)
VALUES ('IT Support', 'support-tickets', 'fas fa-ticket-alt', 95, 1);

-- ── Email templates ───────────────────────────────────────────────────────────
INSERT IGNORE INTO `email_templates` (`action`, `subject`, `body_html`, `is_active`) VALUES

('ticket_created',
 '[{{app_name}}] Support Ticket #{{ticket_number}} Created',
 '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#f4f6fb;padding:24px;border-radius:10px;">
  <div style="background:#4f8ef7;color:#fff;padding:20px 28px;border-radius:8px 8px 0 0;">
    <h2 style="margin:0;font-size:1.3rem;">✅ IT Support Ticket Created</h2>
  </div>
  <div style="background:#fff;padding:28px;border-radius:0 0 8px 8px;border:1px solid #e0e7ef;border-top:none;">
    <p>Dear <strong>{{full_name}}</strong>,</p>
    <p>Your IT support ticket has been submitted successfully. Our team will review it shortly.</p>
    <table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:.9rem;">
      <tr><td style="padding:8px 12px;font-weight:600;background:#f0f4ff;width:140px;border:1px solid #e0e7ef;">Ticket #</td><td style="padding:8px 12px;border:1px solid #e0e7ef;">{{ticket_number}}</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#f0f4ff;border:1px solid #e0e7ef;">Title</td><td style="padding:8px 12px;border:1px solid #e0e7ef;">{{ticket_title}}</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#f0f4ff;border:1px solid #e0e7ef;">Priority</td><td style="padding:8px 12px;border:1px solid #e0e7ef;">{{ticket_priority}}</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#f0f4ff;border:1px solid #e0e7ef;">Category</td><td style="padding:8px 12px;border:1px solid #e0e7ef;">{{ticket_category}}</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#f0f4ff;border:1px solid #e0e7ef;">SLA Deadline</td><td style="padding:8px 12px;border:1px solid #e0e7ef;">{{ticket_deadline}}</td></tr>
    </table>
    <p><a href="{{ticket_url}}" style="background:#4f8ef7;color:#fff;padding:10px 22px;text-decoration:none;border-radius:6px;display:inline-block;font-weight:600;">View Ticket</a></p>
    <hr style="border:none;border-top:1px solid #eee;margin:24px 0 16px;">
    <p style="font-size:.8rem;color:#999;">This is an automated message from <strong>{{app_name}}</strong>. Please do not reply directly to this email.<br>Sent from: no_reply@primeuniversity.ac.bd</p>
  </div>
</div>',
1),

('ticket_assigned',
 '[{{app_name}}] Ticket #{{ticket_number}} Has Been Assigned to You',
 '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#f4f6fb;padding:24px;border-radius:10px;">
  <div style="background:#198754;color:#fff;padding:20px 28px;border-radius:8px 8px 0 0;">
    <h2 style="margin:0;font-size:1.3rem;">📋 Ticket Assigned to You</h2>
  </div>
  <div style="background:#fff;padding:28px;border-radius:0 0 8px 8px;border:1px solid #e0e7ef;border-top:none;">
    <p>Dear <strong>{{full_name}}</strong>,</p>
    <p>A support ticket has been assigned to you. Please review and respond promptly.</p>
    <table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:.9rem;">
      <tr><td style="padding:8px 12px;font-weight:600;background:#f0fff4;width:140px;border:1px solid #e0e7ef;">Ticket #</td><td style="padding:8px 12px;border:1px solid #e0e7ef;">{{ticket_number}}</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#f0fff4;border:1px solid #e0e7ef;">Title</td><td style="padding:8px 12px;border:1px solid #e0e7ef;">{{ticket_title}}</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#f0fff4;border:1px solid #e0e7ef;">Priority</td><td style="padding:8px 12px;border:1px solid #e0e7ef;">{{ticket_priority}}</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#f0fff4;border:1px solid #e0e7ef;">Submitted by</td><td style="padding:8px 12px;border:1px solid #e0e7ef;">{{submitter_name}}</td></tr>
    </table>
    <p><a href="{{ticket_url}}" style="background:#198754;color:#fff;padding:10px 22px;text-decoration:none;border-radius:6px;display:inline-block;font-weight:600;">View Ticket</a></p>
    <hr style="border:none;border-top:1px solid #eee;margin:24px 0 16px;">
    <p style="font-size:.8rem;color:#999;">This is an automated message from <strong>{{app_name}}</strong>.<br>Sent from: no_reply@primeuniversity.ac.bd</p>
  </div>
</div>',
1),

('ticket_status_changed',
 '[{{app_name}}] Ticket #{{ticket_number}} Status Updated to: {{new_status}}',
 '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#f4f6fb;padding:24px;border-radius:10px;">
  <div style="background:#fd7e14;color:#fff;padding:20px 28px;border-radius:8px 8px 0 0;">
    <h2 style="margin:0;font-size:1.3rem;">🔄 Ticket Status Updated</h2>
  </div>
  <div style="background:#fff;padding:28px;border-radius:0 0 8px 8px;border:1px solid #e0e7ef;border-top:none;">
    <p>Dear <strong>{{full_name}}</strong>,</p>
    <p>The status of your support ticket has been updated.</p>
    <table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:.9rem;">
      <tr><td style="padding:8px 12px;font-weight:600;background:#fff8f0;width:140px;border:1px solid #e0e7ef;">Ticket #</td><td style="padding:8px 12px;border:1px solid #e0e7ef;">{{ticket_number}}</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#fff8f0;border:1px solid #e0e7ef;">Title</td><td style="padding:8px 12px;border:1px solid #e0e7ef;">{{ticket_title}}</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#fff8f0;border:1px solid #e0e7ef;">Previous Status</td><td style="padding:8px 12px;border:1px solid #e0e7ef;">{{old_status}}</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#fff8f0;border:1px solid #e0e7ef;">New Status</td><td style="padding:8px 12px;border:1px solid #e0e7ef;font-weight:700;color:#fd7e14;">{{new_status}}</td></tr>
    </table>
    <p><a href="{{ticket_url}}" style="background:#fd7e14;color:#fff;padding:10px 22px;text-decoration:none;border-radius:6px;display:inline-block;font-weight:600;">View Ticket</a></p>
    <hr style="border:none;border-top:1px solid #eee;margin:24px 0 16px;">
    <p style="font-size:.8rem;color:#999;">This is an automated message from <strong>{{app_name}}</strong>.<br>Sent from: no_reply@primeuniversity.ac.bd</p>
  </div>
</div>',
1),

('ticket_comment_added',
 '[{{app_name}}] New Comment on Ticket #{{ticket_number}}',
 '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#f4f6fb;padding:24px;border-radius:10px;">
  <div style="background:#6f42c1;color:#fff;padding:20px 28px;border-radius:8px 8px 0 0;">
    <h2 style="margin:0;font-size:1.3rem;">💬 New Comment Added</h2>
  </div>
  <div style="background:#fff;padding:28px;border-radius:0 0 8px 8px;border:1px solid #e0e7ef;border-top:none;">
    <p>Dear <strong>{{full_name}}</strong>,</p>
    <p><strong>{{commenter_name}}</strong> added a comment to ticket <strong>#{{ticket_number}}</strong>.</p>
    <div style="background:#f8f0ff;border-left:4px solid #6f42c1;padding:14px 18px;margin:16px 0;border-radius:0 8px 8px 0;font-size:.9rem;">
      {{comment_excerpt}}
    </div>
    <p><a href="{{ticket_url}}" style="background:#6f42c1;color:#fff;padding:10px 22px;text-decoration:none;border-radius:6px;display:inline-block;font-weight:600;">View Ticket</a></p>
    <hr style="border:none;border-top:1px solid #eee;margin:24px 0 16px;">
    <p style="font-size:.8rem;color:#999;">This is an automated message from <strong>{{app_name}}</strong>.<br>Sent from: no_reply@primeuniversity.ac.bd</p>
  </div>
</div>',
1),

('ticket_tagged',
 '[{{app_name}}] You Were Tagged in Ticket #{{ticket_number}}',
 '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#f4f6fb;padding:24px;border-radius:10px;">
  <div style="background:#0dcaf0;color:#fff;padding:20px 28px;border-radius:8px 8px 0 0;">
    <h2 style="margin:0;font-size:1.3rem;">🏷 You Were Tagged in a Ticket</h2>
  </div>
  <div style="background:#fff;padding:28px;border-radius:0 0 8px 8px;border:1px solid #e0e7ef;border-top:none;">
    <p>Dear <strong>{{full_name}}</strong>,</p>
    <p><strong>{{tagger_name}}</strong> has tagged you in the following support ticket.</p>
    <table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:.9rem;">
      <tr><td style="padding:8px 12px;font-weight:600;background:#f0fdff;width:140px;border:1px solid #e0e7ef;">Ticket #</td><td style="padding:8px 12px;border:1px solid #e0e7ef;">{{ticket_number}}</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#f0fdff;border:1px solid #e0e7ef;">Title</td><td style="padding:8px 12px;border:1px solid #e0e7ef;">{{ticket_title}}</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#f0fdff;border:1px solid #e0e7ef;">Priority</td><td style="padding:8px 12px;border:1px solid #e0e7ef;">{{ticket_priority}}</td></tr>
    </table>
    <p><a href="{{ticket_url}}" style="background:#0dcaf0;color:#fff;padding:10px 22px;text-decoration:none;border-radius:6px;display:inline-block;font-weight:600;">View Ticket</a></p>
    <hr style="border:none;border-top:1px solid #eee;margin:24px 0 16px;">
    <p style="font-size:.8rem;color:#999;">This is an automated message from <strong>{{app_name}}</strong>.<br>Sent from: no_reply@primeuniversity.ac.bd</p>
  </div>
</div>',
1),

('ticket_overdue',
 '[{{app_name}}] ⚠ OVERDUE: Ticket #{{ticket_number}} Requires Immediate Attention',
 '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#f4f6fb;padding:24px;border-radius:10px;">
  <div style="background:#dc3545;color:#fff;padding:20px 28px;border-radius:8px 8px 0 0;">
    <h2 style="margin:0;font-size:1.3rem;">⚠ Overdue Ticket Alert</h2>
  </div>
  <div style="background:#fff;padding:28px;border-radius:0 0 8px 8px;border:1px solid #f5c2c7;border-top:none;">
    <p>Dear <strong>{{full_name}}</strong>,</p>
    <p>The following support ticket has <strong style="color:#dc3545;">exceeded its SLA deadline</strong> and requires immediate attention.</p>
    <table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:.9rem;">
      <tr><td style="padding:8px 12px;font-weight:600;background:#fff5f5;width:140px;border:1px solid #f5c2c7;">Ticket #</td><td style="padding:8px 12px;border:1px solid #f5c2c7;">{{ticket_number}}</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#fff5f5;border:1px solid #f5c2c7;">Title</td><td style="padding:8px 12px;border:1px solid #f5c2c7;">{{ticket_title}}</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#fff5f5;border:1px solid #f5c2c7;">Priority</td><td style="padding:8px 12px;border:1px solid #f5c2c7;">{{ticket_priority}}</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#fff5f5;border:1px solid #f5c2c7;">SLA Deadline</td><td style="padding:8px 12px;border:1px solid #f5c2c7;color:#dc3545;font-weight:700;">{{ticket_deadline}}</td></tr>
    </table>
    <p><a href="{{ticket_url}}" style="background:#dc3545;color:#fff;padding:10px 22px;text-decoration:none;border-radius:6px;display:inline-block;font-weight:600;">View Ticket Now</a></p>
    <hr style="border:none;border-top:1px solid #f5c2c7;margin:24px 0 16px;">
    <p style="font-size:.8rem;color:#999;">This is an automated overdue alert from <strong>{{app_name}}</strong>.<br>Sent from: no_reply@primeuniversity.ac.bd</p>
  </div>
</div>',
1);
