-- ============================================================
-- Auth V2 Migration
-- Run this on existing installations to:
--   1. Update the forgot_password email template with the
--      Prime University logo ({{logo_url}} variable).
--   2. Add the new password_changed email template.
-- ============================================================

-- -------------------------------------------------------
-- Update: Forgot Password email template (adds logo bar)
-- Variables: {{full_name}}, {{reset_link}}, {{app_name}}, {{expire_minutes}}, {{logo_url}}
-- -------------------------------------------------------
UPDATE `email_templates`
SET
  `subject`   = 'Reset Your Password – {{app_name}}',
  `body_html` = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Reset Your Password</title>
<style>
  body { margin:0; padding:0; background:#f4f6fb; font-family:''Inter'',Arial,sans-serif; }
  .wrapper { max-width:580px; margin:40px auto; background:#fff; border-radius:16px; box-shadow:0 4px 24px rgba(0,0,0,.08); overflow:hidden; }
  .logo-bar { background:#ffffff; padding:24px 40px; text-align:center; border-bottom:1px solid #e5e7eb; }
  .logo-bar img { max-height:64px; max-width:180px; object-fit:contain; }
  .header { background:linear-gradient(135deg,#1a1f36 0%,#2d3561 100%); padding:28px 40px; text-align:center; }
  .header h1 { color:#fff; margin:0; font-size:1.4rem; font-weight:700; }
  .body { padding:36px 40px; color:#374151; }
  .body p  { margin:0 0 16px; line-height:1.7; font-size:.925rem; }
  .btn-wrap { text-align:center; margin:28px 0; }
  .btn { display:inline-block; padding:14px 36px; background:linear-gradient(135deg,#4f8ef7,#2d63e8); color:#fff !important;
         text-decoration:none; border-radius:10px; font-weight:600; font-size:.95rem; }
  .expire { background:#fff8e1; border-left:4px solid #f5a623; padding:12px 16px; border-radius:6px; font-size:.85rem; color:#7a5c00; margin:20px 0; }
  .footer { background:#f4f6fb; padding:20px 40px; text-align:center; font-size:.78rem; color:#9ca3af; }
</style>
</head>
<body>
<div class="wrapper">
  <div class="logo-bar">
    <img src="{{logo_url}}" alt="{{app_name}}">
  </div>
  <div class="header">
    <h1>Password Reset Request</h1>
  </div>
  <div class="body">
    <p>Hi <strong>{{full_name}}</strong>,</p>
    <p>We received a request to reset the password for your admin account. Click the button below to choose a new password:</p>
    <div class="btn-wrap">
      <a href="{{reset_link}}" class="btn">Reset My Password</a>
    </div>
    <div class="expire">
      <strong>⏰ This link expires in {{expire_minutes}} minutes.</strong><br>
      If you did not request a password reset, please ignore this email – your account remains secure.
    </div>
    <p>If the button above does not work, copy and paste the following link into your browser:</p>
    <p style="word-break:break-all;font-size:.82rem;color:#6b7280;">{{reset_link}}</p>
  </div>
  <div class="footer">
    &copy; {{app_name}} &mdash; This is an automated message, please do not reply.
  </div>
</div>
</body>
</html>',
  `variables`  = '{{full_name}},{{reset_link}},{{app_name}},{{expire_minutes}},{{logo_url}}',
  `updated_at` = NOW()
WHERE `action` = 'forgot_password';

-- -------------------------------------------------------
-- Insert: Password Changed email template
-- Variables: {{full_name}}, {{app_name}}, {{login_url}}, {{logo_url}}
-- -------------------------------------------------------
INSERT INTO `email_templates` (`name`, `action`, `subject`, `body_html`, `variables`, `is_active`)
SELECT
  'Password Changed',
  'password_changed',
  'Your Password Has Been Changed – {{app_name}}',
  '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Password Changed</title>
<style>
  body { margin:0; padding:0; background:#f4f6fb; font-family:''Inter'',Arial,sans-serif; }
  .wrapper { max-width:580px; margin:40px auto; background:#fff; border-radius:16px; box-shadow:0 4px 24px rgba(0,0,0,.08); overflow:hidden; }
  .logo-bar { background:#ffffff; padding:24px 40px; text-align:center; border-bottom:1px solid #e5e7eb; }
  .logo-bar img { max-height:64px; max-width:180px; object-fit:contain; }
  .header { background:linear-gradient(135deg,#1a1f36 0%,#2d3561 100%); padding:28px 40px; text-align:center; }
  .header h1 { color:#fff; margin:0; font-size:1.4rem; font-weight:700; }
  .body { padding:36px 40px; color:#374151; }
  .body p  { margin:0 0 16px; line-height:1.7; font-size:.925rem; }
  .success-box { background:#f0fdf4; border-left:4px solid #22c55e; padding:14px 18px; border-radius:6px; font-size:.9rem; color:#15803d; margin:20px 0; }
  .warn-box { background:#fff8e1; border-left:4px solid #f5a623; padding:12px 16px; border-radius:6px; font-size:.85rem; color:#7a5c00; margin:20px 0; }
  .btn-wrap { text-align:center; margin:28px 0; }
  .btn { display:inline-block; padding:14px 36px; background:linear-gradient(135deg,#4f8ef7,#2d63e8); color:#fff !important;
         text-decoration:none; border-radius:10px; font-weight:600; font-size:.95rem; }
  .footer { background:#f4f6fb; padding:20px 40px; text-align:center; font-size:.78rem; color:#9ca3af; }
</style>
</head>
<body>
<div class="wrapper">
  <div class="logo-bar">
    <img src="{{logo_url}}" alt="{{app_name}}">
  </div>
  <div class="header">
    <h1>Password Changed Successfully</h1>
  </div>
  <div class="body">
    <p>Hi <strong>{{full_name}}</strong>,</p>
    <div class="success-box">
      ✅ <strong>Your admin account password has been changed successfully.</strong>
    </div>
    <p>This change was made at the time you clicked the password reset link. You can now sign in using your new password.</p>
    <div class="btn-wrap">
      <a href="{{login_url}}" class="btn">Go to Login</a>
    </div>
    <div class="warn-box">
      <strong>⚠️ Didn''t change your password?</strong><br>
      If you did not perform this action, please contact your system administrator immediately as your account may have been compromised.
    </div>
  </div>
  <div class="footer">
    &copy; {{app_name}} &mdash; This is an automated message, please do not reply.
  </div>
</div>
</body>
</html>',
  '{{full_name}},{{app_name}},{{login_url}},{{logo_url}}',
  1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `email_templates` WHERE `action` = 'password_changed'
);
