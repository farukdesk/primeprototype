<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/includes/config.php';

// ── Fetch job by slug ─────────────────────────────────────────────────────────
$slug = trim($_GET['slug'] ?? '');
$job  = null;

if ($slug !== '') {
    try {
        $db = front_db();
        if ($db) {
            $stmt = $db->prepare(
                "SELECT * FROM jobs
                 WHERE slug = ? AND is_published = 1
                   AND (deadline IS NULL OR deadline >= CURDATE())
                 LIMIT 1"
            );
            $stmt->execute([$slug]);
            $job = $stmt->fetch();
        }
    } catch (Throwable $e) {
        // silently fall through
    }
}

$page_title = $job ? $job['title'] . ' – Prime University' : 'Job Not Found – Prime University';

// ── CSRF token ───────────────────────────────────────────────────────────────
$csrf_token = $_SESSION['pub_csrf'] ?? ($_SESSION['pub_csrf'] = bin2hex(random_bytes(16)));

// ── CV upload directory ───────────────────────────────────────────────────────
$cv_dir = __DIR__ . '/admin/uploads/jobs/';

// ── Handle application submission ─────────────────────────────────────────────
$form_errors  = [];
$form_success = false;

const CV_ALLOWED_MIMES = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];
const CV_ALLOWED_EXTS = ['pdf', 'doc', 'docx'];
const CV_MAX_BYTES    = 5 * 1024 * 1024; // 5 MB

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $job) {
    // CSRF check
    if (!hash_equals($_SESSION['pub_csrf'] ?? '', $_POST['_csrf'] ?? '')) {
        $form_errors[] = 'Security check failed. Please refresh and try again.';
    } else {
        $full_name    = trim($_POST['full_name']    ?? '');
        $email        = trim($_POST['email']        ?? '');
        $phone        = trim($_POST['phone']        ?? '');
        $cover_letter = trim($_POST['cover_letter'] ?? '');

        if ($full_name === '')                    $form_errors[] = 'Full name is required.';
        if ($email === '')                         $form_errors[] = 'Email address is required.';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $form_errors[] = 'Please enter a valid email address.';

        // CV upload
        $cv_filename      = null;
        $cv_original_name = null;

        if (!empty($_FILES['cv']['name'])) {
            $cv_file = $_FILES['cv'];

            if ($cv_file['error'] !== UPLOAD_ERR_OK) {
                $form_errors[] = 'CV upload failed. Please try again.';
            } else {
                $cv_ext = strtolower(pathinfo($cv_file['name'], PATHINFO_EXTENSION));

                if (!in_array($cv_ext, CV_ALLOWED_EXTS, true)) {
                    $form_errors[] = 'CV must be a PDF, DOC, or DOCX file.';
                } elseif ($cv_file['size'] > CV_MAX_BYTES) {
                    $form_errors[] = 'CV file size must not exceed 5 MB.';
                } else {
                    $finfo    = new finfo(FILEINFO_MIME_TYPE);
                    $cv_mime  = $finfo->file($cv_file['tmp_name']);
                    if (!in_array($cv_mime, CV_ALLOWED_MIMES, true)) {
                        $form_errors[] = 'CV file type is not allowed. Please upload a valid PDF, DOC, or DOCX.';
                    } else {
                        if (!is_dir($cv_dir)) mkdir($cv_dir, 0755, true);
                        $cv_stored = bin2hex(random_bytes(12)) . '.' . $cv_ext;
                        if (!move_uploaded_file($cv_file['tmp_name'], $cv_dir . $cv_stored)) {
                            $form_errors[] = 'Failed to save CV. Please try again.';
                        } else {
                            $cv_filename      = $cv_stored;
                            $cv_original_name = $cv_file['name'];
                        }
                    }
                }
            }
        }

        if (empty($form_errors)) {
            // Check for duplicate application
            try {
                $db   = front_db();
                $dupe = $db->prepare(
                    'SELECT id FROM job_applications WHERE job_id = ? AND email = ? LIMIT 1'
                );
                $dupe->execute([$job['id'], $email]);
                if ($dupe->fetch()) {
                    $form_errors[] = 'You have already applied for this position with this email address.';
                    // Remove uploaded CV if duplicate
                    if ($cv_filename && file_exists($cv_dir . $cv_filename)) {
                        @unlink($cv_dir . $cv_filename);
                    }
                } else {
                    $db->prepare(
                        'INSERT INTO job_applications
                            (job_id, full_name, email, phone, cover_letter, cv_filename, cv_original_name)
                         VALUES (?,?,?,?,?,?,?)'
                    )->execute([
                        $job['id'],
                        $full_name,
                        $email,
                        $phone ?: null,
                        $cover_letter ?: null,
                        $cv_filename,
                        $cv_original_name,
                    ]);

                    $form_success = true;
                    // Regenerate CSRF token after successful submission
                    unset($_SESSION['pub_csrf']);
                    $csrf_token = $_SESSION['pub_csrf'] = bin2hex(random_bytes(16));
                }
            } catch (Throwable $e) {
                $form_errors[] = 'An error occurred. Please try again later.';
            }
        }
    }
}

