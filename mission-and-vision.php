<?php
require_once __DIR__ . '/includes/config.php';

$page_title = 'Mission & Vision – Prime University';
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <title><?= fh($page_title) ?></title>
   <meta name="description" content="Discover the mission and vision of Prime University – committed to providing quality higher education that equips students to become productive, lifelong learners.">
   <meta name="viewport" content="width=device-width, initial-scale=1">

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
   /* ════════════════════════════════════════════════════
      MISSION & VISION PAGE – STYLES
      ════════════════════════════════════════════════════ */
   :root {
     --mv-navy:  #002147;
     --mv-gold:  #FFB81C;
     --mv-blue:  #1a4faf;
     --mv-light: #f4f7fb;
     --mv-text:  #334155;
     --mv-white: #ffffff;
     --mv-radius: 18px;
     --mv-shadow: 0 8px 40px rgba(0,33,71,.10);
     --mv-shadow-h: 0 16px 56px rgba(0,33,71,.18);
     --mv-trans: .35s cubic-bezier(.4,0,.2,1);
   }

   /* ── Hero ──────────────────────────────────────────── */
   .mv-hero {
     background: linear-gradient(135deg, #001530 0%, #002f68 55%, #1a4faf 100%);
     padding: 110px 0 90px;
     position: relative;
     overflow: hidden;
   }
   .mv-hero::before {
     content: '';
     position: absolute; inset: 0;
     background:
       radial-gradient(ellipse 60% 80% at 80% 50%, rgba(255,184,28,.10) 0%, transparent 70%),
       radial-gradient(ellipse 40% 60% at 10% 80%, rgba(26,79,175,.35) 0%, transparent 60%);
     pointer-events: none;
   }
   .mv-hero .hero-circle {
     position: absolute; border-radius: 50%; pointer-events: none;
     animation: mvFloat 8s ease-in-out infinite;
   }
   .mv-hero .hero-circle.c1 { width:380px; height:380px; background:rgba(255,184,28,.07); top:-90px; right:-70px; animation-delay:0s; }
   .mv-hero .hero-circle.c2 { width:190px; height:190px; background:rgba(255,255,255,.05); bottom:20px; left:4%; animation-delay:3s; }
   .mv-hero .hero-circle.c3 { width:110px; height:110px; background:rgba(255,184,28,.10); top:35%; right:22%; animation-delay:1.5s; }
   @keyframes mvFloat {
     0%,100% { transform: translateY(0) scale(1); }
     50%      { transform: translateY(-20px) scale(1.05); }
   }
   .mv-hero .breadcrumb-nav {
     display: flex; align-items: center; gap: 8px;
     font-size: .82rem; font-weight: 600; letter-spacing: .05em;
     text-transform: uppercase; color: rgba(255,255,255,.65); margin-bottom: 24px;
   }
   .mv-hero .breadcrumb-nav a { color: var(--mv-gold); text-decoration: none; transition: color var(--mv-trans); }
   .mv-hero .breadcrumb-nav a:hover { color: #fff; }
   .mv-hero .breadcrumb-nav .sep { color: rgba(255,255,255,.35); }
   .mv-hero-tag {
     display: inline-flex; align-items: center; gap: 8px;
     background: rgba(255,184,28,.18); color: var(--mv-gold);
     font-size: .77rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase;
     padding: 7px 18px; border-radius: 50px; border: 1px solid rgba(255,184,28,.35); margin-bottom: 20px;
   }
   .mv-hero-tag .dot {
     width: 7px; height: 7px; border-radius: 50%; background: var(--mv-gold);
     animation: mvPulse 1.8s ease-in-out infinite;
   }
   @keyframes mvPulse { 0%,100%{opacity:1;transform:scale(1);} 50%{opacity:.5;transform:scale(1.6);} }
   .mv-hero h1 {
     font-size: clamp(2.2rem, 5.5vw, 3.6rem);
     font-weight: 800; color: #fff; line-height: 1.12;
     margin-bottom: 20px; font-family: var(--it-ff-heading,'Spartan-Bold',sans-serif);
   }
   .mv-hero h1 .accent { color: var(--mv-gold); }
   .mv-hero p.hero-sub {
     font-size: clamp(.95rem, 2vw, 1.15rem);
     color: rgba(255,255,255,.78); max-width: 560px; line-height: 1.75; margin-bottom: 0;
   }

   /* ── Pillars strip ─────────────────────────────────── */
   .mv-pillars-strip { background: var(--mv-navy); }
   .mv-pillars-inner {
     display: flex; flex-wrap: wrap; justify-content: center;
     border-bottom: 3px solid var(--mv-gold);
   }
   .mv-pillar-item {
     flex: 1 1 160px; display: flex; flex-direction: column;
     align-items: center; justify-content: center;
     padding: 22px 16px; border-right: 1px solid rgba(255,255,255,.1);
     transition: background var(--mv-trans); text-align: center;
   }
   .mv-pillar-item:last-child { border-right: none; }
   .mv-pillar-item:hover { background: rgba(255,255,255,.05); }
   .mv-pillar-item .p-icon { font-size: 1.4rem; color: var(--mv-gold); margin-bottom: 6px; }
   .mv-pillar-item .p-label { font-size: .72rem; font-weight: 700; color: rgba(255,255,255,.65); text-transform: uppercase; letter-spacing: .07em; }

   /* ── Shared section helpers ────────────────────────── */
   .mv-section { padding: 90px 0; }
   .mv-section.bg-light { background: var(--mv-light); }
   .mv-section.bg-white { background: var(--mv-white); }
   .mv-section-tag {
     display: inline-block; font-size: .72rem; font-weight: 700;
     letter-spacing: .14em; text-transform: uppercase;
     padding: 5px 16px; border-radius: 50px; margin-bottom: 14px;
   }
   .mv-section-tag.blue { color: var(--mv-blue); background: rgba(26,79,175,.1); }
   .mv-section-tag.gold { color: #b37e00; background: rgba(255,184,28,.15); }
   .mv-section-title {
     font-size: clamp(1.8rem, 3.5vw, 2.6rem); font-weight: 800;
     color: var(--mv-navy); line-height: 1.18; margin-bottom: 16px;
     font-family: var(--it-ff-heading,'Spartan-Bold',sans-serif);
   }
   .mv-divider { width: 56px; height: 4px; background: linear-gradient(90deg, var(--mv-gold), var(--mv-blue)); border-radius: 2px; margin-bottom: 20px; }
   .mv-body-text { font-size: 1.03rem; color: var(--mv-text); line-height: 1.85; }

   /* ── Vision card ───────────────────────────────────── */
   .vision-card {
     background: #fff; border-radius: var(--mv-radius);
     box-shadow: var(--mv-shadow); padding: 36px 32px;
     height: 100%; position: relative; overflow: hidden;
     transition: transform var(--mv-trans), box-shadow var(--mv-trans);
   }
   .vision-card::before {
     content: ''; position: absolute; top: 0; left: 0; right: 0;
     height: 4px; background: linear-gradient(90deg, var(--mv-blue), var(--mv-gold));
   }
   .vision-card:hover { transform: translateY(-6px); box-shadow: var(--mv-shadow-h); }
   .vision-card .vc-icon {
     width: 60px; height: 60px; border-radius: 16px;
     display: flex; align-items: center; justify-content: center;
     font-size: 1.4rem; margin-bottom: 20px;
   }
   .vision-card .vc-icon.blue { background: rgba(26,79,175,.1); color: var(--mv-blue); }
   .vision-card .vc-icon.gold { background: rgba(255,184,28,.15); color: #c89000; }
   .vision-card .vc-icon.teal { background: rgba(13,148,136,.12); color: #0d9488; }
   .vision-card .vc-icon.navy { background: rgba(0,33,71,.1); color: var(--mv-navy); }
   .vision-card h5 {
     font-size: 1.02rem; font-weight: 800; color: var(--mv-navy); margin-bottom: 10px;
   }
   .vision-card p { font-size: .88rem; color: var(--mv-text); line-height: 1.7; margin: 0; }

   /* ── Quote block ───────────────────────────────────── */
   .mv-quote {
     background: linear-gradient(135deg, var(--mv-navy) 0%, #1a4faf 100%);
     border-radius: var(--mv-radius); padding: 48px 52px;
     position: relative; overflow: hidden;
     box-shadow: 0 12px 48px rgba(0,33,71,.22);
   }
   .mv-quote::before {
     content: '\201C';
     position: absolute; top: -10px; left: 28px;
     font-size: 10rem; font-weight: 900; line-height: 1;
     color: rgba(255,184,28,.15); font-family: Georgia,serif;
     pointer-events: none; user-select: none;
   }
   .mv-quote p {
     font-size: clamp(1rem, 2.2vw, 1.22rem); color: rgba(255,255,255,.92);
     line-height: 1.8; font-style: italic; margin: 0; position: relative; z-index: 1;
   }
   .mv-quote .q-author {
     margin-top: 24px; display: flex; align-items: center; gap: 14px; position: relative; z-index: 1;
   }
   .mv-quote .q-author .q-line {
     width: 36px; height: 2px; background: var(--mv-gold);
   }
   .mv-quote .q-author span {
     font-size: .82rem; font-weight: 700; color: var(--mv-gold);
     letter-spacing: .08em; text-transform: uppercase;
   }
   @media (max-width: 575px) {
     .mv-quote { padding: 36px 28px; }
   }

   /* ── Mission block ─────────────────────────────────── */
   .mission-big-card {
     background: #fff; border-radius: var(--mv-radius);
     box-shadow: var(--mv-shadow); overflow: hidden;
   }
   .mission-big-card .mbc-header {
     background: linear-gradient(135deg, var(--mv-navy) 0%, #1a4faf 100%);
     padding: 40px 44px 36px;
     position: relative; overflow: hidden;
   }
   .mission-big-card .mbc-header::after {
     content: ''; position: absolute; right: -40px; top: -40px;
     width: 220px; height: 220px; border-radius: 50%;
     background: rgba(255,184,28,.10);
   }
   .mission-big-card .mbc-header .mbc-icon {
     width: 70px; height: 70px; border-radius: 20px;
     background: rgba(255,184,28,.2); display: flex; align-items: center;
     justify-content: center; font-size: 1.8rem; color: var(--mv-gold);
     margin-bottom: 20px; position: relative; z-index: 1;
   }
   .mission-big-card .mbc-header h3 {
     font-size: 1.8rem; font-weight: 800; color: #fff;
     margin-bottom: 10px; position: relative; z-index: 1;
   }
   .mission-big-card .mbc-header p {
     font-size: .95rem; color: rgba(255,255,255,.78); max-width: 520px;
     line-height: 1.7; margin: 0; position: relative; z-index: 1;
   }
   .mission-big-card .mbc-body { padding: 40px 44px; }
   @media (max-width: 575px) {
     .mission-big-card .mbc-header,
     .mission-big-card .mbc-body { padding: 28px 24px; }
   }

   /* ── Mission pillars ───────────────────────────────── */
   .mission-pillar {
     display: flex; gap: 18px; align-items: flex-start;
     background: var(--mv-light); border-radius: 14px;
     padding: 22px 24px; margin-bottom: 16px;
     transition: transform var(--mv-trans), box-shadow var(--mv-trans);
   }
   .mission-pillar:last-child { margin-bottom: 0; }
   .mission-pillar:hover { transform: translateX(6px); box-shadow: 0 6px 24px rgba(0,33,71,.10); }
   .mission-pillar .mp-icon {
     width: 48px; height: 48px; border-radius: 13px; flex-shrink: 0;
     display: flex; align-items: center; justify-content: center; font-size: 1.1rem;
   }
   .mission-pillar .mp-icon.blue  { background: rgba(26,79,175,.12); color: var(--mv-blue); }
   .mission-pillar .mp-icon.gold  { background: rgba(255,184,28,.18); color: #c89000; }
   .mission-pillar .mp-icon.teal  { background: rgba(13,148,136,.12); color: #0d9488; }
   .mission-pillar .mp-icon.green { background: rgba(5,150,105,.12);  color: #059669; }
   .mission-pillar .mp-icon.red   { background: rgba(220,38,38,.10);  color: #dc2626; }
   .mission-pillar h6 { font-size: .92rem; font-weight: 800; color: var(--mv-navy); margin-bottom: 4px; }
   .mission-pillar p  { font-size: .84rem; color: var(--mv-text); line-height: 1.6; margin: 0; }

   /* ── Values section ────────────────────────────────── */
   .value-card {
     text-align: center; background: #fff; border-radius: var(--mv-radius);
     box-shadow: var(--mv-shadow); padding: 36px 24px; height: 100%;
     transition: transform var(--mv-trans), box-shadow var(--mv-trans);
     position: relative; overflow: hidden;
   }
   .value-card::after {
     content: ''; position: absolute; bottom: 0; left: 0; right: 0;
     height: 3px; background: linear-gradient(90deg, var(--mv-gold), var(--mv-blue));
     transform: scaleX(0); transform-origin: left;
     transition: transform var(--mv-trans);
   }
   .value-card:hover { transform: translateY(-8px); box-shadow: var(--mv-shadow-h); }
   .value-card:hover::after { transform: scaleX(1); }
   .value-card .val-icon {
     width: 72px; height: 72px; border-radius: 22px;
     display: flex; align-items: center; justify-content: center;
     font-size: 1.6rem; margin: 0 auto 20px;
   }
   .value-card h5 { font-size: 1rem; font-weight: 800; color: var(--mv-navy); margin-bottom: 10px; }
   .value-card p  { font-size: .84rem; color: var(--mv-text); line-height: 1.65; margin: 0; }

   /* ── CTA banner ────────────────────────────────────── */
   .mv-cta-banner {
     background: linear-gradient(135deg, #002147 0%, #1a4faf 100%);
     border-radius: var(--mv-radius); padding: 56px 52px;
     text-align: center; position: relative; overflow: hidden;
     box-shadow: 0 16px 56px rgba(0,33,71,.22);
   }
   .mv-cta-banner::before {
     content: ''; position: absolute; right: -60px; top: -60px;
     width: 260px; height: 260px; border-radius: 50%;
     background: rgba(255,184,28,.10);
   }
   .mv-cta-banner::after {
     content: ''; position: absolute; left: -40px; bottom: -40px;
     width: 180px; height: 180px; border-radius: 50%;
     background: rgba(255,255,255,.05);
   }
   .mv-cta-banner h2 {
     font-size: clamp(1.6rem, 3.5vw, 2.4rem); font-weight: 800;
     color: #fff; margin-bottom: 16px; position: relative; z-index: 1;
   }
   .mv-cta-banner p {
     font-size: 1.02rem; color: rgba(255,255,255,.78);
     max-width: 520px; margin: 0 auto 32px; line-height: 1.75;
     position: relative; z-index: 1;
   }
   .mv-cta-btns { display: inline-flex; gap: 14px; flex-wrap: wrap; justify-content: center; position: relative; z-index: 1; }
   .mv-cta-btns .btn-gold {
     padding: 14px 32px; border-radius: 50px; font-weight: 700; font-size: .95rem;
     background: var(--mv-gold); color: var(--mv-navy); text-decoration: none;
     border: 2px solid var(--mv-gold); display: inline-flex; align-items: center; gap: 8px;
     transition: transform var(--mv-trans), box-shadow var(--mv-trans);
   }
   .mv-cta-btns .btn-gold:hover { transform: translateY(-3px); box-shadow: 0 8px 28px rgba(255,184,28,.4); color: var(--mv-navy); }
   .mv-cta-btns .btn-outline {
     padding: 14px 32px; border-radius: 50px; font-weight: 700; font-size: .95rem;
     background: transparent; color: #fff; text-decoration: none;
     border: 2px solid rgba(255,255,255,.5); display: inline-flex; align-items: center; gap: 8px;
     transition: background var(--mv-trans), border-color var(--mv-trans);
   }
   .mv-cta-btns .btn-outline:hover { background: rgba(255,255,255,.12); border-color: #fff; color: #fff; }
   @media (max-width: 575px) {
     .mv-cta-banner { padding: 40px 24px; }
   }
   </style>
<?php include __DIR__ . '/includes/meta-pixel.php'; ?>
</head>
<body id="body" class="it-magic-cursor">

   <div id="preloader">
      <div class="preloader">
         <span></span>
         <span></span>
      </div>
   </div>

   <div id="magic-cursor">
      <div id="ball"></div>
   </div>

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
            <div class="it-info-wrapper mb-20 d-flex align-items-center">
               <div class="itoffcanvas__info-icon"><a href="#"><i class="fas fa-map-marker-alt"></i></a></div>
               <div class="itoffcanvas__info-address">
                  <span>Location</span>
                  <a href="https://maps.google.com/?q=Prime+University+Dhaka" target="_blank">114/116 Mazar Road, Mirpur-1, Dhaka 1216</a>
               </div>
            </div>
         </div>
      </div>
   </div>
   <div class="body-overlay"></div>
   <!-- offcanvas end -->

   <header class="it-header-height">
      <?php include __DIR__ . '/includes/header-top.php'; ?>
      <?php include __DIR__ . '/includes/nav-menu.php'; ?>
   </header>

   <!-- ══════════════════════════════════════════════════════
        HERO
        ══════════════════════════════════════════════════════ -->
   <section class="mv-hero">
      <div class="hero-circle c1"></div>
      <div class="hero-circle c2"></div>
      <div class="hero-circle c3"></div>
      <div class="container position-relative" style="z-index:2;">
         <nav class="breadcrumb-nav">
            <a href="<?= fh(SITE_URL) ?>/index.php">Home</a>
            <span class="sep">/</span>
            <span>About Us</span>
            <span class="sep">/</span>
            <span>Mission &amp; Vision</span>
         </nav>
         <div class="mv-hero-tag wow fadeInUp" data-wow-delay=".05s">
            <span class="dot"></span> Our Purpose &amp; Direction
         </div>
         <h1 class="wow fadeInUp" data-wow-delay=".12s">
            Mission &amp; <span class="accent">Vision</span>
         </h1>
         <p class="hero-sub wow fadeInUp" data-wow-delay=".22s">
            Shaping the future through quality education, lifelong learning,
            and a commitment to excellence that resonates across the globe.
         </p>
      </div>
   </section>
   <!-- ── HERO END ────────────────────────────────────────── -->

   <!-- ══════════════════════════════════════════════════════
        PILLARS STRIP
        ══════════════════════════════════════════════════════ -->
   <div class="mv-pillars-strip">
      <div class="container">
         <div class="mv-pillars-inner">
            <div class="mv-pillar-item">
               <div class="p-icon"><i class="fas fa-star"></i></div>
               <div class="p-label">Quality Education</div>
            </div>
            <div class="mv-pillar-item">
               <div class="p-icon"><i class="fas fa-globe-asia"></i></div>
               <div class="p-label">Global Perspective</div>
            </div>
            <div class="mv-pillar-item">
               <div class="p-icon"><i class="fas fa-book-reader"></i></div>
               <div class="p-label">Lifelong Learning</div>
            </div>
            <div class="mv-pillar-item">
               <div class="p-icon"><i class="fas fa-flask"></i></div>
               <div class="p-label">Research &amp; Innovation</div>
            </div>
            <div class="mv-pillar-item">
               <div class="p-icon"><i class="fas fa-users"></i></div>
               <div class="p-label">Community Growth</div>
            </div>
            <div class="mv-pillar-item">
               <div class="p-icon"><i class="fas fa-laptop-code"></i></div>
               <div class="p-label">Digital Bangladesh</div>
            </div>
         </div>
      </div>
   </div>
   <!-- ── PILLARS END ─────────────────────────────────────── -->

   <!-- ══════════════════════════════════════════════════════
        VISION SECTION
        ══════════════════════════════════════════════════════ -->
   <section class="mv-section bg-white">
      <div class="container">
         <div class="row align-items-center g-5">

            <!-- Text column -->
            <div class="col-lg-6 wow fadeInLeft" data-wow-delay=".10s">
               <span class="mv-section-tag blue">Our Vision</span>
               <h2 class="mv-section-title">Empowering Minds,<br>Shaping <span style="color:var(--mv-blue);">Futures</span></h2>
               <div class="mv-divider"></div>
               <p class="mv-body-text mb-4">
                  The prime goal of Prime University is to provide <strong>high quality education</strong> at
                  undergraduate and postgraduate levels to meet the needs of a dynamic society around the globe.
               </p>
               <p class="mv-body-text mb-0">
                  The academic goal of the University is not just to make the students pass the examination
                  but <strong>equip them with the means to become productive members of the community</strong>
                  and continue the practice of lifelong learning.
               </p>
            </div>

            <!-- Cards column -->
            <div class="col-lg-6">
               <div class="row g-4">
                  <div class="col-sm-6 wow fadeInUp" data-wow-delay=".10s">
                     <div class="vision-card">
                        <div class="vc-icon blue"><i class="fas fa-graduation-cap"></i></div>
                        <h5>Academic Excellence</h5>
                        <p>Rigorous undergraduate and postgraduate programmes designed to meet the demands of an ever-changing global society.</p>
                     </div>
                  </div>
                  <div class="col-sm-6 wow fadeInUp" data-wow-delay=".18s">
                     <div class="vision-card">
                        <div class="vc-icon gold"><i class="fas fa-globe"></i></div>
                        <h5>Global Reach</h5>
                        <p>Preparing graduates who can compete and contribute on the international stage with knowledge and confidence.</p>
                     </div>
                  </div>
                  <div class="col-sm-6 wow fadeInUp" data-wow-delay=".26s">
                     <div class="vision-card">
                        <div class="vc-icon teal"><i class="fas fa-seedling"></i></div>
                        <h5>Lifelong Learning</h5>
                        <p>Cultivating a passion for continuous learning that extends well beyond the classroom and throughout students' careers.</p>
                     </div>
                  </div>
                  <div class="col-sm-6 wow fadeInUp" data-wow-delay=".34s">
                     <div class="vision-card">
                        <div class="vc-icon navy"><i class="fas fa-users"></i></div>
                        <h5>Productive Community</h5>
                        <p>Developing responsible citizens who contribute meaningfully to society, economy, and culture at every level.</p>
                     </div>
                  </div>
               </div>
            </div>

         </div>
      </div>
   </section>
   <!-- ── VISION END ──────────────────────────────────────── -->

   <!-- ══════════════════════════════════════════════════════
        VISION QUOTE
        ══════════════════════════════════════════════════════ -->
   <section class="mv-section bg-light" style="padding:60px 0;">
      <div class="container">
         <div class="mv-quote wow fadeInUp" data-wow-delay=".10s">
            <p>
               "The University is not just a place to earn a degree — it is a platform where
               curiosity is nurtured, character is formed, and the seeds of a better tomorrow are sown.
               We believe in an education that transcends examinations and transforms lives."
            </p>
            <div class="q-author">
               <span class="q-line"></span>
               <span>Prime University – Academic Philosophy</span>
            </div>
         </div>
      </div>
   </section>
   <!-- ── QUOTE END ───────────────────────────────────────── -->

   <!-- ══════════════════════════════════════════════════════
        MISSION SECTION
        ══════════════════════════════════════════════════════ -->
   <section class="mv-section bg-white">
      <div class="container">

         <div class="text-center mb-56 wow fadeInUp" data-wow-delay=".08s">
            <span class="mv-section-tag gold">Our Mission</span>
            <h2 class="mv-section-title">Committed to <span style="color:var(--mv-gold);">Excellence</span> in<br>Higher Education</h2>
            <div class="mv-divider mx-auto"></div>
         </div>

         <div class="row g-5 align-items-start">

            <!-- Big mission card -->
            <div class="col-lg-6 wow fadeInLeft" data-wow-delay=".10s">
               <div class="mission-big-card">
                  <div class="mbc-header">
                     <div class="mbc-icon"><i class="fas fa-bullseye"></i></div>
                     <h3>The Mission Statement</h3>
                     <p>
                        Prime University is an institution of higher learning and research dedicated to
                        providing <strong style="color:var(--mv-gold);">quality higher education</strong>
                        commensurate with investment.
                     </p>
                  </div>
                  <div class="mbc-body">
                     <p class="mv-body-text mb-4">
                        The Courses and Curriculum are so designed as to enable a student to enter into
                        the world of work and pursue higher academic and professional goals with a
                        <strong>sound academic foundation</strong>.
                     </p>
                     <p class="mv-body-text mb-4">
                        The University supports its students through its commitment to excellence and
                        demonstrates it through <strong>quality academic service</strong>.
                     </p>
                     <p class="mv-body-text mb-0">
                        The University offers academically rigorous and practical instruction in different
                        disciplines to cater to the growing demand for human resources development in
                        compliance with <strong>Digital Bangladesh</strong> as well as in the context of
                        the present day world.
                     </p>
                  </div>
               </div>
            </div>

            <!-- Mission pillars -->
            <div class="col-lg-6 wow fadeInRight" data-wow-delay=".15s">
               <div class="mission-pillar">
                  <div class="mp-icon blue"><i class="fas fa-book-open"></i></div>
                  <div>
                     <h6>Higher Learning &amp; Research</h6>
                     <p>Fostering an environment of academic inquiry, innovation, and evidence-based research across all disciplines.</p>
                  </div>
               </div>
               <div class="mission-pillar">
                  <div class="mp-icon gold"><i class="fas fa-briefcase"></i></div>
                  <div>
                     <h6>Career-Ready Curriculum</h6>
                     <p>Courses designed to bridge academia and industry, giving students the practical skills employers demand.</p>
                  </div>
               </div>
               <div class="mission-pillar">
                  <div class="mp-icon teal"><i class="fas fa-award"></i></div>
                  <div>
                     <h6>Commitment to Excellence</h6>
                     <p>A relentless dedication to academic quality that is reflected in every aspect of teaching, service, and administration.</p>
                  </div>
               </div>
               <div class="mission-pillar">
                  <div class="mp-icon green"><i class="fas fa-laptop-code"></i></div>
                  <div>
                     <h6>Digital Bangladesh Vision</h6>
                     <p>Aligning academic programmes with the national digital transformation agenda to meet the demands of a modern economy.</p>
                  </div>
               </div>
               <div class="mission-pillar">
                  <div class="mp-icon red"><i class="fas fa-hand-holding-heart"></i></div>
                  <div>
                     <h6>Human Resources Development</h6>
                     <p>Building capable, compassionate professionals who can lead and serve in every sector of society.</p>
                  </div>
               </div>
            </div>

         </div>
      </div>
   </section>
   <!-- ── MISSION END ─────────────────────────────────────── -->

   <!-- ══════════════════════════════════════════════════════
        CORE VALUES
        ══════════════════════════════════════════════════════ -->
   <section class="mv-section bg-light">
      <div class="container">

         <div class="text-center mb-50 wow fadeInUp" data-wow-delay=".05s">
            <span class="mv-section-tag blue">Core Values</span>
            <h2 class="mv-section-title">The Principles That<br>Guide <span style="color:var(--mv-blue);">Everything We Do</span></h2>
            <div class="mv-divider mx-auto"></div>
         </div>

         <div class="row g-4">
            <div class="col-lg-3 col-sm-6 wow fadeInUp" data-wow-delay=".08s">
               <div class="value-card">
                  <div class="val-icon" style="background:rgba(26,79,175,.1);color:var(--mv-blue);">
                     <i class="fas fa-medal"></i>
                  </div>
                  <h5>Excellence</h5>
                  <p>Pursuing the highest standards in every academic, research, and administrative endeavour.</p>
               </div>
            </div>
            <div class="col-lg-3 col-sm-6 wow fadeInUp" data-wow-delay=".16s">
               <div class="value-card">
                  <div class="val-icon" style="background:rgba(255,184,28,.15);color:#c89000;">
                     <i class="fas fa-balance-scale"></i>
                  </div>
                  <h5>Integrity</h5>
                  <p>Acting with transparency, honesty, and ethical responsibility in all that we do.</p>
               </div>
            </div>
            <div class="col-lg-3 col-sm-6 wow fadeInUp" data-wow-delay=".24s">
               <div class="value-card">
                  <div class="val-icon" style="background:rgba(13,148,136,.12);color:#0d9488;">
                     <i class="fas fa-lightbulb"></i>
                  </div>
                  <h5>Innovation</h5>
                  <p>Encouraging creative thinking and novel solutions to the challenges of a dynamic world.</p>
               </div>
            </div>
            <div class="col-lg-3 col-sm-6 wow fadeInUp" data-wow-delay=".32s">
               <div class="value-card">
                  <div class="val-icon" style="background:rgba(5,150,105,.12);color:#059669;">
                     <i class="fas fa-hands-helping"></i>
                  </div>
                  <h5>Inclusivity</h5>
                  <p>Welcoming diverse perspectives and ensuring equal opportunity for every student.</p>
               </div>
            </div>
         </div>

      </div>
   </section>
   <!-- ── VALUES END ──────────────────────────────────────── -->

   <!-- ══════════════════════════════════════════════════════
        CTA BANNER
        ══════════════════════════════════════════════════════ -->
   <section class="mv-section bg-white" style="padding:70px 0;">
      <div class="container">
         <div class="mv-cta-banner wow fadeInUp" data-wow-delay=".10s">
            <h2>Ready to Be Part of Our Story?</h2>
            <p>Join thousands of graduates who have built successful careers and contributed to society through the quality education at Prime University.</p>
            <div class="mv-cta-btns">
               <a href="<?= fh(SITE_URL) ?>/apply-now.php" class="btn-gold">
                  <i class="fas fa-pen-nib"></i> Apply Now
               </a>
               <a href="<?= fh(SITE_URL) ?>/contact.php" class="btn-outline">
                  <i class="fas fa-envelope"></i> Contact Us
               </a>
            </div>
         </div>
      </div>
   </section>
   <!-- ── CTA END ─────────────────────────────────────────── -->

   <?php include __DIR__ . '/includes/footer.php'; ?>

   <?php include __DIR__ . '/includes/scripts.php'; ?>
   <script>
   (function () {
      'use strict';
      if (typeof WOW !== 'undefined') {
         new WOW({ mobile: false, offset: 60 }).init();
      }
   }());
   </script>

</body>
</html>
