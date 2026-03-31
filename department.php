<?php
require_once __DIR__ . '/includes/config.php';

$slug = trim($_GET['slug'] ?? '');
if ($slug === '') {
    header('Location: index.php');
    exit;
}

$db       = front_db();
$dept     = null;
$overview = null;
$alumni   = [];
$events   = [];

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
        $st = $db->prepare('SELECT * FROM dept_overview WHERE dept_id = ? LIMIT 1');
        $st->execute([$dept['id']]);
        $overview = $st->fetch() ?: null;
    } catch (Throwable $e) {}

    try {
        $st = $db->prepare('SELECT * FROM dept_alumni WHERE dept_id = ? AND is_active = 1 ORDER BY sort_order ASC, id ASC LIMIT 4');
        $st->execute([$dept['id']]);
        $alumni = $st->fetchAll();
    } catch (Throwable $e) {}

    try {
        $st = $db->prepare('SELECT * FROM dept_events WHERE dept_id = ? AND is_active = 1 ORDER BY event_date ASC LIMIT 3');
        $st->execute([$dept['id']]);
        $events = $st->fetchAll();
    } catch (Throwable $e) {}
}

$current_page = 'overview';
$base         = SITE_URL . '/department';
$page_title   = fh($dept['hero_title'] ?? $dept['name'] ?? 'Department');
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <title><?= $page_title ?> – Prime University</title>
   <meta name="description" content="<?= fh($dept['hero_description'] ?? '') ?>">
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
   .it-dept-subnav {
      background-color: #002147;
      position: sticky;
      top: 0;
      z-index: 999;
      border-bottom: 3px solid #D21034;
   }
   .dept-subnav-inner { display: flex; overflow-x: auto; }
   .dept-subnav-inner ul {
      display: flex;
      list-style: none;
      margin: 0;
      padding: 0;
      flex-wrap: nowrap;
      gap: 0;
   }
   .dept-subnav-inner ul li a {
      display: block;
      color: #E8EEF4;
      text-decoration: none;
      padding: 14px 20px;
      font-size: 14px;
      font-weight: 500;
      white-space: nowrap;
      border-bottom: 3px solid transparent;
      transition: all 0.3s ease;
   }
   .dept-subnav-inner ul li a:hover,
   .dept-subnav-inner ul li a.active {
      color: #FFB81C;
      border-bottom-color: #FFB81C;
      background-color: rgba(255,255,255,0.05);
   }
   @media (max-width: 768px) {
      .dept-subnav-inner ul li a { padding: 12px 14px; font-size: 13px; }
   }
   </style>
