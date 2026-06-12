-- ============================================================
-- Student Portal – Notice Notification
-- Adds email template for notifying students when a new notice
-- is published (university-wide or department notice).
--
-- Run this migration once.
-- ============================================================

-- -------------------------------------------------------
-- University Notice Published – email template
-- Variables: {{student_name}}, {{notice_title}}, {{notice_date}},
--            {{notice_excerpt}}, {{notice_type}}, {{dept_name}},
--            {{portal_url}}, {{app_name}}, {{logo_url}}
-- -------------------------------------------------------
INSERT IGNORE INTO `email_templates` (`name`, `action`, `subject`, `body_html`, `variables`, `is_active`) VALUES (
  'Student Notice Notification',
  'student_notice_published',
  'New Notice: {{notice_title}} – {{app_name}}',
  '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>New Notice</title>
<style>
  body { margin:0; padding:0; background:#f4f6fb; font-family:''Inter'',Arial,sans-serif; }
  .wrapper { max-width:600px; margin:40px auto; background:#fff; border-radius:16px; box-shadow:0 4px 24px rgba(0,0,0,.08); overflow:hidden; }
  .logo-bar { background:#ffffff; padding:24px 40px; text-align:center; border-bottom:1px solid #e5e7eb; }
  .logo-bar img { max-height:64px; max-width:180px; object-fit:contain; }
  .header { background:linear-gradient(135deg,#1a1f36 0%,#2d3561 100%); padding:30px 40px; text-align:center; }
  .header h1 { color:#fff; margin:0; font-size:1.4rem; font-weight:700; letter-spacing:.01em; }
  .header p  { color:#a5b4fc; margin:8px 0 0; font-size:.9rem; }
  .body { padding:36px 40px; color:#374151; }
  .body p { margin:0 0 16px; line-height:1.7; font-size:.925rem; }
  .notice-box { background:#f0f4ff; border-left:4px solid #4f8ef7; border-radius:0 10px 10px 0; padding:18px 22px; margin:20px 0; }
  .notice-box .notice-type { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#4f8ef7; margin-bottom:6px; }
  .notice-box .notice-title { font-size:1.05rem; font-weight:700; color:#1e3a8a; margin-bottom:6px; }
  .notice-box .notice-meta { font-size:.8rem; color:#6b7280; margin-bottom:8px; }
  .notice-box .notice-excerpt { font-size:.875rem; color:#374151; line-height:1.65; }
  .btn-wrap { text-align:center; margin:28px 0 12px; }
  .btn { display:inline-block; padding:14px 36px; background:linear-gradient(135deg,#4f8ef7,#2d63e8); color:#fff !important;
         text-decoration:none; border-radius:10px; font-weight:600; font-size:.95rem; letter-spacing:.01em; }
  .dept-badge { display:inline-block; background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0;
                padding:2px 10px; border-radius:20px; font-size:.75rem; font-weight:600; margin-bottom:10px; }
  .divider { border:none; border-top:1px solid #e5e7eb; margin:24px 0; }
  .footer { background:#f4f6fb; padding:20px 40px; text-align:center; font-size:.78rem; color:#9ca3af; border-top:1px solid #e5e7eb; }
</style>
</head>
<body>
<div class="wrapper">
  <div class="logo-bar">
    <img src="{{logo_url}}" alt="{{app_name}}">
  </div>
  <div class="header">
    <h1>&#128276; New Notice Published</h1>
    <p>A notice has been published for you</p>
  </div>
  <div class="body">
    <p>Dear <strong>{{student_name}}</strong>,</p>
    <p>A new notice has been published on the student portal. Please read it carefully.</p>

    <div class="notice-box">
      <div class="notice-type">&#127979; {{notice_type}}</div>
      {{dept_name_html}}
      <div class="notice-title">{{notice_title}}</div>
      <div class="notice-meta">&#128197; {{notice_date}}</div>
      {{excerpt_html}}
    </div>

    <div class="btn-wrap">
      <a href="{{portal_url}}" class="btn">View on Student Portal</a>
    </div>

    <hr class="divider">
    <p style="font-size:.82rem;color:#6b7280;margin:0;">
      You are receiving this email because you are a registered student at {{app_name}}.
      Log in to your student portal to view all notices.
    </p>
  </div>
  <div class="footer">
    &copy; {{app_name}} &mdash; This is an automated notification, please do not reply.
  </div>
</div>
</body>
</html>',
  '{{student_name}},{{notice_title}},{{notice_date}},{{notice_type}},{{dept_name_html}},{{excerpt_html}},{{portal_url}},{{app_name}},{{logo_url}}',
  1
);
