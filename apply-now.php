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
    <p style="color:#6b7280;font-size:.85rem;">If you have any questions, feel free to contact us at <a href="mailto:admissions@primeuniversity.ac.bd">admissions@primeuniversity.ac.bd</a></p>
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
         overflow:hidden;
      }
      .pu-apply-aside {
         background:linear-gradient(180deg, #f8fbff 0%, #eef4ff 100%);
         padding:38px 32px;
         height:100%;
         border-right:1px solid #e6ecf5;
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
      }
      .pu-form-textarea { min-height:120px; resize:vertical; }
      .pu-form-control:focus,
      .pu-form-select:focus,
      .pu-form-textarea:focus {
         border-color:#2563eb;
         box-shadow:0 0 0 4px rgba(37, 99, 235, .12);
         outline:0;
         transform:translateY(-1px);
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
      @media (max-width: 1199px) {
         .pu-apply-shell { border-radius:24px; }
         .pu-apply-aside { border-right:0; border-bottom:1px solid #e6ecf5; }
      }
      @media (max-width: 991px) {
         .pu-apply-hero { padding:85px 0 130px; }
         .pu-hero-points,
         .pu-stat-grid,
         .pu-degree-toggle { grid-template-columns:1fr; }
         .pu-apply-form-wrap,
         .pu-apply-aside { padding:30px 22px; }
      }
      @media (max-width: 767px) {
         .pu-apply-hero { padding:70px 0 120px; }
         .pu-apply-overlap { margin-top:-68px; }
         .pu-apply-shell { border-radius:22px; }
         .pu-action-row { flex-direction:column; align-items:stretch; }
         .pu-submit-btn,
         .pu-outline-btn { width:100%; }
      }
      @media (max-width: 575px) {
         .pu-apply-hero h1 { font-size:2rem; }
         .pu-panel-title { font-size:1.3rem; }
         .pu-apply-form-wrap,
         .pu-apply-aside { padding:24px 18px; }
      }
   </style>
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
      <button class="close-search"><span class="flaticon-multiply"><i class="fal fa-times"></i></span></button>
      <form method="post" action="#">
         <div class="form-group">
            <input type="search" name="search-field" value="" placeholder="Search Here" required>
            <button type="submit"><i class="fal fa-search"></i></button>
         </div>
      </form>
   </div>

   <div class="it-offcanvas-area">
      <div class="itoffcanvas">
         <div class="itoffcanvas__close-btn">
            <button class="close-btn"><i class="fal fa-times"></i></button>
         </div>
         <div class="itoffcanvas__logo">
            <a href="<?= fh(SITE_URL) ?>/index.php">
               <img src="/assets/img/logo/logo-black.png" alt="Prime University">
            </a>
         </div>
         <div class="it-menu-mobile d-xl-none"></div>
         <div class="itoffcanvas__info">
            <h3 class="offcanva-title">Admissions Help Desk</h3>
            <div class="it-info-wrapper mb-20 d-flex align-items-center">
               <div class="itoffcanvas__info-icon"><a href="#"><i class="fal fa-envelope"></i></a></div>
               <div class="itoffcanvas__info-address">
                  <span>Email</span>
                  <a href="mailto:admissions@primeuniversity.ac.bd">admissions@primeuniversity.ac.bd</a>
               </div>
            </div>
            <div class="it-info-wrapper mb-20 d-flex align-items-center">
               <div class="itoffcanvas__info-icon"><a href="#"><i class="fal fa-phone-alt"></i></a></div>
               <div class="itoffcanvas__info-address">
                  <span>Phone</span>
                  <a href="tel:+8801710996196">+880-1710996196</a>
               </div>
            </div>
            <div class="it-info-wrapper mb-20 d-flex align-items-center">
               <div class="itoffcanvas__info-icon"><a href="#"><i class="fas fa-map-marker-alt"></i></a></div>
               <div class="itoffcanvas__info-address">
                  <span>Location</span>
                  <a href="https://maps.google.com/?q=Prime+University+Dhaka" target="_blank" rel="noopener">114/116 Mazar Road, Mirpur-1, Dhaka 1216</a>
               </div>
            </div>
         </div>
      </div>
   </div>
   <div class="body-overlay"></div>

   <header class="it-header-height">
      <?php include __DIR__ . '/includes/header-top.php'; ?>
      <?php include __DIR__ . '/includes/nav-menu.php'; ?>
   </header>

   <section class="pu-apply-hero">
      <div class="container position-relative" style="z-index:2;">
         <div class="row align-items-center g-5">
            <div class="col-lg-7">
               <nav class="breadcrumb-nav mb-20">
                  <a href="<?= fh(SITE_URL) ?>/index.php">Home</a>
                  <span class="sep">/</span>
                  <span>Apply Now</span>
               </nav>
               <span class="pu-apply-kicker"><i class="fas fa-graduation-cap"></i> Admissions Open</span>
               <h1>Start Your Prime University Journey Today</h1>
               <p>Submit your application in minutes and let our admissions team guide you toward the right department, program, and upcoming intake.</p>
               <div class="pu-hero-points wow fadeInUp" data-wow-delay=".15s">
                  <div class="pu-hero-point">
                     <div class="icon"><i class="fas fa-bolt"></i></div>
                     <div>
                        <strong>Quick Application</strong>
                        <span>Simple form designed for fast submission on mobile, tablet, and desktop.</span>
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
            <div class="col-lg-5">
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
                              <span>Responsive experience across all devices</span>
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
               <div class="col-xl-4">
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
                        <p><a href="mailto:admissions@primeuniversity.ac.bd">admissions@primeuniversity.ac.bd</a></p>
                        <p><a href="tel:+8801710996196">+880-1710996196</a></p>
                        <p>114/116 Mazar Road, Mirpur-1, Dhaka 1216</p>
                     </div>
                  </aside>
               </div>
               <div class="col-xl-8">
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
                                    <label class="pu-form-label">First Name <span class="pu-required">*</span></label>
                                    <input type="text" name="first_name" class="pu-form-control" maxlength="100" value="<?= an_old('first_name', $old) ?>" placeholder="Enter your first name" required>
                                 </div>
                                 <div class="col-md-6">
                                    <label class="pu-form-label">Last Name <span class="pu-required">*</span></label>
                                    <input type="text" name="last_name" class="pu-form-control" maxlength="100" value="<?= an_old('last_name', $old) ?>" placeholder="Enter your last name" required>
                                 </div>
                                 <div class="col-md-6">
                                    <label class="pu-form-label">Email Address</label>
                                    <input type="email" name="email" class="pu-form-control" maxlength="200" value="<?= an_old('email', $old) ?>" placeholder="you@example.com">
                                    <div class="pu-form-note">We will send a confirmation email if you provide one.</div>
                                 </div>
                                 <div class="col-md-6">
                                    <label class="pu-form-label">Phone Number <span class="pu-required">*</span></label>
                                    <input type="text" name="phone" class="pu-form-control" maxlength="30" value="<?= an_old('phone', $old) ?>" placeholder="+880 1XXX-XXXXXX" required>
                                 </div>
                                 <div class="col-md-6">
                                    <label class="pu-form-label">Current City</label>
                                    <input type="text" name="current_city" class="pu-form-control" maxlength="200" value="<?= an_old('current_city', $old) ?>" placeholder="e.g. Dhaka">
                                 </div>
                                 <div class="col-12">
                                    <label class="pu-form-label">Address</label>
                                    <textarea name="address" class="pu-form-textarea" maxlength="1000" placeholder="Street, area, district"><?= an_old('address', $old) ?></textarea>
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
                                    <label class="pu-form-label">Preferred Intake Semester</label>
                                    <select name="preferred_semester" class="pu-form-select">
                                       <option value="">Select semester</option>
                                       <?php foreach ($semesters as $sem): ?>
                                          <option value="<?= an_h($sem) ?>" <?= an_old('preferred_semester', $old) === $sem ? 'selected' : '' ?>><?= an_h($sem) ?></option>
                                       <?php endforeach; ?>
                                    </select>
                                 </div>
                                 <div class="col-md-6">
                                    <label class="pu-form-label">Department</label>
                                    <select name="dept_id" class="pu-form-select" id="an_dept_select">
                                       <option value="">Select department</option>
                                       <?php foreach ($departments as $dept): ?>
                                          <option value="<?= (int)$dept['id'] ?>" <?= an_old('dept_id', $old) === (string)$dept['id'] ? 'selected' : '' ?>><?= an_h($dept['name']) ?></option>
                                       <?php endforeach; ?>
                                    </select>
                                 </div>
                                 <div class="col-12">
                                    <label class="pu-form-label">Interested Program</label>
                                    <select name="program_id" class="pu-form-select" id="an_program_select">
                                       <option value="">Select department first</option>
                                       <?php
                                       $presel_dept = (int)an_old('dept_id', $old);
                                       $presel_prog = (int)an_old('program_id', $old);
                                       if ($presel_dept && isset($programs_by_dept[$presel_dept])) {
                                           foreach ($programs_by_dept[$presel_dept] as $p) {
                                               $sel = $presel_prog === (int)$p['id'] ? 'selected' : '';
                                               echo '<option value="' . (int)$p['id'] . '" ' . $sel . '>' . an_h($p['program_name']) . '</option>';
                                           }
                                       }
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

   <script src="/assets/js/jquery.js"></script>
   <script src="/assets/js/bootstrap.bundle.min.js"></script>
   <script src="/assets/js/purecounter.js"></script>
   <script src="/assets/js/nice-select.js"></script>
   <script src="/assets/js/swiper-bundle.min.js"></script>
   <script src="/assets/js/slick.min.js"></script>
   <script src="/assets/js/wow.js"></script>
   <script src="/assets/js/magnific-popup.js"></script>
   <script src="/assets/js/parallax.js"></script>
   <script src="/assets/js/isotope-pkgd.js"></script>
   <script src="/assets/js/imagesloaded-pkgd.js"></script>
   <script src="/assets/js/main.js"></script>
   <script>
      const programsByDept = <?= json_encode($programs_by_dept, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
      const deptSelect = document.getElementById('an_dept_select');
      const progSelect = document.getElementById('an_program_select');
      const initialProgramId = <?= json_encode(an_old('program_id', $old)) ?>;

      function updatePrograms() {
         if (!deptSelect || !progSelect) return;

         const deptId = parseInt(deptSelect.value, 10) || 0;
         const programs = programsByDept[deptId] || [];
         progSelect.innerHTML = '<option value="">' + (deptId ? 'Select program' : 'Select department first') + '</option>';

         programs.forEach(function (program) {
            const option = document.createElement('option');
            option.value = program.id;
            option.textContent = program.program_name;
            if (String(program.id) === String(initialProgramId)) {
               option.selected = true;
            }
            progSelect.appendChild(option);
         });
      }

      function syncDegreeCards() {
         document.querySelectorAll('.pu-radio-card').forEach(function (card) {
            const input = card.querySelector('input[type="radio"]');
            card.classList.toggle('is-active', !!(input && input.checked));
         });
      }

      if (deptSelect && progSelect) {
         deptSelect.addEventListener('change', function () {
            progSelect.dataset.userChanged = '1';
            updatePrograms();
         });
         updatePrograms();
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
   </script>
</body>
</html>
