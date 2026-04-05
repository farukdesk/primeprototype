<?php
require_once __DIR__ . '/includes/config.php';

$page_title = 'Admission – Prime University';
?>
<!doctype html>
<html class="no-js" lang="en">

<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <title><?= fh($page_title) ?></title>
   <meta name="description" content="Apply for admission at Prime University. Undergraduate, Postgraduate and Foreign Student admission information, eligibility and requirements.">
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

   <!-- offcanvas area start -->
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
                  <a href="https://www.google.com/maps/@23.7934913,90.3547073,15z" target="_blank">114, 116 Mazar Rd, Dhaka 1216</a>
               </div>
            </div>
         </div>
      </div>
   </div>
   <div class="body-overlay"></div>
   <!-- offcanvas area end -->

   <header class="it-header-height">
      <?php include __DIR__ . '/includes/header-top.php'; ?>
      <?php include __DIR__ . '/includes/nav-menu.php'; ?>
   </header>

   <!-- ─────────────────────────────────────────────────────────────────── -->
   <!-- PAGE-SPECIFIC STYLES                                                -->
   <!-- ─────────────────────────────────────────────────────────────────── -->
   <style>
   /* ── Admission Page Custom Styles ────────────────────────────────────── */

   /* ── Hero ─────────────────────────────────────────────── */
   .pu-adm-hero {
      background: linear-gradient(135deg, #0f1f4b 0%, #1a3a6e 45%, #2563eb 100%);
      padding: 100px 0 80px;
      position: relative;
      overflow: hidden;
   }
   .pu-adm-hero::before {
      content: '';
      position: absolute;
      inset: 0;
      background: url('assets/img/shape/breadcrumb-1-bg.png') center/cover no-repeat;
      opacity: .08;
   }
   /* floating blobs */
   .pu-adm-hero .blob {
      position: absolute;
      border-radius: 50%;
      filter: blur(80px);
      opacity: .25;
      pointer-events: none;
   }
   .pu-adm-hero .blob-1 {
      width: 380px; height: 380px;
      background: #3b82f6;
      top: -100px; right: -80px;
      animation: blobFloat 8s ease-in-out infinite;
   }
   .pu-adm-hero .blob-2 {
      width: 260px; height: 260px;
      background: #facc15;
      bottom: -60px; left: 10%;
      animation: blobFloat 11s ease-in-out infinite reverse;
   }
   @keyframes blobFloat {
      0%, 100% { transform: translateY(0) scale(1); }
      50%       { transform: translateY(-30px) scale(1.08); }
   }
   .pu-adm-hero .breadcrumb-nav a,
   .pu-adm-hero .breadcrumb-nav span { color: rgba(255,255,255,.75); font-size: 14px; }
   .pu-adm-hero .breadcrumb-nav a:hover { color: #fff; }
   .pu-adm-hero .breadcrumb-nav .sep { margin: 0 8px; color: rgba(255,255,255,.4); }
   .pu-adm-hero h1 {
      color: #fff;
      font-size: clamp(2rem, 4vw, 3.25rem);
      font-weight: 800;
      line-height: 1.2;
      margin-bottom: 18px;
   }
   .pu-adm-hero h1 span { color: #facc15; }
   .pu-adm-hero .tagline {
      color: rgba(255,255,255,.85);
      font-size: 1.05rem;
      max-width: 560px;
      margin-bottom: 32px;
   }
   .pu-adm-hero-badge {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: rgba(255,255,255,.12);
      border: 1px solid rgba(255,255,255,.25);
      border-radius: 100px;
      padding: 6px 18px;
      color: #fff;
      font-size: 13px;
      font-weight: 500;
      margin-bottom: 20px;
      backdrop-filter: blur(6px);
   }
   .pu-adm-hero-badge i { color: #facc15; }
   .pu-btn-white {
      display: inline-flex; align-items: center; gap: 8px;
      background: #fff; color: #1a2e5a;
      padding: 14px 28px; border-radius: 100px;
      font-weight: 700; font-size: .95rem;
      text-decoration: none;
      transition: transform .25s, box-shadow .25s;
      box-shadow: 0 4px 20px rgba(0,0,0,.15);
   }
   .pu-btn-white:hover { transform: translateY(-3px); box-shadow: 0 8px 30px rgba(0,0,0,.2); color: #1a2e5a; }
   .pu-btn-outline-white {
      display: inline-flex; align-items: center; gap: 8px;
      background: transparent; color: #fff;
      padding: 13px 28px; border-radius: 100px;
      border: 2px solid rgba(255,255,255,.6);
      font-weight: 600; font-size: .95rem;
      text-decoration: none;
      transition: background .25s, border-color .25s, transform .25s;
   }
   .pu-btn-outline-white:hover { background: rgba(255,255,255,.15); border-color: #fff; color: #fff; transform: translateY(-3px); }
   .pu-hero-cta { display: flex; gap: 14px; flex-wrap: wrap; }

   /* floating hero icon */
   .pu-adm-hero .hero-icon-wrap {
      text-align: center;
      animation: heroIconFloat 5s ease-in-out infinite;
   }
   @keyframes heroIconFloat {
      0%, 100% { transform: translateY(0); }
      50%       { transform: translateY(-18px); }
   }
   .pu-adm-hero .hero-icon-wrap i { font-size: 7.5rem; color: rgba(255,255,255,.18); }

   /* ── Section header ───────────────────────────────────── */
   .pu-section-tag {
      display: inline-block;
      background: #eff6ff;
      color: #2563eb;
      font-size: 13px; font-weight: 700;
      letter-spacing: .04em; text-transform: uppercase;
      padding: 5px 16px; border-radius: 100px;
      margin-bottom: 12px;
   }
   .pu-section-title {
      font-size: clamp(1.6rem, 3vw, 2.3rem);
      font-weight: 800; color: #0f1f4b;
      line-height: 1.25;
   }
   .pu-section-title span { color: #2563eb; }
   .pu-section-lead { color: #64748b; font-size: 1rem; max-width: 620px; }

   /* ── Stats bar ───────────────────────────────────────── */
   .pu-stats-bar {
      background: #0f1f4b;
      padding: 32px 0;
   }
   .pu-stat-item { text-align: center; padding: 8px 0; }
   .pu-stat-item .num {
      font-size: 2.4rem; font-weight: 800;
      color: #facc15; line-height: 1;
      display: block;
   }
   .pu-stat-item .lbl { color: rgba(255,255,255,.75); font-size: .88rem; margin-top: 4px; }
   .pu-stat-divider { border-left: 1px solid rgba(255,255,255,.15); }

   /* ── Admission type cards ─────────────────────────────── */
   .pu-adm-type-card {
      background: #fff;
      border-radius: 18px;
      border: 2px solid transparent;
      padding: 36px 32px;
      height: 100%;
      box-shadow: 0 4px 24px rgba(15,31,75,.07);
      transition: border-color .3s, transform .3s, box-shadow .3s;
      position: relative;
      overflow: hidden;
   }
   .pu-adm-type-card::before {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0;
      height: 4px;
      background: linear-gradient(90deg, #2563eb, #7c3aed);
      border-radius: 18px 18px 0 0;
      transform: scaleX(0);
      transform-origin: left;
      transition: transform .35s;
   }
   .pu-adm-type-card:hover { border-color: #dbeafe; transform: translateY(-6px); box-shadow: 0 16px 48px rgba(37,99,235,.12); }
   .pu-adm-type-card:hover::before { transform: scaleX(1); }
   .pu-adm-type-card .card-icon {
      width: 64px; height: 64px;
      border-radius: 16px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.7rem; margin-bottom: 20px;
   }
   .pu-adm-type-card .card-icon.blue { background: #eff6ff; color: #2563eb; }
   .pu-adm-type-card .card-icon.purple { background: #f5f3ff; color: #7c3aed; }
   .pu-adm-type-card .card-icon.green { background: #f0fdf4; color: #16a34a; }
   .pu-adm-type-card h3 { font-size: 1.25rem; font-weight: 700; color: #0f1f4b; margin-bottom: 10px; }
   .pu-adm-type-card p { color: #64748b; font-size: .93rem; line-height: 1.7; }
   .pu-adm-type-card .anchor-link {
      display: inline-flex; align-items: center; gap: 6px;
      color: #2563eb; font-weight: 600; font-size: .9rem;
      text-decoration: none; margin-top: 16px;
      transition: gap .2s;
   }
   .pu-adm-type-card .anchor-link:hover { gap: 10px; }

   /* ── Requirements table / list ───────────────────────── */
   .pu-req-block {
      background: #f8fafc;
      border-radius: 16px;
      padding: 32px;
      border-left: 5px solid #2563eb;
      margin-bottom: 24px;
   }
   .pu-req-block h4 { font-size: 1.05rem; font-weight: 700; color: #0f1f4b; margin-bottom: 16px; display: flex; align-items: center; gap: 10px; }
   .pu-req-block h4 i { color: #2563eb; font-size: 1.1rem; }
   .pu-gpa-pills { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 12px; }
   .pu-gpa-pill {
      background: #fff;
      border: 2px solid #dbeafe;
      border-radius: 12px;
      padding: 12px 20px;
      display: flex; align-items: center; gap: 12px;
      min-width: 180px;
   }
   .pu-gpa-pill .gpa-badge {
      background: #2563eb; color: #fff;
      border-radius: 8px; padding: 4px 10px;
      font-weight: 800; font-size: 1rem; white-space: nowrap;
   }
   .pu-gpa-pill .gpa-label { color: #334155; font-size: .9rem; font-weight: 500; }
   .pu-checklist { list-style: none; padding: 0; margin: 0; }
   .pu-checklist li {
      display: flex; align-items: flex-start; gap: 10px;
      color: #334155; font-size: .95rem;
      padding: 7px 0; border-bottom: 1px solid #e2e8f0;
   }
   .pu-checklist li:last-child { border-bottom: none; }
   .pu-checklist li i { color: #2563eb; margin-top: 3px; flex-shrink: 0; }

   /* ── Steps ───────────────────────────────────────────── */
   .pu-steps-wrap { position: relative; padding-top: 16px; }
   .pu-step {
      display: flex; gap: 24px;
      margin-bottom: 40px;
      position: relative;
   }
   .pu-step::after {
      content: '';
      position: absolute;
      left: 23px; top: 56px;
      width: 2px;
      height: calc(100% - 10px);
      background: linear-gradient(to bottom, #2563eb, #dbeafe);
   }
   .pu-step:last-child::after { display: none; }
   .pu-step-num {
      flex-shrink: 0;
      width: 48px; height: 48px;
      border-radius: 50%;
      background: #2563eb;
      color: #fff;
      font-weight: 800; font-size: 1.1rem;
      display: flex; align-items: center; justify-content: center;
      box-shadow: 0 4px 16px rgba(37,99,235,.35);
      position: relative; z-index: 1;
   }
   .pu-step-body { padding-top: 6px; }
   .pu-step-body h5 { font-weight: 700; font-size: 1rem; color: #0f1f4b; margin-bottom: 5px; }
   .pu-step-body p { color: #64748b; font-size: .92rem; margin: 0; line-height: 1.65; }

   /* ── CTA banner ──────────────────────────────────────── */
   .pu-cta-banner {
      background: linear-gradient(135deg, #1a2e5a 0%, #2563eb 100%);
      border-radius: 24px;
      padding: 60px 50px;
      position: relative;
      overflow: hidden;
   }
   .pu-cta-banner::before {
      content: '';
      position: absolute; inset: 0;
      background: url('assets/img/shape/breadcrumb-1-bg.png') center/cover no-repeat;
      opacity: .06;
   }
   .pu-cta-banner h2 { color: #fff; font-size: clamp(1.6rem, 2.5vw, 2.2rem); font-weight: 800; margin-bottom: 12px; }
   .pu-cta-banner p { color: rgba(255,255,255,.8); font-size: 1rem; max-width: 520px; margin-bottom: 0; }
   .pu-btn-yellow {
      display: inline-flex; align-items: center; gap: 9px;
      background: #facc15; color: #0f1f4b;
      padding: 15px 32px; border-radius: 100px;
      font-weight: 800; font-size: 1rem;
      text-decoration: none;
      transition: transform .25s, box-shadow .25s;
      box-shadow: 0 4px 20px rgba(250,204,21,.4);
   }
   .pu-btn-yellow:hover { transform: translateY(-3px); box-shadow: 0 10px 30px rgba(250,204,21,.5); color: #0f1f4b; }

   /* ── Postgraduate card ───────────────────────────────── */
   .pu-pg-card {
      background: linear-gradient(135deg, #f5f3ff 0%, #ede9fe 100%);
      border-radius: 18px;
      padding: 40px;
      border: 2px solid #e9d5ff;
      position: relative;
      overflow: hidden;
   }
   .pu-pg-card .deco-icon {
      position: absolute;
      right: 30px; bottom: 20px;
      font-size: 7rem;
      color: rgba(124,58,237,.07);
   }
   .pu-pg-points-badge {
      display: inline-flex; align-items: center; gap: 10px;
      background: #7c3aed; color: #fff;
      border-radius: 14px; padding: 14px 24px;
      margin: 20px 0;
   }
   .pu-pg-points-badge .pts { font-size: 2rem; font-weight: 900; line-height: 1; }
   .pu-pg-points-badge .pts-label { font-size: .85rem; line-height: 1.3; opacity: .9; }

   /* ── Foreign student card ─────────────────────────────── */
   .pu-foreign-card {
      background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
      border-radius: 18px;
      padding: 40px;
      border: 2px solid #bbf7d0;
      position: relative;
      overflow: hidden;
   }
   .pu-foreign-card .deco-icon {
      position: absolute;
      right: 30px; bottom: 20px;
      font-size: 7rem;
      color: rgba(22,163,74,.07);
   }

   /* ── Accordion / FAQ ─────────────────────────────────── */
   .pu-faq-item {
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      margin-bottom: 12px;
      overflow: hidden;
      transition: box-shadow .25s;
   }
   .pu-faq-item:hover { box-shadow: 0 4px 20px rgba(15,31,75,.07); }
   .pu-faq-btn {
      width: 100%;
      background: #fff;
      border: none;
      padding: 18px 24px;
      text-align: left;
      font-weight: 700; font-size: .97rem; color: #0f1f4b;
      display: flex; align-items: center; justify-content: space-between;
      cursor: pointer;
      transition: background .2s;
   }
   .pu-faq-btn[aria-expanded="true"] { background: #eff6ff; color: #2563eb; }
   .pu-faq-btn i { transition: transform .3s; }
   .pu-faq-btn[aria-expanded="true"] i { transform: rotate(45deg); }
   .pu-faq-body { background: #fafcff; padding: 0 24px; font-size: .93rem; color: #475569; line-height: 1.75; }

   /* ── Responsive tweaks ───────────────────────────────── */
   @media (max-width: 767px) {
      .pu-adm-hero { padding: 70px 0 60px; }
      .pu-cta-banner { padding: 40px 24px; }
      .pu-pg-card, .pu-foreign-card { padding: 28px 20px; }
      .pu-req-block { padding: 22px 18px; }
      .pu-stat-divider { border-left: none; border-top: 1px solid rgba(255,255,255,.15); }
   }
   </style>

   <!-- ─────────────────────────────────────────────────────────────────── -->
   <!-- HERO                                                                 -->
   <!-- ─────────────────────────────────────────────────────────────────── -->
   <section class="pu-adm-hero">
      <div class="blob blob-1"></div>
      <div class="blob blob-2"></div>
      <div class="container position-relative z-index-1">

         <!-- breadcrumb -->
         <nav class="breadcrumb-nav mb-28" aria-label="breadcrumb">
            <a href="<?= fh(SITE_URL) ?>/index.php">Home</a>
            <span class="sep">/</span>
            <span style="color:rgba(255,255,255,.9);">Admission</span>
         </nav>

         <div class="row align-items-center g-4">
            <div class="col-lg-8">
               <div class="pu-adm-hero-badge wow fadeInDown" data-wow-delay=".1s">
                  <i class="fas fa-graduation-cap"></i>
                  Admissions Open — Join Our Community
               </div>
               <h1 class="wow fadeInUp" data-wow-delay=".2s">
                  Begin Your Journey at<br><span>Prime University</span>
               </h1>
               <p class="tagline wow fadeInUp" data-wow-delay=".3s">
                  Undergraduate, Postgraduate and international programmes — shaped around your ambitions and built for the future.
               </p>
               <div class="pu-hero-cta wow fadeInUp" data-wow-delay=".4s">
                  <a href="https://primeuniversity.ac.bd/apply-now.php" target="_blank" rel="noopener" class="pu-btn-white">
                     <i class="fas fa-paper-plane"></i> Apply Now
                  </a>
                  <a href="#undergraduate" class="pu-btn-outline-white">
                     <i class="fas fa-info-circle"></i> Learn More
                  </a>
               </div>
            </div>
            <div class="col-lg-4 d-none d-lg-block">
               <div class="hero-icon-wrap">
                  <i class="fas fa-university"></i>
               </div>
            </div>
         </div>
      </div>
   </section>
   <!-- hero end -->

   <!-- ─────────────────────────────────────────────────────────────────── -->
   <!-- STATS BAR                                                            -->
   <!-- ─────────────────────────────────────────────────────────────────── -->
   <div class="pu-stats-bar">
      <div class="container">
         <div class="row text-center g-0">
            <div class="col-6 col-md-3 pu-stat-item">
               <span class="num">25+</span>
               <div class="lbl">Programmes Offered</div>
            </div>
            <div class="col-6 col-md-3 pu-stat-item pu-stat-divider">
               <span class="num">15,000+</span>
               <div class="lbl">Enrolled Students</div>
            </div>
            <div class="col-6 col-md-3 pu-stat-item pu-stat-divider">
               <span class="num">300+</span>
               <div class="lbl">Expert Faculty</div>
            </div>
            <div class="col-6 col-md-3 pu-stat-item pu-stat-divider">
               <span class="num">20+</span>
               <div class="lbl">Years of Excellence</div>
            </div>
         </div>
      </div>
   </div>

   <!-- ─────────────────────────────────────────────────────────────────── -->
   <!-- ADMISSION TYPE OVERVIEW                                              -->
   <!-- ─────────────────────────────────────────────────────────────────── -->
   <section class="pt-90 pb-60">
      <div class="container">
         <div class="text-center mb-55">
            <span class="pu-section-tag wow fadeInUp" data-wow-delay=".1s">Admission Pathways</span>
            <h2 class="pu-section-title wow fadeInUp" data-wow-delay=".2s">Choose Your <span>Programme</span></h2>
            <p class="pu-section-lead mx-auto mt-10 wow fadeInUp" data-wow-delay=".3s">
               Prime University welcomes students from all backgrounds. Explore the pathway that suits your academic goals.
            </p>
         </div>
         <div class="row g-4">
            <div class="col-lg-4 col-md-6 wow fadeInUp" data-wow-delay=".15s">
               <div class="pu-adm-type-card">
                  <div class="card-icon blue"><i class="fas fa-user-graduate"></i></div>
                  <h3>Undergraduate Admission</h3>
                  <p>Start your academic journey with our world-class bachelor programmes. Minimum GPA 2.50 in both SSC &amp; HSC required.</p>
                  <a href="#undergraduate" class="anchor-link">Explore requirements <i class="fas fa-arrow-right"></i></a>
               </div>
            </div>
            <div class="col-lg-4 col-md-6 wow fadeInUp" data-wow-delay=".25s">
               <div class="pu-adm-type-card">
                  <div class="card-icon purple"><i class="fas fa-award"></i></div>
                  <h3>Postgraduate Admission</h3>
                  <p>Advance your career with our Masters programmes. Minimum 6 points from your undergraduate CGPA is required for eligibility.</p>
                  <a href="#postgraduate" class="anchor-link">Explore requirements <i class="fas fa-arrow-right"></i></a>
               </div>
            </div>
            <div class="col-lg-4 col-md-6 wow fadeInUp" data-wow-delay=".35s">
               <div class="pu-adm-type-card">
                  <div class="card-icon green"><i class="fas fa-globe-asia"></i></div>
                  <h3>Foreign Student Admission</h3>
                  <p>International students are warmly welcomed. Equivalent academic qualifications are accepted for both UG and PG programmes.</p>
                  <a href="#foreign" class="anchor-link">Explore requirements <i class="fas fa-arrow-right"></i></a>
               </div>
            </div>
         </div>
      </div>
   </section>

   <!-- ─────────────────────────────────────────────────────────────────── -->
   <!-- UNDERGRADUATE ADMISSION                                              -->
   <!-- ─────────────────────────────────────────────────────────────────── -->
   <section id="undergraduate" class="pt-70 pb-80" style="background:#f8fafc;">
      <div class="container">
         <div class="row g-5 align-items-start">

            <!-- Left: info -->
            <div class="col-lg-7">
               <span class="pu-section-tag wow fadeInLeft" data-wow-delay=".1s">Undergraduate Admission</span>
               <h2 class="pu-section-title mt-10 mb-16 wow fadeInLeft" data-wow-delay=".2s">
                  Bachelor&rsquo;s Degree <span>Programmes</span>
               </h2>
               <p class="pu-section-lead mb-30 wow fadeInLeft" data-wow-delay=".3s">
                  Join hundreds of students each year who begin transformative academic careers at Prime University. Our undergraduate programmes are designed to inspire critical thinking, practical skills, and lifelong learning.
               </p>

               <!-- GPA requirements -->
               <div class="pu-req-block wow fadeInLeft" data-wow-delay=".35s">
                  <h4><i class="fas fa-check-circle"></i> Minimum Academic Requirements</h4>
                  <div class="pu-gpa-pills">
                     <div class="pu-gpa-pill">
                        <span class="gpa-badge">2.50</span>
                        <span class="gpa-label">GPA in S.S.C<br><small style="color:#94a3b8;">or equivalent</small></span>
                     </div>
                     <div class="pu-gpa-pill">
                        <span class="gpa-badge">2.50</span>
                        <span class="gpa-label">GPA in H.S.C<br><small style="color:#94a3b8;">or equivalent</small></span>
                     </div>
                  </div>
                  <p style="color:#64748b;font-size:.9rem;margin:0;"><i class="fas fa-info-circle" style="color:#2563eb;"></i>&nbsp; Equivalent foreign or technical board certificates are also accepted.</p>
               </div>

               <!-- Additional info -->
               <div class="pu-req-block wow fadeInLeft" data-wow-delay=".4s" style="border-left-color:#7c3aed;">
                  <h4><i class="fas fa-file-alt" style="color:#7c3aed;"></i> Admission Requirements</h4>
                  <ul class="pu-checklist">
                     <li><i class="fas fa-circle-check"></i> Completed prescribed admission form (available from the Admission Office)</li>
                     <li><i class="fas fa-circle-check"></i> Certified copies of SSC and HSC mark sheets &amp; certificates</li>
                     <li><i class="fas fa-circle-check"></i> Recent passport-size photographs</li>
                     <li><i class="fas fa-circle-check"></i> National ID card / Birth Certificate copy</li>
                     <li><i class="fas fa-circle-check"></i> Transfer certificate from last institution (if applicable)</li>
                  </ul>
               </div>
            </div>

            <!-- Right: steps -->
            <div class="col-lg-5 wow fadeInRight" data-wow-delay=".2s">
               <div style="background:#fff;border-radius:20px;padding:36px;box-shadow:0 4px 32px rgba(15,31,75,.08);">
                  <h4 style="font-weight:800;color:#0f1f4b;margin-bottom:30px;font-size:1.1rem;">
                     <i class="fas fa-route" style="color:#2563eb;"></i>&nbsp; How to Apply — Step by Step
                  </h4>
                  <div class="pu-steps-wrap">
                     <div class="pu-step">
                        <div class="pu-step-num">1</div>
                        <div class="pu-step-body">
                           <h5>Collect the Application Form</h5>
                           <p>Obtain the prescribed admission form from the Admission Office of Prime University or download it from the official portal.</p>
                        </div>
                     </div>
                     <div class="pu-step">
                        <div class="pu-step-num">2</div>
                        <div class="pu-step-body">
                           <h5>Fill in the Form</h5>
                           <p>Duly fill in all required fields. Attach attested copies of your SSC, HSC certificates and photographs.</p>
                        </div>
                     </div>
                     <div class="pu-step">
                        <div class="pu-step-num">3</div>
                        <div class="pu-step-body">
                           <h5>Submit to Admission Office</h5>
                           <p>Submit the completed form to the Admission Office. The respective department will schedule your written exam and/or viva-voce.</p>
                        </div>
                     </div>
                     <div class="pu-step">
                        <div class="pu-step-num">4</div>
                        <div class="pu-step-body">
                           <h5>Written Exam / Viva-Voce</h5>
                           <p>Appear for the examination/viva arranged by the department. Results are communicated within a few working days.</p>
                        </div>
                     </div>
                     <div class="pu-step">
                        <div class="pu-step-num">5</div>
                        <div class="pu-step-body">
                           <h5>Enrolment &amp; Fee Payment</h5>
                           <p>Upon selection, complete enrolment formalities and pay the semester fee to confirm your seat.</p>
                        </div>
                     </div>
                  </div>
               </div>
            </div>
         </div>
      </div>
   </section>

   <!-- ─────────────────────────────────────────────────────────────────── -->
   <!-- APPLY ONLINE CTA                                                     -->
   <!-- ─────────────────────────────────────────────────────────────────── -->
   <section class="pt-70 pb-70">
      <div class="container">
         <div class="pu-cta-banner wow zoomIn" data-wow-delay=".1s">
            <div class="row align-items-center g-4 position-relative z-index-1">
               <div class="col-lg-8">
                  <h2>Ready to Start Your Application?</h2>
                  <p>Apply online through Prime University's official admission portal. Fast, paperless, and available 24/7 — from anywhere in the world.</p>
               </div>
               <div class="col-lg-4 text-lg-end">
                  <a href="https://primeuniversity.ac.bd/apply-now.php" target="_blank" rel="noopener" class="pu-btn-yellow">
                     <i class="fas fa-paper-plane"></i> Apply Online Now
                  </a>
               </div>
            </div>
         </div>
      </div>
   </section>

   <!-- ─────────────────────────────────────────────────────────────────── -->
   <!-- POSTGRADUATE ADMISSION                                               -->
   <!-- ─────────────────────────────────────────────────────────────────── -->
   <section id="postgraduate" class="pb-80" style="background:#f8fafc;">
      <div class="container">
         <div class="text-center mb-50">
            <span class="pu-section-tag wow fadeInUp" data-wow-delay=".1s">Postgraduate Admission</span>
            <h2 class="pu-section-title mt-10 wow fadeInUp" data-wow-delay=".2s">
               Masters &amp; <span>Post Graduate</span> Programmes
            </h2>
            <p class="pu-section-lead mx-auto mt-10 wow fadeInUp" data-wow-delay=".3s">
               Deepen your expertise with a postgraduate qualification from Prime University — recognised, rigorous and career-focused.
            </p>
         </div>
         <div class="row g-4 justify-content-center">
            <div class="col-lg-8 wow fadeInUp" data-wow-delay=".2s">
               <div class="pu-pg-card">
                  <i class="fas fa-award deco-icon"></i>
                  <h3 style="font-weight:800;color:#4c1d95;font-size:1.3rem;">Eligibility for Post Graduate Programmes</h3>
                  <p style="color:#6d28d9;margin-top:10px;">Students intending to enrol in Post Graduate programmes must meet the following minimum academic requirement:</p>
                  <div class="pu-pg-points-badge">
                     <span class="pts">6</span>
                     <span class="pts-label">Minimum<br>Points Required</span>
                  </div>
                  <ul class="pu-checklist" style="margin-top:4px;">
                     <li><i class="fas fa-circle-check" style="color:#7c3aed;"></i> Points calculated from the overall undergraduate CGPA/result</li>
                     <li><i class="fas fa-circle-check" style="color:#7c3aed;"></i> Relevant bachelor's degree from a recognised university</li>
                     <li><i class="fas fa-circle-check" style="color:#7c3aed;"></i> Completed prescribed application form</li>
                     <li><i class="fas fa-circle-check" style="color:#7c3aed;"></i> Original certificates and transcripts from all previously attended institutions</li>
                     <li><i class="fas fa-circle-check" style="color:#7c3aed;"></i> Department may conduct a written/oral interview for final selection</li>
                  </ul>
                  <div class="mt-24">
                     <a href="https://primeuniversity.ac.bd/apply-now.php" target="_blank" rel="noopener" class="pu-btn-yellow" style="background:#7c3aed;color:#fff;box-shadow:0 4px 20px rgba(124,58,237,.35);">
                        <i class="fas fa-paper-plane"></i> Apply for Postgraduate
                     </a>
                  </div>
               </div>
            </div>
         </div>
      </div>
   </section>

   <!-- ─────────────────────────────────────────────────────────────────── -->
   <!-- FOREIGN STUDENT ADMISSION                                            -->
   <!-- ─────────────────────────────────────────────────────────────────── -->
   <section id="foreign" class="pt-80 pb-80">
      <div class="container">
         <div class="text-center mb-50">
            <span class="pu-section-tag wow fadeInUp" data-wow-delay=".1s">International Admissions</span>
            <h2 class="pu-section-title mt-10 wow fadeInUp" data-wow-delay=".2s">
               Foreign Student <span>Admission</span>
            </h2>
            <p class="pu-section-lead mx-auto mt-10 wow fadeInUp" data-wow-delay=".3s">
               Prime University is proud to welcome students from across the globe. International students enrich our campus culture and learning environment.
            </p>
         </div>
         <div class="row g-4 justify-content-center">
            <div class="col-lg-10 wow fadeInUp" data-wow-delay=".2s">
               <div class="pu-foreign-card">
                  <i class="fas fa-globe deco-icon"></i>
                  <div class="row g-4 position-relative z-index-1">
                     <div class="col-md-6">
                        <h4 style="font-weight:800;color:#14532d;font-size:1.15rem;margin-bottom:16px;">
                           <i class="fas fa-graduation-cap" style="color:#16a34a;"></i>&nbsp; Undergraduate Eligibility
                        </h4>
                        <div class="pu-gpa-pills">
                           <div class="pu-gpa-pill" style="border-color:#bbf7d0;">
                              <span class="gpa-badge" style="background:#16a34a;">2.50</span>
                              <span class="gpa-label">GPA in S.S.C<br><small style="color:#94a3b8;">or equivalent</small></span>
                           </div>
                           <div class="pu-gpa-pill" style="border-color:#bbf7d0;">
                              <span class="gpa-badge" style="background:#16a34a;">2.50</span>
                              <span class="gpa-label">GPA in H.S.C<br><small style="color:#94a3b8;">or equivalent</small></span>
                           </div>
                        </div>
                        <p style="color:#166534;font-size:.9rem;margin-top:8px;">
                           <i class="fas fa-info-circle" style="color:#16a34a;"></i>&nbsp; Academic certificates from recognised foreign boards and international qualifications are accepted as equivalent.
                        </p>
                     </div>
                     <div class="col-md-6">
                        <h4 style="font-weight:800;color:#14532d;font-size:1.15rem;margin-bottom:16px;">
                           <i class="fas fa-file-alt" style="color:#16a34a;"></i>&nbsp; Required Documents
                        </h4>
                        <ul class="pu-checklist">
                           <li><i class="fas fa-circle-check" style="color:#16a34a;"></i> Valid passport (all pages copy)</li>
                           <li><i class="fas fa-circle-check" style="color:#16a34a;"></i> Certified academic transcripts &amp; certificates</li>
                           <li><i class="fas fa-circle-check" style="color:#16a34a;"></i> English proficiency certificate (if applicable)</li>
                           <li><i class="fas fa-circle-check" style="color:#16a34a;"></i> Completed admission form</li>
                           <li><i class="fas fa-circle-check" style="color:#16a34a;"></i> Medical fitness certificate</li>
                           <li><i class="fas fa-circle-check" style="color:#16a34a;"></i> Student visa / study permit</li>
                        </ul>
                        <div class="mt-20">
                           <a href="https://primeuniversity.ac.bd/apply-now.php" target="_blank" rel="noopener"
                              class="pu-btn-yellow" style="background:#16a34a;color:#fff;box-shadow:0 4px 20px rgba(22,163,74,.35);">
                              <i class="fas fa-paper-plane"></i> Apply as International Student
                           </a>
                        </div>
                     </div>
                  </div>
               </div>
            </div>
         </div>
      </div>
   </section>

   <!-- ─────────────────────────────────────────────────────────────────── -->
   <!-- FAQ                                                                  -->
   <!-- ─────────────────────────────────────────────────────────────────── -->
   <section class="pt-20 pb-90" style="background:#f8fafc;">
      <div class="container">
         <div class="text-center mb-50">
            <span class="pu-section-tag wow fadeInUp" data-wow-delay=".1s">FAQ</span>
            <h2 class="pu-section-title mt-10 wow fadeInUp" data-wow-delay=".2s">
               Frequently Asked <span>Questions</span>
            </h2>
         </div>
         <div class="row justify-content-center">
            <div class="col-lg-9 wow fadeInUp" data-wow-delay=".2s">

               <div class="pu-faq-item">
                  <button class="pu-faq-btn" data-bs-toggle="collapse" data-bs-target="#faq1" aria-expanded="true" aria-controls="faq1">
                     What is the minimum GPA required for undergraduate admission?
                     <i class="fas fa-plus"></i>
                  </button>
                  <div id="faq1" class="collapse show">
                     <div class="pu-faq-body pb-18 pt-12">
                        Candidates must have a minimum GPA of <strong>2.50</strong> in S.S.C (or equivalent) and a minimum GPA of <strong>2.50</strong> in H.S.C (or equivalent) to be eligible for undergraduate programmes.
                     </div>
                  </div>
               </div>

               <div class="pu-faq-item">
                  <button class="pu-faq-btn collapsed" data-bs-toggle="collapse" data-bs-target="#faq2" aria-expanded="false" aria-controls="faq2">
                     How do I apply for admission?
                     <i class="fas fa-plus"></i>
                  </button>
                  <div id="faq2" class="collapse">
                     <div class="pu-faq-body pb-18 pt-12">
                        You can apply in two ways: (1) Collect a prescribed form from the Admission Office and submit it in person, or (2) Apply online via <a href="https://primeuniversity.ac.bd/apply-now.php" target="_blank" rel="noopener" style="color:#2563eb;">the official admission portal</a>. After submission, the respective department will schedule a written exam or viva-voce.
                     </div>
                  </div>
               </div>

               <div class="pu-faq-item">
                  <button class="pu-faq-btn collapsed" data-bs-toggle="collapse" data-bs-target="#faq3" aria-expanded="false" aria-controls="faq3">
                     What are the requirements for Postgraduate admission?
                     <i class="fas fa-plus"></i>
                  </button>
                  <div id="faq3" class="collapse">
                     <div class="pu-faq-body pb-18 pt-12">
                        Students intending to enrol in Post Graduate programmes must have a minimum of <strong>6 points</strong> calculated from their undergraduate academic results. A relevant bachelor's degree from a recognised institution is mandatory.
                     </div>
                  </div>
               </div>

               <div class="pu-faq-item">
                  <button class="pu-faq-btn collapsed" data-bs-toggle="collapse" data-bs-target="#faq4" aria-expanded="false" aria-controls="faq4">
                     Can international / foreign students apply?
                     <i class="fas fa-plus"></i>
                  </button>
                  <div id="faq4" class="collapse">
                     <div class="pu-faq-body pb-18 pt-12">
                        Yes. Foreign students are welcome to apply. International qualifications equivalent to SSC and HSC with a minimum GPA of 2.50 are accepted for undergraduate programmes. Please contact the Admission Office for specific equivalency guidance.
                     </div>
                  </div>
               </div>

               <div class="pu-faq-item">
                  <button class="pu-faq-btn collapsed" data-bs-toggle="collapse" data-bs-target="#faq5" aria-expanded="false" aria-controls="faq5">
                     Is there an entrance examination?
                     <i class="fas fa-plus"></i>
                  </button>
                  <div id="faq5" class="collapse">
                     <div class="pu-faq-body pb-18 pt-12">
                        The respective department arranges a <strong>written examination and/or viva-voce</strong> for each applicant. The format depends on the programme and department chosen. You will be notified of the date and time after submitting your application.
                     </div>
                  </div>
               </div>

               <div class="pu-faq-item">
                  <button class="pu-faq-btn collapsed" data-bs-toggle="collapse" data-bs-target="#faq6" aria-expanded="false" aria-controls="faq6">
                     Where is the Admission Office located?
                     <i class="fas fa-plus"></i>
                  </button>
                  <div id="faq6" class="collapse">
                     <div class="pu-faq-body pb-18 pt-12">
                        The Admission Office is located at the main campus: <strong>114, 116 Mazar Road, Mirpur, Dhaka 1216, Bangladesh</strong>. Office hours are Sunday–Thursday, 9:00 AM – 5:00 PM. You may also reach us at <a href="mailto:info@primeuniversity.edu.bd" style="color:#2563eb;">info@primeuniversity.edu.bd</a> or <a href="tel:+8801710996196" style="color:#2563eb;">+880-1710996196</a>.
                     </div>
                  </div>
               </div>

            </div>
         </div>
      </div>
   </section>

   <!-- ─────────────────────────────────────────────────────────────────── -->
   <!-- CONTACT / FINAL CTA                                                  -->
   <!-- ─────────────────────────────────────────────────────────────────── -->
   <section class="pt-20 pb-90">
      <div class="container">
         <div class="row g-4">
            <div class="col-md-4 wow fadeInUp" data-wow-delay=".1s">
               <div style="background:#eff6ff;border-radius:16px;padding:32px;text-align:center;height:100%;">
                  <div style="width:60px;height:60px;background:#2563eb;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:1.4rem;color:#fff;">
                     <i class="fas fa-phone-alt"></i>
                  </div>
                  <h5 style="font-weight:700;color:#0f1f4b;margin-bottom:8px;">Call Us</h5>
                  <a href="tel:+8801710996196" style="color:#2563eb;font-weight:600;text-decoration:none;">+880-1710996196</a>
               </div>
            </div>
            <div class="col-md-4 wow fadeInUp" data-wow-delay=".2s">
               <div style="background:#f5f3ff;border-radius:16px;padding:32px;text-align:center;height:100%;">
                  <div style="width:60px;height:60px;background:#7c3aed;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:1.4rem;color:#fff;">
                     <i class="fas fa-envelope"></i>
                  </div>
                  <h5 style="font-weight:700;color:#0f1f4b;margin-bottom:8px;">Email Us</h5>
                  <a href="mailto:info@primeuniversity.edu.bd" style="color:#7c3aed;font-weight:600;text-decoration:none;">info@primeuniversity.edu.bd</a>
               </div>
            </div>
            <div class="col-md-4 wow fadeInUp" data-wow-delay=".3s">
               <div style="background:#f0fdf4;border-radius:16px;padding:32px;text-align:center;height:100%;">
                  <div style="width:60px;height:60px;background:#16a34a;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:1.4rem;color:#fff;">
                     <i class="fas fa-map-marker-alt"></i>
                  </div>
                  <h5 style="font-weight:700;color:#0f1f4b;margin-bottom:8px;">Visit Us</h5>
                  <p style="color:#16a34a;font-weight:600;margin:0;font-size:.9rem;">114, 116 Mazar Rd,<br>Mirpur, Dhaka 1216</p>
               </div>
            </div>
         </div>
      </div>
   </section>

   <!-- ─────────────────────────────────────────────────────────────────── -->
   <!-- FOOTER                                                               -->
<?php include __DIR__ . '/includes/footer.php'; ?>

   <!-- JS Libraries -->
   <?php include __DIR__ . \'/includes/scripts.php\'; ?>

   <script>
   (function () {
      'use strict';

      /* WOW animation init (fallback if main.js doesn't call it) */
      if (typeof WOW !== 'undefined') {
         new WOW({ offset: 60, mobile: false }).init();
      }

      /* FAQ toggle icon rotation via Bootstrap collapse events */
      document.querySelectorAll('.pu-faq-item .collapse').forEach(function (el) {
         el.addEventListener('show.bs.collapse', function () {
            var btn = document.querySelector('[data-bs-target="#' + el.id + '"]');
            if (btn) btn.setAttribute('aria-expanded', 'true');
         });
         el.addEventListener('hide.bs.collapse', function () {
            var btn = document.querySelector('[data-bs-target="#' + el.id + '"]');
            if (btn) btn.setAttribute('aria-expanded', 'false');
         });
      });

      /* Smooth scroll for anchor links */
      document.querySelectorAll('a[href^="#"]').forEach(function (anchor) {
         anchor.addEventListener('click', function (e) {
            var target = document.querySelector(this.getAttribute('href'));
            if (target) {
               e.preventDefault();
               target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
         });
      });
   }());
   </script>

</body>
</html>
