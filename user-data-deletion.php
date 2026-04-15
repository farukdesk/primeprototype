<?php
/**
 * User Data Deletion Policy – Prime University
 * Covers the right to erasure under GDPR, UK DPA 2018, and Bangladesh DSA 2018.
 * Provides a form for data-deletion requests.
 */
require_once __DIR__ . '/includes/config.php';

$page_title     = 'User Data Deletion Policy';
$last_updated   = 'April 15, 2025';

/* ── Handle submission ──────────────────────────────────────────────── */
if (session_status() === PHP_SESSION_NONE) session_start();
$csrf_token = $_SESSION['pub_csrf'] ?? ($_SESSION['pub_csrf'] = bin2hex(random_bytes(16)));

$form_success = false;
$form_errors  = [];
$form_data    = ['name' => '', 'email' => '', 'student_id' => '', 'request_type' => '', 'details' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['pub_csrf'] ?? '', $_POST['_csrf'] ?? '')) {
        $form_errors[] = 'Invalid security token. Please refresh and try again.';
    } else {
        $form_data['name']         = trim($_POST['name']         ?? '');
        $form_data['email']        = trim($_POST['email']        ?? '');
        $form_data['student_id']   = trim($_POST['student_id']   ?? '');
        $form_data['request_type'] = trim($_POST['request_type'] ?? '');
        $form_data['details']      = trim($_POST['details']      ?? '');

        if ($form_data['name'] === '')                                  $form_errors[] = 'Full name is required.';
        if (!filter_var($form_data['email'], FILTER_VALIDATE_EMAIL))   $form_errors[] = 'A valid email address is required.';
        if ($form_data['request_type'] === '')                          $form_errors[] = 'Please select a request type.';

        if (empty($form_errors)) {
            try {
                $db = front_db();
                if ($db) {
                    // Store in contact_messages as a structured request
                    $subject = 'Data Deletion Request – ' . $form_data['request_type'];
                    $message = "Request Type: {$form_data['request_type']}\n"
                             . "Student/Staff ID: {$form_data['student_id']}\n"
                             . "Details: {$form_data['details']}";
                    $db->prepare(
                        'INSERT INTO contact_messages (name, email, phone, subject, message) VALUES (?,?,?,?,?)'
                    )->execute([
                        $form_data['name'],
                        $form_data['email'],
                        '',
                        $subject,
                        $message,
                    ]);
                    $form_success = true;
                    $_SESSION['pub_csrf'] = bin2hex(random_bytes(16));
                    $csrf_token           = $_SESSION['pub_csrf'];
                    $form_data            = ['name' => '', 'email' => '', 'student_id' => '', 'request_type' => '', 'details' => ''];
                }
            } catch (Throwable $e) {
                $form_errors[] = 'Something went wrong. Please try again later or email us directly.';
            }
        }
    }
}
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <meta name="viewport" content="width=device-width, initial-scale=1">
   <title><?= fh($page_title) ?> – Prime University</title>
   <meta name="description" content="Submit a data deletion or access request to Prime University. We process all requests in accordance with GDPR, UK Data Protection Act 2018, and Bangladesh data-protection obligations.">
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
      /* ── User Data Deletion Page ─────────────────────────────────── */
      .udd-hero {
         background: linear-gradient(135deg, #1a0533 0%, #4c1d95 100%);
         padding: 80px 0 70px;
         position: relative;
         overflow: hidden;
      }
      .udd-hero::before {
         content: '';
         position: absolute; inset: 0;
         background: url('/assets/img/shape/footer-bg-3-1.jpg') center/cover no-repeat;
         opacity: .06;
      }
      .udd-badge {
         display: inline-flex;
         align-items: center;
         gap: 8px;
         background: rgba(255,255,255,.12);
         border: 1px solid rgba(255,255,255,.25);
         color: #FFB81C;
         padding: 6px 18px;
         border-radius: 50px;
         font-size: .82rem;
         font-weight: 600;
         letter-spacing: .04em;
         margin-bottom: 18px;
      }
      .udd-meta-bar {
         background: #fff;
         border-bottom: 3px solid #7c3aed;
         padding: 13px 0;
         box-shadow: 0 2px 12px rgba(0,33,71,.06);
         position: sticky;
         top: 0;
         z-index: 50;
      }
      .udd-meta-item {
         display: flex;
         align-items: center;
         gap: 7px;
         font-size: .85rem;
         color: #475569;
      }
      .udd-meta-item i { color: #7c3aed; }
      /* Content */
      .udd-body {
         font-family: 'Inter', 'Segoe UI', sans-serif;
         font-size: 16px;
         line-height: 1.85;
         color: #1e293b;
      }
      .udd-body h2 {
         font-size: 1.3rem;
         font-weight: 700;
         color: #1a0533;
         border-left: 4px solid #7c3aed;
         padding-left: 14px;
         margin-top: 2.2rem;
         margin-bottom: .8rem;
      }
      .udd-body h3 {
         font-size: 1.1rem;
         font-weight: 600;
         color: #4c1d95;
         margin-top: 1.5rem;
         margin-bottom: .5rem;
      }
      .udd-body p { margin-bottom: 1rem; }
      .udd-body ul, .udd-body ol { padding-left: 1.6rem; margin-bottom: 1rem; }
      .udd-body li { margin-bottom: .35rem; }
      .udd-body a { color: #7c3aed; text-decoration: underline; }
      .udd-highlight-box {
         background: #f5f3ff;
         border-left: 4px solid #7c3aed;
         border-radius: 0 8px 8px 0;
         padding: 14px 18px;
         margin-bottom: 1rem;
         font-size: .93rem;
      }
      .udd-step {
         display: flex;
         gap: 16px;
         align-items: flex-start;
         margin-bottom: 20px;
      }
      .udd-step-num {
         flex-shrink: 0;
         width: 42px; height: 42px;
         background: #7c3aed;
         color: #fff;
         border-radius: 50%;
         display: flex; align-items: center; justify-content: center;
         font-weight: 700;
         font-size: 1rem;
      }
      .udd-step-body h5 { font-size: 1rem; font-weight: 600; color: #1a0533; margin-bottom: 4px; }
      .udd-step-body p  { margin: 0; font-size: .9rem; color: #475569; }
      /* Form */
      .udd-form-card {
         border: none;
         border-radius: 14px;
         box-shadow: 0 2px 24px rgba(76,29,149,.12);
      }
      .udd-form-card .form-label { font-weight: 600; font-size: .875rem; color: #1a0533; }
      .udd-form-card .form-control,
      .udd-form-card .form-select {
         border-radius: 8px;
         border: 1px solid #ddd6fe;
         font-size: .92rem;
         padding: .55rem .9rem;
      }
      .udd-form-card .form-control:focus,
      .udd-form-card .form-select:focus {
         border-color: #7c3aed;
         box-shadow: 0 0 0 3px rgba(124,58,237,.15);
      }
      .udd-submit-btn {
         background: #7c3aed;
         color: #fff;
         border: none;
         border-radius: 8px;
         padding: .65rem 2rem;
         font-weight: 600;
         font-size: .95rem;
         transition: background .2s;
      }
      .udd-submit-btn:hover { background: #6d28d9; color: #fff; }
      /* Sidebar */
      .udd-sidebar-card {
         border: none;
         border-radius: 14px;
         box-shadow: 0 2px 16px rgba(0,33,71,.07);
         margin-bottom: 20px;
      }
      .udd-toc-list { list-style: none; padding: 0; margin: 0; }
      .udd-toc-list li { border-bottom: 1px dotted #e2e8f0; }
      .udd-toc-list li:last-child { border: none; }
      .udd-toc-list a {
         display: block;
         padding: 7px 0;
         color: #4c1d95;
         font-size: .875rem;
         text-decoration: none;
      }
      .udd-toc-list a:hover { color: #D21034; }
      .udd-right-badge {
         display: flex;
         align-items: center;
         gap: 10px;
         background: #f5f3ff;
         border: 1px solid #ddd6fe;
         border-radius: 10px;
         padding: 10px 14px;
         margin-bottom: 10px;
         font-size: .85rem;
         color: #4c1d95;
      }
      .udd-right-badge i { color: #7c3aed; width: 18px; text-align: center; }
      .pp-gdpr-tag {
         display: inline-block;
         background: #2563eb; color: #fff;
         font-size: .72rem; font-weight: 700;
         padding: 2px 8px; border-radius: 4px;
         margin-left: 8px; vertical-align: middle; letter-spacing: .03em;
      }
      .pp-dpa-tag {
         display: inline-block;
         background: #7c3aed; color: #fff;
         font-size: .72rem; font-weight: 700;
         padding: 2px 8px; border-radius: 4px;
         margin-left: 8px; vertical-align: middle; letter-spacing: .03em;
      }
      .pp-bd-tag {
         display: inline-block;
         background: #006a4e; color: #fff;
         font-size: .72rem; font-weight: 700;
         padding: 2px 8px; border-radius: 4px;
         margin-left: 8px; vertical-align: middle; letter-spacing: .03em;
      }
   </style>
<?php include __DIR__ . '/includes/meta-pixel.php'; ?>
</head>
<body id="body" class="it-magic-cursor">

   <div id="preloader">
      <div class="preloader"><span></span><span></span></div>
   </div>
   <div id="magic-cursor"><div id="ball"></div></div>
   <button class="scroll-top scroll-to-target" data-target="html">
      <i class="far fa-angle-double-up"></i>
   </button>

   <div class="search-popup">
      <button class="close-search"><span class="flaticon-multiply"><i class="fal fa-times"></i></span></button>
      <form method="post" action="#">
         <div class="form-group">
            <input type="search" name="search-field" value="" placeholder="Search Here" required="">
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
               <img src="/assets/img/logo/logo-black.png" alt="">
            </a>
         </div>
         <div class="it-menu-mobile d-xl-none"></div>
         <div class="itoffcanvas__info">
            <h3 class="offcanva-title">Get In Touch</h3>
            <div class="it-info-wrapper mb-20 d-flex align-items-center">
               <div class="itoffcanvas__info-icon"><a href="#"><i class="fal fa-envelope"></i></a></div>
               <div class="itoffcanvas__info-address">
                  <span>Email</span>
                  <a href="mailto:info@primeuniversity.ac.bd">info@primeuniversity.ac.bd</a>
               </div>
            </div>
            <div class="it-info-wrapper mb-20 d-flex align-items-center">
               <div class="itoffcanvas__info-icon"><a href="#"><i class="fal fa-phone-alt"></i></a></div>
               <div class="itoffcanvas__info-address">
                  <span>Phone</span>
                  <a href="tel:+8801969955566">01969-955566</a>
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

   <main>

   <!-- Hero -->
   <section class="udd-hero">
      <div class="container" style="position:relative;">
         <div class="row justify-content-center text-center">
            <div class="col-lg-8">
               <div class="udd-badge">
                  <i class="fas fa-user-shield"></i> Data Rights
               </div>
               <h1 style="color:#fff;font-size:clamp(26px,4vw,46px);font-weight:700;margin-bottom:14px;">
                  User Data Deletion Policy
               </h1>
               <p style="color:rgba(255,255,255,.8);font-size:17px;line-height:1.8;">
                  You have the right to request deletion of your personal data.
                  Learn what data we can delete, what we must retain by law, and how to submit a request.
               </p>
               <nav aria-label="breadcrumb" class="mt-3">
                  <ol class="breadcrumb justify-content-center mb-0" style="background:none;">
                     <li class="breadcrumb-item">
                        <a href="<?= fh(SITE_URL) ?>/index.php" style="color:rgba(255,255,255,.65);">Home</a>
                     </li>
                     <li class="breadcrumb-item">
                        <a href="/privacy-policy.php" style="color:rgba(255,255,255,.65);">Privacy Policy</a>
                     </li>
                     <li class="breadcrumb-item active" style="color:#FFB81C;">Data Deletion</li>
                  </ol>
               </nav>
            </div>
         </div>
      </div>
   </section>

   <!-- Meta bar -->
   <div class="udd-meta-bar">
      <div class="container">
         <div class="d-flex flex-wrap gap-4 align-items-center">
            <div class="udd-meta-item">
               <i class="fas fa-clock"></i>
               <strong>Last Updated:</strong>&nbsp;<?= fh($last_updated) ?>
            </div>
            <div class="udd-meta-item">
               <i class="fas fa-hourglass-half"></i>
               <strong>Response Time:</strong>&nbsp;Within 30 days
            </div>
            <div class="udd-meta-item ms-auto">
               <i class="fas fa-envelope"></i>
               <a href="mailto:privacy@primeuniversity.ac.bd" style="color:#7c3aed;">privacy@primeuniversity.ac.bd</a>
            </div>
         </div>
      </div>
   </div>

   <!-- Main Content -->
   <section class="pt-70 pb-100" style="background:#f8fafc;">
      <div class="container">
         <div class="row g-4">

            <!-- Policy Body -->
            <div class="col-lg-8">
               <div class="card" style="border:none;border-radius:14px;box-shadow:0 2px 16px rgba(0,33,71,.07);">
                  <div class="card-body p-4 p-lg-5">
                     <div class="udd-body">

                        <!-- 1. Overview -->
                        <h2 id="overview">1. Overview</h2>
                        <p>
                           Prime University recognises your right to have your personal data erased
                           ("<strong>right to be forgotten</strong>") under applicable data-protection law.
                           This page explains our data-deletion policy, the types of requests we can fulfil,
                           limitations imposed by legal obligations, and how to submit a formal request.
                        </p>
                        <div class="udd-highlight-box">
                           <i class="fas fa-info-circle me-2" style="color:#7c3aed;"></i>
                           This right is established under <strong>GDPR Article 17</strong>
                           <span class="pp-gdpr-tag">GDPR</span>,
                           <strong>UK Data Protection Act 2018 Section 94</strong>
                           <span class="pp-dpa-tag">DPA 2018</span>,
                           and general data-protection principles applied in Bangladesh
                           <span class="pp-bd-tag">BD</span>.
                        </div>

                        <!-- 2. What You Can Request -->
                        <h2 id="what-can-request">2. What You Can Request</h2>
                        <p>You may request the deletion, restriction, or portability of personal data, including:</p>

                        <h3>2.1 Full Erasure</h3>
                        <ul>
                           <li>Account / portal login credentials and profile data</li>
                           <li>Marketing preferences and email-subscription records</li>
                           <li>Website usage cookies and tracking identifiers</li>
                           <li>Support-ticket and contact-form submissions</li>
                           <li>Unsuccessful admission application records (after the 2-year retention period)</li>
                        </ul>

                        <h3>2.2 Partial Restriction</h3>
                        <p>
                           Where full deletion is not possible due to legal obligations, you may request
                           that we <strong>restrict processing</strong> of your data — meaning we retain it
                           but stop using it for active purposes.
                        </p>

                        <h3>2.3 Data Portability</h3>
                        <p>
                           You may request a copy of your personal data in a structured,
                           commonly-used, machine-readable format (e.g., CSV or JSON).
                        </p>

                        <!-- 3. Data We Cannot Delete -->
                        <h2 id="cannot-delete">3. Data We Are Legally Required to Retain</h2>
                        <p>
                           Certain data must be retained regardless of your deletion request due to
                           legal, regulatory, or contractual obligations:
                        </p>
                        <ul>
                           <li>
                              <strong>Academic records &amp; transcripts</strong> – Retained permanently
                              as required by the Bangladesh University Grants Commission (UGC) regulations
                              and the Private University Act 2010.
                           </li>
                           <li>
                              <strong>Examination results</strong> – Permanently retained for degree
                              verification and alumni services.
                           </li>
                           <li>
                              <strong>Financial transaction records</strong> – Retained for 7 years under
                              Bangladeshi financial and tax regulations.
                           </li>
                           <li>
                              <strong>Legal proceedings</strong> – Data relevant to ongoing legal disputes
                              or regulatory investigations cannot be deleted until the matter is resolved.
                           </li>
                           <li>
                              <strong>Staff employment records</strong> – Retained for 7 years after the
                              end of employment as required by labour law.
                           </li>
                        </ul>
                        <div class="udd-highlight-box" style="background:#fff7ed;border-color:#f59e0b;">
                           <i class="fas fa-exclamation-triangle me-2" style="color:#f59e0b;"></i>
                           If your data falls into one of the above categories, we will inform you of the
                           specific legal basis for retention and the expected deletion date.
                        </div>

                        <!-- 4. Process -->
                        <h2 id="process">4. How We Process Your Request</h2>

                        <div class="udd-step">
                           <div class="udd-step-num">1</div>
                           <div class="udd-step-body">
                              <h5>Submit Your Request</h5>
                              <p>Complete the request form on this page or email
                                 <a href="mailto:privacy@primeuniversity.ac.bd">privacy@primeuniversity.ac.bd</a>.
                                 Include your full name, registered email, and type of request.
                              </p>
                           </div>
                        </div>

                        <div class="udd-step">
                           <div class="udd-step-num">2</div>
                           <div class="udd-step-body">
                              <h5>Identity Verification</h5>
                              <p>We will verify your identity to protect against unauthorised deletion requests.
                                 We may ask for a copy of your student/staff ID or government-issued identification.
                              </p>
                           </div>
                        </div>

                        <div class="udd-step">
                           <div class="udd-step-num">3</div>
                           <div class="udd-step-body">
                              <h5>Assessment</h5>
                              <p>Our Privacy team reviews your request within <strong>7 working days</strong>
                                 and determines what can be deleted, restricted, or must be retained under law.
                              </p>
                           </div>
                        </div>

                        <div class="udd-step">
                           <div class="udd-step-num">4</div>
                           <div class="udd-step-body">
                              <h5>Action &amp; Notification</h5>
                              <p>We complete the deletion or restriction within <strong>30 calendar days</strong>
                                 of receiving a verified request and notify you by email of the outcome.
                                 If an extension is needed (up to 60 days total), we will inform you.
                              </p>
                           </div>
                        </div>

                        <div class="udd-step">
                           <div class="udd-step-num">5</div>
                           <div class="udd-step-body">
                              <h5>Confirmation</h5>
                              <p>You will receive a written confirmation once the deletion is complete,
                                 including details of any data that was retained and the reason.
                              </p>
                           </div>
                        </div>

                        <!-- 5. Third-Party Systems -->
                        <h2 id="third-party">5. Third-Party &amp; Partner Systems</h2>
                        <p>
                           If your data has been shared with authorised third-party service providers
                           (e.g., payment processors, cloud hosting), we will instruct those parties to
                           delete your data in accordance with their respective retention policies and
                           our data-processing agreements.
                        </p>
                        <p>
                           Data transmitted to government or regulatory bodies cannot be deleted by us
                           once submitted, as those bodies are independent data controllers.
                        </p>

                        <!-- 6. Consequences of Deletion -->
                        <h2 id="consequences">6. Consequences of Data Deletion</h2>
                        <p>Please be aware that deleting your data may result in:</p>
                        <ul>
                           <li>Permanent loss of access to your student/staff portal and digital services.</li>
                           <li>Inability to verify your enrolment or obtain official documents in the future.</li>
                           <li>Loss of eligibility for alumni services, transcript requests, and degree verification.</li>
                           <li>Cancellation of any pending applications, scholarships, or financial arrangements.</li>
                        </ul>
                        <p>
                           We recommend downloading and saving any documents or records you may need
                           <strong>before</strong> submitting a deletion request.
                        </p>

                        <!-- 7. Complaints -->
                        <h2 id="complaints">7. Complaints &amp; Escalation</h2>
                        <p>
                           If you are dissatisfied with how we have handled your request, you may:
                        </p>
                        <ul>
                           <li>Contact our Data Protection Officer at
                               <a href="mailto:dpo@primeuniversity.ac.bd">dpo@primeuniversity.ac.bd</a>.</li>
                           <li>For EEA/UK residents: lodge a complaint with your national supervisory authority
                               (e.g., the UK ICO at <a href="https://ico.org.uk" target="_blank" rel="noopener">ico.org.uk</a>
                               or your EU DPA).</li>
                           <li>Contact the Bangladesh Digital Security Agency (BDSA) for issues governed by the
                               Digital Security Act 2018.</li>
                        </ul>

                     </div><!-- /.udd-body -->
                  </div>
               </div>

               <!-- Request Form -->
               <div id="request-form" class="card udd-form-card mt-4">
                  <div class="card-body p-4 p-lg-5">
                     <h4 class="fw-bold mb-1" style="color:#1a0533;">
                        <i class="fas fa-paper-plane me-2" style="color:#7c3aed;"></i>Submit a Data Request
                     </h4>
                     <p class="text-muted mb-4" style="font-size:.9rem;">
                        All fields marked <span class="text-danger">*</span> are required.
                        We will respond within 30 days.
                     </p>

                     <?php if ($form_success): ?>
                     <div class="alert alert-success d-flex align-items-start gap-3" role="alert">
                        <i class="fas fa-check-circle fa-lg mt-1 text-success"></i>
                        <div>
                           <strong>Request received!</strong><br>
                           We have received your data request. Our Privacy team will contact you at the
                           email address you provided within <strong>7 working days</strong> to verify
                           your identity and confirm next steps.
                        </div>
                     </div>
                     <?php endif; ?>

                     <?php if (!empty($form_errors)): ?>
                     <div class="alert alert-danger" role="alert">
                        <ul class="mb-0">
                           <?php foreach ($form_errors as $err): ?>
                              <li><?= fh($err) ?></li>
                           <?php endforeach; ?>
                        </ul>
                     </div>
                     <?php endif; ?>

                     <?php if (!$form_success): ?>
                     <form method="POST" action="/user-data-deletion.php#request-form" novalidate>
                        <input type="hidden" name="_csrf" value="<?= fh($csrf_token) ?>">

                        <div class="row g-3">
                           <div class="col-md-6">
                              <label class="form-label" for="udd_name">Full Name <span class="text-danger">*</span></label>
                              <input type="text" id="udd_name" name="name" class="form-control"
                                     value="<?= fh($form_data['name']) ?>"
                                     placeholder="Your full legal name" required>
                           </div>
                           <div class="col-md-6">
                              <label class="form-label" for="udd_email">Email Address <span class="text-danger">*</span></label>
                              <input type="email" id="udd_email" name="email" class="form-control"
                                     value="<?= fh($form_data['email']) ?>"
                                     placeholder="your@email.com" required>
                           </div>
                           <div class="col-md-6">
                              <label class="form-label" for="udd_sid">Student / Staff ID</label>
                              <input type="text" id="udd_sid" name="student_id" class="form-control"
                                     value="<?= fh($form_data['student_id']) ?>"
                                     placeholder="e.g. 2023010001 (if applicable)">
                           </div>
                           <div class="col-md-6">
                              <label class="form-label" for="udd_type">Request Type <span class="text-danger">*</span></label>
                              <select id="udd_type" name="request_type" class="form-select" required>
                                 <option value="" disabled <?= $form_data['request_type'] === '' ? 'selected' : '' ?>>— Select —</option>
                                 <option value="Full Data Erasure"      <?= $form_data['request_type'] === 'Full Data Erasure'      ? 'selected' : '' ?>>Full Data Erasure (Right to be Forgotten)</option>
                                 <option value="Partial Data Deletion"  <?= $form_data['request_type'] === 'Partial Data Deletion'  ? 'selected' : '' ?>>Partial Data Deletion</option>
                                 <option value="Restrict Processing"    <?= $form_data['request_type'] === 'Restrict Processing'    ? 'selected' : '' ?>>Restrict Processing of My Data</option>
                                 <option value="Data Portability"       <?= $form_data['request_type'] === 'Data Portability'       ? 'selected' : '' ?>>Export / Portability of My Data</option>
                                 <option value="Access Request"         <?= $form_data['request_type'] === 'Access Request'         ? 'selected' : '' ?>>Access Request (Subject Access Request)</option>
                                 <option value="Withdraw Consent"       <?= $form_data['request_type'] === 'Withdraw Consent'       ? 'selected' : '' ?>>Withdraw Consent (e.g., marketing emails)</option>
                                 <option value="Rectification"          <?= $form_data['request_type'] === 'Rectification'          ? 'selected' : '' ?>>Rectify / Correct Inaccurate Data</option>
                              </select>
                           </div>
                           <div class="col-12">
                              <label class="form-label" for="udd_details">Additional Details</label>
                              <textarea id="udd_details" name="details" class="form-control" rows="4"
                                        placeholder="Describe the specific data or records you want deleted / accessed / corrected…"><?= fh($form_data['details']) ?></textarea>
                           </div>
                           <div class="col-12">
                              <div class="form-check">
                                 <input class="form-check-input" type="checkbox" id="udd_confirm" required>
                                 <label class="form-check-label" for="udd_confirm" style="font-size:.88rem;">
                                    I confirm that the information above is accurate and that I am the data subject
                                    (or have authority to act on their behalf). I understand that we may need to
                                    verify my identity before processing this request.
                                 </label>
                              </div>
                           </div>
                           <div class="col-12 mt-2">
                              <button type="submit" class="udd-submit-btn">
                                 <i class="fas fa-paper-plane me-2"></i>Submit Request
                              </button>
                              <a href="/privacy-policy.php" class="btn btn-link ms-3" style="color:#7c3aed;font-size:.9rem;">
                                 View Privacy Policy
                              </a>
                           </div>
                        </div>
                     </form>
                     <?php endif; ?>
                  </div>
               </div>

            </div><!-- /.col-lg-8 -->

            <!-- Sidebar -->
            <div class="col-lg-4">

               <!-- TOC -->
               <div class="card udd-sidebar-card">
                  <div class="card-body p-4">
                     <h6 class="fw-bold mb-3" style="color:#1a0533;border-bottom:2px solid #FFB81C;padding-bottom:10px;">
                        <i class="fas fa-list me-2 text-muted"></i>On This Page
                     </h6>
                     <ul class="udd-toc-list">
                        <li><a href="#overview"><span class="text-muted me-1">1.</span> Overview</a></li>
                        <li><a href="#what-can-request"><span class="text-muted me-1">2.</span> What You Can Request</a></li>
                        <li><a href="#cannot-delete"><span class="text-muted me-1">3.</span> Data We Must Retain</a></li>
                        <li><a href="#process"><span class="text-muted me-1">4.</span> Request Process</a></li>
                        <li><a href="#third-party"><span class="text-muted me-1">5.</span> Third-Party Systems</a></li>
                        <li><a href="#consequences"><span class="text-muted me-1">6.</span> Consequences</a></li>
                        <li><a href="#complaints"><span class="text-muted me-1">7.</span> Complaints</a></li>
                        <li><a href="#request-form"><i class="fas fa-paper-plane me-1 text-primary" style="font-size:.8rem;"></i>Submit a Request</a></li>
                     </ul>
                  </div>
               </div>

               <!-- Your Rights -->
               <div class="card udd-sidebar-card">
                  <div class="card-body p-4">
                     <h6 class="fw-bold mb-3" style="color:#1a0533;border-bottom:2px solid #FFB81C;padding-bottom:10px;">
                        <i class="fas fa-balance-scale me-2 text-muted"></i>Your Rights
                     </h6>
                     <div class="udd-right-badge"><i class="fas fa-trash-alt"></i> Right to Erasure</div>
                     <div class="udd-right-badge"><i class="fas fa-ban"></i> Right to Restrict Processing</div>
                     <div class="udd-right-badge"><i class="fas fa-download"></i> Right to Data Portability</div>
                     <div class="udd-right-badge"><i class="fas fa-eye"></i> Right of Access (SAR)</div>
                     <div class="udd-right-badge"><i class="fas fa-edit"></i> Right to Rectification</div>
                     <div class="udd-right-badge"><i class="fas fa-undo"></i> Right to Withdraw Consent</div>
                  </div>
               </div>

               <!-- Contact -->
               <div class="card udd-sidebar-card">
                  <div class="card-body p-4">
                     <h6 class="fw-bold mb-3" style="color:#1a0533;border-bottom:2px solid #FFB81C;padding-bottom:10px;">
                        <i class="fas fa-envelope me-2 text-muted"></i>Privacy Contact
                     </h6>
                     <p style="font-size:.875rem;color:#475569;margin-bottom:8px;">
                        <i class="fas fa-envelope me-2 text-muted"></i>
                        <a href="mailto:privacy@primeuniversity.ac.bd" style="color:#7c3aed;">privacy@primeuniversity.ac.bd</a>
                     </p>
                     <p style="font-size:.875rem;color:#475569;margin-bottom:8px;">
                        <i class="fas fa-user-shield me-2 text-muted"></i>
                        DPO: <a href="mailto:dpo@primeuniversity.ac.bd" style="color:#7c3aed;">dpo@primeuniversity.ac.bd</a>
                     </p>
                     <p style="font-size:.875rem;color:#475569;margin-bottom:0;">
                        <i class="fas fa-clock me-2 text-muted"></i>
                        Response within 30 days
                     </p>
                  </div>
               </div>

               <!-- Related -->
               <div class="card udd-sidebar-card">
                  <div class="card-body p-4">
                     <h6 class="fw-bold mb-3" style="color:#1a0533;border-bottom:2px solid #FFB81C;padding-bottom:10px;">
                        <i class="fas fa-link me-2 text-muted"></i>Related Pages
                     </h6>
                     <ul class="udd-toc-list">
                        <li><a href="/privacy-policy.php"><i class="fas fa-shield-alt me-2 text-muted"></i>Privacy Policy</a></li>
                        <li><a href="/contact.php"><i class="fas fa-envelope me-2 text-muted"></i>Contact Us</a></li>
                        <li><a href="/index.php"><i class="fas fa-home me-2 text-muted"></i>Home</a></li>
                     </ul>
                  </div>
               </div>

            </div>
         </div><!-- /.row -->
      </div>
   </section>

   </main>

<?php include __DIR__ . '/includes/footer.php'; ?>

   <?php include __DIR__ . '/includes/scripts.php'; ?>
</body>
</html>
