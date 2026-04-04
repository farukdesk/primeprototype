-- ═══════════════════════════════════════════════════════════════════════════
--  IT Support Tickets – v2 Migration
--  Run AFTER admin/support-tickets.sql
-- ═══════════════════════════════════════════════════════════════════════════

-- ── Add user-type and submitter fields to support_tickets ────────────────────
ALTER TABLE `support_tickets`
  ADD COLUMN `user_type`          ENUM('Student','Faculty','Administrative Employee') DEFAULT NULL AFTER `department`,
  ADD COLUMN `student_id`         VARCHAR(20)  DEFAULT NULL AFTER `user_type`,
  ADD COLUMN `student_department` VARCHAR(200) DEFAULT NULL AFTER `student_id`,
  ADD COLUMN `student_program`    VARCHAR(200) DEFAULT NULL AFTER `student_department`,
  ADD COLUMN `student_batch`      VARCHAR(100) DEFAULT NULL AFTER `student_program`,
  ADD COLUMN `submitter_name`     VARCHAR(200) DEFAULT NULL AFTER `student_batch`,
  ADD COLUMN `submitter_email`    VARCHAR(255) DEFAULT NULL AFTER `submitter_name`,
  ADD COLUMN `submitter_phone`    VARCHAR(50)  DEFAULT NULL AFTER `submitter_email`,
  ADD COLUMN `is_public`          TINYINT(1)   NOT NULL DEFAULT 0 AFTER `submitter_phone`;

