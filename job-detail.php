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

   <link rel="shortcut icon" type="image/x-icon" href="assets/img/logo/favicon.png">

   <!-- CSS Here -->
   <link rel="stylesheet" href="assets/css/bootstrap.min.css">
   <link rel="stylesheet" href="assets/css/font-awesome-pro.css">
   <link rel="stylesheet" href="assets/css/swiper-bundle.min.css">
   <link rel="stylesheet" href="assets/css/slick.css">
   <link rel="stylesheet" href="assets/css/magnific-popup.css">
   <link rel="stylesheet" href="assets/css/nice-select.css">
   <link rel="stylesheet" href="assets/css/custom-animation.css">

   <!-- Theme / Main CSS -->
   <link rel="stylesheet" href="assets/css/spacing.css">
   <link rel="stylesheet" href="assets/css/main.css">
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
               <img src="assets/img/logo/logo-black.png" alt="Prime University">
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
                  <a href="mailto:info@primeuniversity.edu.bd">info@primeuniversity.edu.bd</a>
               </div>
            </div>
            <div class="it-info-wrapper mb-20 d-flex align-items-center">
               <div class="itoffcanvas__info-icon">
                  <a href="#"><i class="fal fa-phone-alt"></i></a>
               </div>
               <div class="itoffcanvas__info-address">
                  <span>Phone</span>
                  <a href="tel:+8801710996196">+880-1710996196</a>
               </div>
            </div>
            <div class="it-info-wrapper mb-20 d-flex align-items-center">
               <div class="itoffcanvas__info-icon">
                  <a href="#"><i class="fas fa-map-marker-alt"></i></a>
               </div>
               <div class="itoffcanvas__info-address">
                  <span>Location</span>
                  <a href="https://www.google.com/maps/@23.7934913,90.3547073,15z" target="_blank">114, 116 Mazar Rd, Dhaka 1216</a>
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
      <img class="it-breadcrumb-shape-1" src="assets/img/shape/breadcrumb-1-1.png" alt="">
      <img class="it-breadcrumb-shape-3" src="assets/img/shape/breadcrumb-1-2.png" alt="">
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
   <div class="postbox-area pt-120 pb-120">
      <div class="container">

         <?php if (!$job): ?>
         <div class="row justify-content-center">
            <div class="col-xl-8 text-center py-60">
               <i class="fas fa-search fa-3x mb-30" style="color:#ccc;"></i>
               <h4 class="mb-15">Job Not Found</h4>
               <p class="mb-30 text-muted">This position is no longer available or the link is incorrect.</p>
               <a href="<?= fh(SITE_URL) ?>/jobs.php" class="it-btn-yellow border-radius-100">
                  <span>
                     <span class="text-1">← View All Jobs</span>
                     <span class="text-2">← View All Jobs</span>
                  </span>
               </a>
            </div>
         </div>

         <?php else: ?>

         <div class="row g-5">

            <!-- Left: Job detail -->
            <div class="col-xl-8">
               <div class="postbox-details-wrapper it-career-details-area">

                  <div class="postbox-content-box">
                     <div class="d-flex flex-wrap gap-2 align-items-center mb-20">
                        <?php $badge_cls = $type_badge[$job['job_type']] ?? 'bg-secondary'; ?>
                        <span class="badge <?= $badge_cls ?> fs-6"><?= fh(ucfirst($job['job_type'])) ?></span>
                        <?php if ($job['department']): ?>
                        <span class="badge bg-light text-dark border"><?= fh($job['department']) ?></span>
                        <?php endif; ?>
                     </div>

                     <h4 class="it-section-title mb-25"><?= fh($job['title']) ?></h4>

                     <!-- Job metadata -->
                     <div class="d-flex flex-wrap gap-4 mb-35 pb-30" style="border-bottom:1px solid #e8e8e8;font-size:.9rem;color:#6b7280;">
                        <?php if ($job['department']): ?>
                        <div><i class="fas fa-building me-2"></i><strong>Department:</strong> <?= fh($job['department']) ?></div>
                        <?php endif; ?>
                        <?php if ($job['location']): ?>
                        <div><i class="fas fa-map-marker-alt me-2"></i><strong>Location:</strong> <?= fh($job['location']) ?></div>
                        <?php endif; ?>
                        <?php if ($job['deadline']): ?>
                        <div><i class="fas fa-calendar-alt me-2"></i><strong>Deadline:</strong> <?= fh(date('d M Y', strtotime($job['deadline']))) ?></div>
                        <?php endif; ?>
                        <?php if ($job['salary_range']): ?>
                        <div><i class="fas fa-money-bill-wave me-2"></i><strong>Salary:</strong> <?= fh($job['salary_range']) ?></div>
                        <?php endif; ?>
                     </div>

                     <!-- Description -->
                     <h5 class="mb-20">Job Description</h5>
                     <div class="postbox-dsc mb-40">
                        <?= $job['description'] ?>
                     </div>

                     <!-- Requirements -->
                     <?php if (!empty($job['requirements'])): ?>
                     <h5 class="mb-20">Requirements</h5>
                     <div class="postbox-dsc mb-40">
                        <?= $job['requirements'] ?>
                     </div>
                     <?php endif; ?>

                     <div class="mt-20">
                        <a href="<?= fh(SITE_URL) ?>/jobs.php" class="it-btn-yellow border-radius-100">
                           <span>
                              <span class="text-1">← View All Jobs</span>
                              <span class="text-2">← View All Jobs</span>
                           </span>
                        </a>
                     </div>
                  </div>

               </div>
            </div><!-- .col -->

            <!-- Right: Application form -->
            <div class="col-xl-4">
               <div class="p-4" style="border:1px solid #e8e8e8;border-radius:12px;background:#fff;position:sticky;top:100px;">

                  <h5 class="mb-25 fw-bold"><i class="fas fa-paper-plane me-2 text-muted"></i>Apply for this Position</h5>

                  <?php if ($form_success): ?>
                  <div class="alert alert-success border-0" style="border-radius:10px;">
                     <i class="fas fa-check-circle me-2"></i>
                     <strong>Application submitted!</strong><br>
                     Thank you, we will be in touch shortly.
                  </div>
                  <?php else: ?>

                  <?php if (!empty($form_errors)): ?>
                  <div class="alert alert-danger border-0 mb-20" style="border-radius:10px;">
                     <ul class="mb-0 ps-3">
                        <?php foreach ($form_errors as $err): ?>
                        <li><?= fh($err) ?></li>
                        <?php endforeach; ?>
                     </ul>
                  </div>
                  <?php endif; ?>

                  <form method="POST" enctype="multipart/form-data" novalidate>
                     <input type="hidden" name="_csrf" value="<?= fh($csrf_token) ?>">

                     <div class="mb-3">
                        <label class="form-label fw-medium">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" class="form-control" required
                               value="<?= fh($_POST['full_name'] ?? '') ?>"
                               placeholder="Your full name" maxlength="200"
                               style="border-radius:8px;">
                     </div>

                     <div class="mb-3">
                        <label class="form-label fw-medium">Email Address <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" required
                               value="<?= fh($_POST['email'] ?? '') ?>"
                               placeholder="you@example.com" maxlength="200"
                               style="border-radius:8px;">
                     </div>

                     <div class="mb-3">
                        <label class="form-label fw-medium">Phone <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="tel" name="phone" class="form-control"
                               value="<?= fh($_POST['phone'] ?? '') ?>"
                               placeholder="+880 1xxx xxxxxx" maxlength="30"
                               style="border-radius:8px;">
                     </div>

                     <div class="mb-3">
                        <label class="form-label fw-medium">Cover Letter <span class="text-muted fw-normal">(optional)</span></label>
                        <textarea name="cover_letter" class="form-control" rows="5"
                                  placeholder="Tell us why you are a great fit…"
                                  style="border-radius:8px;"><?= fh($_POST['cover_letter'] ?? '') ?></textarea>
                     </div>

                     <div class="mb-4">
                        <label class="form-label fw-medium">Upload CV <span class="text-muted fw-normal">(PDF/DOC/DOCX, max 5 MB)</span></label>
                        <input type="file" name="cv" class="form-control" accept=".pdf,.doc,.docx"
                               style="border-radius:8px;">
                     </div>

                     <div class="d-grid">
                        <button type="submit" class="it-btn-yellow border-radius-100"
                                style="border:none;padding:12px 24px;font-size:1rem;cursor:pointer;width:100%;">
                           <span>
                              <span class="text-1">Submit Application</span>
                              <span class="text-2">Submit Application</span>
                           </span>
                        </button>
                     </div>
                  </form>

                  <?php endif; ?>

               </div>
            </div><!-- .col -->

         </div><!-- .row -->

         <?php endif; ?>

      </div><!-- .container -->
   </div>
   <!-- job-detail-area-end -->

   </main>

   <footer>

   <!-- footer-area-start -->
   <section class="it-footer-wrap it-footer-style-2 fix">
      <div class="it-footer-area z-index-1 pt-120 pb-80" data-background="assets/img/shape/footer-bg-3-1.jpg">
         <img class="it-footer-shape-1 d-none d-xxl-block" src="assets/img/shape/footer-3-1.png" alt="">
         <img class="it-footer-shape-2" data-parallax='{"y": -200, "smoothness": 30}' src="assets/img/shape/footer-3-2.png" alt="">
         <div class="it-footer-border"><span></span></div>
         <div class="container">
            <div class="row">
               <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6 mb-50">
                  <div class="it-footer-widget it-footer-col-1-1">
                     <div class="it-footer-widget-logo mb-30">
                        <a href="<?= fh(SITE_URL) ?>/index.php"><img src="assets/img/logo/logo-black.png" alt="Prime University"></a>
                     </div>
                     <div class="it-footer-widget-text">
                        <p>Access expert-led courses designed to help you succeed in your career, all from the comfort of your home.</p>
                     </div>
                     <div class="it-footer-widget-btn">
                        <a href="contact-us.html" class="it-btn-yellow theme-bg border-radius-100">
                           <span>
                              <span class="text-1">Contact Us</span>
                              <span class="text-2">Contact Us</span>
                           </span>
                           <i>
                              <svg width="16" height="15" viewBox="0 0 16 15" fill="none" xmlns="http://www.w3.org/2000/svg">
                                 <path d="M15.0544 8.1364C15.4058 7.78492 15.4058 7.21508 15.0544 6.8636L9.3268 1.13604C8.97533 0.784567 8.40548 0.784567 8.05401 1.13604C7.70254 1.48751 7.70254 2.05736 8.05401 2.40883L13.1452 7.5L8.05401 12.5912C7.70254 12.9426 7.70254 13.5125 8.05401 13.864C8.40548 14.2154 8.97533 14.2154 9.3268 13.864L15.0544 8.1364ZM0.417969 7.5V8.4H14.418V7.5V6.6H0.417969V7.5Z" fill="currentcolor" />
                              </svg>
                           </i>
                        </a>
                     </div>
                  </div>
               </div>
               <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6 mb-50">
                  <div class="it-footer-widget it-footer-col-1-2">
                     <h4 class="it-footer-widget-title">Useful Links</h4>
                     <div class="it-footer-widget-menu">
                        <ul>
                           <li><a href="<?= fh(SITE_URL) ?>/index.php">Home</a></li>
                           <li><a href="about-us-v3.html">About Us</a></li>
                           <li><a href="courses-with-filter.html">Courses</a></li>
                           <li><a href="contact-us.html">Contact Us</a></li>
                        </ul>
                     </div>
                  </div>
               </div>
               <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6 mb-50">
                  <div class="it-footer-widget it-footer-col-1-3">
                     <h4 class="it-footer-widget-title">Our Company</h4>
                     <div class="it-footer-widget-menu">
                        <ul>
                           <li><a href="contact-us.html">Contact Us</a></li>
                           <li><a href="#">Become Teacher</a></li>
                           <li><a href="#">Events</a></li>
                        </ul>
                     </div>
                  </div>
               </div>
               <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6 mb-50">
                  <div class="it-footer-widget it-footer-col-1-4 d-flex justify-content-lg-end">
                     <div>
                        <h4 class="it-footer-widget-title">Get Contact</h4>
                        <div class="it-footer-widget-contact mb-25">
                           <ul>
                              <li><span>Phone:</span><a href="tel:01710996196">01710996196</a></li>
                              <li><span>Email:</span><a href="mailto:primeuniversity@gmail.com">primeuniversity@gmail.com</a></li>
                              <li><span>Location:</span><a target="_blank" href="https://www.google.com/maps/dir///@24.4503253,17.1644279,4.17z">114, 116 Mazar Rd, Dhaka 1216</a></li>
                           </ul>
                        </div>
                     </div>
                  </div>
               </div>
            </div>
         </div>
      </div>

      <!-- copyright-area-start -->
      <div class="it-copyright-area it-copyright-ptb it-copyright-bg z-index-1 theme-bg">
         <div class="container">
            <div class="row align-items-center">
               <div class="col-12">
                  <div class="it-copyright-left style-2 text-center">
                     <p class="mb-0">Copyright &copy; 2025 <a href="#">Prime University</a> All Rights Reserved</p>
                  </div>
               </div>
            </div>
         </div>
      </div>
      <!-- copyright-area-end -->

   </section>
   <!-- footer-area-end -->

   </footer>

   <!-- JS Libraries -->
   <script src="assets/js/jquery.js"></script>
   <script src="assets/js/bootstrap.bundle.min.js"></script>
   <script src="assets/js/purecounter.js"></script>
   <script src="assets/js/nice-select.js"></script>
   <script src="assets/js/swiper-bundle.min.js"></script>
   <script src="assets/js/slick.min.js"></script>
   <script src="assets/js/wow.js"></script>
   <script src="assets/js/magnific-popup.js"></script>
   <script src="assets/js/parallax.js"></script>

   <!-- Custom JS -->
   <script src="assets/js/main.js"></script>

</body>
</html>
