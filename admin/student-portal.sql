-- ============================================================
-- Student Portal User Accounts – Migration
-- Run once against the live database.
-- All statements are idempotent (safe to re-run).
-- ============================================================

SET NAMES utf8mb4;

-- ── 1. Settings table ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS `student_portal_settings` (
    `id`            INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `setting_key`   VARCHAR(100)   NOT NULL,
    `setting_value` TEXT           DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default settings
INSERT IGNORE INTO `student_portal_settings` (`setting_key`, `setting_value`) VALUES
('email_enabled',       '1'),
('sms_enabled',         '0'),
('sms_api_key',         ''),
('sms_sender_id',       ''),
('sms_template',        'Dear {{student_name}}, your Student Portal is ready. Please check your email for the login URL, username and password. Thank you.'),
('default_group_name',  'Students');

-- ── 2. Link students to portal user accounts ───────────────
ALTER TABLE `students`
    ADD COLUMN IF NOT EXISTS `portal_user_id` INT DEFAULT NULL
        COMMENT 'FK to users.id – the portal user account for this student'
        AFTER `id`;

-- ── 3. Welcome email template ──────────────────────────────
INSERT IGNORE INTO `email_templates`
    (`name`, `action`, `subject`, `body_html`, `variables`, `is_active`)
VALUES (
    'Student Portal Welcome',
    'student_portal_welcome',
    'Your Student Portal is Ready – {{app_name}}',
    '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Portal Ready</title>
</head>
<body style="margin:0;padding:0;background:#f4f6fb;font-family:''Inter'',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f6fb;padding:32px 0;">
  <tr>
    <td align="center">
      <table width="580" cellpadding="0" cellspacing="0" border="0"
             style="background:#ffffff;border-radius:16px;overflow:hidden;
                    box-shadow:0 4px 24px rgba(0,0,0,.08);max-width:580px;">

        <!-- Header -->
        <tr>
          <td style="background:linear-gradient(135deg,#1e3a5f 0%,#2563eb 100%);
                     padding:32px 40px;text-align:center;">
            <img src="{{logo_url}}" alt="{{app_name}}" height="56"
                 style="max-height:56px;max-width:180px;object-fit:contain;">
            <p style="margin:12px 0 0;color:rgba(255,255,255,.8);font-size:13px;letter-spacing:.5px;">
              Student Portal
            </p>
          </td>
        </tr>

        <!-- Body -->
        <tr>
          <td style="padding:36px 40px 28px;">
            <h2 style="margin:0 0 16px;font-size:22px;font-weight:700;color:#1e293b;">
              Your Student Portal is Ready 🎉
            </h2>
            <p style="margin:0 0 16px;font-size:15px;color:#475569;line-height:1.6;">
              Dear <strong>{{full_name}}</strong>,
            </p>
            <p style="margin:0 0 24px;font-size:15px;color:#475569;line-height:1.6;">
              Your student portal account has been created. You can now log in using the
              credentials below.
            </p>

            <!-- Credentials box -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="background:#f1f5f9;border-radius:12px;padding:0;margin-bottom:28px;">
              <tr>
                <td style="padding:22px 28px;">
                  <p style="margin:0 0 4px;font-size:11px;font-weight:700;text-transform:uppercase;
                             letter-spacing:.06em;color:#64748b;">Login Details</p>
                  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:12px;">
                    <tr>
                      <td style="font-size:13px;color:#64748b;padding:5px 0;width:110px;">Login URL</td>
                      <td style="font-size:13px;color:#1e293b;padding:5px 0;">
                        <a href="{{login_url}}" style="color:#2563eb;text-decoration:none;font-weight:600;">
                          {{login_url}}
                        </a>
                      </td>
                    </tr>
                    <tr>
                      <td style="font-size:13px;color:#64748b;padding:5px 0;">Username</td>
                      <td style="font-size:13px;color:#1e293b;padding:5px 0;font-weight:600;
                                 font-family:monospace;">{{username}}</td>
                    </tr>
                    <tr>
                      <td style="font-size:13px;color:#64748b;padding:5px 0;">Student ID</td>
                      <td style="font-size:13px;color:#1e293b;padding:5px 0;font-weight:600;
                                 font-family:monospace;">{{student_id}}</td>
                    </tr>
                    <tr>
                      <td style="font-size:13px;color:#64748b;padding:5px 0;">Password</td>
                      <td style="font-size:13px;color:#1e293b;padding:5px 0;font-weight:600;
                                 font-family:monospace;">{{password}}</td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>

            <!-- Login button -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;">
              <tr>
                <td align="center">
                  <a href="{{login_url}}"
                     style="display:inline-block;background:linear-gradient(135deg,#4f8ef7,#2d63e8);
                            color:#ffffff;padding:14px 40px;border-radius:10px;text-decoration:none;
                            font-size:15px;font-weight:700;letter-spacing:.3px;">
                    Sign In to Student Portal
                  </a>
                </td>
              </tr>
            </table>

            <p style="margin:0 0 8px;font-size:13px;color:#94a3b8;line-height:1.6;">
              You can also log in using your <strong>Student ID</strong> ({{student_id}}) or
              registered email address as your username.
            </p>
            <p style="margin:0 0 8px;font-size:13px;color:#94a3b8;line-height:1.6;">
              For security, please change your password after your first login.
            </p>
            <p style="margin:0;font-size:13px;color:#94a3b8;line-height:1.6;">
              If you did not request this account, please contact the university IT office immediately.
            </p>
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td style="background:#f8fafc;border-top:1px solid #e2e8f0;
                     padding:20px 40px;text-align:center;">
            <p style="margin:0;font-size:12px;color:#94a3b8;">
              &copy; {{app_name}} &mdash; This is an automated message, please do not reply.
            </p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>',
    '{{full_name}},{{student_id}},{{username}},{{password}},{{login_url}}',
    1
);

-- ── 4. Register modules ────────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `icon`, `sort_order`, `is_active`) VALUES
('Student Portal Settings', 'student-portal-settings', 'fas fa-user-lock', 61, 1);

-- ── 5. Accounts log table (track portal account creation) ──
CREATE TABLE IF NOT EXISTS `student_portal_log` (
    `id`           INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `student_id`   INT            NOT NULL,
    `user_id`      INT            NOT NULL,
    `action`       VARCHAR(50)    NOT NULL DEFAULT 'created',
    `email_sent`   TINYINT(1)     NOT NULL DEFAULT 0,
    `sms_sent`     TINYINT(1)     NOT NULL DEFAULT 0,
    `created_by`   INT            DEFAULT NULL,
    `created_at`   DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_student` (`student_id`),
    KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