-- ── Make created_by nullable for guest/public submissions ────────────────────
ALTER TABLE `support_tickets`
  DROP FOREIGN KEY `fk_st_created_by`,
  MODIFY COLUMN `created_by` INT(10) UNSIGNED DEFAULT NULL,
  ADD CONSTRAINT `fk_st_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- ── IT Support settings table ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `support_settings` (
  `key`        VARCHAR(100) NOT NULL,
  `value`      TEXT         DEFAULT NULL,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `support_settings` (`key`, `value`) VALUES
  ('notify_emails', 'dd.it@primeuniversity.ac.bd,belayet@primeuniversity.ac.bd')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

-- ── New email templates ───────────────────────────────────────────────────────
INSERT IGNORE INTO `email_templates` (`action`, `name`, `subject`, `body_html`, `variables`, `is_active`) VALUES

-- Notification to IT admins when any ticket is created
('ticket_created_notify',
 'Ticket Created – Admin Notification',
 '[{{app_name}}] New Support Ticket #{{ticket_number}} Submitted',
 '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#f4f6fb;padding:24px;border-radius:10px;">
  <div style="background:#2d6cdf;color:#fff;padding:20px 28px;border-radius:8px 8px 0 0;">
    <h2 style="margin:0;font-size:1.3rem;">🎫 New IT Support Ticket Submitted</h2>
  </div>
  <div style="background:#fff;padding:28px;border-radius:0 0 8px 8px;border:1px solid #e0e7ef;border-top:none;">
    <p>A new IT support ticket has been submitted and requires attention.</p>
    <table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:.9rem;">
      <tr><td style="padding:8px 12px;font-weight:600;background:#f0f4ff;width:140px;border:1px solid #e0e7ef;">Ticket #</td><td style="padding:8px 12px;border:1px solid #e0e7ef;">{{ticket_number}}</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#f0f4ff;border:1px solid #e0e7ef;">Title</td><td style="padding:8px 12px;border:1px solid #e0e7ef;">{{ticket_title}}</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#f0f4ff;border:1px solid #e0e7ef;">Submitted By</td><td style="padding:8px 12px;border:1px solid #e0e7ef;">{{submitter_name}}</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#f0f4ff;border:1px solid #e0e7ef;">Email</td><td style="padding:8px 12px;border:1px solid #e0e7ef;">{{submitter_email}}</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#f0f4ff;border:1px solid #e0e7ef;">User Type</td><td style="padding:8px 12px;border:1px solid #e0e7ef;">{{user_type}}</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#f0f4ff;border:1px solid #e0e7ef;">Priority</td><td style="padding:8px 12px;border:1px solid #e0e7ef;">{{ticket_priority}}</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#f0f4ff;border:1px solid #e0e7ef;">Category</td><td style="padding:8px 12px;border:1px solid #e0e7ef;">{{ticket_category}}</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#f0f4ff;border:1px solid #e0e7ef;">SLA Deadline</td><td style="padding:8px 12px;border:1px solid #e0e7ef;">{{ticket_deadline}}</td></tr>
    </table>
    <p><a href="{{ticket_url}}" style="background:#2d6cdf;color:#fff;padding:10px 22px;text-decoration:none;border-radius:6px;display:inline-block;font-weight:600;">View Ticket in Admin Panel</a></p>
    <hr style="border:none;border-top:1px solid #eee;margin:24px 0 16px;">
    <p style="font-size:.8rem;color:#999;">This is an automated notification from <strong>{{app_name}}</strong>.</p>
  </div>
</div>',
'{{ticket_number}},{{ticket_title}},{{submitter_name}},{{submitter_email}},{{user_type}},{{ticket_priority}},{{ticket_category}},{{ticket_deadline}},{{ticket_url}}',
1),

-- Notification to IT admins when a comment is added
('ticket_comment_notify',
 'Comment Added – Admin Notification',
 '[{{app_name}}] New Comment on Ticket #{{ticket_number}}',
 '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#f4f6fb;padding:24px;border-radius:10px;">
  <div style="background:#6f42c1;color:#fff;padding:20px 28px;border-radius:8px 8px 0 0;">
    <h2 style="margin:0;font-size:1.3rem;">💬 New Comment on Support Ticket</h2>
  </div>
  <div style="background:#fff;padding:28px;border-radius:0 0 8px 8px;border:1px solid #e0e7ef;border-top:none;">
    <p><strong>{{commenter_name}}</strong> added a comment to ticket <strong>#{{ticket_number}}</strong>.</p>
    <div style="background:#f8f0ff;border-left:4px solid #6f42c1;padding:14px 18px;margin:16px 0;border-radius:0 8px 8px 0;font-size:.9rem;">
      {{comment_excerpt}}
    </div>
    <p><a href="{{ticket_url}}" style="background:#6f42c1;color:#fff;padding:10px 22px;text-decoration:none;border-radius:6px;display:inline-block;font-weight:600;">View Ticket</a></p>
    <hr style="border:none;border-top:1px solid #eee;margin:24px 0 16px;">
    <p style="font-size:.8rem;color:#999;">This is an automated notification from <strong>{{app_name}}</strong>.</p>
  </div>
</div>',
'{{ticket_number}},{{commenter_name}},{{comment_excerpt}},{{ticket_url}}',
1),

-- Notification for @mention in comment
('ticket_comment_mention',
 'You Were Mentioned in a Comment',
 '[{{app_name}}] You Were Mentioned in Ticket #{{ticket_number}}',
 '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#f4f6fb;padding:24px;border-radius:10px;">
  <div style="background:#e83e8c;color:#fff;padding:20px 28px;border-radius:8px 8px 0 0;">
    <h2 style="margin:0;font-size:1.3rem;">💬 You Were Mentioned in a Comment</h2>
  </div>
  <div style="background:#fff;padding:28px;border-radius:0 0 8px 8px;border:1px solid #e0e7ef;border-top:none;">
    <p>Dear <strong>{{full_name}}</strong>,</p>
    <p><strong>{{commenter_name}}</strong> mentioned you (@{{username}}) in a comment on ticket <strong>#{{ticket_number}}: {{ticket_title}}</strong>.</p>
    <div style="background:#fff0f5;border-left:4px solid #e83e8c;padding:14px 18px;margin:16px 0;border-radius:0 8px 8px 0;font-size:.9rem;">
      {{comment_excerpt}}
    </div>
    <p><a href="{{ticket_url}}" style="background:#e83e8c;color:#fff;padding:10px 22px;text-decoration:none;border-radius:6px;display:inline-block;font-weight:600;">View Ticket &amp; Reply</a></p>
    <hr style="border:none;border-top:1px solid #eee;margin:24px 0 16px;">
    <p style="font-size:.8rem;color:#999;">This is an automated message from <strong>{{app_name}}</strong>.</p>
  </div>
</div>',
'{{full_name}},{{username}},{{commenter_name}},{{ticket_number}},{{ticket_title}},{{comment_excerpt}},{{ticket_url}}',
1),

-- Confirmation email for public ticket submissions
('ticket_public_confirmation',
 'IT Support Ticket Submitted – Confirmation',
 '[{{app_name}}] Your IT Support Ticket #{{ticket_number}} Has Been Received',
 '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#f4f6fb;padding:24px;border-radius:10px;">
  <div style="background:#20c997;color:#fff;padding:20px 28px;border-radius:8px 8px 0 0;">
    <h2 style="margin:0;font-size:1.3rem;">✅ Your IT Support Request Has Been Received</h2>
  </div>
  <div style="background:#fff;padding:28px;border-radius:0 0 8px 8px;border:1px solid #e0e7ef;border-top:none;">
    <p>Dear <strong>{{full_name}}</strong>,</p>
    <p>Thank you for contacting IT Support. Your ticket has been received and our team will get back to you shortly.</p>
    <table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:.9rem;">
      <tr><td style="padding:8px 12px;font-weight:600;background:#f0fff8;width:140px;border:1px solid #e0e7ef;">Ticket #</td><td style="padding:8px 12px;border:1px solid #e0e7ef;font-weight:700;color:#20c997;">{{ticket_number}}</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#f0fff8;border:1px solid #e0e7ef;">Title</td><td style="padding:8px 12px;border:1px solid #e0e7ef;">{{ticket_title}}</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#f0fff8;border:1px solid #e0e7ef;">Priority</td><td style="padding:8px 12px;border:1px solid #e0e7ef;">{{ticket_priority}}</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#f0fff8;border:1px solid #e0e7ef;">Category</td><td style="padding:8px 12px;border:1px solid #e0e7ef;">{{ticket_category}}</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#f0fff8;border:1px solid #e0e7ef;">SLA Deadline</td><td style="padding:8px 12px;border:1px solid #e0e7ef;">{{ticket_deadline}}</td></tr>
    </table>
    <p style="background:#fff8e1;border:1px solid #ffe082;border-radius:8px;padding:12px 16px;font-size:.9rem;">
      <strong>📌 Save your ticket number:</strong> <strong style="color:#e65100;">{{ticket_number}}</strong><br>
      You can use it along with your email address to check the status of your ticket at any time on our support portal.
    </p>
    <p><a href="{{track_url}}" style="background:#20c997;color:#fff;padding:10px 22px;text-decoration:none;border-radius:6px;display:inline-block;font-weight:600;">Track Your Ticket</a></p>
    <hr style="border:none;border-top:1px solid #eee;margin:24px 0 16px;">
    <p style="font-size:.8rem;color:#999;">This is an automated confirmation from <strong>{{app_name}}</strong>. Please save this email for your records.</p>
  </div>
</div>',
'{{full_name}},{{ticket_number}},{{ticket_title}},{{ticket_priority}},{{ticket_category}},{{ticket_deadline}},{{track_url}}',
1),

-- Status update notification for public (guest) submitters
('ticket_status_public',
 'Ticket Status Updated – Public Notification',
 '[{{app_name}}] Your Ticket #{{ticket_number}} Status: {{new_status}}',
 '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#f4f6fb;padding:24px;border-radius:10px;">
  <div style="background:#fd7e14;color:#fff;padding:20px 28px;border-radius:8px 8px 0 0;">
    <h2 style="margin:0;font-size:1.3rem;">🔄 Your Support Ticket Status Updated</h2>
  </div>
  <div style="background:#fff;padding:28px;border-radius:0 0 8px 8px;border:1px solid #e0e7ef;border-top:none;">
    <p>Dear <strong>{{full_name}}</strong>,</p>
    <p>The status of your IT support ticket has been updated.</p>
    <table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:.9rem;">
      <tr><td style="padding:8px 12px;font-weight:600;background:#fff8f0;width:140px;border:1px solid #e0e7ef;">Ticket #</td><td style="padding:8px 12px;border:1px solid #e0e7ef;">{{ticket_number}}</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#fff8f0;border:1px solid #e0e7ef;">Title</td><td style="padding:8px 12px;border:1px solid #e0e7ef;">{{ticket_title}}</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#fff8f0;border:1px solid #e0e7ef;">Previous Status</td><td style="padding:8px 12px;border:1px solid #e0e7ef;">{{old_status}}</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#fff8f0;border:1px solid #e0e7ef;">New Status</td><td style="padding:8px 12px;border:1px solid #e0e7ef;font-weight:700;color:#fd7e14;">{{new_status}}</td></tr>
    </table>
    <p><a href="{{track_url}}" style="background:#fd7e14;color:#fff;padding:10px 22px;text-decoration:none;border-radius:6px;display:inline-block;font-weight:600;">Track Your Ticket</a></p>
    <hr style="border:none;border-top:1px solid #eee;margin:24px 0 16px;">
    <p style="font-size:.8rem;color:#999;">This is an automated message from <strong>{{app_name}}</strong>.</p>
  </div>
</div>',
'{{full_name}},{{ticket_number}},{{ticket_title}},{{old_status}},{{new_status}},{{track_url}}',
1);
