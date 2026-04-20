<?php
/**
 * Public Lead Application Form – Apply Now
 * Collects prospective student information and stores it as a lead.
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/includes/config.php';

$page_title = 'Apply Now – Prime University';

if (empty($_SESSION['apply_csrf'])) {
    $_SESSION['apply_csrf'] = bin2hex(random_bytes(32));
}
$pub_csrf = $_SESSION['apply_csrf'];

function an_h(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function an_old(string $key, array $old, string $default = ''): string
{
    return an_h($old[$key] ?? $default);
}

function an_generate_lead_number(): string
{
    $db = front_db();
    if (!$db) return 'LD-' . date('Y') . '-0001';

    $year = date('Y');
    $pfx = 'LD-' . $year . '-';
    $stmt = $db->prepare('SELECT lead_number FROM leads WHERE lead_number LIKE ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$pfx . '%']);
    $last = $stmt->fetchColumn();
    $seq = $last ? (int)substr($last, strrpos($last, '-') + 1) + 1 : 1;

    return $pfx . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
}

function an_send_mail(string $to, string $subject, string $body): void
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return;
    $to = filter_var($to, FILTER_SANITIZE_EMAIL);
    if (strpbrk($to, "\r\n\t") !== false) return;

    $from = 'noreply@primeuniversity.ac.bd';
    $fname = '=?UTF-8?B?' . base64_encode('Prime University Admissions') . '?=';
    $headers = 'MIME-Version: 1.0' . "\r\n";
    $headers .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
    $headers .= 'From: ' . $fname . ' <' . $from . '>' . "\r\n";
    $headers .= 'Reply-To: ' . $from . "\r\n";
    @mail($to, $subject, $body, $headers, '-f' . $from);
}

function an_semester_list(): array
{
    $list = [];
    $curYear = (int)date('Y');
    for ($y = $curYear; $y <= $curYear + 3; $y++) {
        $list[] = 'Summer ' . $y;
        $list[] = 'Fall ' . $y;
        $list[] = 'Spring ' . $y;
    }
    return $list;
}

$departments = [];
$programs_by_dept = [];
try {
    $db = front_db();
    if ($db) {
        $departments = $db->query(
            'SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $prog_rows = $db->query(
            'SELECT id, dept_id, program_name
             FROM dept_academic_programs
             WHERE is_active = 1 ORDER BY program_name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($prog_rows as $p) {
            $programs_by_dept[(int)$p['dept_id']][] = $p;
        }
    }
} catch (Throwable $e) {
}

$semesters = an_semester_list();
$form_errors = [];
$form_success = false;
$submitted_number = '';
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['_apply_csrf'] ?? '';
    if (!hash_equals($pub_csrf, $token)) {
        $form_errors[] = 'Security token mismatch. Please refresh the page and try again.';
    }

    if (empty($form_errors)) {
        $old = $_POST;

        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $current_city = trim($_POST['current_city'] ?? '');
        $degree_type = in_array($_POST['degree_type'] ?? '', ['bachelor', 'master'], true)
            ? $_POST['degree_type'] : 'bachelor';
        $dept_id = (int)($_POST['dept_id'] ?? 0) ?: null;
        $program_id = (int)($_POST['program_id'] ?? 0) ?: null;
        $preferred_semester = trim($_POST['preferred_semester'] ?? '');

        if ($first_name === '') $form_errors[] = 'First name is required.';
        if ($last_name === '') $form_errors[] = 'Last name is required.';
        if ($phone === '') $form_errors[] = 'Phone number is required.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $form_errors[] = 'Please enter a valid email address.';
        }
    }

    if (empty($form_errors)) {
        try {
            $db = front_db();
            if (!$db) throw new RuntimeException('Database unavailable.');

            $lead_number = an_generate_lead_number();
            $db->prepare(
                'INSERT INTO leads
                   (lead_number, first_name, last_name, email, phone, address, current_city,
                    degree_type, dept_id, program_id, preferred_semester,
                    status, source)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $lead_number,
                $first_name,
                $last_name,
                $email ?: null,
                $phone,
                $address ?: null,
                $current_city ?: null,
                $degree_type,
                $dept_id,
                $program_id,
                $preferred_semester ?: null,
                'fresh',
                'online',
            ]);
            $lead_id = (int)$db->lastInsertId();

            $db->prepare(
                'INSERT INTO lead_history (lead_id, user_id, action, description)
                 VALUES (?, NULL, ?, ?)'
            )->execute([
                $lead_id,
                'created',
                'Application submitted via online form',
            ]);

            if ($email !== '') {
                $subject = 'Your Application Has Been Received – ' . $lead_number;
                $body = '
<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;background:#f9fafb;border-radius:10px;">
  <div style="background:#1a1f36;color:#fff;padding:20px 28px;border-radius:8px 8px 0 0;text-align:center;">
    <h2 style="margin:0;font-size:1.3rem;">Application Received</h2>
    <p style="margin:4px 0 0;font-size:.85rem;opacity:.8;">Prime University Admissions</p>
  </div>
  <div style="background:#fff;padding:28px;border-radius:0 0 8px 8px;">
    <p>Dear ' . an_h($first_name) . ',</p>
    <p>Thank you for your interest in Prime University. Your application has been received and our admissions team will contact you shortly.</p>
    <table style="width:100%;border-collapse:collapse;margin:16px 0;">
      <tr><td style="padding:8px;border:1px solid #e5e7eb;background:#f9fafb;font-weight:600;width:40%">Application Number</td><td style="padding:8px;border:1px solid #e5e7eb;">' . an_h($lead_number) . '</td></tr>
      <tr><td style="padding:8px;border:1px solid #e5e7eb;background:#f9fafb;font-weight:600">Name</td><td style="padding:8px;border:1px solid #e5e7eb;">' . an_h($first_name . ' ' . $last_name) . '</td></tr>
      <tr><td style="padding:8px;border:1px solid #e5e7eb;background:#f9fafb;font-weight:600">Degree</td><td style="padding:8px;border:1px solid #e5e7eb;">' . an_h(ucfirst($degree_type)) . '</td></tr>
    </table>
    <p style="color:#6b7280;font-size:.85rem;">If you have any questions, feel free to contact us at <a href="mailto:info@primeuniversity.ac.bd">info@primeuniversity.ac.bd</a></p>
    <p>Warm regards,<br><strong>Prime University Admissions Team</strong></p>
  </div>
</div>';
                an_send_mail($email, $subject, $body);
            }

            $form_success = true;
            $submitted_number = $lead_number;
            $old = [];
            $_SESSION['apply_csrf'] = bin2hex(random_bytes(32));
            $pub_csrf = $_SESSION['apply_csrf'];
        } catch (Throwable $e) {
            $form_errors[] = 'Something went wrong. Please try again later.';
        }
    }
}
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <title><?= an_h($page_title) ?></title>
   <meta name="description" content="Apply online to Prime University and connect with our admissions team for your preferred department and intake.">
   <meta name="viewport" content="width=device-width, initial-scale=1">
   <link rel="shortcut icon" type="image/x-icon" href="/assets/img/logo/favicon.png">
   <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
   <link rel="stylesheet" href="/assets/css/font-awesome-pro.css">
   <link rel="stylesheet" href="/assets/css/swiper-bundle.min.css">
   <link rel="stylesheet" href="/assets/css/slick.css">
   <link rel="stylesheet" href="/assets/css/magnific-popup.css">
   <link rel="stylesheet" href="/assets/css/nice-select.css">
   <link rel="stylesheet" href="/assets/css/custom-animation.css">
   <link rel="stylesheet" href="/assets/css/spacing.css">
   <link rel="stylesheet" href="/assets/css/main.css">
   <style>
      body { background:#f5f7fb; }
      .pu-apply-hero {
         position: relative;
         overflow: hidden;
         padding: 95px 0 140px;
         background: linear-gradient(135deg, #0f1f4a 0%, #163d88 55%, #2563eb 100%);
      }
      .pu-apply-hero::before {
         content:'';
         position:absolute;
         inset:0;
         background:url('/assets/img/shape/breadcrumb-1-bg.png') center/cover no-repeat;
         opacity:.08;
      }
      .pu-apply-hero::after {
         content:'';
         position:absolute;
         right:-110px;
         top:-70px;
         width:340px;
         height:340px;
         border-radius:50%;
         background:rgba(255,255,255,.08);
      }
      .pu-apply-hero .breadcrumb-nav a,
      .pu-apply-hero .breadcrumb-nav span {
         color:rgba(255,255,255,.78);
         font-size:.9rem;
      }
      .pu-apply-hero .breadcrumb-nav a:hover { color:#fff; }
      .pu-apply-hero .sep { margin:0 8px; color:rgba(255,255,255,.45); }
      .pu-apply-kicker {
         display:inline-flex;
         align-items:center;
         gap:10px;
         padding:10px 18px;
         border-radius:999px;
         background:rgba(255,255,255,.13);
         color:#fff;
         font-size:.82rem;
         font-weight:700;
         letter-spacing:.08em;
         text-transform:uppercase;
         margin-bottom:18px;
      }
      .pu-apply-hero h1 {
         color:#fff;
         font-size:clamp(2.2rem, 5vw, 4.1rem);
         line-height:1.1;
         font-weight:800;
         margin-bottom:18px;
      }
      .pu-apply-hero p {
         color:rgba(255,255,255,.84);
         font-size:1.05rem;
         max-width:610px;
         line-height:1.8;
         margin-bottom:0;
      }
      .pu-hero-points {
         display:grid;
         grid-template-columns:repeat(2, minmax(0,1fr));
         gap:14px;
         margin-top:32px;
      }
      .pu-hero-point {
         display:flex;
         gap:14px;
         padding:18px 20px;
         border-radius:18px;
         background:rgba(255,255,255,.09);
         backdrop-filter: blur(6px);
         color:#fff;
      }
      .pu-hero-point .icon {
         width:46px;
         height:46px;
         border-radius:14px;
         background:rgba(255,255,255,.14);
         display:flex;
         align-items:center;
         justify-content:center;
         font-size:1rem;
         flex-shrink:0;
      }
      .pu-hero-point strong { display:block; font-size:1rem; margin-bottom:2px; }
      .pu-hero-point span { color:rgba(255,255,255,.72); font-size:.88rem; }
      .pu-apply-overlap { margin-top:-82px; position:relative; z-index:6; }
      .pu-apply-shell {
         background:#fff;
         border-radius:28px;
         box-shadow:0 18px 60px rgba(15, 23, 42, .12);
      }
      .pu-apply-aside {
         background:linear-gradient(180deg, #f8fbff 0%, #eef4ff 100%);
         padding:38px 32px;
         height:100%;
         border-right:1px solid #e6ecf5;
         border-radius:28px 0 0 28px;
      }
      .pu-apply-form-wrap { padding:38px 34px; }
      .pu-panel-title {
         font-size:1.45rem;
         color:#10224d;
         font-weight:800;
         margin-bottom:10px;
      }
      .pu-panel-text { color:#6b7280; font-size:.96rem; line-height:1.8; }
      .pu-stat-grid {
         display:grid;
         grid-template-columns:repeat(2, minmax(0,1fr));
         gap:14px;
         margin:26px 0 30px;
      }
      .pu-stat-card {
         background:#fff;
         border-radius:18px;
         padding:18px;
         box-shadow:0 8px 24px rgba(37, 99, 235, .08);
      }
      .pu-stat-card strong {
         display:block;
         font-size:1.5rem;
         color:#163d88;
         font-weight:800;
         margin-bottom:4px;
      }
      .pu-stat-card span { color:#6b7280; font-size:.88rem; }
      .pu-steps { list-style:none; padding:0; margin:0; }
      .pu-steps li {
         display:flex;
         gap:15px;
         align-items:flex-start;
         padding:15px 0;
         border-top:1px solid #dbe5f2;
      }
      .pu-steps li:first-child { border-top:0; padding-top:0; }
      .pu-step-number {
         width:34px;
         height:34px;
         border-radius:50%;
         background:#163d88;
         color:#fff;
         display:flex;
         align-items:center;
         justify-content:center;
         font-size:.86rem;
         font-weight:700;
         flex-shrink:0;
      }
      .pu-steps strong { display:block; color:#10224d; font-size:.95rem; margin-bottom:4px; }
      .pu-steps span { color:#6b7280; font-size:.88rem; line-height:1.7; }
      .pu-contact-box {
         margin-top:28px;
         border-radius:20px;
         background:#10224d;
         padding:24px;
         color:#fff;
      }
      .pu-contact-box h5 { color:#fff; font-size:1rem; font-weight:700; margin-bottom:10px; }
      .pu-contact-box a,
      .pu-contact-box p { color:rgba(255,255,255,.82); font-size:.9rem; line-height:1.8; margin:0; }
      .pu-contact-box a:hover { color:#ffb81c; }
      .pu-form-section + .pu-form-section {
         margin-top:28px;
         padding-top:28px;
         border-top:1px solid #e5e7eb;
      }
      .pu-section-title {
         display:flex;
         align-items:center;
         gap:12px;
         font-size:1.02rem;
         font-weight:800;
         color:#10224d;
         margin-bottom:18px;
      }
      .pu-section-title .icon {
         width:42px;
         height:42px;
         border-radius:14px;
         display:flex;
         align-items:center;
         justify-content:center;
         background:#eef4ff;
         color:#2563eb;
         font-size:1rem;
      }
      .pu-form-label {
         color:#374151;
         font-size:.9rem;
         font-weight:700;
         margin-bottom:8px;
      }
      .pu-form-control,
      .pu-form-select,
      .pu-form-textarea {
         width:100%;
         border:1px solid #d8dfeb;
         border-radius:14px;
         padding:14px 16px;
         font-size:.95rem;
         color:#111827;
         background:#fff;
         transition:border-color .2s ease, box-shadow .2s ease, transform .2s ease;
         position:relative;
         z-index:1;
      }
      .pu-form-textarea { min-height:120px; resize:vertical; }
      .pu-form-control:focus,
      .pu-form-select:focus,
      .pu-form-textarea:focus {
         border-color:#2563eb;
         box-shadow:0 0 0 4px rgba(37, 99, 235, .12);
         outline:0;
         transform:translateY(-1px);
         z-index:10;
      }
      .pu-required { color:#dc2626; }
      .pu-degree-toggle {
         display:grid;
         grid-template-columns:repeat(2, minmax(0,1fr));
         gap:14px;
      }
      .pu-radio-card {
         position:relative;
         border:1px solid #d8dfeb;
         border-radius:18px;
         padding:18px;
         cursor:pointer;
         transition:all .2s ease;
         height:100%;
      }
      .pu-radio-card input {
         position:absolute;
         inset:0;
         opacity:0;
         cursor:pointer;
      }
      .pu-radio-card .badge {
         display:inline-flex;
         align-items:center;
         justify-content:center;
         width:46px;
         height:46px;
         border-radius:14px;
         background:#eef4ff;
         color:#2563eb;
         font-size:1rem;
         margin-bottom:14px;
      }
      .pu-radio-card strong {
         display:block;
         color:#10224d;
         font-size:1rem;
         margin-bottom:4px;
      }
      .pu-radio-card span { color:#6b7280; font-size:.88rem; line-height:1.7; display:block; }
      .pu-radio-card.is-active {
         border-color:#2563eb;
         box-shadow:0 12px 30px rgba(37, 99, 235, .12);
         background:#f8fbff;
      }
      .pu-radio-card.is-active .badge { background:#2563eb; color:#fff; }
      .pu-form-note { color:#6b7280; font-size:.82rem; margin-top:8px; }
      .pu-alert {
         border-radius:18px;
         padding:16px 18px;
         margin-bottom:24px;
         border:1px solid transparent;
      }
      .pu-alert ul { margin:0; padding-left:20px; }
      .pu-alert-danger { background:#fff1f2; color:#be123c; border-color:#fecdd3; }
      .pu-success-card {
         text-align:center;
         padding:44px 30px;
      }
      .pu-success-icon {
         width:88px;
         height:88px;
         border-radius:50%;
         margin:0 auto 22px;
         display:flex;
         align-items:center;
         justify-content:center;
         background:#ecfdf5;
         color:#059669;
         font-size:2.1rem;
      }
      .pu-success-card h3 {
         font-size:1.9rem;
         color:#10224d;
         font-weight:800;
         margin-bottom:10px;
      }
      .pu-success-card p {
         color:#6b7280;
         font-size:.98rem;
         line-height:1.8;
         max-width:560px;
         margin:0 auto 12px;
      }
      .pu-success-number {
         display:inline-flex;
         align-items:center;
         gap:10px;
         border-radius:999px;
         background:#eef4ff;
         color:#163d88;
         font-weight:800;
         padding:12px 18px;
         margin:14px 0 22px;
      }
      .pu-action-row {
         display:flex;
         justify-content:space-between;
         align-items:center;
         gap:16px;
         flex-wrap:wrap;
         margin-top:30px;
         padding-top:24px;
         border-top:1px solid #e5e7eb;
      }
      .pu-back-link {
         display:inline-flex;
         align-items:center;
         gap:8px;
         color:#6b7280;
         font-weight:600;
      }
      .pu-back-link:hover { color:#2563eb; }
      .pu-submit-btn,
      .pu-outline-btn {
         min-width:180px;
         border:0;
         border-radius:999px;
         padding:16px 26px;
         font-size:.95rem;
         font-weight:700;
         display:inline-flex;
         align-items:center;
         justify-content:center;
         gap:10px;
         transition:transform .2s ease, box-shadow .2s ease, background .2s ease;
      }
      .pu-submit-btn {
         background:linear-gradient(135deg, #ffb81c 0%, #f59e0b 100%);
         color:#10224d;
         box-shadow:0 14px 30px rgba(245, 158, 11, .24);
      }
      .pu-outline-btn {
         background:#fff;
         border:1px solid #d8dfeb;
         color:#10224d;
      }
      .pu-submit-btn:hover,
      .pu-outline-btn:hover {
         transform:translateY(-2px);
      }
      .close-search:focus-visible,
      .close-btn:focus-visible,
      .pu-back-link:focus-visible,
      .pu-submit-btn:focus-visible,
      .pu-outline-btn:focus-visible {
         outline:3px solid rgba(37, 99, 235, .35);
         outline-offset:3px;
      }
      .pu-apply-form-wrap .nice-select .list { z-index: 100; }
      #apply_email { text-transform: none; }
      @media (max-width: 1199px) {
         .pu-apply-shell { border-radius:24px; }
         .pu-apply-aside { border-right:0; border-top:1px solid #e6ecf5; border-radius:0 0 24px 24px; }
         .pu-apply-form-wrap { border-radius:24px 24px 0 0; }
      }
      @media (max-width: 991px) {
         .pu-apply-hero { padding:60px 0 110px; }
         .pu-hero-points,
         .pu-stat-grid,
         .pu-degree-toggle { grid-template-columns:1fr; }
         .pu-apply-form-wrap,
         .pu-apply-aside { padding:30px 22px; }
         .pu-hero-points { display:none; }
      }
      @media (max-width: 767px) {
         .pu-apply-hero { padding:50px 0 95px; }
         .pu-apply-overlap { margin-top:-55px; }
         .pu-apply-shell { border-radius:22px; }
         .pu-apply-aside { border-radius:0 0 22px 22px; border-right:0; border-top:1px solid #e6ecf5; }
         .pu-action-row { flex-direction:column; align-items:stretch; }
         .pu-submit-btn,
         .pu-outline-btn { width:100%; }
         body { padding-bottom:72px; }
      }
      @media (max-width: 575px) {
         .pu-apply-hero h1 { font-size:1.9rem; }
         .pu-panel-title { font-size:1.3rem; }
         .pu-apply-form-wrap,
         .pu-apply-aside { padding:22px 16px; }
      }
      /* Sticky mobile apply bar */
      .pu-sticky-apply {
         position:fixed;
         bottom:0; left:0; right:0;
         z-index:9990;
         padding:14px 20px;
         background:linear-gradient(135deg,#ffb81c 0%,#f59e0b 100%);
         box-shadow:0 -4px 20px rgba(245,158,11,.4);
         animation:pu-pulse-shadow 2.5s ease-in-out infinite;
      }
      .pu-sticky-apply-btn {
         display:flex;
         align-items:center;
         justify-content:center;
         gap:10px;
         color:#10224d;
         font-weight:800;
         font-size:1rem;
         text-decoration:none;
      }
      .pu-sticky-apply-btn:hover { color:#10224d; opacity:.92; }
      @keyframes pu-pulse-shadow {
         0%,100% { box-shadow:0 -4px 20px rgba(245,158,11,.4); }
         50%      { box-shadow:0 -4px 30px rgba(245,158,11,.7); }
      }
   </style>
<?php include __DIR__ . '/includes/meta-pixel.php'; ?>
<?php if ($form_success): ?>
<script>fbq('track', 'Lead');</script>
<?php endif; ?>
</head>
<body id="body" class="it-magic-cursor">
   <div id="preloader">
      <div class="preloader">
         <span></span>
         <span></span>
      </div>
   </div>

   <div id="magic-cursor">
      <div id="ball"></div>
   </div>

   <button class="scroll-top scroll-to-target" data-target="html">
      <i class="far fa-angle-double-up"></i>
   </button>

   <div class="search-popup">
      <button class="close-search" aria-label="Close search"><i class="fal fa-times" aria-hidden="true"></i></button>
      <form method="post" action="#">
         <div class="form-group">
            <input type="search" name="search-field" value="" placeholder="Search Here" required>
            <button type="submit"><i class="fal fa-search"></i></button>
         </div>
      </form>
   </div>
<?php include __DIR__ . '/includes/offcanvas.php'; ?>

   <header class="it-header-height">
      <?php include __DIR__ . '/includes/header-top.php'; ?>
      <?php include __DIR__ . '/includes/nav-menu.php'; ?>
   </header>

   <section class="pu-apply-hero">
      <div class="container position-relative" style="z-index:2;">
         <div class="row align-items-center g-5">
            <div class="col-lg-7">
               <nav class="breadcrumb-nav mb-20" aria-label="breadcrumb">
                  <a href="<?= an_h(SITE_URL) ?>/index.php">Home</a>
                  <span class="sep" aria-hidden="true">/</span>
                  <span>Apply Now</span>
               </nav>
               <span class="pu-apply-kicker"><i class="fas fa-graduation-cap" aria-hidden="true"></i> Admissions Open</span>
               <h1>Start Your Prime University Journey Today</h1>
               <p>Submit your application in minutes and let our admissions team guide you toward the right department, program, and upcoming intake.</p>
               <div class="pu-hero-points wow fadeInUp" data-wow-delay=".15s">
                  <div class="pu-hero-point">
                     <div class="icon"><i class="fas fa-bolt"></i></div>
                     <div>
                        <strong>Quick Application</strong>
                        <span>Fill in minutes — our admissions team reviews your enquiry and follows up personally.</span>
                     </div>
                  </div>
                  <div class="pu-hero-point">
                     <div class="icon"><i class="fas fa-user-headset"></i></div>
                     <div>
                        <strong>Admissions Support</strong>
                        <span>Our team follows up to help you choose the right degree and intake.</span>
                     </div>
                  </div>
               </div>
            </div>
            <div class="col-lg-5 d-none d-lg-block">
               <div class="pu-hero-point wow fadeInRight" data-wow-delay=".2s" style="padding:28px; border-radius:24px;">
                  <div>
                     <span class="d-inline-flex align-items-center gap-2 mb-15" style="color:#ffdf8b;font-weight:700;font-size:.85rem;text-transform:uppercase;letter-spacing:.08em;">
                        <i class="fas fa-star"></i> Why Apply Online
                     </span>
                     <div class="row g-3">
                        <div class="col-sm-6">
                           <div style="background:rgba(255,255,255,.09);border-radius:18px;padding:18px;height:100%;">
                              <strong style="font-size:1.9rem;">24–48h</strong>
                              <span>Typical first response from admissions</span>
                           </div>
                        </div>
                        <div class="col-sm-6">
                           <div style="background:rgba(255,255,255,.09);border-radius:18px;padding:18px;height:100%;">
                              <strong style="font-size:1.9rem;">100%</strong>
                              <span>We respond to every enquiry — no application goes unanswered</span>
                           </div>
                        </div>
                        <div class="col-12">
                           <div style="background:rgba(255,255,255,.09);border-radius:18px;padding:18px;">
                              <strong style="font-size:1.1rem;">Programs, departments, and intake choices in one place</strong>
                              <span>Share your preferred semester and course interest so our team can assist faster.</span>
                           </div>
                        </div>
                     </div>
                  </div>
               </div>
            </div>
         </div>
      </div>
   </section>

   <section class="pu-apply-overlap pb-120">
      <div class="container">
         <div class="pu-apply-shell">
            <div class="row g-0">
               <div class="col-xl-4 order-2 order-xl-1">
                  <aside class="pu-apply-aside">
                     <h2 class="pu-panel-title">Apply with confidence</h2>
                     <p class="pu-panel-text">Share your basic information, choose your preferred department, and our admissions team will help you with the next steps.</p>
                     <div class="pu-stat-grid">
                        <div class="pu-stat-card">
                           <strong>Top</strong>
                           <span>career-focused academic environment</span>
                        </div>
                        <div class="pu-stat-card">
                           <strong>Easy</strong>
                           <span>online application process</span>
                        </div>
                     </div>
                     <ul class="pu-steps">
                        <li>
                           <div class="pu-step-number">01</div>
                           <div>
                              <strong>Fill in your details</strong>
                              <span>Provide your contact information so we can reach you quickly.</span>
                           </div>
                        </li>
                        <li>
                           <div class="pu-step-number">02</div>
                           <div>
                              <strong>Select your academic interest</strong>
                              <span>Choose your degree type, department, and preferred program.</span>
                           </div>
                        </li>
                        <li>
                           <div class="pu-step-number">03</div>
                           <div>
                              <strong>Receive guidance</strong>
                              <span>Our admissions team will contact you with next-step instructions.</span>
                           </div>
                        </li>
                     </ul>
                     <div class="pu-contact-box">
                        <h5>Need help before applying?</h5>
                        <p><a href="mailto:info@primeuniversity.ac.bd">info@primeuniversity.ac.bd</a></p>
                        <p><a href="tel:+8801969955566">01969-955566</a></p>
                        <p>114/116 Mazar Road, Mirpur-1, Dhaka 1216</p>
                     </div>
                  </aside>
               </div>
               <div class="col-xl-8 order-1 order-xl-2">
                  <div class="pu-apply-form-wrap">
                     <?php if ($form_success): ?>
                        <div class="pu-success-card">
                           <div class="pu-success-icon"><i class="fas fa-check"></i></div>
                           <h3>Application Submitted Successfully</h3>
                           <p>Thank you for your interest in Prime University. Your application has been recorded and our admissions team will reach out shortly.</p>
                           <div class="pu-success-number">
                              <i class="fas fa-hashtag"></i>
                              <span><?= an_h($submitted_number) ?></span>
                           </div>
                           <p>Please keep this application number for future communication.</p>
                           <div class="d-flex flex-wrap justify-content-center gap-3 mt-20">
                              <a href="/" class="pu-submit-btn"><i class="fas fa-home"></i> Back to Home</a>
                              <a href="/apply-now.php" class="pu-outline-btn"><i class="fas fa-plus"></i> Submit Another</a>
                           </div>
                        </div>
                     <?php else: ?>
                        <?php if ($form_errors): ?>
                           <div class="pu-alert pu-alert-danger">
                              <ul>
                                 <?php foreach ($form_errors as $err): ?>
                                    <li><?= an_h($err) ?></li>
                                 <?php endforeach; ?>
                              </ul>
                           </div>
                        <?php endif; ?>

                        <form method="post" novalidate id="apply-form">
                           <input type="hidden" name="_apply_csrf" value="<?= an_h($pub_csrf) ?>">

                           <div class="pu-form-section">
                              <div class="pu-section-title"><span class="icon"><i class="fas fa-user"></i></span> Personal Information</div>
                              <div class="row g-4">
                                 <div class="col-md-6">
                                    <label class="pu-form-label" for="apply_first_name">First Name <span class="pu-required">*</span></label>
                                    <input type="text" id="apply_first_name" name="first_name" class="pu-form-control" maxlength="100" value="<?= an_old('first_name', $old) ?>" placeholder="Enter your first name" required>
                                 </div>
                                 <div class="col-md-6">
                                    <label class="pu-form-label" for="apply_last_name">Last Name <span class="pu-required">*</span></label>
                                    <input type="text" id="apply_last_name" name="last_name" class="pu-form-control" maxlength="100" value="<?= an_old('last_name', $old) ?>" placeholder="Enter your last name" required>
                                 </div>
                                 <div class="col-md-6">
                                    <label class="pu-form-label" for="apply_email">Email Address</label>
                                    <input type="email" id="apply_email" name="email" class="pu-form-control" maxlength="200" value="<?= an_old('email', $old) ?>" placeholder="you@example.com" autocapitalize="none" autocorrect="off" spellcheck="false" inputmode="email">
                                    <div class="pu-form-note">We will send a confirmation email if you provide one.</div>
                                 </div>
                                 <div class="col-md-6">
                                    <label class="pu-form-label" for="apply_phone">Phone Number <span class="pu-required">*</span></label>
                                    <input type="text" id="apply_phone" name="phone" class="pu-form-control" maxlength="30" value="<?= an_old('phone', $old) ?>" placeholder="+880 1XXX-XXXXXX" required>
                                 </div>
                                 <div class="col-md-6">
                                    <label class="pu-form-label" for="apply_current_city">Current City</label>
                                    <input type="text" id="apply_current_city" name="current_city" class="pu-form-control" maxlength="200" value="<?= an_old('current_city', $old) ?>" placeholder="e.g. Dhaka">
                                 </div>
                                 <div class="col-12">
                                    <label class="pu-form-label" for="apply_address">Address</label>
                                    <textarea id="apply_address" name="address" class="pu-form-textarea" maxlength="1000" placeholder="Street, area, district"><?= an_old('address', $old) ?></textarea>
                                 </div>
                              </div>
                           </div>

                           <div class="pu-form-section">
                              <div class="pu-section-title"><span class="icon"><i class="fas fa-graduation-cap"></i></span> Education Information</div>
                              <div class="row g-4">
                                 <div class="col-12">
                                    <label class="pu-form-label">Applying For</label>
                                    <div class="pu-degree-toggle">
                                       <label class="pu-radio-card<?= an_old('degree_type', $old, 'bachelor') !== 'master' ? ' is-active' : '' ?>">
                                          <input type="radio" name="degree_type" value="bachelor" <?= an_old('degree_type', $old, 'bachelor') !== 'master' ? 'checked' : '' ?>>
                                          <span class="badge"><i class="fas fa-user-graduate"></i></span>
                                          <strong>Bachelor Degree</strong>
                                          <span>Choose undergraduate studies across the university’s core disciplines.</span>
                                       </label>
                                       <label class="pu-radio-card<?= an_old('degree_type', $old) === 'master' ? ' is-active' : '' ?>">
                                          <input type="radio" name="degree_type" value="master" <?= an_old('degree_type', $old) === 'master' ? 'checked' : '' ?>>
                                          <span class="badge"><i class="fas fa-medal"></i></span>
                                          <strong>Master Degree</strong>
                                          <span>Select postgraduate study paths designed for professional advancement.</span>
                                       </label>
                                    </div>
                                 </div>
                                 <div class="col-md-6">
                                    <label class="pu-form-label" for="apply_preferred_semester">Preferred Intake Semester</label>
                                    <select id="apply_preferred_semester" name="preferred_semester" class="pu-form-select">
                                       <option value="">Select semester</option>
                                       <?php foreach ($semesters as $sem): ?>
                                          <option value="<?= an_h($sem) ?>" <?= an_old('preferred_semester', $old) === $sem ? 'selected' : '' ?>><?= an_h($sem) ?></option>
                                       <?php endforeach; ?>
                                    </select>
                                 </div>
                                 <div class="col-md-6">
                                    <label class="pu-form-label" for="an_dept_select">Department</label>
                                    <select name="dept_id" class="pu-form-select" id="an_dept_select">
                                       <option value="">Select department</option>
                                       <?php foreach ($departments as $dept): ?>
                                          <option value="<?= (int)$dept['id'] ?>" <?= an_old('dept_id', $old) === (string)$dept['id'] ? 'selected' : '' ?>><?= an_h($dept['name']) ?></option>
                                       <?php endforeach; ?>
                                    </select>
                                 </div>
                                 <div class="col-12">
                                    <label class="pu-form-label" for="an_program_select">Interested Program</label>
                                    <select name="program_id" class="pu-form-select" id="an_program_select">
                                       <option value="">Select department first</option>
                                       <?php
                                        $presel_dept = (int)an_old('dept_id', $old);
                                        $presel_prog = (int)an_old('program_id', $old);
                                        if ($presel_dept && isset($programs_by_dept[$presel_dept])):
                                            foreach ($programs_by_dept[$presel_dept] as $p):
                                        ?>
                                           <option value="<?= (int)$p['id'] ?>" <?= $presel_prog === (int)$p['id'] ? 'selected' : '' ?>><?= an_h($p['program_name']) ?></option>
                                        <?php
                                            endforeach;
                                        endif;
                                        ?>
                                    </select>
                                 </div>
                              </div>
                           </div>

                           <div class="pu-action-row">
                              <a href="/" class="pu-back-link"><i class="fas fa-arrow-left"></i> Back to Home</a>
                              <button type="submit" class="pu-submit-btn"><i class="fas fa-paper-plane"></i> Submit Application</button>
                           </div>
                        </form>
                     <?php endif; ?>
                  </div>
               </div>
            </div>
         </div>
      </div>
   </section>

   <?php include __DIR__ . '/includes/footer.php'; ?>

   <!-- Sticky mobile apply bar (hidden when form is visible) -->
   <div class="pu-sticky-apply d-xl-none" id="puStickyApply">
      <a href="#apply-form" class="pu-sticky-apply-btn">
         <i class="fas fa-paper-plane" aria-hidden="true"></i>
         <span>Apply Now – Takes 2 Minutes</span>
         <i class="fas fa-arrow-right" aria-hidden="true"></i>
      </a>
   </div>

   <?php include __DIR__ . '/includes/scripts.php'; ?>
   <script>
      const programsByDept = <?= json_encode($programs_by_dept, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const deptSelect = document.getElementById('an_dept_select');
      const progSelect = document.getElementById('an_program_select');
      const initialProgramId = <?= json_encode((int)an_old('program_id', $old), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

      function updatePrograms() {
         if (!deptSelect || !progSelect) return;

         const deptId = parseInt(deptSelect.value, 10) || 0;
         const programs = programsByDept[deptId] || [];
         progSelect.innerHTML = '<option value="">' + (deptId ? 'Select program' : 'Select department first') + '</option>';

         programs.forEach(function (program) {
            const option = document.createElement('option');
            option.value = program.id;
            option.textContent = program.program_name;
            if (parseInt(program.id, 10) === initialProgramId) {
               option.selected = true;
            }
            progSelect.appendChild(option);
         });

         if (typeof $ !== 'undefined' && typeof $.fn.niceSelect !== 'undefined') {
            $(progSelect).niceSelect('update');
         }
      }

      function syncDegreeCards() {
         document.querySelectorAll('.pu-radio-card').forEach(function (card) {
            const input = card.querySelector('input[type="radio"]');
            card.classList.toggle('is-active', !!(input && input.checked));
         });
      }

      if (typeof $ !== 'undefined' && deptSelect && progSelect) {
         $(deptSelect).on('change', updatePrograms);
      } else if (deptSelect && progSelect) {
         deptSelect.addEventListener('change', updatePrograms);
      }

      if (typeof $ !== 'undefined') {
         $(document).ready(function () {
            if (deptSelect && progSelect) updatePrograms();
         });
      } else if (deptSelect && progSelect) {
         updatePrograms();
      }

      const applyEmailInput = document.getElementById('apply_email');
      if (applyEmailInput) {
         applyEmailInput.addEventListener('input', function () {
            const start = this.selectionStart;
            const end = this.selectionEnd;
            this.value = this.value.toLowerCase();
            this.setSelectionRange(start, end);
         });
      }

      document.querySelectorAll('.pu-radio-card input[type="radio"]').forEach(function (input) {
         input.addEventListener('change', syncDegreeCards);
      });
      syncDegreeCards();

      if (typeof WOW !== 'undefined') {
         new WOW({ mobile: false, offset: 60 }).init();
      }

      const form = document.getElementById('apply-form');
      if (form) {
         form.addEventListener('submit', function (event) {
            let firstInvalid = null;
            form.querySelectorAll('[required]').forEach(function (field) {
               if (!field.value.trim()) {
                  field.style.borderColor = '#dc2626';
                  if (!firstInvalid) firstInvalid = field;
               } else {
                  field.style.borderColor = '';
               }
            });
            const emailField = form.querySelector('input[type="email"]');
            if (emailField && emailField.value.trim()) {
               const emailOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailField.value.trim());
               if (!emailOk) {
                  emailField.style.borderColor = '#dc2626';
                  if (!firstInvalid) firstInvalid = emailField;
               }
            }
            if (firstInvalid) {
               event.preventDefault();
               firstInvalid.focus();
            }
         });

         form.querySelectorAll('input, textarea, select').forEach(function (field) {
            field.addEventListener('input', function () {
               this.style.borderColor = '';
            });
            field.addEventListener('change', function () {
               this.style.borderColor = '';
            });
         });
      }

      // Hide sticky bar when the form is visible on screen
      const puStickyApply = document.getElementById('puStickyApply');
      if (puStickyApply && typeof IntersectionObserver !== 'undefined') {
         const formAnchor = document.getElementById('apply-form');
         if (formAnchor) {
            const obs = new IntersectionObserver(function (entries) {
               puStickyApply.style.display = entries[0].isIntersecting ? 'none' : '';
            }, { threshold: 0.15 });
            obs.observe(formAnchor);
         }
      }
   </script>
</body>
</html>
