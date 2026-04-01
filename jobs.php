<?php
require_once __DIR__ . '/includes/config.php';

$page_title = 'Career Opportunities – Prime University';

$jobs = [];
try {
    $db = front_db();
    if ($db) {
        $stmt = $db->query(
            "SELECT * FROM jobs
             WHERE is_published = 1
               AND (deadline IS NULL OR deadline >= CURDATE())
             ORDER BY created_at DESC"
        );
        $jobs = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    // silently fall through
}
?>
<!doctype html>
<html class="no-js" lang="en">

<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <title><?= fh($page_title) ?></title>
   <meta name="description" content="Explore career opportunities at Prime University.">
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
                     <h3 class="it-breadcrumb-title style-2">Career Opportunities</h3>
                  </div>
                  <div class="it-breadcrumb-list">
                     <ul>
                        <li><a href="<?= fh(SITE_URL) ?>/index.php">Home</a></li>
                        <li><span>Career Opportunities</span></li>
                     </ul>
                  </div>
               </div>
            </div>
         </div>
      </div>
   </div>
   <!-- breadcrumb-area-end -->

   <!-- jobs-area-start -->
   <div class="postbox-area pt-120 pb-120">
      <div class="container">

         <?php if (empty($jobs)): ?>
         <div class="row justify-content-center">
            <div class="col-xl-8 text-center py-60">
               <i class="fas fa-briefcase fa-3x mb-30" style="color:#ccc;"></i>
               <h4 class="mb-15">No Vacancies at the Moment</h4>
               <p class="mb-30 text-muted">There are currently no open positions. Please check back later.</p>
               <a href="<?= fh(SITE_URL) ?>/index.php" class="it-btn-yellow border-radius-100">
                  <span>
                     <span class="text-1">← Back to Home</span>
                     <span class="text-2">← Back to Home</span>
                  </span>
               </a>
            </div>
         </div>
         <?php else: ?>

         <div class="row">
            <div class="col-12 mb-50">
               <div class="it-section-title-box text-center">
                  <h3 class="it-section-title">Open Positions</h3>
               </div>
            </div>
         </div>

         <div class="row g-4">
            <?php
            $type_badge = [
                'full-time'  => 'bg-primary',
                'part-time'  => 'bg-secondary',
                'contract'   => 'bg-warning text-dark',
                'internship' => 'bg-info text-dark',
            ];
            foreach ($jobs as $job):
                $excerpt    = mb_strimwidth(strip_tags($job['description']), 0, 200, '…');
                $badge_cls  = $type_badge[$job['job_type']] ?? 'bg-secondary';
                $detail_url = fh(SITE_URL) . '/job-detail.php?slug=' . urlencode($job['slug']);
            ?>
            <div class="col-xl-6 col-lg-6">
               <div class="it-blog-grid-item mb-30 p-30"
                    style="border:1px solid #e8e8e8;border-radius:12px;height:100%;background:#fff;">
                  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-15">
                     <h5 class="mb-0" style="font-size:1.1rem;">
                        <a href="<?= $detail_url ?>" class="text-decoration-none text-dark">
                           <?= fh($job['title']) ?>
                        </a>
                     </h5>
                     <span class="badge <?= $badge_cls ?>"><?= fh(ucfirst($job['job_type'])) ?></span>
                  </div>

                  <div class="d-flex flex-wrap gap-3 mb-15" style="font-size:.875rem;color:#6b7280;">
                     <?php if ($job['department']): ?>
                     <span><i class="fas fa-building me-1"></i><?= fh($job['department']) ?></span>
                     <?php endif; ?>
                     <?php if ($job['location']): ?>
                     <span><i class="fas fa-map-marker-alt me-1"></i><?= fh($job['location']) ?></span>
                     <?php endif; ?>
                     <?php if ($job['deadline']): ?>
                     <span><i class="fas fa-calendar-alt me-1"></i>Deadline: <?= fh(date('d M Y', strtotime($job['deadline']))) ?></span>
                     <?php endif; ?>
                  </div>

                  <p class="mb-20" style="color:#6b7280;font-size:.9rem;"><?= fh($excerpt) ?></p>

                  <a href="<?= $detail_url ?>" class="it-btn-yellow border-radius-100">
                     <span>
                        <span class="text-1">Apply Now</span>
                        <span class="text-2">Apply Now</span>
                     </span>
                  </a>
               </div>
            </div>
            <?php endforeach; ?>
         </div>

         <?php endif; ?>

      </div>
   </div>
   <!-- jobs-area-end -->

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