</head>
<body id="body" class="it-magic-cursor">

   <!-- preloader -->
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
               <img src="assets/img/logo/logo-black.png" alt="">
            </a>
         </div>
         <div class="it-menu-mobile d-xl-none"></div>
         <div class="itoffcanvas__info">
            <h3 class="offcanva-title">Get In Touch</h3>
            <div class="it-info-wrapper mb-20 d-flex align-items-center">
               <div class="itoffcanvas__info-icon"><a href="#"><i class="fal fa-envelope"></i></a></div>
               <div class="itoffcanvas__info-address">
                  <span>Email</span>
                  <a href="mailto:info@primeuniversity.edu.bd">info@primeuniversity.edu.bd</a>
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
                  <a href="https://www.google.com/maps/@37.4801311,22.8928877,3z" target="_blank">114, 116 Mazar Rd, Dhaka 1216</a>
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

   <!-- Hero Section -->
   <section class="it-slider-area" style="background: linear-gradient(135deg, #F8FAFC 0%, #E8EEF4 100%);">
      <div class="container">
         <div class="row align-items-center" style="min-height: 500px;">
            <div class="col-xxl-8 col-xl-7 col-lg-7">
               <div class="it-slider-content py-5">
                  <span class="it-section-subtitle" style="color: #D21034; font-weight: 600;">
                     <i class="fas fa-graduation-cap" style="margin-right: 8px;"></i>
                     <?= fh($dept['faculty_label'] ?? '') ?>
                  </span>
                  <h1 class="it-slider-title mb-25" style="color: #002147; font-size: clamp(28px,4vw,48px); font-weight: 700; line-height: 1.2;">
                     <?= fh($dept['hero_title'] ?? $dept['name'] ?? '') ?>
                  </h1>
                  <p class="mb-40" style="color: #334155; font-size: 18px; line-height: 1.8;">
                     <?= fh($dept['hero_description'] ?? '') ?>
                  </p>
                  <?php if (!empty($dept['cta_url'])): ?>
                  <a href="<?= fh($dept['cta_url']) ?>" class="it-btn-yellow theme-bg border-radius-100">
                     <span>
                        <span class="text-1"><?= fh($dept['cta_text'] ?? 'Apply Now') ?></span>
                        <span class="text-2"><?= fh($dept['cta_text'] ?? 'Apply Now') ?></span>
                     </span>
                     <i><svg width="16" height="15" viewBox="0 0 16 15" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M15.0544 8.1364C15.4058 7.78492 15.4058 7.21508 15.0544 6.8636L9.3268 1.13604C8.97533 0.784567 8.40548 0.784567 8.05401 1.13604C7.70254 1.48751 7.70254 2.05736 8.05401 2.40883L13.1452 7.5L8.05401 12.5912C7.70254 12.9426 7.70254 13.5125 8.05401 13.864C8.40548 14.2154 8.97533 14.2154 9.3268 13.864L15.0544 8.1364ZM0.417969 7.5V8.4H14.418V7.5V6.6H0.417969V7.5Z" fill="currentcolor"/></svg></i>
                  </a>
                  <?php endif; ?>
               </div>
            </div>
            <div class="col-xxl-4 col-xl-5 col-lg-5 d-none d-lg-block">
               <div class="text-center">
                  <i class="<?= fh($dept['hero_icon'] ?? 'fas fa-university') ?>" style="font-size: 200px; color: #002147; opacity: 0.1;"></i>
               </div>
            </div>
         </div>
      </div>
   </section>

   <!-- Sub-navigation -->
   <?php include __DIR__ . '/includes/dept-subnav.php'; ?>

   <!-- Vision & Mission Section -->
   <section class="pt-130 pb-100" style="background-color: #FFFFFF;">
      <div class="container">
         <div class="row justify-content-center">
            <div class="col-12 text-center mb-60">
               <span class="it-section-subtitle" style="color: #D21034;">
                  <i class="fas fa-bullseye"></i> Our Direction
               </span>
               <h4 class="it-section-title" style="color: #002147;">Vision &amp; Mission</h4>
            </div>
         </div>
         <div class="row g-4">
            <div class="col-xl-6 col-lg-6 wow itfadeUp" data-wow-duration=".9s" data-wow-delay=".3s">
               <div class="card h-100 border-0 shadow-sm" style="border-left: 4px solid #D21034 !important;">
                  <div class="card-body p-40">
                     <div class="d-flex align-items-center mb-30">
                        <div class="flex-shrink-0">
                           <i class="fas fa-eye" style="font-size: 48px; color: #D21034;"></i>
                        </div>
                        <div class="flex-grow-1 ms-3">
                           <h4 style="color: #002147; font-weight: 700;">Our Vision</h4>
                        </div>
                     </div>
                     <p style="color: #334155; font-size: 16px; line-height: 1.8;">
                        <?= nl2br(fh($overview['vision'] ?? 'To establish a center of excellence that fosters innovation, critical thinking, and lifelong learning — producing graduates who lead with integrity and make a meaningful impact on society.')) ?>
                     </p>
                  </div>
               </div>
            </div>
            <div class="col-xl-6 col-lg-6 wow itfadeUp" data-wow-duration=".9s" data-wow-delay=".5s">
               <div class="card h-100 border-0 shadow-sm" style="border-left: 4px solid #FFB81C !important;">
                  <div class="card-body p-40">
                     <div class="d-flex align-items-center mb-30">
                        <div class="flex-shrink-0">
                           <i class="fas fa-rocket" style="font-size: 48px; color: #FFB81C;"></i>
                        </div>
                        <div class="flex-grow-1 ms-3">
                           <h4 style="color: #002147; font-weight: 700;">Our Mission</h4>
                        </div>
                     </div>
                     <p style="color: #334155; font-size: 16px; line-height: 1.8;">
                        <?= nl2br(fh($overview['mission'] ?? 'To provide quality education through innovative curriculum, hands-on learning experiences, and strong industry partnerships — developing ethical, skilled, and creative graduates who contribute meaningfully to society.')) ?>
                     </p>
                  </div>
               </div>
            </div>
         </div>
      </div>
   </section>

   <?php
   $head_name = trim($overview['head_name'] ?? '');
   if ($head_name !== ''):
   ?>
   <!-- Department Head Section -->
   <section class="pt-100 pb-100" style="background-color: #F8FAFC;">
      <div class="container">
         <div class="row justify-content-center">
            <div class="col-12 text-center mb-60">
               <span class="it-section-subtitle" style="color: #D21034;">
                  <i class="fas fa-user-tie"></i> Leadership
               </span>
               <h4 class="it-section-title" style="color: #002147;">Message from the Head</h4>
            </div>
         </div>
         <div class="row align-items-center g-5">
            <div class="col-xl-3 col-lg-4 text-center">
               <?php if (!empty($overview['head_photo'])): ?>
               <img src="<?= fh(ADMIN_UPLOAD_URL . '/departments/' . $overview['head_photo']) ?>"
                    alt="<?= fh($head_name) ?>"
                    class="rounded-circle shadow"
                    style="width:200px; height:200px; object-fit:cover; border: 4px solid #FFB81C;">
               <?php else: ?>
               <div style="width:200px; height:200px; border-radius:50%; background:#002147; display:inline-flex; align-items:center; justify-content:center; border:4px solid #FFB81C;">
                  <i class="fas fa-user-tie" style="font-size:80px; color:#FFB81C;"></i>
               </div>
               <?php endif; ?>
               <h5 class="mt-20 mb-5" style="color:#002147; font-weight:700;"><?= fh($head_name) ?></h5>
               <p style="color:#D21034; font-size:14px; font-weight:600;"><?= fh($overview['head_designation'] ?? '') ?></p>
               <?php if (!empty($overview['head_edu_qualifications'])): ?>
               <p style="color:#334155; font-size:13px; line-height:1.6;"><?= nl2br(fh($overview['head_edu_qualifications'])) ?></p>
               <?php endif; ?>
            </div>
            <div class="col-xl-9 col-lg-8">
               <div class="card border-0 shadow-sm" style="border-left: 4px solid #002147 !important;">
                  <div class="card-body p-40">
                     <i class="fas fa-quote-left" style="font-size:36px; color:#FFB81C; opacity:0.4; margin-bottom:15px;"></i>
                     <p style="color:#334155; font-size:16px; line-height:1.9;">
                        <?= nl2br(fh($overview['head_message'] ?? '')) ?>
                     </p>
                  </div>
               </div>
            </div>
         </div>
      </div>
   </section>
   <?php endif; ?>

   <?php if (!empty($alumni)): ?>
   <!-- Notable Alumni Section -->
   <section class="pt-100 pb-100" style="background-color: #FFFFFF;">
      <div class="container">
         <div class="row justify-content-center">
            <div class="col-12 text-center mb-60">
               <span class="it-section-subtitle" style="color: #D21034;">
                  <i class="fas fa-star"></i> Success Stories
               </span>
               <h4 class="it-section-title" style="color: #002147;">Notable Alumni</h4>
            </div>
         </div>
         <div class="row g-4">
            <?php foreach ($alumni as $alum): ?>
            <div class="col-xl-3 col-lg-4 col-md-6 wow itfadeUp" data-wow-duration=".9s">
               <div class="card h-100 border-0 shadow-sm text-center" style="border-top: 3px solid #FFB81C !important;">
                  <div class="card-body p-30">
                     <?php if (!empty($alum['photo'])): ?>
                     <img src="<?= fh(ADMIN_UPLOAD_URL . '/departments/' . $alum['photo']) ?>"
                          alt="<?= fh($alum['name'] ?? '') ?>"
                          class="rounded-circle mb-20"
                          style="width:90px; height:90px; object-fit:cover; border:3px solid #FFB81C;">
                     <?php else: ?>
                     <div class="rounded-circle mb-20 mx-auto d-flex align-items-center justify-content-center"
                          style="width:90px; height:90px; background:#002147; border:3px solid #FFB81C;">
                        <i class="fas fa-user" style="font-size:36px; color:#FFB81C;"></i>
                     </div>
                     <?php endif; ?>
                     <h6 style="color:#002147; font-weight:700;"><?= fh($alum['name'] ?? '') ?></h6>
                     <?php if (!empty($alum['batch_year'])): ?>
                     <p style="color:#D21034; font-size:13px; font-weight:600;">Batch <?= fh($alum['batch_year']) ?></p>
                     <?php endif; ?>
                     <?php if (!empty($alum['current_position'])): ?>
                     <p style="color:#334155; font-size:13px;"><?= fh($alum['current_position']) ?></p>
                     <?php endif; ?>
                     <?php if (!empty($alum['company'])): ?>
                     <p style="color:#334155; font-size:13px; font-weight:500;"><?= fh($alum['company']) ?></p>
                     <?php endif; ?>
                  </div>
               </div>
            </div>
            <?php endforeach; ?>
         </div>
      </div>
   </section>
   <?php endif; ?>

   <?php if (!empty($events)): ?>
   <!-- Upcoming Events Section -->
   <section class="pt-100 pb-100" style="background-color: #F8FAFC;">
      <div class="container">
         <div class="row justify-content-center">
            <div class="col-12 text-center mb-60">
               <span class="it-section-subtitle" style="color: #D21034;">
                  <i class="fas fa-calendar-alt"></i> What's On
               </span>
               <h4 class="it-section-title" style="color: #002147;">Upcoming Events</h4>
            </div>
         </div>
         <div class="row g-4">
            <?php foreach ($events as $ev): ?>
            <?php
               $ev_date  = !empty($ev['event_date']) ? strtotime($ev['event_date']) : null;
               $ev_day   = $ev_date ? date('d', $ev_date) : '--';
               $ev_month = $ev_date ? date('M', $ev_date) : '';
            ?>
            <div class="col-xl-4 col-lg-4 col-md-6 wow itfadeUp" data-wow-duration=".9s">
               <div class="card h-100 border-0 shadow-sm" style="border-top: 3px solid #D21034 !important;">
                  <div class="card-body p-30">
                     <div class="d-flex align-items-start gap-3 mb-20">
                        <div class="text-center flex-shrink-0 rounded" style="background:#002147; color:#FFB81C; padding:10px 16px; min-width:60px;">
                           <div style="font-size:24px; font-weight:700; line-height:1;"><?= fh($ev_day) ?></div>
                           <div style="font-size:11px; font-weight:600; letter-spacing:1px;"><?= fh($ev_month) ?></div>
                        </div>
                        <div>
                           <h6 style="color:#002147; font-weight:700; margin-bottom:5px;"><?= fh($ev['title'] ?? '') ?></h6>
                           <?php if (!empty($ev['location'])): ?>
                           <p style="color:#D21034; font-size:13px; margin-bottom:0;"><i class="fas fa-map-marker-alt me-1"></i><?= fh($ev['location']) ?></p>
                           <?php endif; ?>
                        </div>
                     </div>
                     <?php if (!empty($ev['description'])): ?>
                     <p style="color:#334155; font-size:14px; line-height:1.7;"><?= fh($ev['description']) ?></p>
                     <?php endif; ?>
                     <?php if (!empty($ev['link_url'])): ?>
                     <a href="<?= fh($ev['link_url']) ?>" class="it-btn-yellow border-radius-100" style="font-size:13px;" target="_blank" rel="noopener">
                        <span><span class="text-1">Learn More</span><span class="text-2">Learn More</span></span>
                     </a>
                     <?php endif; ?>
                  </div>
               </div>
            </div>
            <?php endforeach; ?>
         </div>
      </div>
   </section>
   <?php endif; ?>

   <!-- Call to Action Section -->
   <?php if (!empty($dept['cta_section_title'])): ?>
   <section class="pt-100 pb-100" style="background: linear-gradient(135deg, #002147 0%, #003366 100%);">
      <div class="container">
         <div class="row justify-content-center text-center">
            <div class="col-xl-8 col-lg-10">
               <h3 class="mb-20" style="color:#FFFFFF; font-weight:700;"><?= fh($dept['cta_section_title']) ?></h3>
               <?php if (!empty($dept['cta_section_text'])): ?>
               <p class="mb-40" style="color:#E8EEF4; font-size:17px; line-height:1.8;"><?= fh($dept['cta_section_text']) ?></p>
               <?php endif; ?>
               <?php if (!empty($dept['cta_url'])): ?>
               <a href="<?= fh($dept['cta_url']) ?>" class="it-btn-yellow theme-bg border-radius-100">
                  <span>
                     <span class="text-1"><?= fh($dept['cta_text'] ?? 'Apply Now') ?></span>
                     <span class="text-2"><?= fh($dept['cta_text'] ?? 'Apply Now') ?></span>
                  </span>
                  <i><svg width="16" height="15" viewBox="0 0 16 15" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M15.0544 8.1364C15.4058 7.78492 15.4058 7.21508 15.0544 6.8636L9.3268 1.13604C8.97533 0.784567 8.40548 0.784567 8.05401 1.13604C7.70254 1.48751 7.70254 2.05736 8.05401 2.40883L13.1452 7.5L8.05401 12.5912C7.70254 12.9426 7.70254 13.5125 8.05401 13.864C8.40548 14.2154 8.97533 14.2154 9.3268 13.864L15.0544 8.1364ZM0.417969 7.5V8.4H14.418V7.5V6.6H0.417969V7.5Z" fill="currentcolor"/></svg></i>
               </a>
               <?php endif; ?>
            </div>
         </div>
      </div>
   </section>
   <?php endif; ?>

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
                     <div class="it-footer-widget-menu">
                        <ul>
                           <li><a href="#">Marketplace</a></li>
                           <li><a href="#">University</a></li>
                           <li><a href="#">GYM Coaching</a></li>
                           <li><a href="#">Cooking</a></li>
                        </ul>
                     </div>
                  </div>
               </div>
               <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6 mb-50">
                  <div class="it-footer-widget it-footer-col-1-3">
                     <h4 class="it-footer-widget-title">Our Company</h4>
                     <div class="it-footer-widget-menu">
                        <ul>
                           <li><a href="#">Contact Us</a></li>
                           <li><a href="#">Become Teacher</a></li>
                           <li><a href="#">Blog</a></li>
                           <li><a href="#">Instructor</a></li>
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