$type_badge = [
    'full-time'  => 'bg-primary',
    'part-time'  => 'bg-secondary',
    'contract'   => 'bg-warning text-dark',
    'internship' => 'bg-info text-dark',
];
?>
<!doctype html>
<html class="no-js" lang="en">

<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <title><?= fh($page_title) ?></title>
   <meta name="description" content="<?= $job ? fh(mb_strimwidth(strip_tags($job['description']), 0, 160, '…')) : '' ?>">
   <meta name="viewport" content="width=device-width, initial-scale=1">

   <link rel="shortcut icon" type="image/x-icon" href="/assets/img/logo/favicon.png">

   <!-- CSS Here -->
   <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
   <link rel="stylesheet" href="/assets/css/font-awesome-pro.css">
   <link rel="stylesheet" href="/assets/css/swiper-bundle.min.css">
   <link rel="stylesheet" href="/assets/css/slick.css">
   <link rel="stylesheet" href="/assets/css/magnific-popup.css">
   <link rel="stylesheet" href="/assets/css/nice-select.css">
   <link rel="stylesheet" href="/assets/css/custom-animation.css">

   <!-- Theme / Main CSS -->
   <link rel="stylesheet" href="/assets/css/spacing.css">
   <link rel="stylesheet" href="/assets/css/main.css">
</head>

