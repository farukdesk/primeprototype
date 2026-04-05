-- ============================================================
-- Users: Admin Password Reset email template
-- Run once to insert the admin_password_reset email template.
-- ============================================================

INSERT IGNORE INTO `email_templates` (`name`, `action`, `subject`, `body_html`, `variables`, `is_active`)
VALUES (
  'Admin Password Reset',
  'admin_password_reset',
  'Your Password Has Been Reset – {{app_name}}',
  '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Password Reset</title>
<style>
  body { margin:0; padding:0; background:#f4f6fb; font-family:''Inter'',Arial,sans-serif; }
  .wrapper { max-width:580px; margin:40px auto; background:#fff; border-radius:16px; box-shadow:0 4px 24px rgba(0,0,0,.08); overflow:hidden; }
  .logo-bar { background:#ffffff; padding:24px 40px; text-align:center; border-bottom:1px solid #e5e7eb; }
  .logo-bar img { max-height:64px; max-width:180px; object-fit:contain; }
  .header { background:linear-gradient(135deg,#1a1f36 0%,#2d3561 100%); padding:28px 40px; text-align:center; }
  .header h1 { color:#fff; margin:0; font-size:1.4rem; font-weight:700; }
  .body { padding:36px 40px; color:#374151; }
  .body p { margin:0 0 16px; line-height:1.7; font-size:.925rem; }
  .creds { width:100%; border-collapse:collapse; margin:16px 0; font-size:.9rem; }
  .creds td { padding:10px 14px; border:1px solid #d1d5db; }
  .creds td:first-child { font-weight:600; background:#f0f4ff; width:130px; }
  .creds td:last-child { font-weight:700; color:#1d4ed8; font-size:1rem; }
  .warn { background:#fff8e1; border-left:4px solid #f5a623; padding:12px 16px; border-radius:6px; font-size:.875rem; color:#7a5c00; margin:20px 0; }
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
    <h1>Your Password Has Been Reset</h1>
  </div>
  <div class="body">
    <p>Hi <strong>{{full_name}}</strong>,</p>
    <p>An administrator has reset your account password on <strong>{{app_name}}</strong>. Your new login credentials are below:</p>
    <table class="creds">
      <tr>
        <td>Username</td>
        <td>{{username}}</td>
      </tr>
      <tr>
        <td>Password</td>
        <td>{{new_password}}</td>
      </tr>
    </table>
    <div class="btn-wrap">
      <a href="{{login_url}}" class="btn">Login to Your Account</a>
    </div>
    <div class="warn">
      <strong>&#9888;&#65039; Important:</strong> Please log in and change your password immediately for security purposes.
    </div>
    <p>If you did not expect this change, please contact your system administrator immediately.</p>
  </div>
  <div class="footer">
    &copy; {{app_name}} &mdash; This is an automated message, please do not reply.
  </div>
</div>
</body>
</html>',
  '{{full_name}},{{username}},{{new_password}},{{login_url}},{{app_name}},{{logo_url}}',
  1
);
