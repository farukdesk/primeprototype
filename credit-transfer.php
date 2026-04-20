<?php
require_once __DIR__ . '/includes/config.php';

$page_title = 'Credit Transfer – Prime University';
?>
<!doctype html>
<html class="no-js" lang="en">

<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <title><?= fh($page_title) ?></title>
   <meta name="description" content="Learn about Prime University's credit transfer policy, eligibility criteria, and the step-by-step process for transferring credits from other universities.">
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

   <style>
   /* ══════════════════════════════════════════════
      Credit Transfer Page – Custom Styles
   ══════════════════════════════════════════════ */

   /* ── Variables ───────────────────────────────── */
   :root {
      --ct-navy:   #1a2e5a;
      --ct-blue:   #2563eb;
      --ct-gold:   #FFB81C;
      --ct-light:  #f0f5ff;
      --ct-muted:  #6b7280;
      --ct-border: #e2e8f0;
      --ct-green:  #10b981;
      --ct-red:    #ef4444;
   }

   /* ── Hero ─────────────────────────────────────── */
   .ct-hero {
      background: linear-gradient(135deg, var(--ct-navy) 0%, #1e40af 60%, var(--ct-blue) 100%);
      padding: 100px 0 80px;
      position: relative;
      overflow: hidden;
   }
   .ct-hero::before {
      content: '';
      position: absolute;
      inset: 0;
      background: url('assets/img/shape/breadcrumb-1-bg.png') center/cover no-repeat;
      opacity: .06;
      pointer-events: none;
   }
   /* animated floating circles */
   .ct-hero-blob {
      position: absolute;
      border-radius: 50%;
      background: rgba(255,255,255,.05);
      animation: ct-float 8s ease-in-out infinite;
   }
   .ct-hero-blob-1 { width: 320px; height: 320px; top: -80px; right: -60px; animation-delay: 0s; }
   .ct-hero-blob-2 { width: 180px; height: 180px; bottom: -50px; left: 10%; animation-delay: 3s; }
   .ct-hero-blob-3 { width: 100px; height: 100px; top: 40%; right: 20%; animation-delay: 5s; }
   @keyframes ct-float {
      0%,100% { transform: translateY(0) scale(1); }
      50%      { transform: translateY(-22px) scale(1.04); }
   }

   .ct-breadcrumb a,
   .ct-breadcrumb span { color: rgba(255,255,255,.65); font-size: .85rem; text-decoration: none; }
   .ct-breadcrumb a:hover { color: #fff; }
   .ct-breadcrumb .sep   { margin: 0 8px; color: rgba(255,255,255,.35); }
   .ct-breadcrumb .active{ color: rgba(255,255,255,.9); }

   .ct-hero h1 {
      font-size: clamp(2rem, 5vw, 3.2rem);
      font-weight: 800;
      color: #fff;
      line-height: 1.2;
      margin: 16px 0 16px;
   }
   .ct-hero h1 span {
      color: var(--ct-gold);
      position: relative;
      display: inline-block;
   }
   .ct-hero h1 span::after {
      content: '';
      display: block;
      width: 100%;
      height: 3px;
      background: var(--ct-gold);
      border-radius: 2px;
      margin-top: 2px;
      transform: scaleX(0);
      transform-origin: left;
      animation: ct-underline .8s .6s ease forwards;
   }
   @keyframes ct-underline { to { transform: scaleX(1); } }

   .ct-hero .tagline {
      font-size: 1.1rem;
      color: rgba(255,255,255,.82);
      max-width: 580px;
      margin-bottom: 36px;
      line-height: 1.7;
   }

   /* hero stat badges */
   .ct-stat-badges {
      display: flex;
      flex-wrap: wrap;
      gap: 14px;
   }
   .ct-stat-badge {
      background: rgba(255,255,255,.12);
      backdrop-filter: blur(8px);
      border: 1px solid rgba(255,255,255,.2);
      border-radius: 50px;
      padding: 9px 22px;
      color: #fff;
      font-size: .88rem;
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 8px;
      transition: background .25s;
   }
   .ct-stat-badge:hover { background: rgba(255,255,255,.2); }
   .ct-stat-badge i     { color: var(--ct-gold); font-size: 1rem; }

   /* hero image card */
   .ct-hero-card {
      background: rgba(255,255,255,.1);
      backdrop-filter: blur(14px);
      border: 1px solid rgba(255,255,255,.2);
      border-radius: 20px;
      padding: 36px 30px;
      animation: ct-hero-card-in .9s .3s both;
   }
   @keyframes ct-hero-card-in {
      from { opacity:0; transform: translateY(30px); }
      to   { opacity:1; transform: translateY(0); }
   }
   .ct-hero-card .icon-wrap {
      width: 64px; height: 64px;
      border-radius: 16px;
      background: var(--ct-gold);
      display: flex; align-items: center; justify-content: center;
      font-size: 1.6rem;
      color: var(--ct-navy);
      margin-bottom: 18px;
   }
   .ct-hero-card h3 {
      font-size: 1.25rem;
      font-weight: 700;
      color: #fff;
      margin-bottom: 10px;
   }
   .ct-hero-card p  { color: rgba(255,255,255,.92); font-size: .95rem; line-height: 1.75; margin: 0; }

   /* ── Section Shared ──────────────────────────── */
   .ct-section { padding: 90px 0; }
   .ct-section-bg { background: var(--ct-light); }
   .ct-section-dark { background: var(--ct-navy); }

   .ct-section-head { margin-bottom: 55px; }
   .ct-section-head .badge-label {
      display: inline-block;
      background: rgba(37,99,235,.12);
      color: var(--ct-blue);
      font-size: .78rem;
      font-weight: 700;
      letter-spacing: .08em;
      text-transform: uppercase;
      padding: 6px 18px;
      border-radius: 50px;
      margin-bottom: 14px;
   }
   .ct-section-head h2 {
      font-size: clamp(1.7rem, 3.5vw, 2.4rem);
      font-weight: 800;
      color: var(--ct-navy);
      line-height: 1.25;
      margin-bottom: 14px;
   }
   .ct-section-head h2 span { color: var(--ct-blue); }
   .ct-section-head p {
      font-size: 1rem;
      color: var(--ct-muted);
      /* max-width: 560px; */
      line-height: 1.75;
   }
   /* dark variant */
   .ct-section-dark .ct-section-head .badge-label {
      background: rgba(255,255,255,.12);
      color: var(--ct-gold);
   }
   .ct-section-dark .ct-section-head h2 { color: #fff; }
   .ct-section-dark .ct-section-head h2 span { color: var(--ct-gold); }
   .ct-section-dark .ct-section-head p { color: rgba(255,255,255,.7); }

   /* divider accent */
   .ct-divider {
      width: 54px; height: 4px;
      background: linear-gradient(90deg, var(--ct-blue), var(--ct-gold));
      border-radius: 4px;
      margin: 0 0 22px;
   }

   /* ── Stats Row ────────────────────────────────── */
   .ct-stats-row {
      padding: 0;
      margin-top: 20px;
      position: relative;
      z-index: 10;
   }
   .ct-stat-card {
      background: #fff;
      border-radius: 16px;
      box-shadow: 0 8px 40px rgba(26,46,90,.1);
      padding: 32px 24px;
      text-align: center;
      border-top: 4px solid var(--ct-blue);
      transition: transform .3s, box-shadow .3s;
   }
   .ct-stat-card:hover {
      transform: translateY(-6px);
      box-shadow: 0 16px 50px rgba(26,46,90,.15);
   }
   .ct-stat-card .num {
      font-size: 2.6rem;
      font-weight: 800;
      color: var(--ct-navy);
      line-height: 1;
      margin-bottom: 6px;
   }
   .ct-stat-card .num span { color: var(--ct-blue); }
   .ct-stat-card .num.ct-stat-abbr { font-size: 1.9rem; letter-spacing: .04em; }
   .ct-stat-card .num.ct-stat-abbr abbr { text-decoration: none; }
   .ct-stat-card .lbl {
      font-size: .9rem;
      color: var(--ct-muted);
      font-weight: 500;
   }
   .ct-stat-card .ico {
      width: 64px; height: 64px;
      border-radius: 14px;
      background: var(--ct-light);
      display: flex; align-items: center; justify-content: center;
      font-size: 1.75rem;
      color: var(--ct-blue);
      margin: 0 auto 18px;
   }

   /* ── Process Steps ────────────────────────────── */
   .ct-steps-wrap { position: relative; }
   /* vertical connector */
   .ct-steps-wrap::before {
      content: '';
      position: absolute;
      left: 28px;
      top: 28px;
      bottom: 28px;
      width: 3px;
      background: linear-gradient(to bottom, var(--ct-blue), var(--ct-gold));
      border-radius: 3px;
   }
   @media (max-width: 767px) {
      .ct-steps-wrap::before { left: 22px; }
   }

   .ct-step {
      display: flex;
      gap: 24px;
      align-items: flex-start;
      margin-bottom: 36px;
      position: relative;
   }
   .ct-step:last-child { margin-bottom: 0; }
   .ct-step-num {
      flex-shrink: 0;
      width: 58px; height: 58px;
      border-radius: 50%;
      background: var(--ct-blue);
      color: #fff;
      font-size: 1.1rem;
      font-weight: 800;
      display: flex; align-items: center; justify-content: center;
      box-shadow: 0 6px 20px rgba(37,99,235,.35);
      position: relative;
      z-index: 2;
      transition: background .3s, transform .3s;
   }
   .ct-step:hover .ct-step-num {
      background: var(--ct-gold);
      color: var(--ct-navy);
      transform: scale(1.1);
   }
   .ct-step-body {
      background: #fff;
      border-radius: 14px;
      padding: 22px 24px;
      box-shadow: 0 4px 24px rgba(26,46,90,.07);
      flex: 1;
      border-left: 3px solid transparent;
      transition: border-color .3s, box-shadow .3s;
   }
   .ct-step:hover .ct-step-body {
      border-color: var(--ct-blue);
      box-shadow: 0 8px 36px rgba(26,46,90,.13);
   }
   .ct-step-body h4 {
      font-size: 1.05rem;
      font-weight: 700;
      color: var(--ct-navy);
      margin-bottom: 6px;
   }
   .ct-step-body p { font-size: .92rem; color: var(--ct-muted); margin: 0; line-height: 1.65; }

   /* ── Policy Cards ─────────────────────────────── */
   .ct-policy-card {
      background: #fff;
      border-radius: 16px;
      padding: 30px 28px;
      box-shadow: 0 4px 24px rgba(26,46,90,.07);
      height: 100%;
      position: relative;
      overflow: hidden;
      transition: transform .3s, box-shadow .3s;
      border-bottom: 4px solid transparent;
   }
   .ct-policy-card:hover {
      transform: translateY(-6px);
      box-shadow: 0 16px 48px rgba(26,46,90,.13);
      border-bottom-color: var(--ct-blue);
   }
   .ct-policy-card::before {
      content: '';
      position: absolute;
      top: 0; right: 0;
      width: 80px; height: 80px;
      background: radial-gradient(circle at top right, rgba(37,99,235,.08), transparent 70%);
      border-radius: 0 16px 0 100%;
   }
   .ct-policy-card .ct-pc-icon {
      width: 56px; height: 56px;
      border-radius: 14px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.5rem;
      margin-bottom: 18px;
      flex-shrink: 0;
   }
   .ct-policy-card h4 {
      font-size: 1.05rem;
      font-weight: 700;
      color: var(--ct-navy);
      margin-bottom: 10px;
   }
   .ct-policy-card p {
      font-size: .91rem;
      color: var(--ct-muted);
      line-height: 1.7;
      margin: 0;
   }

   /* icon color variants */
   .icon-blue  { background: rgba(37,99,235,.1);  color: var(--ct-blue); }
   .icon-gold  { background: rgba(255,184,28,.12); color: #d97706; }
   .icon-green { background: rgba(16,185,129,.1);  color: var(--ct-green); }
   .icon-red   { background: rgba(239,68,68,.1);   color: var(--ct-red); }
   .icon-navy  { background: rgba(26,46,90,.1);    color: var(--ct-navy); }
   .icon-purple{ background: rgba(124,58,237,.1);  color: #7c3aed; }

   /* ── Grade Table ──────────────────────────────── */
   .ct-grade-table-wrap {
      background: #fff;
      border-radius: 20px;
      box-shadow: 0 8px 40px rgba(26,46,90,.1);
      overflow: hidden;
   }
   .ct-grade-table-head {
      background: linear-gradient(90deg, var(--ct-navy), var(--ct-blue));
      padding: 28px 30px;
   }
   .ct-grade-table-head h3 {
      color: #fff;
      font-size: 1.2rem;
      font-weight: 700;
      margin: 0;
   }
   .ct-grade-table-head p {
      color: rgba(255,255,255,.72);
      font-size: .88rem;
      margin: 6px 0 0;
   }
   .ct-grade-table { width: 100%; border-collapse: collapse; }
   .ct-grade-table th {
      background: var(--ct-light);
      color: var(--ct-navy);
      font-size: .8rem;
      font-weight: 700;
      letter-spacing: .06em;
      text-transform: uppercase;
      padding: 14px 20px;
      text-align: left;
      border-bottom: 2px solid var(--ct-border);
   }
   .ct-grade-table td {
      padding: 14px 20px;
      font-size: .92rem;
      color: #374151;
      border-bottom: 1px solid var(--ct-border);
      transition: background .2s;
   }
   .ct-grade-table tr:last-child td { border-bottom: none; }
   .ct-grade-table tr:hover td    { background: var(--ct-light); }
   .ct-grade-pass  { color: var(--ct-green); font-weight: 700; }
   .ct-grade-fail  { color: var(--ct-red);   font-weight: 700; }
   .ct-grade-badge {
      display: inline-block;
      padding: 3px 10px;
      border-radius: 50px;
      font-size: .8rem;
      font-weight: 700;
   }
   .ct-grade-badge.pass { background: rgba(16,185,129,.12); color: var(--ct-green); }
   .ct-grade-badge.fail { background: rgba(239,68,68,.12);  color: var(--ct-red); }

   /* ── Eligibility List ─────────────────────────── */
   .ct-eligibility-card {
      background: #fff;
      border-radius: 20px;
      padding: 40px 36px;
      box-shadow: 0 8px 40px rgba(26,46,90,.1);
   }
   .ct-elig-item {
      display: flex;
      gap: 16px;
      align-items: flex-start;
      padding: 16px 0;
      border-bottom: 1px solid var(--ct-border);
   }
   .ct-elig-item:last-child { border-bottom: none; padding-bottom: 0; }
   .ct-elig-icon {
      width: 40px; height: 40px;
      border-radius: 10px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1rem;
      flex-shrink: 0;
      margin-top: 2px;
   }
   .ct-elig-icon.ok  { background: rgba(16,185,129,.12); color: var(--ct-green); }
   .ct-elig-icon.warn{ background: rgba(255,184,28,.15);  color: #d97706; }
   .ct-elig-text h5 {
      font-size: .97rem;
      font-weight: 700;
      color: var(--ct-navy);
      margin-bottom: 4px;
   }
   .ct-elig-text p  {
      font-size: .88rem;
      color: var(--ct-muted);
      margin: 0;
      line-height: 1.6;
   }

   /* authority card */
   .ct-authority-card {
      background: linear-gradient(135deg, var(--ct-navy) 0%, #1e3a8a 100%);
      border-radius: 20px;
      padding: 36px 32px;
      color: #fff;
      position: relative;
      overflow: hidden;
   }
   .ct-authority-card::before {
      content: '';
      position: absolute;
      top: -40px; right: -40px;
      width: 150px; height: 150px;
      border-radius: 50%;
      background: rgba(255,255,255,.06);
   }
   .ct-authority-card::after {
      content: '';
      position: absolute;
      bottom: -30px; left: -30px;
      width: 100px; height: 100px;
      border-radius: 50%;
      background: rgba(255,184,28,.08);
   }
   .ct-authority-card .role-badge {
      display: inline-block;
      background: var(--ct-gold);
      color: var(--ct-navy);
      font-size: .75rem;
      font-weight: 800;
      letter-spacing: .08em;
      text-transform: uppercase;
      padding: 4px 14px;
      border-radius: 50px;
      margin-bottom: 14px;
   }
   .ct-authority-card h4 {
      font-size: 1.15rem;
      font-weight: 700;
      color: #fff;
      margin-bottom: 10px;
   }
   .ct-authority-card p {
      font-size: .93rem;
      color: rgba(255,255,255,.9);
      line-height: 1.7;
      margin: 0;
   }

   /* ── CTA Section ──────────────────────────────── */
   .ct-cta {
      background: linear-gradient(135deg, var(--ct-navy), var(--ct-blue));
      padding: 90px 0;
      position: relative;
      overflow: hidden;
   }
   .ct-cta::before {
      content: '';
      position: absolute;
      inset: 0;
      background: url('assets/img/shape/breadcrumb-1-bg.png') center/cover no-repeat;
      opacity: .05;
   }
   .ct-cta-inner { text-align: center; position: relative; z-index: 1; }
   .ct-cta-inner .label {
      display: inline-block;
      background: rgba(255,255,255,.15);
      color: var(--ct-gold);
      font-size: .8rem;
      font-weight: 700;
      letter-spacing: .1em;
      text-transform: uppercase;
      padding: 6px 20px;
      border-radius: 50px;
      margin-bottom: 20px;
   }
   .ct-cta-inner h2 {
      font-size: clamp(1.8rem, 4vw, 2.8rem);
      font-weight: 800;
      color: #fff;
      margin-bottom: 16px;
      line-height: 1.2;
   }
   .ct-cta-inner p {
      font-size: 1.05rem;
      color: rgba(255,255,255,.8);
      max-width: 540px;
      margin: 0 auto 36px;
      line-height: 1.7;
   }
   .ct-cta-btns { display: flex; flex-wrap: wrap; gap: 14px; justify-content: center; }
   .ct-btn-primary {
      background: var(--ct-gold);
      color: var(--ct-navy);
      font-weight: 700;
      font-size: .95rem;
      padding: 14px 36px;
      border-radius: 50px;
      text-decoration: none;
      border: 2px solid var(--ct-gold);
      display: inline-flex; align-items: center; gap: 10px;
      transition: all .25s;
   }
   .ct-btn-primary:hover {
      background: transparent;
      color: var(--ct-gold);
   }
   .ct-btn-outline {
      background: transparent;
      color: #fff;
      font-weight: 700;
      font-size: .95rem;
      padding: 14px 36px;
      border-radius: 50px;
      text-decoration: none;
      border: 2px solid rgba(255,255,255,.5);
      display: inline-flex; align-items: center; gap: 10px;
      transition: all .25s;
   }
   .ct-btn-outline:hover {
      border-color: #fff;
      color: #fff;
      background: rgba(255,255,255,.1);
   }

   /* ── FAQ Accordion ───────────────────────────── */
   .ct-faq .accordion-item {
      border: 1px solid var(--ct-border);
      border-radius: 12px !important;
      margin-bottom: 12px;
      overflow: hidden;
      box-shadow: 0 2px 12px rgba(26,46,90,.05);
   }
   .ct-faq .accordion-button {
      font-size: .97rem;
      font-weight: 700;
      color: var(--ct-navy);
      background: #fff;
      padding: 20px 24px;
      box-shadow: none;
   }
   .ct-faq .accordion-button:not(.collapsed) {
      color: var(--ct-blue);
      background: var(--ct-light);
   }
   .ct-faq .accordion-button::after {
      filter: none;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='%232563eb'%3E%3Cpath fill-rule='evenodd' d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z'/%3E%3C/svg%3E");
   }
   .ct-faq .accordion-body {
      font-size: .92rem;
      color: var(--ct-muted);
      line-height: 1.75;
      padding: 8px 24px 22px;
      background: var(--ct-light);
   }

   /* ── Responsive tweaks ───────────────────────── */
   @media (max-width: 991px) {
      .ct-hero { padding: 80px 0 120px; }
      .ct-stats-row { margin-top: 30px; }
      .ct-steps-wrap::before { display: none; }
      .ct-eligibility-card { padding: 28px 22px; }
   }
   @media (max-width: 767px) {
      .ct-hero { padding: 70px 0 90px; }
      .ct-hero-card { margin-top: 36px; }
      .ct-section { padding: 65px 0; }
      .ct-section-head { margin-bottom: 36px; }
      .ct-stat-card .num { font-size: 2rem; }
      .ct-step-body { padding: 16px 18px; }
      .ct-authority-card { padding: 28px 22px; }
   }
   </style>
<?php include __DIR__ . '/includes/meta-pixel.php'; ?>
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
<?php include __DIR__ . '/includes/offcanvas.php'; ?>
   <header class="it-header-height">
      <?php include __DIR__ . '/includes/header-top.php'; ?>
      <?php include __DIR__ . '/includes/nav-menu.php'; ?>
   </header>

   <main>

      <!-- ══ HERO ══════════════════════════════════════════ -->
      <section class="ct-hero">
         <!-- animated blobs -->
         <div class="ct-hero-blob ct-hero-blob-1"></div>
         <div class="ct-hero-blob ct-hero-blob-2"></div>
         <div class="ct-hero-blob ct-hero-blob-3"></div>

         <div class="container">
            <div class="row align-items-center">
               <div class="col-lg-7">
                  <nav class="ct-breadcrumb mb-2" aria-label="breadcrumb">
                     <a href="<?= fh(SITE_URL) ?>/index.php">Home</a>
                     <span class="sep">›</span>
                     <span>Admissions</span>
                     <span class="sep">›</span>
                     <span class="active">Credit Transfer</span>
                  </nav>
                  <h1 class="wow fadeInUp" data-wow-delay=".1s">
                     Credit <span>Transfer</span><br>Policy &amp; Process
                  </h1>
                  <p class="tagline wow fadeInUp" data-wow-delay=".2s">
                     Seamlessly transfer your academic credits to Prime University. Our transparent, UGC-compliant process ensures your prior learning is recognised fairly.
                  </p>
                  <div class="ct-stat-badges wow fadeInUp" data-wow-delay=".3s">
                     <span class="ct-stat-badge"><i class="fas fa-shield-check"></i> UGC Compliant</span>
                     <span class="ct-stat-badge"><i class="fas fa-university"></i> All Programmes</span>
                     <span class="ct-stat-badge"><i class="fas fa-clock"></i> Fast Processing</span>
                  </div>
               </div>
               <div class="col-lg-5 d-none d-lg-block">
                  <div class="ct-hero-card wow fadeInRight" data-wow-delay=".25s">
                     <div class="icon-wrap"><i class="fas fa-exchange-alt"></i></div>
                     <h3>Quick Eligibility Check</h3>
                     <p>A minimum grade of <strong style="color:var(--ct-gold);">C+</strong> (&#x2265;&nbsp;50% marks) is required for each course, and the total transferred courses must not exceed programme limits. Every application is reviewed individually by the Equivalence Committee.</p>
                  </div>
               </div>
            </div>
         </div>
      </section>

      <!-- ══ STATS STRIP ═══════════════════════════════════ -->
      <div class="container ct-stats-row">
         <div class="row g-4">
            <div class="col-6 col-md-3">
               <div class="ct-stat-card wow fadeInUp" data-wow-delay=".1s">
                  <div class="ico"><i class="fas fa-list-ol"></i></div>
                  <div class="num">6</div>
                  <div class="lbl">Application Steps</div>
               </div>
            </div>
            <div class="col-6 col-md-3">
               <div class="ct-stat-card wow fadeInUp" data-wow-delay=".2s">
                  <div class="ico"><i class="fas fa-award"></i></div>
                  <div class="num ct-stat-abbr"><abbr title="University Grants Commission">UGC</abbr></div>
                  <div class="lbl">Compliant Policy</div>
               </div>
            </div>
            <div class="col-6 col-md-3">
               <div class="ct-stat-card wow fadeInUp" data-wow-delay=".3s">
                  <div class="ico"><i class="fas fa-users"></i></div>
                  <div class="num">2</div>
                  <div class="lbl">Review Committees</div>
               </div>
            </div>
            <div class="col-6 col-md-3">
               <div class="ct-stat-card wow fadeInUp" data-wow-delay=".4s">
                  <div class="ico"><i class="fas fa-gavel"></i></div>
                  <div class="num">VC</div>
                  <div class="lbl">Final Approval Authority</div>
               </div>
            </div>
         </div>
      </div>

      <!-- ══ POLICY OVERVIEW ════════════════════════════════ -->
      <section class="ct-section" id="policy">
         <div class="container">
            <div class="row">
               <div class="col-lg-4 mb-4 mb-lg-0">
                  <div class="ct-section-head">
                     <span class="badge-label">Policy Overview</span>
                     <div class="ct-divider"></div>
                     <h2>Key Rules for<br><span>Credit Transfer</span></h2>
                     <p>Prime University follows a well-defined, UGC-compliant framework to evaluate and accept credits earned at other accredited institutions.</p>
                  </div>
               </div>
               <div class="col-lg-8">
                  <div class="row g-4">
                     <div class="col-md-6 wow fadeInUp" data-wow-delay=".1s">
                        <div class="ct-policy-card">
                           <div class="ct-pc-icon icon-blue"><i class="fas fa-users-cog"></i></div>
                           <h4>Equivalence Committee</h4>
                           <p>Applications are reviewed by the Equivalence Committee of the respective Department, which evaluates course equivalency and recommends transferable credits.</p>
                        </div>
                     </div>
                     <div class="col-md-6 wow fadeInUp" data-wow-delay=".2s">
                        <div class="ct-policy-card">
                           <div class="ct-pc-icon icon-gold"><i class="fas fa-star"></i></div>
                           <h4>Minimum Grade Requirement</h4>
                           <p>No course will be accepted with a grade below <strong>C+</strong> or with less than <strong>50% marks</strong>. Courses falling below this threshold are ineligible for transfer.</p>
                        </div>
                     </div>
                     <div class="col-md-6 wow fadeInUp" data-wow-delay=".3s">
                        <div class="ct-policy-card">
                           <div class="ct-pc-icon icon-red"><i class="fas fa-ban"></i></div>
                           <h4>50% Course Cap</h4>
                           <p>No student may enrol with more than 50% of the total courses of their relevant programme already completed at another university.</p>
                        </div>
                     </div>
                     <div class="col-md-6 wow fadeInUp" data-wow-delay=".4s">
                        <div class="ct-policy-card">
                           <div class="ct-pc-icon icon-green"><i class="fas fa-sync-alt"></i></div>
                           <h4>Grade Conversion</h4>
                           <p>The Controller of Examinations converts transferred grades in compliance with the <strong>UGC Uniform Grading System</strong> of Bangladesh.</p>
                        </div>
                     </div>
                     <div class="col-md-6 wow fadeInUp" data-wow-delay=".5s">
                        <div class="ct-policy-card">
                           <div class="ct-pc-icon icon-purple"><i class="fas fa-calculator"></i></div>
                           <h4>CGPA Calculation</h4>
                           <p>The final CGPA is calculated by incorporating both the converted transferred grades and the grades earned at Prime University.</p>
                        </div>
                     </div>
                     <div class="col-md-6 wow fadeInUp" data-wow-delay=".6s">
                        <div class="ct-policy-card">
                           <div class="ct-pc-icon icon-navy"><i class="fas fa-university"></i></div>
                           <h4>University-Level Review</h4>
                           <p>In addition to the departmental committee, the University Equivalence Committee may also review applications to ensure institutional standards.</p>
                        </div>
                     </div>
                     <div class="col-12 wow fadeInUp" data-wow-delay=".7s">
                        <div class="ct-authority-card">
                           <span class="role-badge">Final Authority</span>
                           <h4>Vice Chancellor's Approval</h4>
                           <p>All recommendations from the Equivalence Committee are placed before the Vice Chancellor, whose decision is final and binding in every credit transfer case.</p>
                        </div>
                     </div>
                  </div>
               </div>
            </div>
         </div>
      </section>

      <!-- ══ APPLICATION PROCESS ════════════════════════════ -->
      <section class="ct-section ct-section-bg" id="process">
         <div class="container">
            <div class="row justify-content-center mb-5">
               <div class="col-lg-7 text-center">
                  <div class="ct-section-head">
                     <span class="badge-label">Step-by-Step</span>
                     <div class="ct-divider mx-auto"></div>
                     <h2>How the Credit Transfer <span>Process Works</span></h2>
                     <p>From initial application to final CGPA calculation – here is the complete journey of a credit transfer at Prime University.</p>
                  </div>
               </div>
            </div>
            <div class="row justify-content-center">
               <div class="col-lg-8">
                  <div class="ct-steps-wrap">

                     <div class="ct-step wow fadeInUp" data-wow-delay=".1s">
                        <div class="ct-step-num">01</div>
                        <div class="ct-step-body">
                           <h4>Submit Application</h4>
                           <p>The intending student submits a formal application to enrol at Prime University, along with official transcripts, course syllabi, and supporting documents from their previous institution.</p>
                        </div>
                     </div>

                     <div class="ct-step wow fadeInUp" data-wow-delay=".15s">
                        <div class="ct-step-num">02</div>
                        <div class="ct-step-body">
                           <h4>Departmental Equivalence Committee Review</h4>
                           <p>The Equivalence Committee of the relevant Department examines the application. Each submitted course is evaluated against Prime University's curriculum for content, credit hours, and minimum grade requirements (C+ / ≥ 50%).</p>
                        </div>
                     </div>

                     <div class="ct-step wow fadeInUp" data-wow-delay=".2s">
                        <div class="ct-step-num">03</div>
                        <div class="ct-step-body">
                           <h4>University Equivalence Committee (if required)</h4>
                           <p>For complex cases or inter-disciplinary transfers, the application may be escalated to the University Equivalence Committee for a broader institutional review.</p>
                        </div>
                     </div>

                     <div class="ct-step wow fadeInUp" data-wow-delay=".25s">
                        <div class="ct-step-num">04</div>
                        <div class="ct-step-body">
                           <h4>Vice Chancellor's Approval</h4>
                           <p>The committee's recommendations are placed before the Vice Chancellor. The VC's decision is final. Upon approval, the list of transferable courses is confirmed.</p>
                        </div>
                     </div>

                     <div class="ct-step wow fadeInUp" data-wow-delay=".3s">
                        <div class="ct-step-num">05</div>
                        <div class="ct-step-body">
                           <h4>Grade Conversion by Controller of Examinations</h4>
                           <p>The Controller of Examinations converts the approved transferred grades in strict compliance with the Uniform Grading System of the University Grants Commission (UGC) of Bangladesh.</p>
                        </div>
                     </div>

                     <div class="ct-step wow fadeInUp" data-wow-delay=".35s">
                        <div class="ct-step-num">06</div>
                        <div class="ct-step-body">
                           <h4>Enrolment &amp; CGPA Calculation</h4>
                           <p>The student is officially enrolled. The final CGPA is calculated incorporating both the converted transferred grades and all grades subsequently earned at Prime University.</p>
                        </div>
                     </div>

                  </div>
               </div>
            </div>
         </div>
      </section>

      <!-- ══ GRADE TABLE ════════════════════════════════════ -->
      <section class="ct-section" id="grades">
         <div class="container">
            <div class="row justify-content-center mb-5">
               <div class="col-lg-7 text-center">
                  <div class="ct-section-head">
                     <span class="badge-label">Grade Requirements</span>
                     <div class="ct-divider mx-auto"></div>
                     <h2>Accepted &amp; <span>Rejected Grade Levels</span></h2>
                     <p>Only courses with a minimum grade of <strong>C+</strong> or ≥ 50% marks are eligible for credit transfer consideration.</p>
                  </div>
               </div>
            </div>
            <div class="row justify-content-center">
               <div class="col-lg-10 wow fadeInUp" data-wow-delay=".1s">
                  <div class="ct-grade-table-wrap">
                     <div class="ct-grade-table-head">
                        <h3><i class="fas fa-table me-2"></i> UGC Grade Reference Table (Bangladesh)</h3>
                        <p>Based on the Uniform Grading System – University Grants Commission, Bangladesh</p>
                     </div>
                     <div class="table-responsive">
                        <table class="ct-grade-table">
                           <thead>
                              <tr>
                                 <th>Numerical Grade</th>
                                 <th>Letter Grade</th>
                                 <th>Grade Point</th>
                                 <th>Marks Range</th>
                                 <th>Transfer Eligible</th>
                              </tr>
                           </thead>
                           <tbody>
                              <tr>
                                 <td>4.00</td>
                                 <td><strong>A+</strong></td>
                                 <td>4.00</td>
                                 <td>80% – 100%</td>
                                 <td><span class="ct-grade-badge pass"><i class="fas fa-check me-1"></i>Eligible</span></td>
                              </tr>
                              <tr>
                                 <td>3.75</td>
                                 <td><strong>A</strong></td>
                                 <td>3.75</td>
                                 <td>75% – 79%</td>
                                 <td><span class="ct-grade-badge pass"><i class="fas fa-check me-1"></i>Eligible</span></td>
                              </tr>
                              <tr>
                                 <td>3.50</td>
                                 <td><strong>A–</strong></td>
                                 <td>3.50</td>
                                 <td>70% – 74%</td>
                                 <td><span class="ct-grade-badge pass"><i class="fas fa-check me-1"></i>Eligible</span></td>
                              </tr>
                              <tr>
                                 <td>3.25</td>
                                 <td><strong>B+</strong></td>
                                 <td>3.25</td>
                                 <td>65% – 69%</td>
                                 <td><span class="ct-grade-badge pass"><i class="fas fa-check me-1"></i>Eligible</span></td>
                              </tr>
                              <tr>
                                 <td>3.00</td>
                                 <td><strong>B</strong></td>
                                 <td>3.00</td>
                                 <td>60% – 64%</td>
                                 <td><span class="ct-grade-badge pass"><i class="fas fa-check me-1"></i>Eligible</span></td>
                              </tr>
                              <tr>
                                 <td>2.75</td>
                                 <td><strong>B–</strong></td>
                                 <td>2.75</td>
                                 <td>55% – 59%</td>
                                 <td><span class="ct-grade-badge pass"><i class="fas fa-check me-1"></i>Eligible</span></td>
                              </tr>
                              <tr>
                                 <td>2.50</td>
                                 <td><strong>C+</strong></td>
                                 <td>2.50</td>
                                 <td>50% – 54%</td>
                                 <td><span class="ct-grade-badge pass"><i class="fas fa-check me-1"></i>Eligible</span></td>
                              </tr>
                              <tr style="background: rgba(239,68,68,.03);">
                                 <td class="ct-grade-fail">2.25</td>
                                 <td><strong class="ct-grade-fail">C</strong></td>
                                 <td class="ct-grade-fail">2.25</td>
                                 <td class="ct-grade-fail">45% – 49%</td>
                                 <td><span class="ct-grade-badge fail"><i class="fas fa-times me-1"></i>Not Eligible</span></td>
                              </tr>
                              <tr style="background: rgba(239,68,68,.03);">
                                 <td class="ct-grade-fail">2.00</td>
                                 <td><strong class="ct-grade-fail">D</strong></td>
                                 <td class="ct-grade-fail">2.00</td>
                                 <td class="ct-grade-fail">40% – 44%</td>
                                 <td><span class="ct-grade-badge fail"><i class="fas fa-times me-1"></i>Not Eligible</span></td>
                              </tr>
                              <tr style="background: rgba(239,68,68,.05);">
                                 <td class="ct-grade-fail">0.00</td>
                                 <td><strong class="ct-grade-fail">F</strong></td>
                                 <td class="ct-grade-fail">0.00</td>
                                 <td class="ct-grade-fail">Below 40%</td>
                                 <td><span class="ct-grade-badge fail"><i class="fas fa-times me-1"></i>Not Eligible</span></td>
                              </tr>
                           </tbody>
                        </table>
                     </div>
                  </div>
               </div>
            </div>
         </div>
      </section>

      <!-- ══ ELIGIBILITY ════════════════════════════════════ -->
      <section class="ct-section ct-section-bg" id="eligibility">
         <div class="container">
            <div class="row">
               <div class="col-lg-5 mb-4 mb-lg-0">
                  <div class="ct-section-head">
                     <span class="badge-label">Who Can Apply</span>
                     <div class="ct-divider"></div>
                     <h2>Eligibility <span>Criteria</span></h2>
                     <p>To be considered for credit transfer, applicants must satisfy all of the following conditions before submitting their application.</p>
                  </div>
                  <div class="ct-authority-card wow fadeInLeft" data-wow-delay=".2s">
                     <span class="role-badge">Important Note</span>
                     <h4>CGPA Impact</h4>
                     <p>Your final CGPA at Prime University will be computed by blending your converted transferred grades with all grades you earn during your studies here. A strong transfer record directly benefits your overall CGPA.</p>
                  </div>
               </div>
               <div class="col-lg-7">
                  <div class="ct-eligibility-card wow fadeInRight" data-wow-delay=".15s">

                     <div class="ct-elig-item">
                        <div class="ct-elig-icon ok"><i class="fas fa-check"></i></div>
                        <div class="ct-elig-text">
                           <h5>Enrolled or Previously Enrolled at an Accredited University</h5>
                           <p>The applicant must be currently enrolled in or have attended a recognised and accredited university or institution to be eligible for credit transfer.</p>
                        </div>
                     </div>

                     <div class="ct-elig-item">
                        <div class="ct-elig-icon ok"><i class="fas fa-check"></i></div>
                        <div class="ct-elig-text">
                           <h5>Minimum Grade of C+ or ≥ 50% in Each Course</h5>
                           <p>Each individual course submitted for transfer must carry a grade of C+ (grade point 2.50) or above, equivalent to 50% or more marks. Courses below this threshold will not be considered.</p>
                        </div>
                     </div>

                     <div class="ct-elig-item">
                        <div class="ct-elig-icon ok"><i class="fas fa-check"></i></div>
                        <div class="ct-elig-text">
                           <h5>Transfer Does Not Exceed 50% of Programme Courses</h5>
                           <p>The total number of courses the student seeks to transfer must not exceed 50% of the total courses required to complete the relevant programme at Prime University.</p>
                        </div>
                     </div>

                     <div class="ct-elig-item">
                        <div class="ct-elig-icon ok"><i class="fas fa-check"></i></div>
                        <div class="ct-elig-text">
                           <h5>Courses Relevant to the Intended Programme</h5>
                           <p>Only courses that are equivalent in content, credit hours, and level to courses offered in the relevant programme at Prime University are eligible for transfer.</p>
                        </div>
                     </div>

                     <div class="ct-elig-item">
                        <div class="ct-elig-icon warn"><i class="fas fa-exclamation"></i></div>
                        <div class="ct-elig-text">
                           <h5>Official Transcripts and Syllabi Required</h5>
                           <p>Applicants must submit official transcripts and detailed course syllabi from their previous institution. The Equivalence Committee uses these documents for evaluation.</p>
                        </div>
                     </div>

                     <div class="ct-elig-item">
                        <div class="ct-elig-icon warn"><i class="fas fa-exclamation"></i></div>
                        <div class="ct-elig-text">
                           <h5>Subject to Committee Discretion</h5>
                           <p>Meeting the minimum requirements does not guarantee acceptance. The Equivalence Committee retains full discretion, and the Vice Chancellor holds final approval authority in all cases.</p>
                        </div>
                     </div>

                  </div>
               </div>
            </div>
         </div>
      </section>

      <!-- ══ FAQ ════════════════════════════════════════════ -->
      <section class="ct-section" id="faq">
         <div class="container">
            <div class="row justify-content-center mb-5">
               <div class="col-lg-7 text-center">
                  <div class="ct-section-head">
                     <span class="badge-label">FAQ</span>
                     <div class="ct-divider mx-auto"></div>
                     <h2>Frequently Asked <span>Questions</span></h2>
                     <p>Have questions about the credit transfer process? We have answered the most common ones below.</p>
                  </div>
               </div>
            </div>
            <div class="row justify-content-center">
               <div class="col-lg-9 wow fadeInUp" data-wow-delay=".1s">
                  <div class="accordion ct-faq" id="ctFaqAccordion">

                     <div class="accordion-item">
                        <h2 class="accordion-header" id="faq1h">
                           <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faq1" aria-expanded="true" aria-controls="faq1">
                              Who reviews my credit transfer application?
                           </button>
                        </h2>
                        <div id="faq1" class="accordion-collapse collapse show" aria-labelledby="faq1h" data-bs-parent="#ctFaqAccordion">
                           <div class="accordion-body">
                              Your application is first reviewed by the Equivalence Committee of your intended Department at Prime University. For complex cases, it may also be reviewed at the University level. All recommendations are then placed before the Vice Chancellor for final approval.
                           </div>
                        </div>
                     </div>

                     <div class="accordion-item">
                        <h2 class="accordion-header" id="faq2h">
                           <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2" aria-expanded="false" aria-controls="faq2">
                              What is the minimum grade required for credit transfer?
                           </button>
                        </h2>
                        <div id="faq2" class="accordion-collapse collapse" aria-labelledby="faq2h" data-bs-parent="#ctFaqAccordion">
                           <div class="accordion-body">
                              Each course must have been completed with a grade of <strong>C+</strong> or above, equivalent to a minimum of <strong>50% marks</strong>. Courses graded below C+ (i.e., C, D, or F) are not eligible for transfer under any circumstances.
                           </div>
                        </div>
                     </div>

                     <div class="accordion-item">
                        <h2 class="accordion-header" id="faq3h">
                           <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3" aria-expanded="false" aria-controls="faq3">
                              How many courses can I transfer?
                           </button>
                        </h2>
                        <div id="faq3" class="accordion-collapse collapse" aria-labelledby="faq3h" data-bs-parent="#ctFaqAccordion">
                           <div class="accordion-body">
                              You may transfer a maximum of <strong>50% of the total courses</strong> required for the programme you intend to pursue at Prime University. For example, if a programme requires 40 courses, a maximum of 20 may be considered for transfer.
                           </div>
                        </div>
                     </div>

                     <div class="accordion-item">
                        <h2 class="accordion-header" id="faq4h">
                           <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq4" aria-expanded="false" aria-controls="faq4">
                              How will my transferred grades affect my CGPA?
                           </button>
                        </h2>
                        <div id="faq4" class="accordion-collapse collapse" aria-labelledby="faq4h" data-bs-parent="#ctFaqAccordion">
                           <div class="accordion-body">
                              The Controller of Examinations converts your transferred grades in accordance with the <strong>UGC Uniform Grading System</strong> of Bangladesh. These converted grades are then included in your CGPA calculation alongside all grades you earn at Prime University, giving you a single, unified academic record.
                           </div>
                        </div>
                     </div>

                     <div class="accordion-item">
                        <h2 class="accordion-header" id="faq5h">
                           <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq5" aria-expanded="false" aria-controls="faq5">
                              What documents do I need to submit?
                           </button>
                        </h2>
                        <div id="faq5" class="accordion-collapse collapse" aria-labelledby="faq5h" data-bs-parent="#ctFaqAccordion">
                           <div class="accordion-body">
                              You will need to provide: (1) a completed credit transfer application form, (2) official transcripts from your previous institution, (3) detailed course syllabi for each course you wish to transfer, (4) proof of your current or previous enrolment, and (5) any additional documents requested by the Equivalence Committee.
                           </div>
                        </div>
                     </div>

                     <div class="accordion-item">
                        <h2 class="accordion-header" id="faq6h">
                           <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq6" aria-expanded="false" aria-controls="faq6">
                              Can I appeal if my transfer request is denied?
                           </button>
                        </h2>
                        <div id="faq6" class="accordion-collapse collapse" aria-labelledby="faq6h" data-bs-parent="#ctFaqAccordion">
                           <div class="accordion-body">
                              The Vice Chancellor's decision is final as per university policy. However, if you believe there has been a procedural error or if new evidence is available, you may contact the Registrar's Office to inquire about submitting a formal petition for reconsideration.
                           </div>
                        </div>
                     </div>

                  </div>
               </div>
            </div>
         </div>
      </section>

      <!-- ══ CTA ════════════════════════════════════════════ -->
      <section class="ct-cta">
         <div class="container">
            <div class="ct-cta-inner">
               <span class="label wow fadeInDown" data-wow-delay=".1s">Ready to Transfer?</span>
               <h2 class="wow fadeInUp" data-wow-delay=".15s">Start Your Credit Transfer<br>Application Today</h2>
               <p class="wow fadeInUp" data-wow-delay=".2s">
                  Contact the Registrar's Office or the relevant Department to receive the application form and begin the credit transfer process.
               </p>
               <div class="ct-cta-btns wow fadeInUp" data-wow-delay=".25s">
                  <a href="https://primeuniversity.ac.bd/apply-now.php" class="ct-btn-primary">
                     <i class="fas fa-paper-plane"></i> Apply Now
                  </a>
                  <a href="contact.php" class="ct-btn-outline">
                     <i class="fas fa-phone-alt"></i> Contact Us
                  </a>
               </div>
            </div>
         </div>
      </section>

   </main>

   <!-- Footer -->
<?php include __DIR__ . '/includes/footer.php'; ?>

   <!-- JS Libraries -->
   <?php include __DIR__ . '/includes/scripts.php'; ?>

   <script>
   (function () {
      'use strict';

      /* ── Animate stat counters when they scroll into view ── */
      var counters = document.querySelectorAll('.ct-stat-card .num');
      var animated = false;

      function isInViewport(el) {
         var rect = el.getBoundingClientRect();
         return rect.top < window.innerHeight && rect.bottom > 0;
      }

      function animateCounters() {
         if (animated) return;
         var allVisible = Array.from(counters).some(function (el) {
            return isInViewport(el);
         });
         if (!allVisible) return;
         animated = true;

         counters.forEach(function (el) {
            var rawText = el.textContent;
            var isGrade = rawText.trim() === 'C+';
            var isVC    = rawText.trim() === 'VC';
            if (isGrade || isVC) return;

            var target = parseInt(rawText.replace(/[^0-9]/g, ''), 10);
            if (isNaN(target)) return;

            var span = el.querySelector('span');
            var suffix = span ? span.textContent : '';

            // Create (or reuse) a text node for the number so the span is preserved
            var numNode = document.createTextNode('0');
            while (el.firstChild) el.removeChild(el.firstChild);
            el.appendChild(numNode);
            if (span) el.appendChild(span);

            var duration  = 1200;
            var startTime = null;

            function step(ts) {
               if (!startTime) startTime = ts;
               var progress = Math.min((ts - startTime) / duration, 1);
               numNode.nodeValue = String(Math.round(progress * target));
               if (progress < 1) requestAnimationFrame(step);
            }
            requestAnimationFrame(step);
         });
      }

      window.addEventListener('scroll', animateCounters, { passive: true });
      animateCounters(); // run once on load in case already visible

   }());
   </script>

</body>
</html>
