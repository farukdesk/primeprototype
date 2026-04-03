<?php
require_once __DIR__ . '/includes/config.php';

$page_title = 'Scholarships & Waivers – Prime University';
?>
<!doctype html>
<html class="no-js" lang="en">

<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <title><?= fh($page_title) ?></title>
   <meta name="description" content="Prime University offers a range of scholarships and tuition waivers including GPA-based, merit-based, attendance-based, and flat waivers for special categories.">
   <meta name="viewport" content="width=device-width, initial-scale=1">

   <link rel="shortcut icon" type="image/x-icon" href="/assets/img/logo/favicon.png">

   <!-- CSS Libraries -->
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
   /* ===== SCHOLARSHIPS PAGE – CUSTOM STYLES ===== */

   :root {
      --pu-navy:   #1a2e5a;
      --pu-blue:   #2563eb;
      --pu-gold:   #FFB81C;
      --pu-red:    #D21034;
      --pu-green:  #059669;
      --pu-purple: #7c3aed;
      --pu-teal:   #0891b2;
   }

   /* ── Hero ───────────────────────────────────────────────────────── */
   .pu-sc-hero {
      background: linear-gradient(135deg, var(--pu-navy) 0%, #0f1f40 55%, #1e3a6e 100%);
      padding: 90px 0 80px;
      position: relative;
      overflow: hidden;
   }
   .pu-sc-hero::before {
      content: '';
      position: absolute;
      inset: 0;
      background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.03'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
   }
   .pu-sc-hero .breadcrumb-nav {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: .85rem;
      color: rgba(255,255,255,.65);
      margin-bottom: 28px;
   }
   .pu-sc-hero .breadcrumb-nav a {
      color: rgba(255,255,255,.65);
      text-decoration: none;
      transition: color .2s;
   }
   .pu-sc-hero .breadcrumb-nav a:hover { color: var(--pu-gold); }
   .pu-sc-hero .breadcrumb-nav .sep { opacity: .45; }
   .pu-sc-hero h1 {
      font-size: clamp(2rem, 5vw, 3rem);
      font-weight: 900;
      color: #fff;
      line-height: 1.2;
      margin-bottom: 18px;
   }
   .pu-sc-hero h1 span { color: var(--pu-gold); }
   .pu-sc-hero .hero-sub {
      font-size: 1rem;
      color: rgba(255,255,255,.8);
      max-width: 580px;
      line-height: 1.7;
      margin-bottom: 32px;
   }
   .pu-sc-hero-cta {
      display: flex;
      flex-wrap: wrap;
      gap: 14px;
   }
   .pu-btn-gold {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: var(--pu-gold);
      color: #1a2e5a;
      font-weight: 700;
      font-size: .9rem;
      padding: 13px 26px;
      border-radius: 50px;
      text-decoration: none;
      transition: transform .25s, box-shadow .25s;
   }
   .pu-btn-gold:hover {
      transform: translateY(-3px);
      box-shadow: 0 8px 24px rgba(255,184,28,.4);
      color: #1a2e5a;
   }
   .pu-btn-outline-white {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      border: 2px solid rgba(255,255,255,.4);
      color: #fff;
      font-weight: 600;
      font-size: .9rem;
      padding: 12px 26px;
      border-radius: 50px;
      text-decoration: none;
      transition: background .25s, border-color .25s, transform .25s;
   }
   .pu-btn-outline-white:hover {
      background: rgba(255,255,255,.1);
      border-color: rgba(255,255,255,.7);
      color: #fff;
      transform: translateY(-3px);
   }
   .pu-sc-hero-illustration {
      display: flex;
      align-items: center;
      justify-content: center;
      opacity: .12;
   }
   .pu-sc-hero-illustration i { font-size: 10rem; color: #fff; }

   /* ── Stat banner ─────────────────────────────────────────────────── */
   .pu-sc-stats {
      background: #fff;
      box-shadow: 0 8px 32px rgba(26,46,90,.1);
      border-radius: 20px;
      margin: -40px auto 0;
      position: relative;
      z-index: 10;
      padding: 28px 32px;
   }
   .pu-stat-item {
      text-align: center;
      padding: 16px 10px;
   }
   .pu-stat-item .stat-num {
      font-size: 2rem;
      font-weight: 900;
      color: var(--pu-navy);
      line-height: 1;
   }
   .pu-stat-item .stat-num span { color: var(--pu-blue); }
   .pu-stat-item .stat-label {
      font-size: .8rem;
      font-weight: 600;
      color: #6b7280;
      text-transform: uppercase;
      letter-spacing: .06em;
      margin-top: 6px;
   }
   .pu-stat-divider {
      width: 1px;
      background: #e5e7eb;
      align-self: stretch;
   }

   /* ── Section wrapper ─────────────────────────────────────────────── */
   .pu-sc-section {
      padding: 80px 0;
   }
   .pu-sc-section-alt {
      background: #f8faff;
   }
   .pu-section-tag {
      display: inline-block;
      background: #dbeafe;
      color: var(--pu-blue);
      font-size: .78rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .08em;
      padding: 6px 14px;
      border-radius: 50px;
   }
   .pu-section-title {
      font-size: clamp(1.6rem, 3.5vw, 2.2rem);
      font-weight: 900;
      color: var(--pu-navy);
      line-height: 1.25;
      margin-bottom: 0;
   }
   .pu-section-lead {
      font-size: 1rem;
      color: #4b5563;
      line-height: 1.7;
      margin-top: 14px;
      max-width: 640px;
   }

   /* ── Scholarship cards ───────────────────────────────────────────── */
   .pu-sc-card {
      background: #fff;
      border-radius: 20px;
      border: 1.5px solid #e5e7eb;
      padding: 32px;
      height: 100%;
      transition: transform .3s, box-shadow .3s, border-color .3s;
      position: relative;
      overflow: hidden;
   }
   .pu-sc-card::before {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0;
      height: 4px;
      background: var(--card-accent, var(--pu-blue));
      border-radius: 20px 20px 0 0;
      transform: scaleX(0);
      transform-origin: left;
      transition: transform .4s ease;
   }
   .pu-sc-card:hover::before { transform: scaleX(1); }
   .pu-sc-card:hover {
      transform: translateY(-8px);
      box-shadow: 0 20px 48px rgba(26,46,90,.12);
      border-color: rgba(37,99,235,.2);
   }
   .pu-sc-card-icon {
      width: 64px;
      height: 64px;
      border-radius: 16px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 22px;
      font-size: 1.6rem;
      flex-shrink: 0;
   }
   .pu-sc-card h4 {
      font-size: 1.1rem;
      font-weight: 800;
      color: var(--pu-navy);
      margin-bottom: 12px;
   }
   .pu-sc-card p {
      font-size: .9rem;
      color: #4b5563;
      line-height: 1.7;
      margin-bottom: 20px;
   }
   .pu-sc-badge {
      display: inline-block;
      font-size: .75rem;
      font-weight: 700;
      padding: 5px 12px;
      border-radius: 50px;
      text-transform: uppercase;
      letter-spacing: .05em;
   }
   .pu-sc-badge-note {
      display: flex;
      align-items: flex-start;
      gap: 8px;
      background: #f0fdf4;
      border: 1px solid #bbf7d0;
      border-radius: 10px;
      padding: 10px 14px;
      font-size: .8rem;
      color: #166534;
      margin-top: 16px;
   }
   .pu-sc-badge-note i { flex-shrink: 0; margin-top: 2px; }

   /* ── GPA Table ───────────────────────────────────────────────────── */
   .pu-gpa-table {
      border-radius: 14px;
      overflow: hidden;
      box-shadow: 0 4px 20px rgba(26,46,90,.08);
      border: 1px solid #e5e7eb;
   }
   .pu-gpa-table thead tr th {
      background: var(--pu-navy);
      color: #fff;
      font-size: .82rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .06em;
      padding: 14px 18px;
      border: none;
   }
   .pu-gpa-table tbody tr td {
      padding: 13px 18px;
      font-size: .9rem;
      color: #374151;
      border-color: #f3f4f6;
      vertical-align: middle;
   }
   .pu-gpa-table tbody tr:nth-child(even) { background: #f9fafb; }
   .pu-gpa-table tbody tr:hover { background: #eff6ff; }
   .pu-waiver-bar {
      display: flex;
      align-items: center;
      gap: 10px;
   }
   .pu-waiver-bar-fill {
      height: 8px;
      border-radius: 50px;
      transition: width .6s ease;
      flex: 0 0 auto;
   }
   .pu-waiver-bar-pct {
      font-size: .82rem;
      font-weight: 700;
      color: var(--pu-navy);
      white-space: nowrap;
   }

   /* ── Merit visual panel ──────────────────────────────────────────── */
   .pu-merit-visual {
      background: linear-gradient(135deg, #1e3a6e 0%, var(--pu-navy) 100%);
      border-radius: 20px;
      padding: 36px 32px;
      color: #fff;
   }
   .pu-merit-visual h4 {
      font-size: 1.15rem;
      font-weight: 800;
      color: var(--pu-gold);
      margin-bottom: 24px;
   }
   .pu-merit-tier {
      display: flex;
      align-items: center;
      gap: 14px;
      padding: 14px 0;
      border-bottom: 1px solid rgba(255,255,255,.1);
   }
   .pu-merit-tier:last-child { border-bottom: none; }
   .pu-merit-tier-rank {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: .8rem;
      font-weight: 800;
      flex-shrink: 0;
   }
   .pu-merit-tier-info { flex: 1; }
   .pu-merit-tier-info .gpa-range {
      font-size: .85rem;
      color: rgba(255,255,255,.7);
      font-weight: 500;
   }
   .pu-merit-tier-info .tier-name {
      font-size: .95rem;
      font-weight: 700;
      color: #fff;
   }
   .pu-merit-tier-pct {
      font-size: 1.1rem;
      font-weight: 900;
      color: var(--pu-gold);
   }
   .pu-merit-tier-track {
      width: 100%;
      height: 6px;
      background: rgba(255,255,255,.12);
      border-radius: 50px;
      margin-top: 4px;
      overflow: hidden;
   }
   .pu-merit-tier-track-fill {
      height: 100%;
      border-radius: 50px;
      background: var(--pu-gold);
      transition: width 1s ease;
   }

   /* ── Attendance section ──────────────────────────────────────────── */
   .pu-attendance-steps {
      display: flex;
      flex-direction: column;
      gap: 20px;
   }
   .pu-attendance-step {
      display: flex;
      gap: 18px;
      align-items: flex-start;
   }
   .pu-attendance-step-num {
      width: 44px;
      height: 44px;
      border-radius: 50%;
      background: var(--pu-teal);
      color: #fff;
      font-weight: 800;
      font-size: 1rem;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      box-shadow: 0 4px 12px rgba(8,145,178,.35);
   }
   .pu-attendance-step-body h5 {
      font-size: .98rem;
      font-weight: 700;
      color: var(--pu-navy);
      margin-bottom: 4px;
   }
   .pu-attendance-step-body p {
      font-size: .88rem;
      color: #6b7280;
      margin: 0;
      line-height: 1.6;
   }

   /* ── Flat-waiver cards ───────────────────────────────────────────── */
   .pu-waiver-card {
      background: #fff;
      border-radius: 18px;
      border: 1.5px solid #e5e7eb;
      padding: 28px 24px;
      text-align: center;
      height: 100%;
      transition: transform .3s, box-shadow .3s;
      position: relative;
      overflow: hidden;
   }
   .pu-waiver-card::after {
      content: '';
      position: absolute;
      bottom: 0; left: 0; right: 0;
      height: 4px;
      background: var(--card-accent, var(--pu-blue));
      transform: scaleX(0);
      transform-origin: left;
      transition: transform .4s ease;
   }
   .pu-waiver-card:hover::after { transform: scaleX(1); }
   .pu-waiver-card:hover {
      transform: translateY(-7px);
      box-shadow: 0 16px 40px rgba(26,46,90,.11);
   }
   .pu-waiver-card-icon {
      width: 72px;
      height: 72px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 18px;
      font-size: 1.9rem;
   }
   .pu-waiver-card h5 {
      font-size: 1rem;
      font-weight: 800;
      color: var(--pu-navy);
      margin-bottom: 10px;
   }
   .pu-waiver-card p {
      font-size: .85rem;
      color: #6b7280;
      line-height: 1.65;
      margin-bottom: 16px;
   }
   .pu-waiver-pct-pill {
      display: inline-block;
      font-size: .95rem;
      font-weight: 900;
      padding: 7px 18px;
      border-radius: 50px;
      letter-spacing: .03em;
   }
   .pu-stackable-note {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: .78rem;
      color: #9ca3af;
      margin-top: 10px;
      justify-content: center;
   }

   /* ── Summary table ───────────────────────────────────────────────── */
   .pu-summary-table-wrap {
      border-radius: 16px;
      overflow: hidden;
      box-shadow: 0 6px 28px rgba(26,46,90,.09);
   }
   .pu-summary-table thead th {
      background: var(--pu-navy);
      color: #fff;
      padding: 14px 20px;
      font-size: .82rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .06em;
      border: none;
   }
   .pu-summary-table tbody td {
      padding: 13px 20px;
      font-size: .88rem;
      color: #374151;
      border-color: #f3f4f6;
      vertical-align: middle;
   }
   .pu-summary-table tbody tr:nth-child(even) { background: #f9fafb; }
   .pu-summary-table tbody tr:hover { background: #eff6ff; transition: background .15s; }

   /* ── CTA banner ──────────────────────────────────────────────────── */
   .pu-sc-cta {
      background: linear-gradient(135deg, var(--pu-blue) 0%, #1d4ed8 100%);
      border-radius: 24px;
      padding: 56px 40px;
      text-align: center;
      position: relative;
      overflow: hidden;
   }
   .pu-sc-cta::before {
      content: '';
      position: absolute;
      inset: 0;
      background: url("data:image/svg+xml,%3Csvg width='80' height='80' viewBox='0 0 80 80' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Cpath d='M50 50c0-5.523 4.477-10 10-10s10 4.477 10 10-4.477 10-10 10c0 5.523-4.477 10-10 10s-10-4.477-10-10 4.477-10 10-10zM10 10c0-5.523 4.477-10 10-10s10 4.477 10 10-4.477 10-10 10c0 5.523-4.477 10-10 10S0 25.523 0 20s4.477-10 10-10z' /%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
   }
   .pu-sc-cta h3 {
      font-size: clamp(1.5rem, 3.5vw, 2rem);
      font-weight: 900;
      color: #fff;
      margin-bottom: 14px;
   }
   .pu-sc-cta p {
      color: rgba(255,255,255,.85);
      font-size: 1rem;
      max-width: 540px;
      margin: 0 auto 28px;
      line-height: 1.7;
   }

   /* ── Responsive ──────────────────────────────────────────────────── */
   @media (max-width: 991px) {
      .pu-sc-stats { margin: -28px 12px 0; padding: 20px 18px; }
      .pu-stat-divider { display: none; }
      .pu-sc-section { padding: 60px 0; }
   }
   @media (max-width: 767px) {
      .pu-sc-hero { padding: 70px 0 60px; }
      .pu-sc-hero h1 { font-size: 1.9rem; }
      .pu-sc-stats { margin: -20px 8px 0; border-radius: 14px; padding: 16px 12px; }
      .pu-sc-card { padding: 22px 20px; }
      .pu-waiver-card { padding: 22px 18px; }
      .pu-sc-cta { padding: 40px 20px; border-radius: 16px; }
      .pu-merit-visual { padding: 24px 20px; }
   }
   @media (max-width: 575px) {
      .pu-sc-hero-cta { flex-direction: column; }
      .pu-btn-gold, .pu-btn-outline-white { justify-content: center; }
   }

   /* ── Animated progress bars ──────────────────────────────────────── */
   @keyframes fillBar {
      from { width: 0; }
   }
   .animate-bar { animation: fillBar 1.2s ease forwards; }

   /* ── Floating decorative icons ───────────────────────────────────── */
   .pu-sc-deco {
      position: absolute;
      opacity: .08;
      pointer-events: none;
   }
   </style>
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

   <!-- back-to-top -->
   <button class="scroll-top scroll-to-target" data-target="html">
      <i class="far fa-angle-double-up"></i>
   </button>

   <!-- search popup -->
   <div class="search-popup">
      <button class="close-search"><span class="flaticon-multiply"><i class="fal fa-times"></i></span></button>
      <form method="post" action="#">
         <div class="form-group">
            <input type="search" name="search-field" value="" placeholder="Search Here" required="">
            <button type="submit"><i class="fal fa-search"></i></button>
         </div>
      </form>
   </div>

   <!-- offcanvas -->
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
                  <a href="mailto:primeuniversity@gmail.com">primeuniversity@gmail.com</a>
               </div>
            </div>
            <div class="it-info-wrapper mb-20 d-flex align-items-center">
               <div class="itoffcanvas__info-icon">
                  <a href="#"><i class="fal fa-phone-alt"></i></a>
               </div>
               <div class="itoffcanvas__info-address">
                  <span>Phone</span>
                  <a href="tel:01710996196">01710996196</a>
               </div>
            </div>
         </div>
         <div class="it-offcanvas-social">
            <a href="#"><i class="fab fa-facebook-f"></i></a>
            <a href="#"><i class="fab fa-twitter"></i></a>
            <a href="#"><i class="fab fa-instagram"></i></a>
            <a href="#"><i class="fab fa-linkedin-in"></i></a>
         </div>
      </div>
   </div>
   <div class="body-overlay"></div>

   <!-- HEADER -->
   <header class="it-header-height">
      <?php include __DIR__ . '/includes/header-top.php'; ?>
      <?php include __DIR__ . '/includes/nav-menu.php'; ?>
   </header>

   <main>

   <?php include __DIR__ . '/includes/news-ticker.php'; ?>

   <!-- ════════════════════════════════════════════════════════════════
        HERO
   ════════════════════════════════════════════════════════════════ -->
   <section class="pu-sc-hero">
      <!-- Floating decorative icons -->
      <i class="fas fa-graduation-cap pu-sc-deco" style="font-size:14rem;top:-30px;right:5%;color:#fff;"></i>
      <i class="fas fa-award pu-sc-deco" style="font-size:6rem;bottom:20px;right:22%;color:#FFB81C;"></i>

      <div class="container position-relative z-index-1">
         <!-- Breadcrumb -->
         <nav class="breadcrumb-nav" aria-label="breadcrumb">
            <a href="<?= fh(SITE_URL) ?>/index.php">Home</a>
            <span class="sep">/</span>
            <span>Scholarships &amp; Waivers</span>
         </nav>

         <div class="row align-items-center">
            <div class="col-lg-8">
               <h1>Scholarships &amp;<br><span>Waivers</span> at Prime University</h1>
               <p class="hero-sub">Prime University is committed to making quality education accessible. Explore our merit, attendance, and category-based scholarship programmes designed to reward excellence and support every student.</p>
               <div class="pu-sc-hero-cta">
                  <a href="#merit-scholarships" class="pu-btn-gold">
                     <i class="fas fa-star"></i> Merit Scholarships
                  </a>
                  <a href="#flat-waivers" class="pu-btn-outline-white">
                     <i class="fas fa-shield-alt"></i> Flat Waivers
                  </a>
               </div>
            </div>
            <div class="col-lg-4 d-none d-lg-flex pu-sc-hero-illustration">
               <i class="fas fa-university"></i>
            </div>
         </div>
      </div>
   </section>
   <!-- hero-end -->

   <!-- ════════════════════════════════════════════════════════════════
        STATS BANNER
   ════════════════════════════════════════════════════════════════ -->
   <div class="container">
      <div class="pu-sc-stats wow itfadeUp" data-wow-duration=".8s" data-wow-delay=".2s">
         <div class="row g-0 align-items-center">
            <div class="col-6 col-md-3">
               <div class="pu-stat-item">
                  <div class="stat-num"><span>100</span>%</div>
                  <div class="stat-label">Max Tuition Waiver</div>
               </div>
            </div>
            <div class="col d-none d-md-block"><div class="pu-stat-divider" style="height:56px;"></div></div>
            <div class="col-6 col-md-3">
               <div class="pu-stat-item">
                  <div class="stat-num"><span>7</span>+</div>
                  <div class="stat-label">Scholarship Types</div>
               </div>
            </div>
            <div class="col d-none d-md-block"><div class="pu-stat-divider" style="height:56px;"></div></div>
            <div class="col-6 col-md-3">
               <div class="pu-stat-item">
                  <div class="stat-num"><span>4</span></div>
                  <div class="stat-label">Flat Waiver Categories</div>
               </div>
            </div>
            <div class="col d-none d-md-block"><div class="pu-stat-divider" style="height:56px;"></div></div>
            <div class="col-6 col-md-3">
               <div class="pu-stat-item">
                  <div class="stat-num"><span>1st</span></div>
                  <div class="stat-label">Semester GPA Scholarship</div>
               </div>
            </div>
         </div>
      </div>
   </div>

   <!-- ════════════════════════════════════════════════════════════════
        SECTION 1 – GPA-BASED (1ST SEMESTER)
   ════════════════════════════════════════════════════════════════ -->
   <section id="merit-scholarships" class="pu-sc-section">
      <div class="container">
         <div class="row g-5 align-items-center">

            <!-- Left: info -->
            <div class="col-lg-6 wow itfadeUp" data-wow-duration=".9s" data-wow-delay=".2s">
               <span class="pu-section-tag"><i class="fas fa-award me-1"></i> 1st Semester</span>
               <h2 class="pu-section-title mt-2">GPA-Based Scholarship</h2>
               <p class="pu-section-lead">Your journey at Prime University starts with a reward. First-semester tuition fees are waived based on your <strong>combined SSC &amp; HSC GPA</strong> — the higher you score, the bigger your scholarship.</p>

               <div class="pu-sc-card mt-4" style="--card-accent: var(--pu-gold);">
                  <div class="pu-sc-card-icon" style="background:#fef9c3;">
                     <i class="fas fa-trophy" style="color:#d97706;"></i>
                  </div>
                  <h4>Full Waiver for Perfect Score</h4>
                  <p>Students who achieve a combined SSC + HSC GPA of <strong>10.00</strong> receive a <strong>100% tuition fee waiver</strong> for their first semester.</p>
                  <span class="pu-sc-badge" style="background:#fef3c7;color:#92400e;">SSC + HSC Combined GPA</span>
                  <div class="pu-sc-badge-note mt-3">
                     <i class="fas fa-info-circle"></i>
                     <span>Scholarship is applied directly to the first-semester tuition invoice. No separate application required.</span>
                  </div>
               </div>
            </div>

            <!-- Right: GPA table -->
            <div class="col-lg-6 wow itfadeUp" data-wow-duration=".9s" data-wow-delay=".4s">
               <div class="pu-gpa-table">
                  <table class="table table-hover mb-0">
                     <thead>
                        <tr>
                           <th>SSC + HSC Combined GPA</th>
                           <th>Tuition Waiver</th>
                        </tr>
                     </thead>
                     <tbody>
                        <tr>
                           <td><strong>10.00</strong> <span class="badge ms-1" style="background:#d1fae5;color:#065f46;font-size:.7rem;">Perfect Score</span></td>
                           <td>
                              <div class="pu-waiver-bar">
                                 <div class="pu-waiver-bar-fill" style="background:#059669;width:100%;" data-width="100"></div>
                                 <span class="pu-waiver-bar-pct">100%</span>
                              </div>
                           </td>
                        </tr>
                        <tr>
                           <td><strong>9.00 – 9.99</strong></td>
                           <td>
                              <div class="pu-waiver-bar">
                                 <div class="pu-waiver-bar-fill" style="background:#2563eb;width:75%;" data-width="75"></div>
                                 <span class="pu-waiver-bar-pct">75%</span>
                              </div>
                           </td>
                        </tr>
                        <tr>
                           <td><strong>8.00 – 8.99</strong></td>
                           <td>
                              <div class="pu-waiver-bar">
                                 <div class="pu-waiver-bar-fill" style="background:#0891b2;width:50%;" data-width="50"></div>
                                 <span class="pu-waiver-bar-pct">50%</span>
                              </div>
                           </td>
                        </tr>
                        <tr>
                           <td><strong>7.00 – 7.99</strong></td>
                           <td>
                              <div class="pu-waiver-bar">
                                 <div class="pu-waiver-bar-fill" style="background:#7c3aed;width:25%;" data-width="25"></div>
                                 <span class="pu-waiver-bar-pct">25%</span>
                              </div>
                           </td>
                        </tr>
                        <tr>
                           <td><strong>Below 7.00</strong></td>
                           <td>
                              <div class="pu-waiver-bar">
                                 <div class="pu-waiver-bar-fill" style="background:#e5e7eb;width:5%;" data-width="5"></div>
                                 <span class="pu-waiver-bar-pct text-muted">–</span>
                              </div>
                           </td>
                        </tr>
                     </tbody>
                  </table>
               </div>
               <p class="mt-3" style="font-size:.82rem;color:#9ca3af;">
                  <i class="fas fa-asterisk me-1"></i> Exact waiver slabs for intermediate GPA ranges (7–9.99) are indicative. The 10.00 → 100% rule is university policy.
               </p>
            </div>

         </div>
      </div>
   </section>

   <!-- ════════════════════════════════════════════════════════════════
        SECTION 2 – MERIT-BASED (2ND SEMESTER+)
   ════════════════════════════════════════════════════════════════ -->
   <section class="pu-sc-section pu-sc-section-alt">
      <div class="container">
         <div class="row g-5 align-items-center">

            <!-- Left: visual tiers -->
            <div class="col-lg-5 order-lg-2 wow itfadeUp" data-wow-duration=".9s" data-wow-delay=".2s">
               <div class="pu-merit-visual">
                  <h4><i class="fas fa-chart-line me-2"></i>Merit Scholarship Tiers</h4>

                  <div class="pu-merit-tier">
                     <div class="pu-merit-tier-rank" style="background:var(--pu-gold);color:#1a2e5a;">1</div>
                     <div class="pu-merit-tier-info">
                        <div class="tier-name">Gold Standard</div>
                        <div class="gpa-range">Previous Semester GPA: 3.75 – 4.00</div>
                        <div class="pu-merit-tier-track"><div class="pu-merit-tier-track-fill" style="width:100%;"></div></div>
                     </div>
                     <div class="pu-merit-tier-pct">100%</div>
                  </div>

                  <div class="pu-merit-tier">
                     <div class="pu-merit-tier-rank" style="background:#e2e8f0;color:#1a2e5a;">2</div>
                     <div class="pu-merit-tier-info">
                        <div class="tier-name">Silver Standard</div>
                        <div class="gpa-range">Previous Semester GPA: 3.50 – 3.74</div>
                        <div class="pu-merit-tier-track"><div class="pu-merit-tier-track-fill" style="width:75%;"></div></div>
                     </div>
                     <div class="pu-merit-tier-pct">75%</div>
                  </div>

                  <div class="pu-merit-tier">
                     <div class="pu-merit-tier-rank" style="background:#b45309;color:#fff;">3</div>
                     <div class="pu-merit-tier-info">
                        <div class="tier-name">Bronze Standard</div>
                        <div class="gpa-range">Previous Semester GPA: 3.25 – 3.49</div>
                        <div class="pu-merit-tier-track"><div class="pu-merit-tier-track-fill" style="width:50%;"></div></div>
                     </div>
                     <div class="pu-merit-tier-pct">50%</div>
                  </div>

                  <div class="pu-merit-tier">
                     <div class="pu-merit-tier-rank" style="background:rgba(255,255,255,.12);color:rgba(255,255,255,.7);">4</div>
                     <div class="pu-merit-tier-info">
                        <div class="tier-name">Achiever</div>
                        <div class="gpa-range">Previous Semester GPA: 3.00 – 3.24</div>
                        <div class="pu-merit-tier-track"><div class="pu-merit-tier-track-fill" style="width:25%;"></div></div>
                     </div>
                     <div class="pu-merit-tier-pct">25%</div>
                  </div>
               </div>
            </div>

            <!-- Right: explanation -->
            <div class="col-lg-7 order-lg-1 wow itfadeUp" data-wow-duration=".9s" data-wow-delay=".35s">
               <span class="pu-section-tag"><i class="fas fa-medal me-1"></i> 2nd Semester Onward</span>
               <h2 class="pu-section-title mt-2">Merit-Based Scholarship</h2>
               <p class="pu-section-lead">From the second semester onwards, tuition fee waivers are determined by your <strong>previous semester GPA</strong>. Top performers are rewarded every semester — keep your GPA high and your scholarship keeps coming.</p>

               <div class="row g-3 mt-2">
                  <div class="col-sm-6">
                     <div class="pu-sc-card" style="--card-accent:var(--pu-blue);padding:20px 22px;">
                        <div class="d-flex align-items-center gap-3 mb-3">
                           <div class="pu-sc-card-icon" style="background:#dbeafe;width:44px;height:44px;border-radius:12px;font-size:1rem;margin:0;">
                              <i class="fas fa-sync-alt" style="color:var(--pu-blue);"></i>
                           </div>
                           <h4 class="mb-0" style="font-size:.95rem;">Renewable Every Semester</h4>
                        </div>
                        <p class="mb-0" style="font-size:.85rem;">Scholarship is evaluated fresh each semester based on the most recent academic result.</p>
                     </div>
                  </div>
                  <div class="col-sm-6">
                     <div class="pu-sc-card" style="--card-accent:var(--pu-green);padding:20px 22px;">
                        <div class="d-flex align-items-center gap-3 mb-3">
                           <div class="pu-sc-card-icon" style="background:#d1fae5;width:44px;height:44px;border-radius:12px;font-size:1rem;margin:0;">
                              <i class="fas fa-layer-group" style="color:var(--pu-green);"></i>
                           </div>
                           <h4 class="mb-0" style="font-size:.95rem;">Stackable with Flat Waivers</h4>
                        </div>
                        <p class="mb-0" style="font-size:.85rem;">Merit scholarships can be combined with category-based flat waivers for greater savings.</p>
                     </div>
                  </div>
                  <div class="col-sm-6">
                     <div class="pu-sc-card" style="--card-accent:var(--pu-purple);padding:20px 22px;">
                        <div class="d-flex align-items-center gap-3 mb-3">
                           <div class="pu-sc-card-icon" style="background:#ede9fe;width:44px;height:44px;border-radius:12px;font-size:1rem;margin:0;">
                              <i class="fas fa-user-graduate" style="color:var(--pu-purple);"></i>
                           </div>
                           <h4 class="mb-0" style="font-size:.95rem;">No Separate Application</h4>
                        </div>
                        <p class="mb-0" style="font-size:.85rem;">Awarded automatically after each semester result is published — no application needed.</p>
                     </div>
                  </div>
                  <div class="col-sm-6">
                     <div class="pu-sc-card" style="--card-accent:var(--pu-teal);padding:20px 22px;">
                        <div class="d-flex align-items-center gap-3 mb-3">
                           <div class="pu-sc-card-icon" style="background:#e0f2fe;width:44px;height:44px;border-radius:12px;font-size:1rem;margin:0;">
                              <i class="fas fa-calendar-check" style="color:var(--pu-teal);"></i>
                           </div>
                           <h4 class="mb-0" style="font-size:.95rem;">Applied to Tuition Invoice</h4>
                        </div>
                        <p class="mb-0" style="font-size:.85rem;">The waiver is deducted directly from your tuition fee invoice at the start of each semester.</p>
                     </div>
                  </div>
               </div>
            </div>

         </div>
      </div>
   </section>

   <!-- ════════════════════════════════════════════════════════════════
        SECTION 3 – ATTENDANCE-BASED SCHOLARSHIP
   ════════════════════════════════════════════════════════════════ -->
   <section class="pu-sc-section">
      <div class="container">
         <div class="row g-5 align-items-center">

            <!-- Illustration side -->
            <div class="col-lg-5 wow itfadeUp" data-wow-duration=".9s" data-wow-delay=".2s">
               <div style="background:linear-gradient(135deg,#0891b2 0%,#0e7490 100%);border-radius:20px;padding:40px 36px;color:#fff;text-align:center;">
                  <i class="fas fa-clipboard-check" style="font-size:4rem;opacity:.25;display:block;margin-bottom:12px;"></i>
                  <div style="font-size:3.5rem;font-weight:900;line-height:1;color:var(--pu-gold);">75%+</div>
                  <div style="font-size:1.1rem;font-weight:600;margin-top:8px;">Minimum Attendance</div>
                  <div style="font-size:.85rem;color:rgba(255,255,255,.75);margin-top:6px;">Required to qualify for an Attendance-Based Scholarship</div>
                  <div style="margin-top:24px;display:flex;justify-content:center;gap:12px;flex-wrap:wrap;">
                     <span style="background:rgba(255,255,255,.12);border-radius:50px;padding:6px 16px;font-size:.8rem;font-weight:600;">
                        <i class="fas fa-check-circle me-1"></i>Regular Attendance
                     </span>
                     <span style="background:rgba(255,255,255,.12);border-radius:50px;padding:6px 16px;font-size:.8rem;font-weight:600;">
                        <i class="fas fa-check-circle me-1"></i>Class Participation
                     </span>
                  </div>
               </div>
            </div>

            <!-- Text side -->
            <div class="col-lg-7 wow itfadeUp" data-wow-duration=".9s" data-wow-delay=".35s">
               <span class="pu-section-tag" style="background:#cffafe;color:var(--pu-teal);"><i class="fas fa-calendar-alt me-1"></i> Alternative Pathway</span>
               <h2 class="pu-section-title mt-2">Attendance-Based Scholarship</h2>
               <p class="pu-section-lead">Not every student qualifies for a merit scholarship — but consistent effort deserves recognition too. Students who do not qualify for a merit scholarship can receive an <strong>Attendance-Based Scholarship</strong> by maintaining good attendance throughout the semester.</p>

               <div class="pu-attendance-steps mt-4">
                  <div class="pu-attendance-step wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".2s">
                     <div class="pu-attendance-step-num">1</div>
                     <div class="pu-attendance-step-body">
                        <h5>Check Your Merit Status</h5>
                        <p>At the end of each semester, merit scholarships are awarded first based on GPA rankings.</p>
                     </div>
                  </div>
                  <div class="pu-attendance-step wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".3s">
                     <div class="pu-attendance-step-num">2</div>
                     <div class="pu-attendance-step-body">
                        <h5>Maintain Good Attendance</h5>
                        <p>Students not qualifying for merit must maintain satisfactory attendance as recorded by their department.</p>
                     </div>
                  </div>
                  <div class="pu-attendance-step wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".4s">
                     <div class="pu-attendance-step-num">3</div>
                     <div class="pu-attendance-step-body">
                        <h5>Receive Your Scholarship</h5>
                        <p>Eligible students receive an attendance-based partial tuition waiver applied to the next semester invoice.</p>
                     </div>
                  </div>
               </div>
            </div>

         </div>
      </div>
   </section>

   <!-- ════════════════════════════════════════════════════════════════
        SECTION 4 – FLAT WAIVERS
   ════════════════════════════════════════════════════════════════ -->
   <section id="flat-waivers" class="pu-sc-section pu-sc-section-alt">
      <div class="container">
         <div class="row mb-50">
            <div class="col-12 text-center wow itfadeUp" data-wow-duration=".8s" data-wow-delay=".2s">
               <span class="pu-section-tag" style="background:#fce7f3;color:#be185d;"><i class="fas fa-shield-alt me-1"></i> Category-Based</span>
               <h2 class="pu-section-title mt-2">Flat Tuition Waivers</h2>
               <p class="pu-section-lead mx-auto mt-3">In addition to merit scholarships, Prime University provides <strong>flat tuition waivers</strong> for specific categories. These apply to <strong>all semesters</strong> and do <strong>not affect</strong> merit-based or GPA-based scholarships.</p>
            </div>
         </div>

         <div class="row g-4">

            <!-- Freedom Fighters -->
            <div class="col-lg-3 col-md-6 wow itfadeUp" data-wow-duration=".8s" data-wow-delay=".2s">
               <div class="pu-waiver-card" style="--card-accent:#dc2626;">
                  <div class="pu-waiver-card-icon" style="background:#fee2e2;">
                     <i class="fas fa-flag" style="color:#dc2626;"></i>
                  </div>
                  <h5>Children of Freedom Fighters</h5>
                  <p>Honoring the heroes who shaped our nation. Children of Bangladeshi freedom fighters receive the highest waiver.</p>
                  <div class="pu-waiver-pct-pill" style="background:#fee2e2;color:#991b1b;">100% Waiver</div>
                  <div class="pu-stackable-note">
                     <i class="fas fa-layer-group" style="color:var(--pu-green);"></i>
                     <span>Stackable with merit scholarship</span>
                  </div>
               </div>
            </div>

            <!-- Spouse & Siblings -->
            <div class="col-lg-3 col-md-6 wow itfadeUp" data-wow-duration=".8s" data-wow-delay=".3s">
               <div class="pu-waiver-card" style="--card-accent:#7c3aed;">
                  <div class="pu-waiver-card-icon" style="background:#ede9fe;">
                     <i class="fas fa-users" style="color:#7c3aed;"></i>
                  </div>
                  <h5>Spouse &amp; Siblings</h5>
                  <p>Spouse or siblings of current Prime University students or staff benefit from a generous family waiver.</p>
                  <div class="pu-waiver-pct-pill" style="background:#ede9fe;color:#5b21b6;">Up to 100% Waiver</div>
                  <div class="pu-stackable-note">
                     <i class="fas fa-layer-group" style="color:var(--pu-green);"></i>
                     <span>Stackable with merit scholarship</span>
                  </div>
               </div>
            </div>

            <!-- Physically Challenged & Tribal -->
            <div class="col-lg-3 col-md-6 wow itfadeUp" data-wow-duration=".8s" data-wow-delay=".4s">
               <div class="pu-waiver-card" style="--card-accent:#059669;">
                  <div class="pu-waiver-card-icon" style="background:#d1fae5;">
                     <i class="fas fa-wheelchair" style="color:#059669;"></i>
                  </div>
                  <h5>Physically Challenged &amp; Tribal Students</h5>
                  <p>Supporting inclusivity and equal access to education for physically challenged students and members of tribal communities.</p>
                  <div class="pu-waiver-pct-pill" style="background:#d1fae5;color:#065f46;">Up to 50% Waiver</div>
                  <div class="pu-stackable-note">
                     <i class="fas fa-layer-group" style="color:var(--pu-green);"></i>
                     <span>Stackable with merit scholarship</span>
                  </div>
               </div>
            </div>

            <!-- Cultural & Sports -->
            <div class="col-lg-3 col-md-6 wow itfadeUp" data-wow-duration=".8s" data-wow-delay=".5s">
               <div class="pu-waiver-card" style="--card-accent:#d97706;">
                  <div class="pu-waiver-card-icon" style="background:#fef3c7;">
                     <i class="fas fa-futbol" style="color:#d97706;"></i>
                  </div>
                  <h5>Cultural Activists &amp; Sports Persons</h5>
                  <p>Recognizing students who represent Prime University in cultural events, competitions, or sports at regional and national level.</p>
                  <div class="pu-waiver-pct-pill" style="background:#fef3c7;color:#92400e;">Up to 50% Waiver</div>
                  <div class="pu-stackable-note">
                     <i class="fas fa-layer-group" style="color:var(--pu-green);"></i>
                     <span>Stackable with merit scholarship</span>
                  </div>
               </div>
            </div>

         </div>

         <!-- Stackable note callout -->
         <div class="row mt-40 wow itfadeUp" data-wow-duration=".8s" data-wow-delay=".3s">
            <div class="col-md-10 mx-auto">
               <div style="background:linear-gradient(90deg,#eff6ff,#f0fdf4);border-radius:14px;padding:20px 28px;display:flex;align-items:flex-start;gap:16px;border:1px solid #bfdbfe;">
                  <div style="width:44px;height:44px;background:#dbeafe;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                     <i class="fas fa-layer-group" style="color:var(--pu-blue);font-size:1.1rem;"></i>
                  </div>
                  <div>
                     <strong style="color:var(--pu-navy);font-size:.95rem;">All flat waivers are stackable with merit and attendance-based scholarships.</strong>
                     <p class="mb-0 mt-1" style="font-size:.85rem;color:#4b5563;line-height:1.6;">Flat waivers apply to tuition fees for all semesters throughout the student's academic career. They are processed separately from GPA-based and merit scholarships and are non-competitive.</p>
                  </div>
               </div>
            </div>
         </div>

      </div>
   </section>

   <!-- ════════════════════════════════════════════════════════════════
        SECTION 5 – SUMMARY TABLE
   ════════════════════════════════════════════════════════════════ -->
   <section class="pu-sc-section">
      <div class="container">
         <div class="row mb-40">
            <div class="col-12 text-center wow itfadeUp" data-wow-duration=".8s" data-wow-delay=".2s">
               <span class="pu-section-tag"><i class="fas fa-table me-1"></i> Quick Reference</span>
               <h2 class="pu-section-title mt-2">Scholarship &amp; Waiver Summary</h2>
               <p class="pu-section-lead mx-auto mt-3">A complete overview of all available scholarships and waivers at Prime University.</p>
            </div>
         </div>

         <div class="row wow itfadeUp" data-wow-duration=".9s" data-wow-delay=".3s">
            <div class="col-12">
               <div class="pu-summary-table-wrap">
                  <div class="table-responsive">
                     <table class="table pu-summary-table mb-0">
                        <thead>
                           <tr>
                              <th>Scholarship / Waiver</th>
                              <th>Applicable From</th>
                              <th>Basis</th>
                              <th>Max Benefit</th>
                              <th>Stackable?</th>
                           </tr>
                        </thead>
                        <tbody>
                           <tr>
                              <td><strong>GPA-Based Scholarship</strong></td>
                              <td>1st Semester</td>
                              <td>SSC + HSC Combined GPA</td>
                              <td><span class="badge" style="background:#d1fae5;color:#065f46;">100%</span></td>
                              <td><i class="fas fa-check" style="color:var(--pu-green);"></i> Yes</td>
                           </tr>
                           <tr>
                              <td><strong>Merit-Based Scholarship</strong></td>
                              <td>2nd Semester Onward</td>
                              <td>Previous Semester GPA</td>
                              <td><span class="badge" style="background:#dbeafe;color:#1e40af;">100%</span></td>
                              <td><i class="fas fa-check" style="color:var(--pu-green);"></i> Yes</td>
                           </tr>
                           <tr>
                              <td><strong>Attendance-Based Scholarship</strong></td>
                              <td>Any Semester</td>
                              <td>Attendance Record</td>
                              <td><span class="badge" style="background:#e0f2fe;color:#0c4a6e;">Partial</span></td>
                              <td><i class="fas fa-check" style="color:var(--pu-green);"></i> Yes</td>
                           </tr>
                           <tr>
                              <td><strong>Children of Freedom Fighters</strong></td>
                              <td>All Semesters</td>
                              <td>Freedom Fighter Parent Cert.</td>
                              <td><span class="badge" style="background:#fee2e2;color:#991b1b;">100%</span></td>
                              <td><i class="fas fa-check" style="color:var(--pu-green);"></i> Yes</td>
                           </tr>
                           <tr>
                              <td><strong>Spouse &amp; Siblings Waiver</strong></td>
                              <td>All Semesters</td>
                              <td>Family Relationship Proof</td>
                              <td><span class="badge" style="background:#ede9fe;color:#5b21b6;">Up to 100%</span></td>
                              <td><i class="fas fa-check" style="color:var(--pu-green);"></i> Yes</td>
                           </tr>
                           <tr>
                              <td><strong>Physically Challenged &amp; Tribal</strong></td>
                              <td>All Semesters</td>
                              <td>Certificate / Document</td>
                              <td><span class="badge" style="background:#d1fae5;color:#065f46;">Up to 50%</span></td>
                              <td><i class="fas fa-check" style="color:var(--pu-green);"></i> Yes</td>
                           </tr>
                           <tr>
                              <td><strong>Cultural Activists &amp; Sports Persons</strong></td>
                              <td>All Semesters</td>
                              <td>Participation &amp; Achievement</td>
                              <td><span class="badge" style="background:#fef3c7;color:#92400e;">Up to 50%</span></td>
                              <td><i class="fas fa-check" style="color:var(--pu-green);"></i> Yes</td>
                           </tr>
                        </tbody>
                     </table>
                  </div>
               </div>
            </div>
         </div>
      </div>
   </section>

   <!-- ════════════════════════════════════════════════════════════════
        CTA SECTION
   ════════════════════════════════════════════════════════════════ -->
   <section class="pu-sc-section pu-sc-section-alt">
      <div class="container">
         <div class="pu-sc-cta wow itfadeUp" data-wow-duration=".9s" data-wow-delay=".2s">
            <div class="position-relative z-index-1">
               <i class="fas fa-graduation-cap" style="font-size:2.5rem;color:rgba(255,255,255,.35);display:block;margin-bottom:16px;"></i>
               <h3>Ready to Start Your Scholarship Journey?</h3>
               <p>Apply today and take advantage of Prime University's commitment to accessible, affordable, and world-class education for every student.</p>
               <div class="d-flex flex-wrap gap-3 justify-content-center">
                  <a href="admission.php" class="pu-btn-gold">
                     <i class="fas fa-paper-plane"></i> Apply for Admission
                  </a>
                  <a href="contact.php" class="pu-btn-outline-white">
                     <i class="fas fa-phone-alt"></i> Contact the Office
                  </a>
               </div>
            </div>
         </div>
      </div>
   </section>

   <!-- ════════════════════════════════════════════════════════════════
        NEWSLETTER
   ════════════════════════════════════════════════════════════════ -->
   <div class="it-newsletter-area it-newsletter-style-2">
      <div class="container">
         <div class="it-newsletter-wrap theme-bg z-index-2 wow itfadeUp" data-wow-duration=".9s" data-wow-delay=".3s">
            <img class="it-newsletter-shape-1" src="/assets/img/shape/newsletter-2-1.png" alt="">
            <div class="row align-items-center">
               <div class="col-lg-6">
                  <div class="it-newsletter-2-left">
                     <h4 class="it-newsletter-2-title text-white mb-0">Sign up for the latest<br> scholarship news &amp; updates</h4>
                  </div>
               </div>
               <div class="col-lg-6">
                  <div class="it-newsletter-input-box">
                     <form class="input-wrap p-relative" action="#">
                        <input type="email" placeholder="Enter your Email Address">
                        <button type="submit">
                           <svg width="26" height="27" viewBox="0 0 26 27" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M24.7282 1.82586C24.3517 1.44485 23.8834 1.16736 23.3684 1.02022C22.8534 0.873071 22.3091 0.861239 21.7882 0.985864L4.9882 4.52436C4.0207 4.65705 3.10947 5.05722 2.35711 5.6798C1.60475 6.30238 1.04115 7.12265 0.729789 8.04823C0.418424 8.97381 0.371658 9.96795 0.594756 10.9187C0.817855 11.8694 1.30196 12.739 1.99255 13.4294L3.79645 15.2323C3.89408 15.3299 3.97151 15.4458 4.0243 15.5733C4.07709 15.7009 4.1042 15.8376 4.1041 15.9757V19.3021C4.10641 19.7698 4.21408 20.2309 4.4191 20.6513L4.4107 20.6587L4.438 20.686C4.74566 21.3045 5.24816 21.8048 5.8681 22.1098L5.8954 22.1371L5.90275 22.1287C6.32312 22.3337 6.78429 22.4413 7.252 22.4437H10.5784C10.8567 22.4434 11.1237 22.5537 11.3207 22.7503L13.1236 24.5531C13.6071 25.042 14.1827 25.4304 14.817 25.6958C15.4514 25.9613 16.132 26.0986 16.8196 26.0998C17.3926 26.0991 17.9618 26.0054 18.5048 25.8226C19.422 25.5214 20.2367 24.97 20.8571 24.2305C21.4775 23.4909 21.8789 22.5928 22.016 21.6373L25.5598 4.80051C25.6909 4.27514 25.6832 3.72471 25.5374 3.20322C25.3916 2.68173 25.1127 2.20709 24.7282 1.82586ZM5.28325 13.7497L3.4783 11.9468C3.058 11.5366 2.76343 11.0151 2.62915 10.4434C2.49487 9.87166 2.52645 9.27351 2.7202 8.71911C2.90804 8.15035 3.25528 7.64751 3.72063 7.27039C4.18598 6.89327 4.74986 6.65774 5.3452 6.59181L21.9782 3.09006L6.202 18.8684V15.9757C6.20359 15.5623 6.12321 15.1528 5.96551 14.7707C5.80781 14.3886 5.57591 14.0416 5.28325 13.7497ZM19.9528 21.2782C19.8722 21.8581 19.6315 22.4041 19.2578 22.8549C18.8841 23.3056 18.3921 23.6433 17.8372 23.83C17.2822 24.0167 16.6862 24.045 16.116 23.9118C15.5459 23.7786 15.0241 23.4891 14.6093 23.0758L12.8033 21.2698C12.5118 20.9767 12.1651 20.7443 11.7832 20.586C11.4013 20.4278 10.9918 20.3468 10.5784 20.3479H7.68565L23.464 4.57476L19.9528 21.2782Z" fill="currentcolor"/></svg>
                        </button>
                     </form>
                  </div>
               </div>
            </div>
         </div>
      </div>
   </div>

   </main>

<?php include __DIR__ . '/includes/footer.php'; ?>

   <!-- JS Libraries -->
   <script src="/assets/js/jquery.js"></script>
   <script src="/assets/js/bootstrap.bundle.min.js"></script>
   <script src="/assets/js/purecounter.js"></script>
   <script src="/assets/js/range-slider.js"></script>
   <script src="/assets/js/nice-select.js"></script>
   <script src="/assets/js/swiper-bundle.min.js"></script>
   <script src="/assets/js/isotope-pkgd.js"></script>
   <script src="/assets/js/slick.min.js"></script>
   <script src="/assets/js/wow.js"></script>
   <script src="/assets/js/countdown.js"></script>
   <script src="/assets/js/magnific-popup.js"></script>
   <script src="/assets/js/imagesloaded-pkgd.js"></script>
   <script src="/assets/js/parallax.js"></script>
   <script src="/assets/js/slider.js"></script>
   <script src="/assets/js/main.js"></script>

   <script>
   (function () {
      'use strict';

      /* ── Animate waiver progress bars on scroll ── */
      var bars = document.querySelectorAll('.pu-waiver-bar-fill');
      var barsAnimated = false;

      function animateBars() {
         if (barsAnimated) return;
         var trigger = window.innerHeight * 0.85;
         var section = document.querySelector('.pu-gpa-table');
         if (!section) return;
         var rect = section.getBoundingClientRect();
         if (rect.top < trigger) {
            bars.forEach(function(bar) {
               var w = bar.style.width;
               bar.style.width = '0';
               bar.style.transition = 'width 1s ease';
               setTimeout(function() { bar.style.width = w; }, 60);
            });
            barsAnimated = true;
         }
      }

      /* ── Animate merit tier tracks on scroll ── */
      var tierTracks = document.querySelectorAll('.pu-merit-tier-track-fill');
      var tracksAnimated = false;

      function animateTracks() {
         if (tracksAnimated) return;
         var trigger = window.innerHeight * 0.85;
         var section = document.querySelector('.pu-merit-visual');
         if (!section) return;
         var rect = section.getBoundingClientRect();
         if (rect.top < trigger) {
            tierTracks.forEach(function(track) {
               var w = track.style.width;
               track.style.width = '0';
               track.style.transition = 'width 1.2s ease';
               setTimeout(function() { track.style.width = w; }, 80);
            });
            tracksAnimated = true;
         }
      }

      window.addEventListener('scroll', function() {
         animateBars();
         animateTracks();
      }, { passive: true });

      /* Initial check (if already in viewport) */
      animateBars();
      animateTracks();

      /* ── Smooth scroll for anchor links ── */
      document.querySelectorAll('a[href^="#"]').forEach(function(anchor) {
         anchor.addEventListener('click', function(e) {
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
