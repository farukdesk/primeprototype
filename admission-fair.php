<?php
/**
 * Admission Fair Registration – Spring 2027
 * Short, mobile-first lead form for the Admission Fair (09–11 December 2026).
 * Stores registrations in the same `leads` table as apply-now.php.
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/captcha-helpers.php';

/* ---------- Fair settings (edit here) ---------- */
const AF_FAIR_NAME     = 'Prime University Admission Fair, Spring 2027';
const AF_FAIR_DATES    = '09–11 December 2026';
const AF_FAIR_VENUE    = 'Prime University Campus, 114/116 Mazar Road, Mirpur-1, Dhaka 1216';
const AF_SEMESTER      = 'Spring 2027';
// Value saved in leads.source. Requires admin/leads/admission-fair-source.sql to be run once
// (adds 'admission_fair' to the `source` ENUM). Shown as "Admission Fair" in admin/leads.
const AF_LEAD_SOURCE   = 'admission_fair';
const AF_HISTORY_NOTE  = 'Registered via Admission Fair Spring 2027 form';

$page_title = 'Admission Fair Spring 2027 – Register Now | Prime University';

if (empty($_SESSION['fair_csrf'])) {
    $_SESSION['fair_csrf'] = bin2hex(random_bytes(32));
}
$pub_csrf = $_SESSION['fair_csrf'];

function af_h(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function af_old(string $key, array $old): string
{
    return af_h($old[$key] ?? '');
}

/* Same numbering scheme as apply-now.php so fair leads share one sequence. */
function af_generate_lead_number(PDO $db): string
{
    $pfx = 'LD-' . date('Y') . '-';
    $stmt = $db->prepare('SELECT lead_number FROM leads WHERE lead_number LIKE ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$pfx . '%']);
    $last = $stmt->fetchColumn();
    $seq = $last ? (int)substr($last, strrpos($last, '-') + 1) + 1 : 1;
    return $pfx . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
}

function af_send_mail(string $to, string $subject, string $body): void
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return;
    $to = filter_var($to, FILTER_SANITIZE_EMAIL);
    if (strpbrk($to, "\r\n\t") !== false) return;

    $from = 'noreply@primeuniversity.ac.bd';
    $fname = '=?UTF-8?B?' . base64_encode('Prime University Admissions') . '?=';
    $headers  = 'MIME-Version: 1.0' . "\r\n";
    $headers .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
    $headers .= 'From: ' . $fname . ' <' . $from . '>' . "\r\n";
    $headers .= 'Reply-To: ' . $from . "\r\n";
    @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers, '-f' . $from);
}

/* Keep digits and a leading +, e.g. "+880 1711-234567" -> "+8801711234567" */
function af_clean_phone(string $raw): string
{
    $raw = trim($raw);
    $plus = str_starts_with($raw, '+') ? '+' : '';
    return $plus . preg_replace('/\D+/', '', $raw);
}