<body id="body" class="it-magic-cursor">

   <!-- preloader -->
   <div id="preloader">
      <div class="preloader">
         <span></span>
         <span></span>
      </div>
   </div>
   <!-- preloader end -->

   <div id="magic-cursor">
      <div id="ball"></div>
   </div>

   <!-- back-to-top-start -->
   <button class="scroll-top scroll-to-target" data-target="html">
      <i class="far fa-angle-double-up"></i>
   </button>
   <!-- back-to-top-end -->

   <!-- search popup start -->
   <div class="search-popup">
        <button class="close-search"><span class="flaticon-multiply"><i class="fal fa-times"></i></span></button>
        <form method="post" action="#">
            <div class="form-group">
                <input type="search" name="search-field" value="" placeholder="Search Here" required="">
                <button type="submit"><i class="fal fa-search"></i></button>
            </div>
        </form>
   </div>
   <!-- search popup end -->

   <!-- it-offcanvus-area-start -->
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
            <h3 class="offcanva-title">Get In Touch</h3>
            <div class="it-info-wrapper mb-20 d-flex align-items-center">
               <div class="itoffcanvas__info-icon">
                  <a href="#"><i class="fal fa-envelope"></i></a>
               </div>
               <div class="itoffcanvas__info-address">
                  <span>Email</span>
                  <a href="mailto:info@primeuniversity.ac.bd">info@primeuniversity.ac.bd</a>
               </div>
            </div>
            <div class="it-info-wrapper mb-20 d-flex align-items-center">
               <div class="itoffcanvas__info-icon">
                  <a href="#"><i class="fal fa-phone-alt"></i></a>
               </div>
               <div class="itoffcanvas__info-address">
                  <span>Phone</span>
                  <a href="tel:+8801969955566">01969-955566</a>
               </div>
            </div>
            <div class="it-info-wrapper mb-20 d-flex align-items-center">
               <div class="itoffcanvas__info-icon">
                  <a href="#"><i class="fas fa-map-marker-alt"></i></a>
               </div>
               <div class="itoffcanvas__info-address">
                  <span>Location</span>
                  <a href="https://www.google.com/maps/@23.7934913,90.3547073,15z" target="_blank">114/116, Mazar Rd, Dhaka-1216</a>
               </div>
            </div>
         </div>
      </div>
   </div>
   <div class="body-overlay"></div>
   <!-- it-offcanvus-area-end -->

   <header class="it-header-height">
      <?php include __DIR__ . '/includes/header-top.php'; ?>
      <?php include __DIR__ . '/includes/nav-menu.php'; ?>
   </header>

   <main>

   <?php include __DIR__ . '/includes/news-ticker.php'; ?>

   <!-- breadcrumb-area-start -->
   <div class="it-breadcrumb-area fix it-breadcrumb-style-2 z-index-1" data-background="assets/img/shape/breadcrumb-1-bg.png">
      <img class="it-breadcrumb-shape-1" src="/assets/img/shape/breadcrumb-1-1.png" alt="">
      <img class="it-breadcrumb-shape-3" src="/assets/img/shape/breadcrumb-1-2.png" alt="">
      <div class="container">
         <div class="row align-items-center">
            <div class="col-12">
               <div class="it-breadcrumb-content text-center z-index-1">
                  <div class="it-breadcrumb-title-box">
                     <h3 class="it-breadcrumb-title style-2">
                        <?= $job ? fh($job['title']) : 'Job Not Found' ?>
                     </h3>
                  </div>
                  <div class="it-breadcrumb-list">
                     <ul>
                        <li><a href="<?= fh(SITE_URL) ?>/index.php">Home</a></li>
                        <li><a href="<?= fh(SITE_URL) ?>/jobs.php">Careers</a></li>
                        <li><span><?= $job ? fh($job['title']) : 'Not Found' ?></span></li>
                     </ul>
                  </div>
               </div>
            </div>
         </div>
      </div>
   </div>
   <!-- breadcrumb-area-end -->

   <!-- job-detail-area-start -->
   <style>
   /* ── Job Detail Page Styles ──────────────────────────────────────── */
   /* Breadcrumb overrides for job-detail page */
   .it-breadcrumb-style-2 { padding-top: 15px !important; padding-bottom: 35px !important; }
   .it-breadcrumb-list a,
   .it-breadcrumb-list li > span { color: #fff !important; }

   .jd-main-card {
      background: #fff;
      border: 1px solid #e5e7eb;
      border-radius: 16px;
      padding: 40px 40px 36px;
      box-shadow: 0 2px 16px rgba(0,0,0,.06);
   }
   @media (max-width: 575.98px) {
      .jd-main-card { padding: 24px 18px 24px; }
   }

   /* Quick Info Bar */
   .jd-info-bar {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      margin: 24px 0 32px;
   }
   .jd-info-chip {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: #f8f9ff;
      border: 1px solid #e5e7eb;
      border-radius: 50px;
      padding: 8px 18px;
      font-size: .875rem;
      font-weight: 500;
      color: #1e293b;
      white-space: nowrap;
   }
   .jd-info-chip i {
      font-size: .85rem;
      color: #f5a623;
   }
   .jd-info-chip.chip-deadline i { color: #ef4444; }
   .jd-info-chip.chip-salary   i { color: #10b981; }

   /* Content sections */
   .jd-section-heading {
      font-size: 1.1rem;
      font-weight: 700;
      color: #0f172a;
      margin-bottom: 16px;
      padding-bottom: 10px;
      border-bottom: 2px solid #f5a623;
      display: inline-block;
   }
   .jd-content {
      font-size: .96rem;
      line-height: 1.8;
      color: #374151;
   }
   .jd-content p,
   .jd-content li {
      color: #374151;
   }
   .jd-content ul, .jd-content ol {
      padding-left: 1.4rem;
   }

   /* Application form card */
   .jd-form-card {
      background: #fff;
      border: 1px solid #e5e7eb;
      border-radius: 16px;
      box-shadow: 0 2px 16px rgba(0,0,0,.06);
      overflow: hidden;
   }
   .jd-form-header {
      background: linear-gradient(135deg, #1e2d6e 0%, #253694 100%);
      padding: 22px 28px;
      color: #fff;
   }
   .jd-form-header h5 {
      margin: 0;
      font-size: 1.05rem;
      font-weight: 700;
      letter-spacing: .02em;
      color: #fff;
   }
   .jd-form-header p {
      margin: 4px 0 0;
      font-size: .8rem;
      opacity: .75;
      color: #fff;
   }
   .jd-form-body {
      padding: 28px 28px 24px;
   }
   @media (max-width: 575.98px) {
      .jd-form-body { padding: 20px 16px 20px; }
      .jd-form-header { padding: 18px 16px; }
   }

   /* Floating-label style inputs */
   .jd-field {
      position: relative;
      margin-bottom: 20px;
   }
   .jd-field label {
      display: block;
      font-size: .82rem;
      font-weight: 600;
      color: #374151;
      margin-bottom: 6px;
      letter-spacing: .02em;
      text-transform: uppercase;
   }
   .jd-field label .req { color: #ef4444; margin-left: 2px; }
   .jd-field label .opt { color: #9ca3af; font-weight: 400; text-transform: none; font-size: .78rem; }
   .jd-field .form-control {
      border: 1.5px solid #d1d5db;
      border-radius: 10px;
      padding: 11px 14px;
      font-size: .93rem;
      color: #1e293b;
      background: #f9fafb;
      transition: border-color .2s, box-shadow .2s;
   }
   .jd-field .form-control:focus {
      border-color: #f5a623;
      box-shadow: 0 0 0 3px rgba(245,166,35,.18);
      background: #fff;
      outline: none;
   }
   textarea.form-control { resize: vertical; min-height: 110px; max-height: 280px; }

   /* Branded file upload */
   .jd-file-upload-wrap {
      border: 2px dashed #d1d5db;
      border-radius: 10px;
      padding: 18px 14px;
      text-align: center;
      background: #f9fafb;
      cursor: pointer;
      transition: border-color .2s, background .2s;
      position: relative;
   }
   .jd-file-upload-wrap:hover,
   .jd-file-upload-wrap:focus-within {
      border-color: #f5a623;
      background: #fffbf0;
   }
   .jd-file-upload-wrap input[type="file"] {
      position: absolute;
      inset: 0;
      opacity: 0;
      cursor: pointer;
      width: 100%;
      height: 100%;
   }
   .jd-file-upload-icon { font-size: 1.5rem; color: #f5a623; margin-bottom: 6px; }
   .jd-file-upload-text { font-size: .85rem; color: #6b7280; }
   .jd-file-name-display {
      font-size: .82rem;
      color: #374151;
      margin-top: 6px;
      font-weight: 500;
      display: none;
   }

   /* Submit button */
   .jd-submit-btn {
      width: 100%;
      background: #f5a623;
      color: #1e293b;
      border: none;
      border-radius: 50px;
      padding: 13px 24px;
      font-size: 1rem;
      font-weight: 700;
      cursor: pointer;
      transition: background .2s, transform .15s, box-shadow .2s;
      letter-spacing: .03em;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
   }
   .jd-submit-btn:hover {
      background: #e09510;
      box-shadow: 0 4px 16px rgba(245,166,35,.4);
      transform: translateY(-1px);
   }
   .jd-submit-btn:active { transform: translateY(0); }

   /* Alerts */
   .jd-alert-success {
      background: #ecfdf5;
      border: 1px solid #6ee7b7;
      border-radius: 12px;
      padding: 20px 22px;
      color: #065f46;
      font-size: .93rem;
   }
   .jd-alert-success .jd-alert-icon {
      font-size: 2rem;
      color: #10b981;
      margin-bottom: 8px;
   }
   .jd-alert-error {
      background: #fef2f2;
      border: 1px solid #fca5a5;
      border-radius: 12px;
      padding: 16px 18px;
      margin-bottom: 18px;
      color: #991b1b;
      font-size: .88rem;
   }
   .jd-alert-error ul { margin: 0; padding-left: 1.2rem; }

   /* Sticky sidebar */
   .jd-sidebar-sticky {
      position: sticky;
      top: 90px;
   }
   @media (max-width: 1199.98px) {
      .jd-sidebar-sticky { position: static; }
   }

   /* Back link */
   .jd-back-link {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-size: .88rem;
      font-weight: 600;
      color: #6b7280;
      text-decoration: none;
      margin-bottom: 28px;
      transition: color .2s;
   }
   .jd-back-link:hover { color: #f5a623; }

   /* Divider */
   .jd-divider {
      border: none;
      border-top: 1px solid #f0f0f0;
      margin: 32px 0;
   }

   /* Badge strip */
   .jd-badge-strip {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-bottom: 18px;
   }
   .jd-badge {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 5px 14px;
      border-radius: 50px;
      font-size: .8rem;
      font-weight: 600;
      letter-spacing: .03em;
   }
   .jd-badge-type   { background: #1e2d6e; color: #fff; }
   .jd-badge-dept   { background: #e0e7ff; color: #3730a3; }
   </style>

   <div class="postbox-area pt-80 pb-100">
      <div class="container">

         <?php if (!$job): ?>
         <div class="row justify-content-center">
            <div class="col-xl-7 col-lg-9 text-center py-80">
               <div style="width:80px;height:80px;background:#f0f4ff;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 24px;">
                  <i class="fas fa-briefcase" style="font-size:2rem;color:#1e2d6e;"></i>
               </div>
               <h4 class="mb-15" style="color:#0f172a;">Job Not Found</h4>
               <p class="mb-30" style="color:#6b7280;">This position is no longer available or the link may be incorrect.</p>
               <a href="<?= fh(SITE_URL) ?>/jobs.php" class="it-btn-yellow border-radius-100">
                  <span>
                     <span class="text-1">← View All Jobs</span>
                     <span class="text-2">← View All Jobs</span>
                  </span>
               </a>
            </div>
         </div>

         <?php else: ?>

         <!-- Back link -->
         <a href="<?= fh(SITE_URL) ?>/jobs.php" class="jd-back-link">
            <i class="fas fa-arrow-left"></i> Back to All Jobs
         </a>

         <div class="row g-4 g-xl-5 align-items-start">

            <!-- ── Left: Job detail ──────────────────────────────────── -->
            <div class="col-xl-8">
               <div class="jd-main-card">

                  <!-- Badge strip -->
                  <div class="jd-badge-strip">
                     <?php $badge_cls = $type_badge[$job['job_type']] ?? 'bg-secondary'; ?>
                     <span class="jd-badge jd-badge-type">
                        <i class="fas fa-briefcase" style="font-size:.7rem;"></i>
                        <?= fh(ucfirst(str_replace('-', ' ', $job['job_type']))) ?>
                     </span>
                     <?php if ($job['department']): ?>
                     <span class="jd-badge jd-badge-dept">
                        <i class="fas fa-building" style="font-size:.7rem;"></i>
                        <?= fh($job['department']) ?>
                     </span>
                     <?php endif; ?>
                  </div>

                  <!-- Job title -->
                  <h2 style="font-size:clamp(1.4rem,3.5vw,2rem);font-weight:800;color:#0f172a;line-height:1.25;margin-bottom:6px;">
                     <?= fh($job['title']) ?>
                  </h2>
                  <p style="font-size:.9rem;color:#9ca3af;margin-bottom:0;">Posted at Prime University</p>

                  <!-- ── Quick Info Bar ────────────────────────────── -->
                  <?php $has_info = $job['location'] || $job['deadline'] || $job['salary_range'] || $job['department']; ?>
                  <?php if ($has_info): ?>
                  <div class="jd-info-bar">
                     <?php if ($job['location']): ?>
                     <span class="jd-info-chip">
                        <i class="fas fa-map-marker-alt"></i>
                        <?= fh($job['location']) ?>
                     </span>
                     <?php endif; ?>
                     <?php if ($job['deadline']): ?>
                     <span class="jd-info-chip chip-deadline">
                        <i class="fas fa-calendar-times"></i>
                        Apply by <?= fh(date('d M Y', strtotime($job['deadline']))) ?>
                     </span>
                     <?php endif; ?>
                     <?php if ($job['salary_range']): ?>
                     <span class="jd-info-chip chip-salary">
                        <i class="fas fa-money-bill-wave"></i>
                        <?= fh($job['salary_range']) ?>
                     </span>
                     <?php endif; ?>
                  </div>
                  <?php else: ?>
                  <div style="margin-top:24px;"></div>
                  <?php endif; ?>

                  <hr class="jd-divider">

                  <!-- Description -->
                  <h3 class="jd-section-heading">Job Description</h3>
                  <div class="jd-content mb-40">
                     <?= $job['description'] ?>
                  </div>

                  <!-- Requirements -->
                  <?php if (!empty($job['requirements'])): ?>
                  <hr class="jd-divider">
                  <h3 class="jd-section-heading">Requirements</h3>
                  <div class="jd-content mb-40">
                     <?= $job['requirements'] ?>
                  </div>
                  <?php endif; ?>

                  <!-- Mobile: show apply form hint -->
                  <div class="d-xl-none mt-20 p-3 text-center" style="background:#f8f9ff;border-radius:12px;border:1px dashed #c7d2fe;">
                     <p class="mb-2" style="font-size:.88rem;color:#4b5563;">Ready to apply? Scroll down for the application form.</p>
                     <a href="#apply-form" style="font-size:.88rem;font-weight:600;color:#f5a623;text-decoration:none;">
                        <i class="fas fa-arrow-down me-1"></i>Go to Application Form
                     </a>
                  </div>

               </div>
            </div><!-- .col -->

            <!-- ── Right: Application form ───────────────────────────── -->
            <div class="col-xl-4" id="apply-form">
               <div class="jd-sidebar-sticky">
                  <div class="jd-form-card">

                     <div class="jd-form-header">
                        <h5><i class="fas fa-paper-plane me-2" style="opacity:.8;"></i>Apply for this Position</h5>
                        <p>Fill in the form below and we'll be in touch</p>
                     </div>

                     <div class="jd-form-body">

                        <?php if ($form_success): ?>
                        <div class="jd-alert-success text-center">
                           <div class="jd-alert-icon"><i class="fas fa-check-circle"></i></div>
                           <strong style="font-size:1rem;">Application Submitted!</strong>
                           <p class="mt-8 mb-0" style="font-size:.88rem;">Thank you, <?= fh($_POST['full_name'] ?? 'candidate') ?>. We will be in touch shortly.</p>
                        </div>

                        <?php else: ?>

                        <?php if (!empty($form_errors)): ?>
                        <div class="jd-alert-error">
                           <div class="d-flex align-items-start gap-2">
                              <i class="fas fa-exclamation-circle mt-1" style="flex-shrink:0;"></i>
                              <ul>
                                 <?php foreach ($form_errors as $err): ?>
                                 <li><?= fh($err) ?></li>
                                 <?php endforeach; ?>
                              </ul>
                           </div>
                        </div>
                        <?php endif; ?>

                        <form method="POST" enctype="multipart/form-data">
                           <input type="hidden" name="_csrf" value="<?= fh($csrf_token) ?>">

                           <div class="jd-field">
                              <label>Full Name <span class="req">*</span></label>
                              <input type="text" name="full_name" class="form-control" required
                                     value="<?= fh($_POST['full_name'] ?? '') ?>"
                                     placeholder="e.g. Md. Faruk Ahmed" maxlength="200">
                           </div>

                           <div class="jd-field">
                              <label>Email Address <span class="req">*</span></label>
                              <input type="email" name="email" class="form-control" required
                                     value="<?= fh($_POST['email'] ?? '') ?>"
                                     placeholder="you@example.com" maxlength="200">
                           </div>

                           <div class="jd-field">
                              <label>Phone <span class="opt">(optional)</span></label>
                              <input type="tel" name="phone" class="form-control"
                                     value="<?= fh($_POST['phone'] ?? '') ?>"
                                     placeholder="+880 1xxx xxxxxx" maxlength="30">
                           </div>

                           <div class="jd-field">
                              <label>Cover Letter <span class="opt">(optional)</span></label>
                              <textarea name="cover_letter" class="form-control"
                                        placeholder="Tell us why you are a great fit for this role…"><?= fh($_POST['cover_letter'] ?? '') ?></textarea>
                           </div>

                           <div class="jd-field">
                              <label>Upload CV <span class="opt">(PDF/DOC/DOCX · max 5 MB)</span></label>
                              <div class="jd-file-upload-wrap" id="cvDropZone">
                                 <input type="file" name="cv" accept=".pdf,.doc,.docx" id="cvInput"
                                        onchange="jdShowFilename(this)">
                                 <div class="jd-file-upload-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                                 <div class="jd-file-upload-text"><strong>Click to upload</strong> or drag &amp; drop</div>
                                 <div class="jd-file-upload-text" style="margin-top:4px;">PDF, DOC or DOCX · up to 5 MB</div>
                                 <div class="jd-file-name-display" id="cvFilename"></div>
                              </div>
                           </div>

                           <div class="d-grid mt-4">
                              <button type="submit" class="jd-submit-btn">
                                 <i class="fas fa-paper-plane"></i>
                                 Submit Application
                              </button>
                           </div>
                        </form>

                        <?php endif; ?>

                     </div><!-- .jd-form-body -->
                  </div><!-- .jd-form-card -->
               </div><!-- .jd-sidebar-sticky -->
            </div><!-- .col -->

         </div><!-- .row -->

         <?php endif; ?>

      </div><!-- .container -->
   </div>
   <!-- job-detail-area-end -->

   <script>
   function jdShowFilename(input) {
      const display = document.getElementById('cvFilename');
      if (input.files && input.files.length > 0) {
         display.textContent = '📎 ' + input.files[0].name;
         display.style.display = 'block';
      } else {
         display.style.display = 'none';
      }
   }
   </script>

   </main>

<?php include __DIR__ . '/includes/footer.php'; ?>

   <!-- JS Libraries -->
   <?php include __DIR__ . '/includes/scripts.php'; ?>

</body>
</html>
