<?php
/**
 * Public IT Support Ticket Portal
 * Allows anyone to submit a support ticket and track status without login.
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/includes/config.php';

$page_title = 'IT Support – Prime University';

// ── CSRF ─────────────────────────────────────────────────────────────────────
if (empty($_SESSION['st_pub_csrf'])) {
    $_SESSION['st_pub_csrf'] = bin2hex(random_bytes(32));
}
$pub_csrf = $_SESSION['st_pub_csrf'];

// ── Helpers ───────────────────────────────────────────────────────────────────
function pub_db(): ?PDO { return front_db(); }
function pub_h(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pub_generate_ticket_number(): string
{
    $db   = pub_db();
    if (!$db) return 'TKT-' . date('Y') . '-0001';
    $year = date('Y');
    $pfx  = 'TKT-' . $year . '-';
    $stmt = $db->prepare("SELECT ticket_number FROM support_tickets WHERE ticket_number LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$pfx . '%']);
    $last = $stmt->fetchColumn();
    $seq  = $last ? (int)substr($last, strrpos($last, '-') + 1) + 1 : 1;
    return $pfx . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
}

function pub_sla_hours(string $priority): int
{
    return match($priority) {
        'Critical' => 4,
        'High'     => 24,
        'Medium'   => 72,
        default    => 120,
    };
}

function pub_notify_emails(): array
{
    $db = pub_db();
    if (!$db) return [];
    try {
        $stmt = $db->prepare("SELECT `value` FROM support_settings WHERE `key` = 'notify_emails' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetchColumn();
        if (!$row) return [];
        return array_values(array_filter(array_map('trim', explode(',', $row))));
    } catch (Throwable $e) {
        return [];
    }
}

function pub_send_mail(string $to, string $to_name, string $subject, string $body): void
{
    $from  = 'noreply@primeuniversity.ac.bd';
    $fname = '=?UTF-8?B?' . base64_encode('Prime University IT Support') . '?=';
    $headers  = 'MIME-Version: 1.0' . "\r\n";
    $headers .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
    $headers .= 'From: ' . $fname . ' <' . $from . '>' . "\r\n";
    $headers .= 'Reply-To: ' . $from . "\r\n";
    @mail($to, $subject, $body, $headers, '-f' . $from);
}

function pub_confirmation_email(string $email, string $name, string $ticket_no,
                                string $title, string $priority, string $deadline): void
{
    $track_url = 'https://primeuniversity.ac.bd/support-ticket.php?track=' . urlencode($ticket_no);
    $subject = '[Prime University] Your IT Support Ticket #' . $ticket_no . ' Has Been Received';
    $body = '
<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#f4f6fb;padding:24px;border-radius:10px;">
  <div style="background:#20c997;color:#fff;padding:20px 28px;border-radius:8px 8px 0 0;">
    <h2 style="margin:0;font-size:1.3rem;">✅ Your IT Support Request Has Been Received</h2>
  </div>
  <div style="background:#fff;padding:28px;border-radius:0 0 8px 8px;border:1px solid #e0e7ef;border-top:none;">
    <p>Dear <strong>' . pub_h($name) . '</strong>,</p>
    <p>Thank you for contacting IT Support. Your ticket has been received and our team will respond as soon as possible.</p>
    <table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:.9rem;">
      <tr><td style="padding:8px 12px;font-weight:600;background:#f0fff8;width:140px;border:1px solid #e0e7ef;">Ticket #</td>
          <td style="padding:8px 12px;border:1px solid #e0e7ef;font-weight:700;color:#20c997;">' . pub_h($ticket_no) . '</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#f0fff8;border:1px solid #e0e7ef;">Title</td>
          <td style="padding:8px 12px;border:1px solid #e0e7ef;">' . pub_h($title) . '</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#f0fff8;border:1px solid #e0e7ef;">Priority</td>
          <td style="padding:8px 12px;border:1px solid #e0e7ef;">' . pub_h($priority) . '</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#f0fff8;border:1px solid #e0e7ef;">SLA Deadline</td>
          <td style="padding:8px 12px;border:1px solid #e0e7ef;">' . pub_h($deadline) . '</td></tr>
    </table>
    <p style="background:#fff8e1;border:1px solid #ffe082;border-radius:8px;padding:12px 16px;font-size:.9rem;">
      <strong>📌 Save your ticket number:</strong> <strong style="color:#e65100;">' . pub_h($ticket_no) . '</strong><br>
      Use it with your email address to check your ticket status on our support portal.
    </p>
    <p><a href="' . $track_url . '" style="background:#20c997;color:#fff;padding:10px 22px;text-decoration:none;border-radius:6px;display:inline-block;font-weight:600;">Track Your Ticket</a></p>
    <hr style="border:none;border-top:1px solid #eee;margin:24px 0 16px;">
    <p style="font-size:.8rem;color:#999;">This is an automated confirmation from <strong>Prime University IT Support</strong>.</p>
  </div>
</div>';
    pub_send_mail($email, $name, $subject, $body);
}

function pub_admin_notification(string $ticket_no, string $submitter_name, string $submitter_email,
                                 string $title, string $priority, string $category,
                                 string $user_type, string $deadline, int $ticket_id): void
{
    $admin_url = 'https://primeuniversity.ac.bd/admin/support-tickets/view.php?id=' . $ticket_id;
    $subject   = '[Prime University] New Support Ticket #' . $ticket_no . ' Submitted';
    $body = '
<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#f4f6fb;padding:24px;border-radius:10px;">
  <div style="background:#2d6cdf;color:#fff;padding:20px 28px;border-radius:8px 8px 0 0;">
    <h2 style="margin:0;font-size:1.3rem;">🎫 New IT Support Ticket Submitted</h2>
  </div>
  <div style="background:#fff;padding:28px;border-radius:0 0 8px 8px;border:1px solid #e0e7ef;border-top:none;">
    <p>A new support ticket has been submitted via the public portal.</p>
    <table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:.9rem;">
      <tr><td style="padding:8px 12px;font-weight:600;background:#f0f4ff;width:140px;border:1px solid #e0e7ef;">Ticket #</td>
          <td style="padding:8px 12px;border:1px solid #e0e7ef;">' . pub_h($ticket_no) . '</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#f0f4ff;border:1px solid #e0e7ef;">Title</td>
          <td style="padding:8px 12px;border:1px solid #e0e7ef;">' . pub_h($title) . '</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#f0f4ff;border:1px solid #e0e7ef;">Submitted By</td>
          <td style="padding:8px 12px;border:1px solid #e0e7ef;">' . pub_h($submitter_name) . '</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#f0f4ff;border:1px solid #e0e7ef;">Email</td>
          <td style="padding:8px 12px;border:1px solid #e0e7ef;">' . pub_h($submitter_email) . '</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#f0f4ff;border:1px solid #e0e7ef;">User Type</td>
          <td style="padding:8px 12px;border:1px solid #e0e7ef;">' . pub_h($user_type ?: 'Not specified') . '</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#f0f4ff;border:1px solid #e0e7ef;">Priority</td>
          <td style="padding:8px 12px;border:1px solid #e0e7ef;">' . pub_h($priority) . '</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#f0f4ff;border:1px solid #e0e7ef;">Category</td>
          <td style="padding:8px 12px;border:1px solid #e0e7ef;">' . pub_h($category) . '</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#f0f4ff;border:1px solid #e0e7ef;">SLA Deadline</td>
          <td style="padding:8px 12px;border:1px solid #e0e7ef;">' . pub_h($deadline) . '</td></tr>
    </table>
    <p><a href="' . $admin_url . '" style="background:#2d6cdf;color:#fff;padding:10px 22px;text-decoration:none;border-radius:6px;display:inline-block;font-weight:600;">View in Admin Panel</a></p>
    <hr style="border:none;border-top:1px solid #eee;margin:24px 0 16px;">
    <p style="font-size:.8rem;color:#999;">Automated notification from <strong>Prime University IT Support</strong>.</p>
  </div>
</div>';
    foreach (pub_notify_emails() as $admin_email) {
        pub_send_mail($admin_email, 'IT Support Team', $subject, $body);
    }
}

// ── Track ticket (GET ?track=TKT-XXXX) ───────────────────────────────────────
$track_ticket  = null;
$track_error   = null;
$track_number  = trim($_GET['track'] ?? '');

if ($track_number !== '') {
    $db = pub_db();
    if ($db) {
        try {
            $stmt = $db->prepare(
                "SELECT t.*,
                        COALESCE(u.full_name, t.submitter_name) AS creator_name,
                        a.full_name AS assignee_name
                 FROM support_tickets t
                 LEFT JOIN users u ON u.id = t.created_by
                 LEFT JOIN users a ON a.id = t.assigned_to
                 WHERE t.ticket_number = ?"
            );
            $stmt->execute([$track_number]);
            $track_ticket = $stmt->fetch();
            if (!$track_ticket) {
                $track_error = 'No ticket found with number <strong>' . pub_h($track_number) . '</strong>. Please check and try again.';
            }
        } catch (Throwable $e) {
            $track_error = 'Could not look up ticket. Please try again.';
        }
    }
}

// ── Handle form submission ────────────────────────────────────────────────────
$form_errors  = [];
$form_success = false;
$submitted_ticket_no = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action'] === 'submit_ticket') {
    // CSRF check
    if (!hash_equals($pub_csrf, $_POST['_csrf'] ?? '')) {
        $form_errors[] = 'Security token mismatch. Please refresh and try again.';
    } else {
        $full_name          = trim($_POST['full_name']          ?? '');
        $email              = trim($_POST['email']              ?? '');
        $phone              = trim($_POST['phone']              ?? '');
        $title              = trim($_POST['title']              ?? '');
        $description        = trim($_POST['description']        ?? '');
        $category           = $_POST['category']                ?? 'Other';
        $priority           = $_POST['priority']                ?? 'Medium';
        $user_type          = $_POST['user_type']               ?? '';
        $student_id         = trim($_POST['student_id']         ?? '');
        $student_department = trim($_POST['student_department'] ?? '');
        $student_program    = trim($_POST['student_program']    ?? '');
        $student_batch      = trim($_POST['student_batch']      ?? '');

        $valid_cats   = ['Hardware','Software','Network','Email','Other'];
        $valid_prios  = ['Low','Medium','High','Critical'];
        $valid_utypes = ['','Student','Faculty','Administrative Employee'];

        if ($full_name === '')                    $form_errors[] = 'Your name is required.';
        if ($email === '')                         $form_errors[] = 'Email address is required.';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $form_errors[] = 'Please enter a valid email address.';
        if ($title === '')                         $form_errors[] = 'Issue title is required.';
        if (mb_strlen($title) > 500)               $form_errors[] = 'Title must be 500 characters or less.';
        if ($description === '')                   $form_errors[] = 'Issue description is required.';
        if (!in_array($category, $valid_cats, true))   $category  = 'Other';
        if (!in_array($priority, $valid_prios, true))  $priority  = 'Medium';
        if (!in_array($user_type, $valid_utypes, true)) $user_type = '';
        if ($user_type === 'Student' && $student_id === '') $form_errors[] = 'Student ID is required.';

        if (empty($form_errors)) {
            $db = pub_db();
            if (!$db) {
                $form_errors[] = 'Database connection error. Please try again later.';
            } else {
                try {
                    $ticket_number = pub_generate_ticket_number();
                    $hours    = pub_sla_hours($priority);
                    $deadline = date('Y-m-d H:i:s', strtotime("+{$hours} hours"));
                    $deadline_display = date('M d, Y H:i', strtotime($deadline));

                    $db->prepare(
                        'INSERT INTO support_tickets
                           (ticket_number, title, description, category, priority, status,
                            deadline, created_by, user_type, student_id, student_department,
                            student_program, student_batch, submitter_name, submitter_email,
                            submitter_phone, is_public)
                         VALUES (?,?,?,?,?,\'Open\',?,NULL,?,?,?,?,?,?,?,?,1)'
                    )->execute([
                        $ticket_number, $title, $description, $category, $priority,
                        $deadline,
                        $user_type ?: null,
                        $student_id ?: null,
                        $student_department ?: null,
                        $student_program ?: null,
                        $student_batch ?: null,
                        $full_name, $email, $phone ?: null,
                    ]);

                    $ticket_id = (int)$db->lastInsertId();

                    // Regenerate CSRF after success
                    $_SESSION['st_pub_csrf'] = bin2hex(random_bytes(32));
                    $pub_csrf = $_SESSION['st_pub_csrf'];

                    // Send emails
                    pub_confirmation_email($email, $full_name, $ticket_number, $title, $priority, $deadline_display);
                    pub_admin_notification($ticket_number, $full_name, $email, $title, $priority, $category, $user_type, $deadline_display, $ticket_id);

                    $form_success        = true;
                    $submitted_ticket_no = $ticket_number;
                } catch (Throwable $e) {
                    $form_errors[] = 'Could not save your ticket. Please try again.';
                }
            }
        }
    }
}

// ── Status badge helper ───────────────────────────────────────────────────────
function pub_status_color(string $status): string
{
    return match($status) {
        'Open'        => '#0d6efd',
        'In Progress' => '#0dcaf0',
        'Pending'     => '#ffc107',
        'Resolved'    => '#198754',
        'Closed'      => '#6c757d',
        'Reopened'    => '#dc3545',
        default       => '#6c757d',
    };
}
?>
<!doctype html>
<html class="no-js" lang="en">

<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <title><?= pub_h($page_title) ?></title>
   <meta name="description" content="Submit an IT support ticket or track your existing request at Prime University.">
   <meta name="viewport" content="width=device-width, initial-scale=1">
   <link rel="shortcut icon" type="image/x-icon" href="/assets/img/logo/favicon.png">
   <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
   <link rel="stylesheet" href="/assets/css/font-awesome-pro.css">
   <link rel="stylesheet" href="/assets/css/spacing.css">
   <link rel="stylesheet" href="/assets/css/main.css">
   <style>
      .pu-support-hero {
         background: linear-gradient(135deg, #1a1f70 0%, #0d6efd 100%);
         padding: 60px 0 40px;
         color: #fff;
      }
      .pu-support-hero h1 { font-size: 2rem; font-weight: 700; margin-bottom: 8px; }
      .pu-support-hero p  { font-size: 1rem; opacity: .85; }
      .pu-tab-btn {
         border: 2px solid #0d6efd;
         background: #fff;
         color: #0d6efd;
         border-radius: 10px;
         padding: 10px 28px;
         font-weight: 600;
         cursor: pointer;
         transition: all .2s;
      }
      .pu-tab-btn.active, .pu-tab-btn:hover {
         background: #0d6efd;
         color: #fff;
      }
      .pu-support-card {
         border-radius: 14px;
         border: 1px solid #e0e7ef;
         overflow: hidden;
      }
      .pu-support-card .card-header {
         background: #f8f9ff;
         border-bottom: 1px solid #e0e7ef;
         padding: 18px 24px;
         font-weight: 600;
         font-size: 1.05rem;
      }
      .form-control, .form-select {
         border-radius: 8px !important;
      }
      .status-pill {
         display: inline-block;
         padding: 5px 14px;
         border-radius: 20px;
         color: #fff;
         font-size: .85rem;
         font-weight: 600;
      }
      .pu-ticket-info td { padding: 8px 12px; border-bottom: 1px solid #f0f0f0; font-size: .9rem; }
      .pu-ticket-info td:first-child { font-weight: 600; color: #555; width: 150px; }
   </style>
</head>

<body id="body">

   <!-- preloader -->
   <div id="preloader">
      <div class="preloader"><span></span><span></span></div>
   </div>

   <header class="it-header-height">
      <?php include __DIR__ . '/includes/header-top.php'; ?>
      <?php include __DIR__ . '/includes/nav-menu.php'; ?>
   </header>

   <!-- Hero Section -->
   <div class="pu-support-hero">
      <div class="container">
         <h1><i class="fas fa-headset me-2"></i>IT Support Portal</h1>
         <p>Submit a support ticket or track the status of your existing request.</p>
         <nav aria-label="breadcrumb" class="mt-3">
            <ol class="breadcrumb mb-0" style="background:transparent;padding:0;">
               <li class="breadcrumb-item"><a href="/" style="color:rgba(255,255,255,.7);text-decoration:none;">Home</a></li>
               <li class="breadcrumb-item active" style="color:#fff;">IT Support</li>
            </ol>
         </nav>
      </div>
   </div>

   <div class="container py-5">

      <!-- Tab Switcher -->
      <div class="d-flex gap-3 mb-4 flex-wrap">
         <button class="pu-tab-btn <?= ($track_number === '' && !$form_success) ? 'active' : '' ?>"
                 onclick="showTab('submit')" id="tab-submit-btn">
            <i class="fas fa-paper-plane me-1"></i> Submit a Ticket
         </button>
         <button class="pu-tab-btn <?= ($track_number !== '' || $form_success) ? 'active' : '' ?>"
                 onclick="showTab('track')" id="tab-track-btn">
            <i class="fas fa-search me-1"></i> Track My Ticket
         </button>
      </div>

      <!-- ── SUBMIT TICKET PANEL ──────────────────────────────────────────── -->
      <div id="panel-submit" <?= ($track_number !== '' || $form_success) ? 'style="display:none;"' : '' ?>>

         <?php if ($form_success): ?>
         <!-- Success Message -->
         <div class="pu-support-card p-0 mb-4">
            <div class="card-header" style="background:#d1f7ec;border-color:#a3e9cf;color:#0a5c42;">
               <i class="fas fa-check-circle me-2 text-success"></i>Ticket Submitted Successfully!
            </div>
            <div class="p-4">
               <p class="mb-2">Your IT support ticket has been submitted. A confirmation has been sent to your email address.</p>
               <p class="mb-3"><strong>Your Ticket Number:</strong>
                  <span style="font-size:1.3rem;font-weight:700;color:#0d6efd;"><?= pub_h($submitted_ticket_no) ?></span>
               </p>
               <p class="text-muted mb-3" style="font-size:.9rem;">
                  Please save this ticket number. You can use it along with your email address to track the progress of your request.
               </p>
               <button class="btn btn-outline-primary btn-sm" onclick="document.getElementById('track-input').value='<?= pub_h($submitted_ticket_no) ?>'; showTab('track');" style="border-radius:8px;">
                  <i class="fas fa-search me-1"></i> Track This Ticket
               </button>
               <button class="btn btn-primary btn-sm ms-2" onclick="location.reload();" style="border-radius:8px;">
                  <i class="fas fa-plus me-1"></i> Submit Another Ticket
               </button>
            </div>
         </div>

         <?php else: ?>

         <?php if ($form_errors): ?>
         <div class="alert alert-danger mb-4">
            <ul class="mb-0 ps-3">
               <?php foreach ($form_errors as $e): ?><li><?= pub_h($e) ?></li><?php endforeach; ?>
            </ul>
         </div>
         <?php endif; ?>

         <div class="row g-4">

            <div class="col-lg-8">
               <div class="pu-support-card">
                  <div class="card-header">
                     <i class="fas fa-ticket-alt me-2 text-primary"></i>Submit a Support Ticket
                  </div>
                  <div class="p-4">
                     <form method="POST" novalidate>
                        <input type="hidden" name="_action" value="submit_ticket">
                        <input type="hidden" name="_csrf"   value="<?= pub_h($pub_csrf) ?>">

                        <!-- Personal Info -->
                        <h6 class="fw-semibold mb-3 text-muted" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.05em;">Your Information</h6>
                        <div class="row g-3 mb-4">
                           <div class="col-md-6">
                              <label class="form-label fw-medium">Full Name <span class="text-danger">*</span></label>
                              <input type="text" name="full_name" class="form-control"
                                     value="<?= pub_h($_POST['full_name'] ?? '') ?>"
                                     placeholder="Your full name" required maxlength="200">
                           </div>
                           <div class="col-md-6">
                              <label class="form-label fw-medium">Email Address <span class="text-danger">*</span></label>
                              <input type="email" name="email" class="form-control"
                                     value="<?= pub_h($_POST['email'] ?? '') ?>"
                                     placeholder="you@primeuniversity.ac.bd" required>
                           </div>
                           <div class="col-md-6">
                              <label class="form-label fw-medium">Phone Number</label>
                              <input type="tel" name="phone" class="form-control"
                                     value="<?= pub_h($_POST['phone'] ?? '') ?>"
                                     placeholder="01xxxxxxxxx">
                           </div>
                           <div class="col-md-6">
                              <label class="form-label fw-medium">User Type</label>
                              <select name="user_type" id="pub_user_type" class="form-select"
                                      onchange="pubToggleUserType(this.value)">
                                 <option value="">— Select your role —</option>
                                 <option value="Student"                   <?= (($_POST['user_type'] ?? '') === 'Student') ? 'selected' : '' ?>>Student</option>
                                 <option value="Faculty"                   <?= (($_POST['user_type'] ?? '') === 'Faculty') ? 'selected' : '' ?>>Faculty</option>
                                 <option value="Administrative Employee"   <?= (($_POST['user_type'] ?? '') === 'Administrative Employee') ? 'selected' : '' ?>>Administrative Employee</option>
                              </select>
                           </div>
                        </div>

                        <!-- Student Fields -->
                        <div id="pub_student_fields" style="display:none;" class="mb-4">
                           <h6 class="fw-semibold mb-3 text-muted" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.05em;">Student Details</h6>
                           <div class="row g-3">
                              <div class="col-md-6">
                                 <label class="form-label fw-medium">Student ID <span class="text-danger">*</span></label>
                                 <input type="text" name="student_id" class="form-control"
                                        value="<?= pub_h($_POST['student_id'] ?? '') ?>"
                                        placeholder="e.g. 2312345678" maxlength="20">
                              </div>
                              <div class="col-md-6">
                                 <label class="form-label fw-medium">Department</label>
                                 <input type="text" name="student_department" class="form-control"
                                        value="<?= pub_h($_POST['student_department'] ?? '') ?>"
                                        placeholder="e.g. Computer Science" maxlength="200">
                              </div>
                              <div class="col-md-6">
                                 <label class="form-label fw-medium">Program</label>
                                 <input type="text" name="student_program" class="form-control"
                                        value="<?= pub_h($_POST['student_program'] ?? '') ?>"
                                        placeholder="e.g. BSc in CSE" maxlength="200">
                              </div>
                              <div class="col-md-6">
                                 <label class="form-label fw-medium">Batch</label>
                                 <input type="text" name="student_batch" class="form-control"
                                        value="<?= pub_h($_POST['student_batch'] ?? '') ?>"
                                        placeholder="e.g. Spring 2023" maxlength="100">
                              </div>
                           </div>
                        </div>

                        <!-- Faculty/Admin Employee Department -->
                        <div id="pub_faculty_fields" style="display:none;" class="mb-4">
                           <h6 class="fw-semibold mb-3 text-muted" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.05em;">Department</h6>
                           <div class="row g-3">
                              <div class="col-md-8">
                                 <label class="form-label fw-medium">Department</label>
                                 <input type="text" name="student_department" class="form-control"
                                        value="<?= pub_h($_POST['student_department'] ?? '') ?>"
                                        placeholder="e.g. Computer Science" maxlength="200">
                              </div>
                           </div>
                        </div>

                        <!-- Issue Details -->
                        <h6 class="fw-semibold mb-3 text-muted" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.05em;">Issue Details</h6>
                        <div class="mb-3">
                           <label class="form-label fw-medium">Issue Title <span class="text-danger">*</span></label>
                           <input type="text" name="title" class="form-control form-control-lg"
                                  value="<?= pub_h($_POST['title'] ?? '') ?>"
                                  placeholder="Brief description of your issue" required maxlength="500">
                        </div>
                        <div class="mb-3">
                           <label class="form-label fw-medium">Detailed Description <span class="text-danger">*</span></label>
                           <textarea name="description" class="form-control" rows="6"
                                     placeholder="Please describe your issue in detail. Include any error messages, steps you've tried, etc."
                                     required><?= pub_h($_POST['description'] ?? '') ?></textarea>
                        </div>

                        <div class="row g-3 mb-4">
                           <div class="col-md-6">
                              <label class="form-label fw-medium">Category</label>
                              <select name="category" class="form-select">
                                 <?php foreach (['Hardware','Software','Network','Email','Other'] as $cat): ?>
                                 <option value="<?= $cat ?>" <?= (($_POST['category'] ?? 'Other') === $cat) ? 'selected' : '' ?>><?= $cat ?></option>
                                 <?php endforeach; ?>
                              </select>
                           </div>
                           <div class="col-md-6">
                              <label class="form-label fw-medium">Priority</label>
                              <select name="priority" id="pub_priority" class="form-select"
                                      onchange="updatePubSlaHint(this.value)">
                                 <?php foreach (['Low','Medium','High','Critical'] as $prio): ?>
                                 <option value="<?= $prio ?>" <?= (($_POST['priority'] ?? 'Medium') === $prio) ? 'selected' : '' ?>><?= $prio ?></option>
                                 <?php endforeach; ?>
                              </select>
                              <div id="pub_sla_hint" class="form-text text-muted mt-1"></div>
                           </div>
                        </div>

                        <button type="submit" class="btn btn-primary btn-lg w-100" style="border-radius:10px;">
                           <i class="fas fa-paper-plane me-2"></i>Submit Support Ticket
                        </button>
                     </form>
                  </div>
               </div>
            </div>

            <!-- Sidebar Info -->
            <div class="col-lg-4">
               <div class="pu-support-card mb-3">
                  <div class="card-header" style="background:#e8f4ff;">
                     <i class="fas fa-clock me-2 text-primary"></i>Response Times
                  </div>
                  <div class="p-4">
                     <table style="width:100%;font-size:.875rem;">
                        <tr><td class="py-1"><span class="badge" style="background:#dc3545;">Critical</span></td><td class="py-1 ps-2">Within <strong>4 hours</strong></td></tr>
                        <tr><td class="py-1"><span class="badge" style="background:#ffc107;color:#000;">High</span></td><td class="py-1 ps-2">Within <strong>1 day</strong></td></tr>
                        <tr><td class="py-1"><span class="badge" style="background:#0dcaf0;color:#000;">Medium</span></td><td class="py-1 ps-2">Within <strong>3 days</strong></td></tr>
                        <tr><td class="py-1"><span class="badge" style="background:#198754;">Low</span></td><td class="py-1 ps-2">Within <strong>5 days</strong></td></tr>
                     </table>
                  </div>
               </div>
               <div class="pu-support-card mb-3">
                  <div class="card-header" style="background:#fff8e1;">
                     <i class="fas fa-info-circle me-2 text-warning"></i>What to Include
                  </div>
                  <div class="p-4" style="font-size:.875rem;">
                     <ul class="mb-0 ps-3">
                        <li class="mb-1">Your name and contact email</li>
                        <li class="mb-1">A clear description of the issue</li>
                        <li class="mb-1">Steps to reproduce the problem</li>
                        <li class="mb-1">Any error messages you see</li>
                        <li>Device/software information</li>
                     </ul>
                  </div>
               </div>
               <div class="pu-support-card">
                  <div class="card-header" style="background:#f0fff4;">
                     <i class="fas fa-phone me-2 text-success"></i>Direct Contact
                  </div>
                  <div class="p-4" style="font-size:.875rem;">
                     <p class="mb-1"><strong>IT Department</strong></p>
                     <p class="mb-1"><i class="fas fa-envelope me-1 text-muted"></i> dd.it@primeuniversity.ac.bd</p>
                     <p class="mb-0"><i class="fas fa-map-marker-alt me-1 text-muted"></i> IT Office, Admin Building</p>
                  </div>
               </div>
            </div>

         </div>
         <?php endif; ?>
      </div>

      <!-- ── TRACK TICKET PANEL ────────────────────────────────────────────── -->
      <div id="panel-track" <?= ($track_number === '' && !$form_success) ? 'style="display:none;"' : '' ?>>

         <!-- Search Form -->
         <div class="pu-support-card mb-4">
            <div class="card-header">
               <i class="fas fa-search me-2 text-primary"></i>Track Your Ticket Status
            </div>
            <div class="p-4">
               <form method="GET" class="row g-3 align-items-end">
                  <div class="col-md-8">
                     <label class="form-label fw-medium">Ticket Number</label>
                     <input type="text" id="track-input" name="track" class="form-control form-control-lg"
                            value="<?= pub_h($track_number) ?>"
                            placeholder="e.g. TKT-2026-0001" style="font-family:monospace;">
                  </div>
                  <div class="col-md-4">
                     <button type="submit" class="btn btn-primary btn-lg w-100" style="border-radius:8px;">
                        <i class="fas fa-search me-1"></i> Search
                     </button>
                  </div>
               </form>
            </div>
         </div>

         <?php if ($track_error): ?>
         <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i><?= $track_error ?>
         </div>
         <?php endif; ?>

         <?php if ($track_ticket): ?>
         <!-- Ticket Details -->
         <?php
            $status   = $track_ticket['status'];
            $is_over  = !in_array($status, ['Resolved','Closed']) && !empty($track_ticket['deadline']) && strtotime($track_ticket['deadline']) < time();
         ?>
         <div class="pu-support-card mb-4">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
               <span><i class="fas fa-ticket-alt me-2"></i><?= pub_h($track_ticket['ticket_number']) ?></span>
               <div class="d-flex gap-2 flex-wrap align-items-center">
                  <span class="status-pill" style="background:<?= pub_h(pub_status_color($status)) ?>;"><?= pub_h($status) ?></span>
                  <?php if ($is_over): ?>
                  <span class="badge bg-danger">OVERDUE</span>
                  <?php endif; ?>
               </div>
            </div>
            <div class="p-4">
               <h5 class="fw-semibold mb-4"><?= pub_h($track_ticket['title']) ?></h5>

               <div class="row g-4">
                  <div class="col-md-6">
                     <h6 class="fw-semibold mb-2 text-muted" style="font-size:.8rem;text-transform:uppercase;">Ticket Info</h6>
                     <table class="pu-ticket-info" style="width:100%;">
                        <tr><td>Status</td><td><span class="status-pill" style="background:<?= pub_h(pub_status_color($status)) ?>;font-size:.8rem;"><?= pub_h($status) ?></span></td></tr>
                        <tr><td>Priority</td><td><?= pub_h($track_ticket['priority']) ?></td></tr>
                        <tr><td>Category</td><td><?= pub_h($track_ticket['category']) ?></td></tr>
                        <tr><td>Submitted By</td><td><?= pub_h($track_ticket['creator_name'] ?? 'Guest') ?></td></tr>
                        <tr><td>Submitted On</td><td><?= date('M d, Y H:i', strtotime($track_ticket['created_at'])) ?></td></tr>
                        <?php if (!empty($track_ticket['deadline'])): ?>
                        <tr>
                           <td>Deadline</td>
                           <td class="<?= $is_over ? 'text-danger fw-semibold' : '' ?>">
                              <?= date('M d, Y H:i', strtotime($track_ticket['deadline'])) ?>
                              <?php if ($is_over): ?><span class="badge bg-danger ms-1" style="font-size:.65rem;">Overdue</span><?php endif; ?>
                           </td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($track_ticket['assignee_name'])): ?>
                        <tr><td>Assigned To</td><td><?= pub_h($track_ticket['assignee_name']) ?></td></tr>
                        <?php endif; ?>
                        <?php if (!empty($track_ticket['resolved_at'])): ?>
                        <tr><td>Resolved On</td><td><?= date('M d, Y H:i', strtotime($track_ticket['resolved_at'])) ?></td></tr>
                        <?php endif; ?>
                        <?php if (!empty($track_ticket['closed_at'])): ?>
                        <tr><td>Closed On</td><td><?= date('M d, Y H:i', strtotime($track_ticket['closed_at'])) ?></td></tr>
                        <?php endif; ?>
                     </table>
                  </div>
                  <div class="col-md-6">
                     <!-- Progress Indicator -->
                     <h6 class="fw-semibold mb-3 text-muted" style="font-size:.8rem;text-transform:uppercase;">Progress</h6>
                     <?php
                     $steps = ['Open' => 1, 'In Progress' => 2, 'Pending' => 2, 'Reopened' => 2, 'Resolved' => 3, 'Closed' => 4];
                     $current_step = $steps[$status] ?? 1;
                     $step_labels = ['Submitted', 'In Progress', 'Resolved', 'Closed'];
                     ?>
                     <div style="position:relative;padding:10px 0;">
                        <?php foreach ($step_labels as $si => $sl): ?>
                        <?php $step_num = $si + 1; $is_done = $current_step >= $step_num; $is_current = $current_step === $step_num; ?>
                        <div class="d-flex align-items-center mb-3">
                           <div style="width:32px;height:32px;border-radius:50%;background:<?= $is_done ? '#0d6efd' : '#dee2e6' ?>;color:<?= $is_done ? '#fff' : '#999' ?>;display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700;flex-shrink:0;">
                              <?= $is_done ? '<i class="fas fa-check" style="font-size:.7rem;"></i>' : $step_num ?>
                           </div>
                           <div class="ms-2">
                              <span style="font-size:.875rem;font-weight:<?= $is_current ? '700' : '400' ?>;color:<?= $is_done ? '#0d6efd' : '#999' ?>;"><?= $sl ?></span>
                              <?php if ($is_current): ?><span class="badge bg-primary ms-1" style="font-size:.65rem;">Current</span><?php endif; ?>
                           </div>
                        </div>
                        <?php endforeach; ?>
                     </div>
                  </div>
               </div>
            </div>
         </div>

         <div class="text-center text-muted" style="font-size:.875rem;">
            <i class="fas fa-info-circle me-1"></i>
            For urgent issues, please contact the IT department directly at
            <a href="mailto:dd.it@primeuniversity.ac.bd">dd.it@primeuniversity.ac.bd</a>.
         </div>
         <?php endif; ?>

         <?php if ($track_number === '' && !$form_success): ?>
         <div class="text-center py-5 text-muted">
            <i class="fas fa-ticket-alt" style="font-size:3rem;color:#dee2e6;margin-bottom:16px;display:block;"></i>
            Enter your ticket number above to check its current status.
         </div>
         <?php endif; ?>

      </div>

   </div><!-- /container -->

   <?php include __DIR__ . '/includes/news-ticker.php'; ?>
   <?php include __DIR__ . '/includes/footer.php'; ?>

   <!-- Scripts -->
   <script src="/assets/js/vendor/jquery.js"></script>
   <script src="/assets/js/bootstrap.bundle.min.js"></script>
   <script src="/assets/js/main.js"></script>
   <script>
   function showTab(tab) {
      document.getElementById('panel-submit').style.display = (tab === 'submit') ? '' : 'none';
      document.getElementById('panel-track').style.display  = (tab === 'track')  ? '' : 'none';
      document.getElementById('tab-submit-btn').classList.toggle('active', tab === 'submit');
      document.getElementById('tab-track-btn').classList.toggle('active', tab === 'track');
   }

   const slaMap = { Low: '5 days', Medium: '3 days', High: '1 day', Critical: '4 hours' };
   function updatePubSlaHint(val) {
      const el = document.getElementById('pub_sla_hint');
      if (el) el.textContent = slaMap[val] ? 'Expected response: ' + slaMap[val] : '';
   }
   updatePubSlaHint(document.getElementById('pub_priority')?.value || 'Medium');

   function pubToggleUserType(val) {
      const sf = document.getElementById('pub_student_fields');
      const ff = document.getElementById('pub_faculty_fields');
      if (sf) sf.style.display = (val === 'Student') ? '' : 'none';
      if (ff) ff.style.display = (val === 'Faculty' || val === 'Administrative Employee') ? '' : 'none';
   }
   pubToggleUserType(document.getElementById('pub_user_type')?.value || '');
   </script>
</body>
</html>
