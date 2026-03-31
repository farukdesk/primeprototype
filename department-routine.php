<?php
require_once __DIR__ . '/includes/config.php';

$slug = trim($_GET['slug'] ?? '');
if ($slug === '') {
    header('Location: index.php');
    exit;
}

$db       = front_db();
$dept     = null;
$routines = [];

if ($db) {
    try {
        $st = $db->prepare('SELECT * FROM dept_departments WHERE slug = ? AND is_active = 1 LIMIT 1');
        $st->execute([$slug]);
        $dept = $st->fetch() ?: null;
    } catch (Throwable $e) {}
}

if (!$dept) {
    header('Location: index.php');
    exit;
}

if ($db) {
    try {
        $st = $db->prepare('SELECT * FROM dept_routines WHERE dept_id = ? AND is_active = 1 ORDER BY type ASC, effective_from DESC, id DESC');
        $st->execute([$dept['id']]);
        $routines = $st->fetchAll();
    } catch (Throwable $e) {}
}

// Group routines by type
$grouped = [];
foreach ($routines as $r) {
    $type = $r['type'] ?? 'Other';
    $grouped[$type][] = $r;
}

$current_page = 'routine';
$base         = SITE_URL . '/department';
$dept_name    = fh($dept['name'] ?? 'Department');
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <title>Class/Exam Routine – <?= $dept_name ?> – Prime University</title>
   <meta name="description" content="Class and exam routines for <?= $dept_name ?> at Prime University.">
   <meta name="viewport" content="width=device-width, initial-scale=1">
   <link rel="shortcut icon" type="image/x-icon" href="assets/img/logo/favicon.png">
   <link rel="stylesheet" href="assets/css/bootstrap.min.css">
   <link rel="stylesheet" href="assets/css/font-awesome-pro.css">
   <link rel="stylesheet" href="assets/css/swiper-bundle.min.css">
   <link rel="stylesheet" href="assets/css/slick.css">
   <link rel="stylesheet" href="assets/css/magnific-popup.css">
   <link rel="stylesheet" href="assets/css/nice-select.css">
   <link rel="stylesheet" href="assets/css/custom-animation.css">
   <link rel="stylesheet" href="assets/css/spacing.css">
   <link rel="stylesheet" href="assets/css/main.css">
   <style>
   .it-dept-subnav { background-color: #002147; position: sticky; top: 0; z-index: 999; border-bottom: 3px solid #D21034; }
   .dept-subnav-inner { display: flex; overflow-x: auto; }
   .dept-subnav-inner ul { display: flex; list-style: none; margin: 0; padding: 0; flex-wrap: nowrap; gap: 0; }
   .dept-subnav-inner ul li a { display: block; color: #E8EEF4; text-decoration: none; padding: 14px 20px; font-size: 14px; font-weight: 500; white-space: nowrap; border-bottom: 3px solid transparent; transition: all 0.3s ease; }
   .dept-subnav-inner ul li a:hover, .dept-subnav-inner ul li a.active { color: #FFB81C; border-bottom-color: #FFB81C; background-color: rgba(255,255,255,0.05); }
   @media (max-width: 768px) { .dept-subnav-inner ul li a { padding: 12px 14px; font-size: 13px; } }
   .routine-card { transition: transform 0.3s ease, box-shadow 0.3s ease; }
   .routine-card:hover { transform: translateY(-3px); box-shadow: 0 10px 30px rgba(0,33,71,0.12) !important; }
   .type-badge-class { background-color: #002147; color: #FFB81C; }
   .type-badge-exam  { background-color: #D21034; color: #FFFFFF; }
   .type-badge-other { background-color: #334155; color: #FFFFFF; }
   </style>
</head>
<body id="body" class="it-magic-cursor">

   <div id="preloader"><div class="preloader"><span></span><span></span></div></div>
   <div id="magic-cursor"><div id="ball"></div></div>
   <button class="scroll-top scroll-to-target" data-target="html"><i class="far fa-angle-double-up"></i></button>

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
         <div class="itoffcanvas__close-btn"><button class="close-btn"><i class="fal fa-times"></i></button></div>
         <div class="itoffcanvas__logo">
            <a href="<?= fh(SITE_URL) ?>/index.php"><img src="assets/img/logo/logo-black.png" alt=""></a>
         </div>
         <div class="it-menu-mobile d-xl-none"></div>
         <div class="itoffcanvas__info">
            <h3 class="offcanva-title">Get In Touch</h3>
            <div class="it-info-wrapper mb-20 d-flex align-items-center">
               <div class="itoffcanvas__info-icon"><a href="#"><i class="fal fa-envelope"></i></a></div>
               <div class="itoffcanvas__info-address"><span>Email</span><a href="mailto:info@primeuniversity.edu.bd">info@primeuniversity.edu.bd</a></div>
            </div>
            <div class="it-info-wrapper mb-20 d-flex align-items-center">
               <div class="itoffcanvas__info-icon"><a href="#"><i class="fal fa-phone-alt"></i></a></div>
               <div class="itoffcanvas__info-address"><span>Phone</span><a href="tel:+8801710996196">+880-1710996196</a></div>
            </div>
            <div class="it-info-wrapper mb-20 d-flex align-items-center">
               <div class="itoffcanvas__info-icon"><a href="#"><i class="fas fa-map-marker-alt"></i></a></div>
               <div class="itoffcanvas__info-address"><span>Location</span><a href="https://www.google.com/maps/@37.4801311,22.8928877,3z" target="_blank">114, 116 Mazar Rd, Dhaka 1216</a></div>
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

   <!-- Banner -->
   <div style="background: linear-gradient(135deg, #002147 0%, #003366 100%); padding: 80px 0 60px;">
      <div class="container">
         <div class="row">
            <div class="col-12">
               <nav aria-label="breadcrumb" class="mb-20">
                  <ol class="breadcrumb" style="background:transparent; padding:0; margin:0;">
                     <li class="breadcrumb-item"><a href="<?= fh(SITE_URL) ?>/index.php" style="color:#FFB81C;">Home</a></li>
                     <li class="breadcrumb-item"><a href="<?= fh(SITE_URL) ?>/department.php?slug=<?= urlencode($slug) ?>" style="color:#E8EEF4;"><?= $dept_name ?></a></li>
                     <li class="breadcrumb-item active" style="color:#E8EEF4;">Class/Exam Routine</li>
                  </ol>
               </nav>
               <h2 style="color:#FFFFFF; font-weight:700; margin-bottom:10px;">Class / Exam Routine</h2>
               <p style="color:#E8EEF4; font-size:16px;"><?= $dept_name ?></p>
            </div>
         </div>
      </div>
   </div>

   <!-- Sub-navigation -->
   <?php include __DIR__ . '/includes/dept-subnav.php'; ?>

   <!-- Routines -->
   <section class="pt-100 pb-120" style="background-color: #FFFFFF;">
      <div class="container">
         <div class="row justify-content-center mb-60">
            <div class="col-12 text-center">
               <span class="it-section-subtitle" style="color: #D21034;"><i class="fas fa-calendar-check"></i> Schedules</span>
               <h4 class="it-section-title" style="color: #002147;">Class &amp; Exam Routines</h4>
            </div>
         </div>

         <?php if (!empty($routines)): ?>
            <?php foreach ($grouped as $type => $items): ?>
            <div class="mb-60">
               <h5 class="mb-30 pb-15" style="color:#002147; font-weight:700; border-bottom:2px solid #FFB81C; display:inline-block; padding-right:20px;">
                  <i class="fas <?= strtolower($type) === 'exam' ? 'fa-file-alt' : 'fa-clock' ?> me-2" style="color:#D21034;"></i>
                  <?= fh(ucfirst($type)) ?> Routines
               </h5>
               <div class="row g-4">
                  <?php foreach ($items as $rt): ?>
                  <div class="col-xl-4 col-lg-6 wow itfadeUp" data-wow-duration=".9s">
                     <div class="card routine-card h-100 border-0 shadow-sm" style="border-left:4px solid <?= strtolower($type) === 'exam' ? '#D21034' : '#002147' ?> !important;">
                        <div class="card-body p-30">
                           <div class="d-flex align-items-start justify-content-between mb-15 flex-wrap gap-2">
                              <h6 style="color:#002147; font-weight:700; margin-bottom:0;"><?= fh($rt['title'] ?? '') ?></h6>
                              <span class="badge type-badge-<?= strtolower(preg_replace('/[^a-z]/i', '', $type)) === 'exam' ? 'exam' : 'class' ?>" style="font-size:11px; padding:5px 10px; border-radius:20px;">
                                 <?= fh(ucfirst($type)) ?>
                              </span>
                           </div>
                           <?php if (!empty($rt['semester'])): ?>
                           <p style="color:#334155; font-size:13px; margin-bottom:6px;"><i class="fas fa-book me-1" style="color:#FFB81C;"></i><strong>Semester:</strong> <?= fh($rt['semester']) ?></p>
                           <?php endif; ?>
                           <?php if (!empty($rt['section'])): ?>
                           <p style="color:#334155; font-size:13px; margin-bottom:6px;"><i class="fas fa-users me-1" style="color:#FFB81C;"></i><strong>Section:</strong> <?= fh($rt['section']) ?></p>
                           <?php endif; ?>
                           <?php if (!empty($rt['effective_from'])): ?>
                           <p style="color:#334155; font-size:13px; margin-bottom:6px;">
                              <i class="fas fa-calendar me-1" style="color:#FFB81C;"></i><strong>Effective:</strong>
                              <?= fh(date('d M, Y', strtotime($rt['effective_from']))) ?>
                           </p>
                           <?php endif; ?>
                           <?php if (!empty($rt['file_path'])): ?>
                           <div class="mt-20">
                              <a href="<?= fh(ADMIN_UPLOAD_URL . '/departments/' . $rt['file_path']) ?>"
                                 class="it-btn-yellow border-radius-100"
                                 style="font-size:13px; display:inline-flex; align-items:center; gap:6px;"
                                 target="_blank" rel="noopener">
                                 <i class="fas fa-download"></i>
                                 <span><span class="text-1">Download</span><span class="text-2">Download</span></span>
                              </a>
                           </div>
                           <?php endif; ?>
                        </div>
                     </div>
                  </div>
                  <?php endforeach; ?>
               </div>
            </div>
            <?php endforeach; ?>
         <?php else: ?>
         <div class="row">
            <div class="col-12 text-center py-80">
               <i class="fas fa-calendar-alt" style="font-size:64px; color:#002147; opacity:0.2; display:block; margin-bottom:20px;"></i>
               <p style="color:#334155; font-size:17px;">Routines are published at the start of each semester.</p>
               <p style="color:#334155; font-size:15px;">Please check back later or contact the department office.</p>
            </div>
         </div>
         <?php endif; ?>
      </div>
   </section>

   </main>

   <footer>
   <section class="it-footer-wrap it-footer-style-2 fix">
      <div class="it-footer-area z-index-1 pt-200 pb-80" data-background="assets/img/shape/footer-bg-3-1.jpg">
         <img class="it-footer-shape-1 d-none d-xxl-block" src="assets/img/shape/footer-3-1.png" alt="">
         <img class="it-footer-shape-2" data-parallax='{"y": -200, "smoothness": 30}' src="assets/img/shape/footer-3-2.png" alt="">
         <div class="it-footer-border"><span></span></div>
         <div class="container">
            <div class="row">
               <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6 mb-50">
                  <div class="it-footer-widget it-footer-col-1-1">
                     <div class="it-footer-widget-logo mb-30"><a href="<?= fh(SITE_URL) ?>/index.php"><img src="assets/img/logo/logo-black.png" alt="Prime University"></a></div>
                     <div class="it-footer-widget-text"><p>Access expert-led courses designed to help you succeed in your career, all from the comfort of your home.</p></div>
                     <div class="it-footer-widget-btn">
                        <a href="contact-us.html" class="it-btn-yellow theme-bg border-radius-100">
                           <span><span class="text-1">Contact Us</span><span class="text-2">Contact Us</span></span>
                           <i><svg width="16" height="15" viewBox="0 0 16 15" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M15.0544 8.1364C15.4058 7.78492 15.4058 7.21508 15.0544 6.8636L9.3268 1.13604C8.97533 0.784567 8.40548 0.784567 8.05401 1.13604C7.70254 1.48751 7.70254 2.05736 8.05401 2.40883L13.1452 7.5L8.05401 12.5912C7.70254 12.9426 7.70254 13.5125 8.05401 13.864C8.40548 14.2154 8.97533 14.2154 9.3268 13.864L15.0544 8.1364ZM0.417969 7.5V8.4H14.418V7.5V6.6H0.417969V7.5Z" fill="currentcolor"/></svg></i>
                        </a>
                     </div>
                  </div>
               </div>
               <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6 mb-50">
                  <div class="it-footer-widget it-footer-col-1-2">
                     <h4 class="it-footer-widget-title">Useful Links</h4>
                     <div class="it-footer-widget-menu"><ul><li><a href="#">Marketplace</a></li><li><a href="#">University</a></li><li><a href="#">GYM Coaching</a></li><li><a href="#">Cooking</a></li></ul></div>
                  </div>
               </div>
               <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6 mb-50">
                  <div class="it-footer-widget it-footer-col-1-3">
                     <h4 class="it-footer-widget-title">Our Company</h4>
                     <div class="it-footer-widget-menu"><ul><li><a href="#">Contact Us</a></li><li><a href="#">Become Teacher</a></li><li><a href="#">Blog</a></li><li><a href="#">Instructor</a></li><li><a href="#">Events</a></li></ul></div>
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
      <div class="it-copyright-area it-copyright-ptb it-copyright-bg z-index-1 theme-bg">
         <div class="container">
            <div class="row align-items-center">
               <div class="col-12">
                  <div class="it-copyright-left style-2 text-center">
                     <p class="mb-0">Copyright &copy; <?= date('Y') ?> <a href="#">Prime University</a> All Rights Reserved</p>
                  </div>
               </div>
            </div>
         </div>
      </div>
   </section>
   </footer>

   <script src="assets/js/jquery.js"></script>
   <script src="assets/js/bootstrap.bundle.min.js"></script>
   <script src="assets/js/purecounter.js"></script>
   <script src="assets/js/range-slider.js"></script>
   <script src="assets/js/nice-select.js"></script>
   <script src="assets/js/swiper-bundle.min.js"></script>
   <script src="assets/js/isotope-pkgd.js"></script>
   <script src="assets/js/slick.min.js"></script>
   <script src="assets/js/wow.js"></script>
   <script src="assets/js/countdown.js"></script>
   <script src="assets/js/magnific-popup.js"></script>
   <script src="assets/js/imagesloaded-pkgd.js"></script>
   <script src="assets/js/parallax.js"></script>
   <script src="assets/js/slider.js"></script>
   <script src="assets/js/main.js"></script>
</body>
</html>
