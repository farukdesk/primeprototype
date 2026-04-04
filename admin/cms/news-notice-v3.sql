-- ============================================================
-- News & Notice – v3 migration
-- Adds email template for notice approval notifications
-- Run after news-notice-v2.sql
-- ============================================================

-- -------------------------------------------------------
-- Notice Approval Needed email template
-- Variables: {{full_name}}, {{requester_name}}, {{notice_title}},
--            {{action}}, {{pending_url}}, {{app_name}}, {{logo_url}}
-- -------------------------------------------------------
INSERT IGNORE INTO `email_templates` (`name`, `action`, `subject`, `body_html`, `variables`, `is_active`) VALUES (
  'Notice Approval Needed',
  'notice_approval_needed',
  'Action Required: Notice {{action}} Pending Approval – {{app_name}}',
  '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Notice Approval Required</title>
<style>
  body { margin:0; padding:0; background:#f4f6fb; font-family:''Inter'',Arial,sans-serif; }
  .wrapper { max-width:580px; margin:40px auto; background:#fff; border-radius:16px; box-shadow:0 4px 24px rgba(0,0,0,.08); overflow:hidden; }
  .logo-bar { background:#ffffff; padding:24px 40px; text-align:center; border-bottom:1px solid #e5e7eb; }
  .logo-bar img { max-height:64px; max-width:180px; object-fit:contain; }
  .header { background:linear-gradient(135deg,#1a1f36 0%,#2d3561 100%); padding:28px 40px; text-align:center; }
  .header h1 { color:#fff; margin:0; font-size:1.4rem; font-weight:700; }
  .body { padding:36px 40px; color:#374151; }
  .body p { margin:0 0 16px; line-height:1.7; font-size:.925rem; }
  .info-box { background:#f0f4ff; border-left:4px solid #4f8ef7; padding:14px 18px; border-radius:6px; margin:20px 0; }
  .info-box strong { display:block; margin-bottom:4px; color:#1e3a8a; font-size:.85rem; text-transform:uppercase; letter-spacing:.04em; }
  .info-box span { font-size:.95rem; color:#1e3a8a; font-weight:600; }
  .action-badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:.78rem; font-weight:700; text-transform:uppercase; }
  .action-create { background:#d1fae5; color:#065f46; }
  .action-edit   { background:#fff3cd; color:#7a4f00; }
  .action-delete { background:#fee2e2; color:#7f1d1d; }
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
    <h1>&#128276; Notice Approval Required</h1>
  </div>
  <div class="body">
    <p>Hi <strong>{{full_name}}</strong>,</p>
    <p>A notice requires your approval before it can be published on the website.</p>
    <div class="info-box">
      <strong>Notice Title</strong>
      <span>{{notice_title}}</span>
    </div>
    <div class="info-box">
      <strong>Requested By</strong>
      <span>{{requester_name}}</span>
    </div>
    <div class="info-box">
      <strong>Action</strong>
      <span>{{action}}</span>
    </div>
    <p>The notice has been hidden from the public website and will remain hidden until you approve or reject this request.</p>
    <div class="btn-wrap">
      <a href="{{pending_url}}" class="btn">Review Pending Approvals</a>
    </div>
    <p style="font-size:.85rem;color:#6b7280;">If the button above does not work, copy and paste this link into your browser:<br>
    <span style="word-break:break-all;">{{pending_url}}</span></p>
  </div>
  <div class="footer">
    &copy; {{app_name}} &mdash; This is an automated message, please do not reply.
  </div>
</div>
</body>
</html>',
  '{{full_name}},{{requester_name}},{{notice_title}},{{action}},{{pending_url}},{{app_name}},{{logo_url}}',
  1
);