$form_errors = [];
$form_success = false;
$submitted_number = '';
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['_fair_csrf'] ?? '';
    if (!hash_equals($pub_csrf, (string)$token)) {
        $form_errors[] = 'Security token mismatch. Please refresh the page and try again.';
    }

    if (empty($form_errors)) {
        $old = $_POST;

        if (!captcha_verify_request()) {
            $form_errors[] = 'CAPTCHA verification failed. Please verify that you are not a robot.';
        }

        $first_name = trim($_POST['first_name'] ?? '');
        $last_name  = trim($_POST['last_name'] ?? '');
        $email      = strtolower(trim($_POST['email'] ?? ''));
        $phone      = af_clean_phone($_POST['phone'] ?? '');
        $digits     = ltrim($phone, '+');

        if ($first_name === '') $form_errors[] = 'First name is required.';
        if ($last_name === '')  $form_errors[] = 'Last name is required.';
        if ($digits === '') {
            $form_errors[] = 'Phone number is required.';
        } elseif (strlen($digits) < 10 || strlen($digits) > 15) {
            $form_errors[] = 'Please enter a valid phone number (e.g. 01XXXXXXXXX).';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $form_errors[] = 'Please enter a valid email address.';
        }
        if (mb_strlen($first_name) > 100 || mb_strlen($last_name) > 100 || mb_strlen($email) > 200) {
            $form_errors[] = 'One of the fields is too long.';
        }
    }

    if (empty($form_errors)) {
        try {
            $db = front_db();
            if (!$db) throw new RuntimeException('Database unavailable.');

            $lead_number = af_generate_lead_number($db);
            $db->prepare(
                'INSERT INTO leads
                   (lead_number, first_name, last_name, email, phone,
                    degree_type, preferred_semester, status, source)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([
                $lead_number,
                $first_name,
                $last_name,
                $email ?: null,
                $phone,
                'bachelor',
                AF_SEMESTER,
                'fresh',
                AF_LEAD_SOURCE,
            ]);
            $lead_id = (int)$db->lastInsertId();

            $db->prepare(
                'INSERT INTO lead_history (lead_id, user_id, action, description)
                 VALUES (?, NULL, ?, ?)'
            )->execute([$lead_id, 'created', AF_HISTORY_NOTE]);

            if ($email !== '') {
                $subject = 'You are registered for the Admission Fair – ' . $lead_number;
                $body = '
<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;background:#f9fafb;border-radius:10px;">
  <div style="background:#10224d;color:#fff;padding:20px 28px;border-radius:8px 8px 0 0;text-align:center;">
    <h2 style="margin:0;font-size:1.3rem;">Admission Fair Registration Confirmed</h2>
    <p style="margin:4px 0 0;font-size:.85rem;opacity:.8;">' . af_h(AF_FAIR_NAME) . '</p>
  </div>
  <div style="background:#fff;padding:28px;border-radius:0 0 8px 8px;">
    <p>Dear ' . af_h($first_name) . ',</p>
    <p>Thank you for registering. We look forward to meeting you at the Admission Fair.</p>
    <table style="width:100%;border-collapse:collapse;margin:16px 0;">
      <tr><td style="padding:8px;border:1px solid #e5e7eb;background:#f9fafb;font-weight:600;width:40%">Registration Number</td><td style="padding:8px;border:1px solid #e5e7eb;">' . af_h($lead_number) . '</td></tr>
      <tr><td style="padding:8px;border:1px solid #e5e7eb;background:#f9fafb;font-weight:600">Dates</td><td style="padding:8px;border:1px solid #e5e7eb;">' . af_h(AF_FAIR_DATES) . '</td></tr>
      <tr><td style="padding:8px;border:1px solid #e5e7eb;background:#f9fafb;font-weight:600">Venue</td><td style="padding:8px;border:1px solid #e5e7eb;">' . af_h(AF_FAIR_VENUE) . '</td></tr>
    </table>
    <p>Please show this registration number at the registration desk.</p>
    <p style="color:#6b7280;font-size:.85rem;">Questions? Call <a href="tel:+8801969955566">01969-955566</a> or email <a href="mailto:info@primeuniversity.ac.bd">info@primeuniversity.ac.bd</a>.</p>
    <p>Warm regards,<br><strong>Prime University Admissions Team</strong></p>
  </div>
</div>';
                af_send_mail($email, $subject, $body);
            }

            $form_success = true;
            $submitted_number = $lead_number;
            $old = [];
            $_SESSION['fair_csrf'] = bin2hex(random_bytes(32));
            $pub_csrf = $_SESSION['fair_csrf'];
        } catch (Throwable $e) {
            error_log('[admission-fair] ' . $e->getMessage());
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
   <title><?= af_h($page_title) ?></title>
   <meta name="description" content="Register for the Prime University Admission Fair, 09–11 December 2026. Nearly BDT 1.5 crore in scholarships, a fixed 50% admission fee waiver, up to 100% tuition fee waiver and a Talent Hunt Quiz for 24 full tuition waivers.">
   <meta name="viewport" content="width=device-width, initial-scale=1">
   <meta property="og:title" content="Prime University Admission Fair – Spring 2027">
   <meta property="og:description" content="09–11 December 2026 · Nearly BDT 1.5 crore in scholarships · Talent Hunt Quiz: 24 students win a 100% tuition fee waiver. Register now.">
   <link rel="shortcut icon" type="image/x-icon" href="/assets/img/logo/favicon.png">
   <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
   <link rel="stylesheet" href="/assets/css/font-awesome-pro.css">
   <link rel="stylesheet" href="/assets/css/custom-animation.css">
   <link rel="stylesheet" href="/assets/css/spacing.css">
   <link rel="stylesheet" href="/assets/css/main.css">
   <style>
      body { background:#f5f7fb; }
      .af-hero {
         position:relative; overflow:hidden;
         padding:70px 0 130px;
         background:linear-gradient(135deg,#0f1f4a 0%,#163d88 55%,#2563eb 100%);
         color:#fff;
      }
      .af-hero::after {
         content:''; position:absolute; right:-120px; top:-80px;
         width:360px; height:360px; border-radius:50%;
         background:rgba(255,255,255,.08);
      }
      .af-hero .breadcrumb-nav a, .af-hero .breadcrumb-nav span { color:rgba(255,255,255,.78); font-size:.9rem; }
      .af-hero .breadcrumb-nav a:hover { color:#fff; }
      .af-hero .sep { margin:0 8px; color:rgba(255,255,255,.45); }
      .af-kicker {
         display:inline-flex; align-items:center; gap:10px;
         padding:9px 16px; border-radius:999px;
         background:rgba(255,184,28,.18); color:#ffdf8b;
         font-size:.8rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase;
         margin-bottom:16px;
      }
      .af-hero h1 { color:#fff; font-size:clamp(2rem,5vw,3.8rem); line-height:1.1; font-weight:800; margin-bottom:14px; }
      .af-hero h1 span { color:#ffb81c; }
      .af-tagline { color:rgba(255,255,255,.88); font-size:1.15rem; margin-bottom:22px; }
      .af-meta { display:flex; flex-wrap:wrap; gap:12px; }
      .af-meta div {
         display:flex; align-items:center; gap:10px;
         background:rgba(255,255,255,.1); border-radius:14px;
         padding:12px 16px; font-weight:600; font-size:.95rem;
      }
      .af-meta i { color:#ffb81c; }

      .af-overlap { margin-top:-90px; position:relative; z-index:6; }
      .af-shell { background:#fff; border-radius:28px; box-shadow:0 18px 60px rgba(15,23,42,.12); overflow:hidden; }
      .af-form-wrap { padding:36px 34px; }
      .af-aside { background:linear-gradient(180deg,#f8fbff 0%,#eef4ff 100%); padding:36px 30px; height:100%; }

      .af-form-title { font-size:1.5rem; color:#10224d; font-weight:800; margin-bottom:6px; }
      .af-form-sub { color:#6b7280; font-size:.95rem; margin-bottom:24px; }
      .af-label { color:#374151; font-size:.9rem; font-weight:700; margin-bottom:8px; display:block; }
      .af-required { color:#dc2626; }
      .af-input {
         width:100%; border:1px solid #d8dfeb; border-radius:14px;
         padding:15px 16px; font-size:16px; /* 16px stops iOS zoom on focus */
         color:#111827; background:#fff;
         transition:border-color .2s ease, box-shadow .2s ease;
      }
      .af-input:focus { border-color:#2563eb; box-shadow:0 0 0 4px rgba(37,99,235,.12); outline:0; }
      .af-note { color:#6b7280; font-size:.82rem; margin-top:6px; }
      .af-alert { border-radius:16px; padding:14px 18px; margin-bottom:22px; background:#fff1f2; color:#be123c; border:1px solid #fecdd3; }
      .af-alert ul { margin:0; padding-left:20px; }
      .af-submit {
         width:100%; border:0; border-radius:999px; padding:17px 26px;
         font-size:1.05rem; font-weight:800;
         display:flex; align-items:center; justify-content:center; gap:10px;
         background:linear-gradient(135deg,#ffb81c 0%,#f59e0b 100%); color:#10224d;
         box-shadow:0 14px 30px rgba(245,158,11,.28);
         transition:transform .2s ease;
      }
      .af-submit:hover { transform:translateY(-2px); }
      .af-submit:focus-visible { outline:3px solid rgba(37,99,235,.35); outline-offset:3px; }
      .af-captcha { margin:22px 0 18px; }
      .af-privacy { color:#9ca3af; font-size:.8rem; text-align:center; margin-top:12px; }

      .af-aside h3 { font-size:1.15rem; color:#10224d; font-weight:800; margin-bottom:16px; }
      .af-offer {
         display:flex; gap:14px; align-items:center;
         background:#fff; border-radius:18px; padding:14px 16px; margin-bottom:12px;
         box-shadow:0 8px 24px rgba(37,99,235,.08);
      }
      .af-offer .big {
         min-width:78px; height:58px; border-radius:14px;
         display:flex; align-items:center; justify-content:center;
         font-weight:800; font-size:1.15rem; color:#fff; background:#e6007e;
      }
      .af-offer.teal .big { background:#00a896; }
      .af-offer.gold { background:linear-gradient(135deg,#ffd76a 0%,#ffb81c 100%); }
      .af-offer.gold .big { background:#10224d; color:#ffb81c; font-size:.95rem; }
      .af-offer strong { display:block; color:#10224d; font-size:.98rem; }
      .af-offer span { color:#4b5563; font-size:.86rem; }
      .af-list { list-style:none; padding:0; margin:22px 0 0; }
      .af-list li { display:flex; gap:12px; align-items:center; padding:10px 0; color:#10224d; font-weight:600; font-size:.95rem; border-top:1px solid #dbe5f2; }
      .af-list li:first-child { border-top:0; }
      .af-list i { width:34px; height:34px; border-radius:50%; background:#163d88; color:#fff; display:flex; align-items:center; justify-content:center; font-size:.85rem; flex-shrink:0; }

      .af-stats { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-top:22px; max-width:820px; }
      .af-stat { background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.14); border-radius:16px; padding:14px 16px; }
      .af-stat strong { display:block; color:#ffb81c; font-size:1.6rem; font-weight:800; line-height:1.1; }
      .af-stat span { color:rgba(255,255,255,.8); font-size:.82rem; }

      .af-pool {
         display:flex; align-items:center; gap:14px; margin-bottom:22px;
         padding:16px 18px; border-radius:18px; color:#fff;
         background:linear-gradient(135deg,#10224d 0%,#163d88 100%);
         box-shadow:0 12px 28px rgba(16,34,77,.25);
      }
      .af-pool i { font-size:1.6rem; color:#ffb81c; }
      .af-pool span { display:block; font-size:.78rem; letter-spacing:.08em; text-transform:uppercase; color:rgba(255,255,255,.75); }
      .af-pool strong { font-size:1.35rem; font-weight:800; color:#ffb81c; }

      .af-quiz-wrap { padding:60px 0 0; }
      .af-quiz {
         position:relative; overflow:hidden; border-radius:28px; padding:44px 40px; color:#fff;
         background:radial-gradient(circle at 85% 15%, rgba(255,184,28,.25) 0, rgba(255,184,28,0) 40%),
                    linear-gradient(135deg,#10224d 0%,#1e3a8a 60%,#6d28d9 100%);
         box-shadow:0 20px 50px rgba(16,34,77,.3);
      }
      .af-quiz-badge {
         display:inline-flex; align-items:center; gap:8px; padding:8px 14px; border-radius:999px;
         background:#ffb81c; color:#10224d; font-size:.78rem; font-weight:800; letter-spacing:.06em; text-transform:uppercase;
         margin-bottom:14px;
      }
      .af-quiz h2 { color:#fff; font-size:clamp(1.9rem,4vw,2.7rem); font-weight:800; margin-bottom:12px; }
      .af-quiz-lead { color:rgba(255,255,255,.88); font-size:1.05rem; line-height:1.7; }
      .af-quiz-lead strong { color:#ffb81c; }
      .af-quiz-big { display:flex; align-items:center; gap:16px; margin-top:22px; }
      .af-quiz-big > div:not(.af-quiz-x) {
         background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.18); border-radius:18px;
         padding:14px 18px; text-align:center; min-width:120px;
      }
      .af-quiz-big strong { display:block; font-size:2.6rem; font-weight:800; color:#ffb81c; line-height:1; }
      .af-quiz-big span { font-size:.8rem; color:rgba(255,255,255,.8); }
      .af-quiz-x { font-size:1.8rem; font-weight:800; color:rgba(255,255,255,.6); }
      .af-quiz-steps { list-style:none; padding:0; margin:0 0 24px; }
      .af-quiz-steps li {
         display:flex; gap:16px; align-items:flex-start;
         background:rgba(255,255,255,.08); border-radius:18px; padding:16px 18px; margin-bottom:12px;
      }
      .af-quiz-steps .n {
         width:40px; height:40px; border-radius:50%; flex-shrink:0;
         display:flex; align-items:center; justify-content:center;
         background:#ffb81c; color:#10224d; font-weight:800;
      }
      .af-quiz-steps strong { display:block; color:#fff; font-size:1rem; margin-bottom:2px; }
      .af-quiz-steps span { color:rgba(255,255,255,.78); font-size:.9rem; }
      .af-quiz-cta { text-decoration:none; }
      .af-quiz-cta:hover { color:#10224d; }

      .af-depts { padding:60px 0 20px; }
      .af-depts h2 { font-size:1.6rem; color:#10224d; font-weight:800; text-align:center; margin-bottom:24px; }
      .af-dept-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
      .af-dept {
         background:#fff; border-radius:16px; padding:16px 18px;
         border-left:6px solid #2563eb; color:#10224d; font-weight:700; font-size:.95rem;
         box-shadow:0 6px 18px rgba(15,23,42,.06);
      }
      .af-dept:nth-child(4n+2) { border-left-color:#e6007e; }
      .af-dept:nth-child(4n+3) { border-left-color:#00a896; }
      .af-dept:nth-child(4n+4) { border-left-color:#ffb81c; }

      .af-success { text-align:center; padding:30px 10px; }
      .af-success-icon { width:84px; height:84px; border-radius:50%; margin:0 auto 20px; display:flex; align-items:center; justify-content:center; background:#ecfdf5; color:#059669; font-size:2rem; }
      .af-success h3 { font-size:1.7rem; color:#10224d; font-weight:800; margin-bottom:10px; }
      .af-success p { color:#6b7280; line-height:1.8; max-width:520px; margin:0 auto 10px; }
      .af-number { display:inline-flex; align-items:center; gap:10px; border-radius:999px; background:#eef4ff; color:#163d88; font-weight:800; font-size:1.15rem; padding:12px 20px; margin:12px 0 18px; }

      .af-sticky {
         position:fixed; bottom:0; left:0; right:0; z-index:9990;
         padding:14px 20px;
         background:linear-gradient(135deg,#ffb81c 0%,#f59e0b 100%);
         box-shadow:0 -4px 20px rgba(245,158,11,.4);
      }
      .af-sticky a { display:flex; align-items:center; justify-content:center; gap:10px; color:#10224d; font-weight:800; font-size:1rem; text-decoration:none; }

      @media (max-width: 991px) {
         .af-hero { padding:44px 0 110px; }
         .af-form-wrap, .af-aside { padding:26px 20px; }
      }
      @media (max-width: 767px) {
         .af-hero { padding:34px 0 90px; }
         .af-tagline { font-size:1rem; }
         .af-meta div { width:100%; }
         .af-overlap { margin-top:-60px; }
         .af-shell { border-radius:22px; }
         .af-dept-grid { grid-template-columns:1fr; }
         .af-stats { grid-template-columns:repeat(2,minmax(0,1fr)); }
         .af-quiz { padding:30px 20px; border-radius:22px; }
         .af-quiz-big { gap:10px; }
         .af-quiz-big > div:not(.af-quiz-x) { min-width:0; flex:1; padding:12px; }
         .af-quiz-big strong { font-size:2.1rem; }
         body { padding-bottom:72px; }
      }
   </style>
<?php include __DIR__ . '/includes/meta-pixel.php'; ?>
<?php if ($form_success): ?>
<script>fbq('track', 'Lead', {content_name: 'Admission Fair Spring 2027'});</script>
<?php endif; ?>
<?php captcha_render_script(); ?>
</head>
<body id="body">
<?php include __DIR__ . '/includes/offcanvas.php'; ?>

   <header class="it-header-height">
      <?php include __DIR__ . '/includes/header-top.php'; ?>
      <?php include __DIR__ . '/includes/nav-menu.php'; ?>
   </header>

   <section class="af-hero">
      <div class="container position-relative" style="z-index:2;">
         <nav class="breadcrumb-nav mb-20" aria-label="breadcrumb">
            <a href="<?= af_h(SITE_URL) ?>/index.php">Home</a>
            <span class="sep" aria-hidden="true">/</span>
            <span>Admission Fair</span>
         </nav>
         <span class="af-kicker"><i class="fas fa-ticket-alt" aria-hidden="true"></i> Free Registration</span>
         <h1>Admission Fair <span>Spring 2027</span></h1>
         <p class="af-tagline">Spot admission, special waivers and scholarships worth nearly <strong>BDT 1.5 Crore</strong>. Register now and reserve your visit.</p>
         <div class="af-meta">
            <div><i class="fas fa-calendar-alt" aria-hidden="true"></i> <?= af_h(AF_FAIR_DATES) ?></div>
            <div><i class="fas fa-map-marker-alt" aria-hidden="true"></i> Prime University Campus, Mirpur-1</div>
         </div>
         <div class="af-stats">
            <div class="af-stat"><strong>BDT 1.5 Cr</strong><span>Scholarships</span></div>
            <div class="af-stat"><strong>24</strong><span>Full tuition waivers via quiz</span></div>
            <div class="af-stat"><strong>50%</strong><span>Admission fee waiver</span></div>
            <div class="af-stat"><strong>8</strong><span>Departments</span></div>
         </div>
      </div>
   </section>

   <section class="af-overlap">
      <div class="container">
         <div class="af-shell">
            <div class="row g-0">
               <div class="col-lg-7">
                  <div class="af-form-wrap" id="register">
                     <?php if ($form_success): ?>
                        <div class="af-success">
                           <div class="af-success-icon"><i class="fas fa-check" aria-hidden="true"></i></div>
                           <h3>You're Registered!</h3>
                           <p>Thank you for registering for the Prime University Admission Fair. Our admissions team will contact you soon.</p>
                           <div class="af-number"><i class="fas fa-hashtag" aria-hidden="true"></i> <?= af_h($submitted_number) ?></div>
                           <p>Please show this registration number at the registration desk on <?= af_h(AF_FAIR_DATES) ?>.</p>
                           <div class="d-flex flex-wrap justify-content-center gap-3 mt-20">
                              <a href="/" class="af-submit" style="width:auto;min-width:200px;"><i class="fas fa-home" aria-hidden="true"></i> Back to Home</a>
                           </div>
                        </div>
                     <?php else: ?>
                        <h2 class="af-form-title">Register for the Fair</h2>
                        <p class="af-form-sub">Takes less than a minute. Fields marked <span class="af-required">*</span> are required.</p>

                        <?php if ($form_errors): ?>
                           <div class="af-alert" role="alert">
                              <ul>
                                 <?php foreach ($form_errors as $err): ?>
                                    <li><?= af_h($err) ?></li>
                                 <?php endforeach; ?>
                              </ul>
                           </div>
                        <?php endif; ?>

                        <form method="post" action="#register" novalidate id="fair-form">
                           <input type="hidden" name="_fair_csrf" value="<?= af_h($pub_csrf) ?>">
                           <div class="row g-3">
                              <div class="col-sm-6">
                                 <label class="af-label" for="af_first_name">First Name <span class="af-required">*</span></label>
                                 <input type="text" id="af_first_name" name="first_name" class="af-input" maxlength="100" autocomplete="given-name" value="<?= af_old('first_name', $old) ?>" placeholder="First name" required>
                              </div>
                              <div class="col-sm-6">
                                 <label class="af-label" for="af_last_name">Last Name <span class="af-required">*</span></label>
                                 <input type="text" id="af_last_name" name="last_name" class="af-input" maxlength="100" autocomplete="family-name" value="<?= af_old('last_name', $old) ?>" placeholder="Last name" required>
                              </div>
                              <div class="col-12">
                                 <label class="af-label" for="af_phone">Phone Number <span class="af-required">*</span></label>
                                 <input type="tel" id="af_phone" name="phone" class="af-input" maxlength="20" inputmode="tel" autocomplete="tel" value="<?= af_old('phone', $old) ?>" placeholder="01XXXXXXXXX" required>
                                 <div class="af-note">We'll call this number to confirm your visit.</div>
                              </div>
                              <div class="col-12">
                                 <label class="af-label" for="af_email">Email Address</label>
                                 <input type="email" id="af_email" name="email" class="af-input" maxlength="200" inputmode="email" autocomplete="email" autocapitalize="none" autocorrect="off" spellcheck="false" value="<?= af_old('email', $old) ?>" placeholder="you@example.com">
                                 <div class="af-note">Optional. We'll email your registration number.</div>
                              </div>
                           </div>
                           <div class="af-captcha"><?php captcha_render_widget(); ?></div>
                           <button type="submit" class="af-submit"><i class="fas fa-paper-plane" aria-hidden="true"></i> Register Now</button>
                           <p class="af-privacy">Your information is used only by Prime University Admissions.</p>
                        </form>
                     <?php endif; ?>
                  </div>
               </div>

               <div class="col-lg-5">
                  <aside class="af-aside">
                     <div class="af-pool">
                        <i class="fas fa-trophy" aria-hidden="true"></i>
                        <div><span>Scholarship pool</span><strong>Nearly BDT 1.5 Crore</strong></div>
                     </div>
                     <h3>Admission Offers</h3>
                     <div class="af-offer">
                        <div class="big">50%</div>
                        <div><strong>Admission Fee Waiver</strong><span>Fixed 50% waiver on admission fee</span></div>
                     </div>
                     <div class="af-offer teal">
                        <div class="big">100%</div>
                        <div><strong>Tuition Fee Waiver</strong><span>Up to 100% waiver on tuition fees</span></div>
                     </div>
                     <div class="af-offer gold">
                        <div class="big">GPA-5</div>
                        <div><strong>Golden GPA-5 Scholarship</strong><span>100% tuition fee waiver guaranteed</span></div>
                     </div>

                     <h3 class="mt-30">At the Fair</h3>
                     <ul class="af-list">
                        <li><i class="fas fa-check" aria-hidden="true"></i> Spot admission</li>
                        <li><i class="fas fa-lightbulb" aria-hidden="true"></i> Talent Hunt Quiz for 100% tuition waivers</li>
                        <li><i class="fas fa-users" aria-hidden="true"></i> Talk directly with faculty members</li>
                        <li><i class="fas fa-briefcase" aria-hidden="true"></i> Career counselling</li>
                        <li><i class="fas fa-university" aria-hidden="true"></i> Explore the university campus</li>
                     </ul>
                  </aside>
               </div>
            </div>
         </div>
      </div>
   </section>

   <section class="af-quiz-wrap">
      <div class="container">
         <div class="af-quiz">
            <div class="row g-4 align-items-center">
               <div class="col-lg-5">
                  <div class="af-quiz-badge"><i class="fas fa-star" aria-hidden="true"></i> Fair Highlight</div>
                  <h2>Talent Hunt Quiz</h2>
                  <p class="af-quiz-lead">Lower GPA? Your talent still counts. Take our on-the-spot quiz at the fair and win a <strong>100% tuition fee waiver</strong>.</p>
                  <div class="af-quiz-big">
                     <div><strong>24</strong><span>students win</span></div>
                     <div class="af-quiz-x">=</div>
                     <div><strong>3</strong><span>from each of 8 departments</span></div>
                  </div>
               </div>
               <div class="col-lg-7">
                  <ol class="af-quiz-steps">
                     <li>
                        <span class="n">1</span>
                        <div><strong>Register for the fair</strong><span>Fill in the form above. It takes less than a minute.</span></div>
                     </li>
                     <li>
                        <span class="n">2</span>
                        <div><strong>Take the quiz at the fair</strong><span>A short merit test on the spot at the fair.</span></div>
                     </li>
                     <li>
                        <span class="n">3</span>
                        <div><strong>Top 3 in each department win</strong><span>24 students in total receive a 100% tuition fee waiver.</span></div>
                     </li>
                  </ol>
                  <a href="#register" class="af-submit af-quiz-cta"><i class="fas fa-paper-plane" aria-hidden="true"></i> Register to Take the Quiz</a>
               </div>
            </div>
         </div>
      </div>
   </section>

   <section class="af-depts">
      <div class="container">
         <h2>8 Departments, One Campus</h2>
         <div class="af-dept-grid">
            <div class="af-dept">Department of Computer Science &amp; Engineering</div>
            <div class="af-dept">Department of Electrical &amp; Electronic Engineering</div>
            <div class="af-dept">Department of Civil Engineering</div>
            <div class="af-dept">Department of Fashion Design &amp; Apparel Engineering</div>
            <div class="af-dept">Department of Law</div>
            <div class="af-dept">Department of Business Administration</div>
            <div class="af-dept">Department of Bangla</div>
            <div class="af-dept">Department of English</div>
         </div>
      </div>
   </section>

   <div class="pb-80"></div>

   <?php include __DIR__ . '/includes/footer.php'; ?>

   <?php if (!$form_success): ?>
   <div class="af-sticky d-lg-none" id="afSticky">
      <a href="#register"><i class="fas fa-paper-plane" aria-hidden="true"></i> <span>Register for the Fair – Free</span> <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
   </div>
   <?php endif; ?>

   <?php include __DIR__ . '/includes/scripts.php'; ?>
   <script>
      (function () {
         var form = document.getElementById('fair-form');
         if (form) {
            var email = document.getElementById('af_email');
            if (email) {
               email.addEventListener('input', function () {
                  var s = this.selectionStart, e = this.selectionEnd;
                  this.value = this.value.toLowerCase();
                  this.setSelectionRange(s, e);
               });
            }
            form.addEventListener('submit', function (event) {
               var firstInvalid = null;
               form.querySelectorAll('[required]').forEach(function (f) {
                  var bad = !f.value.trim();
                  if (f.id === 'af_phone' && !bad) {
                     var d = f.value.replace(/\D/g, '');
                     bad = d.length < 10 || d.length > 15;
                  }
                  f.style.borderColor = bad ? '#dc2626' : '';
                  if (bad && !firstInvalid) firstInvalid = f;
               });
               if (email && email.value.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim())) {
                  email.style.borderColor = '#dc2626';
                  if (!firstInvalid) firstInvalid = email;
               }
               if (firstInvalid) { event.preventDefault(); firstInvalid.focus(); }
            });
            form.querySelectorAll('input').forEach(function (f) {
               f.addEventListener('input', function () { this.style.borderColor = ''; });
            });
         }

         // Hide the sticky bar while the form is on screen
         var sticky = document.getElementById('afSticky');
         var target = document.getElementById('register');
         if (sticky && target && 'IntersectionObserver' in window) {
            new IntersectionObserver(function (entries) {
               sticky.style.display = entries[0].isIntersecting ? 'none' : '';
            }, { threshold: 0.15 }).observe(target);
         }
      })();
   </script>
</body>
</html>