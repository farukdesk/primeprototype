<?php
require_once __DIR__ . '/includes/config.php';

$slug = trim($_GET['slug'] ?? '');
if ($slug === '') {
    header('Location: index.php');
    exit;
}

$db      = front_db();
$dept    = null;
$faculty = [];

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
        $st = $db->prepare('SELECT * FROM dept_faculty WHERE dept_id = ? AND is_active = 1 ORDER BY sort_order ASC, id ASC');
        $st->execute([$dept['id']]);
        $faculty = $st->fetchAll();
    } catch (Throwable $e) {}
}

// Separate head of department from regular faculty
$head_faculty   = array_filter($faculty, fn($f) => !empty($f['is_head']));
$regular_faculty = array_filter($faculty, fn($f) => empty($f['is_head']));

$current_page = 'faculty';
$base         = SITE_URL . '/department';
$dept_name    = fh($dept['name'] ?? 'Department');
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <title>Faculty Members – <?= $dept_name ?> – Prime University</title>
   <meta name="description" content="Meet the dedicated faculty members of <?= $dept_name ?> at Prime University.">
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
   .faculty-card { transition: transform 0.3s ease, box-shadow 0.3s ease; }
   .faculty-card:hover { transform: translateY(-5px); box-shadow: 0 15px 40px rgba(0,33,71,0.15) !important; }
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
                     <li class="breadcrumb-item active" style="color:#E8EEF4;">Faculty Members</li>
                  </ol>
               </nav>
               <h2 style="color:#FFFFFF; font-weight:700; margin-bottom:10px;">Faculty Members</h2>
               <p style="color:#E8EEF4; font-size:16px;"><?= $dept_name ?></p>
            </div>
         </div>
      </div>
   </div>

   <!-- Sub-navigation -->
   <?php include __DIR__ . '/includes/dept-subnav.php'; ?>

   <?php if (!empty($head_faculty)): ?>
   <!-- Head of Department -->
   <section class="pt-80 pb-60" style="background-color: #F8FAFC;">
      <div class="container">
         <div class="row justify-content-center mb-50">
            <div class="col-12 text-center">
               <span class="it-section-subtitle" style="color: #D21034;"><i class="fas fa-user-tie"></i> Leadership</span>
               <h4 class="it-section-title" style="color: #002147;">Head of Department</h4>
            </div>
         </div>
         <?php foreach ($head_faculty as $hf): ?>
         <div class="row align-items-center g-5 mb-50">
            <div class="col-xl-3 col-lg-4 text-center">
               <?php if (!empty($hf['photo'])): ?>
               <img src="<?= fh(ADMIN_UPLOAD_URL . '/departments/' . $hf['photo']) ?>"
                    alt="<?= fh($hf['name'] ?? '') ?>"
                    class="rounded-circle shadow"
                    style="width:180px; height:180px; object-fit:cover; border:4px solid #FFB81C;">
               <?php else: ?>
               <div style="width:180px; height:180px; border-radius:50%; background:#002147; display:inline-flex; align-items:center; justify-content:center; border:4px solid #FFB81C;">
                  <i class="fas fa-user-tie" style="font-size:70px; color:#FFB81C;"></i>
               </div>
               <?php endif; ?>
               <h5 class="mt-20 mb-5" style="color:#002147; font-weight:700;"><?= fh($hf['name'] ?? '') ?></h5>
               <p style="color:#D21034; font-size:14px; font-weight:600; margin-bottom:5px;"><?= fh($hf['designation'] ?? '') ?></p>
               <?php if (!empty($hf['email'])): ?>
               <p style="font-size:13px;"><a href="mailto:<?= fh($hf['email']) ?>" style="color:#334155;"><?= fh($hf['email']) ?></a></p>
               <?php endif; ?>
               <?php if (!empty($hf['edu_qualifications'])): ?>
               <p style="color:#334155; font-size:13px; line-height:1.6;"><?= nl2br(fh($hf['edu_qualifications'])) ?></p>
               <?php endif; ?>
            </div>
            <div class="col-xl-9 col-lg-8">
               <div class="card border-0 shadow-sm" style="border-left:4px solid #002147 !important;">
                  <div class="card-body p-40">
                     <?php if (!empty($hf['specialization'])): ?>
                     <p class="mb-10"><strong style="color:#002147;">Specialization:</strong> <span style="color:#334155;"><?= fh($hf['specialization']) ?></span></p>
                     <?php endif; ?>
                     <?php if (!empty($hf['message'])): ?>
                     <i class="fas fa-quote-left" style="font-size:36px; color:#FFB81C; opacity:0.4; margin-bottom:15px;"></i>
                     <p style="color:#334155; font-size:16px; line-height:1.9;"><?= nl2br(fh($hf['message'])) ?></p>
                     <?php endif; ?>
                  </div>
               </div>
            </div>
         </div>
         <?php endforeach; ?>
      </div>
   </section>
   <?php endif; ?>

   <!-- Faculty Grid -->
   <section class="pt-80 pb-100" style="background-color: #FFFFFF;">
      <div class="container">
         <div class="row justify-content-center mb-60">
            <div class="col-12 text-center">
               <span class="it-section-subtitle" style="color: #D21034;"><i class="fas fa-chalkboard-teacher"></i> Our Team</span>
               <h4 class="it-section-title" style="color: #002147;">Faculty Members</h4>
            </div>
         </div>
         <?php if (!empty($regular_faculty)): ?>
         <div class="row g-4">
            <?php foreach ($regular_faculty as $f): ?>
            <div class="col-xl-4 col-lg-4 col-md-6 wow itfadeUp" data-wow-duration=".9s">
               <div class="card faculty-card h-100 border-0 shadow-sm text-center" style="border-top:3px solid #002147 !important;">
                  <div class="card-body p-30">
                     <?php if (!empty($f['photo'])): ?>
                     <img src="<?= fh(ADMIN_UPLOAD_URL . '/departments/' . $f['photo']) ?>"
                          alt="<?= fh($f['name'] ?? '') ?>"
                          class="rounded-circle mb-20"
                          style="width:110px; height:110px; object-fit:cover; border:3px solid #FFB81C;">
                     <?php else: ?>
                     <div class="rounded-circle mb-20 mx-auto d-flex align-items-center justify-content-center"
                          style="width:110px; height:110px; background:#F8FAFC; border:3px solid #FFB81C;">
                        <i class="fas fa-user" style="font-size:44px; color:#002147;"></i>
                     </div>
                     <?php endif; ?>
                     <h6 style="color:#002147; font-weight:700; margin-bottom:6px;"><?= fh($f['name'] ?? '') ?></h6>
                     <p style="color:#D21034; font-size:13px; font-weight:600; margin-bottom:6px;"><?= fh($f['designation'] ?? '') ?></p>
                     <?php if (!empty($f['specialization'])): ?>
                     <p style="color:#334155; font-size:13px; margin-bottom:6px;"><i class="fas fa-flask me-1" style="color:#FFB81C;"></i><?= fh($f['specialization']) ?></p>
                     <?php endif; ?>
                     <?php if (!empty($f['email'])): ?>
                     <p style="font-size:13px; margin-bottom:0;"><a href="mailto:<?= fh($f['email']) ?>" style="color:#002147;"><i class="fas fa-envelope me-1" style="color:#FFB81C;"></i><?= fh($f['email']) ?></a></p>
                     <?php endif; ?>
                  </div>
               </div>
            </div>
            <?php endforeach; ?>
         </div>
         <?php else: ?>
         <div class="row">
            <div class="col-12 text-center py-80">
               <i class="fas fa-chalkboard-teacher" style="font-size:64px; color:#002147; opacity:0.2; display:block; margin-bottom:20px;"></i>
               <p style="color:#334155; font-size:17px;">Faculty information will be available soon.</p>
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
                     <div class="it-footer-widget-logo mb-30">
                        <a href="<?= fh(SITE_URL) ?>/index.php"><img src="assets/img/logo/logo-black.png" alt="Prime University"></a>
                     </div>
                     <div class="it-footer-widget-text">
                        <p>Access expert-led courses designed to help you succeed in your career, all from the comfort of your home.</p>
                     </div>
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
