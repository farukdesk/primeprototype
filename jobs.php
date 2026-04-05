<?php
require_once __DIR__ . '/includes/config.php';

$page_title = 'Career Opportunities – Prime University';

$jobs = [];
try {
    $db = front_db();
    if ($db) {
        $stmt = $db->query(
            "SELECT j.*, COALESCE(a.app_count, 0) AS app_count
             FROM jobs j
             LEFT JOIN (
                 SELECT job_id, COUNT(*) AS app_count
                 FROM job_applications
                 GROUP BY job_id
             ) a ON a.job_id = j.id
             WHERE j.is_published = 1
               AND (j.deadline IS NULL OR j.deadline >= CURDATE())
             ORDER BY j.created_at DESC"
        );
        $jobs = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    // silently fall through
}

// Build unique filter lists
$departments = [];
$locations   = [];
foreach ($jobs as $j) {
    if ($j['department'] !== '') $departments[$j['department']] = true;
    if ($j['location']   !== '') $locations[$j['location']]     = true;
}
$departments = array_keys($departments);
$locations   = array_keys($locations);
?>
<!doctype html>
<html class="no-js" lang="en">

<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <title><?= fh($page_title) ?></title>
   <meta name="description" content="Explore career opportunities at Prime University.">
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

   <style>
   /* ── Jobs Page Custom Styles ─────────────────────────────── */

   /* Hero */
   .pu-jobs-hero {
      background: linear-gradient(135deg, #1a2e5a 0%, #2563eb 100%);
      padding: 90px 0 70px;
      position: relative;
      overflow: hidden;
   }
   .pu-jobs-hero::before {
      content: '';
      position: absolute;
      inset: 0;
      background: url('assets/img/shape/breadcrumb-1-bg.png') center/cover no-repeat;
      opacity: .08;
   }
   .pu-jobs-hero .breadcrumb-nav a,
   .pu-jobs-hero .breadcrumb-nav span {
      color: rgba(255,255,255,.7);
      font-size: .85rem;
   }
   .pu-jobs-hero .breadcrumb-nav a:hover { color: #fff; }
   .pu-jobs-hero .breadcrumb-nav .sep { margin: 0 8px; color: rgba(255,255,255,.4); }
   .pu-jobs-hero h1 {
      font-size: clamp(2rem, 5vw, 3rem);
      font-weight: 800;
      color: #fff;
      line-height: 1.2;
      margin-bottom: 14px;
   }
   .pu-jobs-hero .tagline {
      font-size: 1.1rem;
      color: rgba(255,255,255,.82);
      margin-bottom: 32px;
   }
   .pu-hero-cta {
      display: inline-flex;
      align-items: center;
      gap: 14px;
      flex-wrap: wrap;
   }
   .pu-btn-white {
      background: #fff;
      color: #1a2e5a;
      font-weight: 700;
      font-size: .95rem;
      padding: 13px 30px;
      border-radius: 50px;
      text-decoration: none;
      transition: all .25s ease;
      border: 2px solid #fff;
      display: inline-flex;
      align-items: center;
      gap: 8px;
   }
   .pu-btn-white:hover {
      background: transparent;
      color: #fff;
   }
   .pu-btn-outline-white {
      background: transparent;
      color: #fff;
      font-weight: 700;
      font-size: .95rem;
      padding: 13px 30px;
      border-radius: 50px;
      text-decoration: none;
      transition: all .25s ease;
      border: 2px solid rgba(255,255,255,.6);
      display: inline-flex;
      align-items: center;
      gap: 8px;
   }
   .pu-btn-outline-white:hover {
      background: #fff;
      color: #1a2e5a;
      border-color: #fff;
   }

   /* Filters bar */
   .pu-filters-bar {
      background: #fff;
      border-radius: 16px;
      box-shadow: 0 4px 30px rgba(0,0,0,.10);
      padding: 24px 28px;
      margin-top: -38px;
      position: relative;
      z-index: 10;
   }
   .pu-filters-bar .form-control,
   .pu-filters-bar .form-select {
      border: 1.5px solid #e2e8f0;
      border-radius: 10px;
      padding: 11px 16px;
      font-size: .92rem;
      color: #374151;
      background-color: #f8fafc;
      transition: border-color .2s, box-shadow .2s;
   }
   .pu-filters-bar .form-control:focus,
   .pu-filters-bar .form-select:focus {
      border-color: #2563eb;
      box-shadow: 0 0 0 3px rgba(37,99,235,.12);
      background: #fff;
      outline: none;
   }
   .pu-filters-bar .input-group-text {
      background: #f8fafc;
      border: 1.5px solid #e2e8f0;
      border-right: none;
      border-radius: 10px 0 0 10px;
      color: #2563eb;
   }
   .pu-filters-bar .input-group .form-control {
      border-left: none;
      border-radius: 0 10px 10px 0;
   }
   .pu-filters-bar label {
      font-size: .78rem;
      font-weight: 600;
      color: #6b7280;
      text-transform: uppercase;
      letter-spacing: .05em;
      margin-bottom: 6px;
      display: block;
   }

   /* Section heading */
   .pu-section-tag {
      display: inline-block;
      background: #eef2ff;
      color: #2563eb;
      font-size: .8rem;
      font-weight: 700;
      letter-spacing: .06em;
      text-transform: uppercase;
      padding: 5px 14px;
      border-radius: 50px;
      margin-bottom: 10px;
   }
   .pu-section-title {
      font-size: clamp(1.6rem, 3vw, 2.2rem);
      font-weight: 800;
      color: #1a2e5a;
      line-height: 1.25;
   }
   .pu-results-count {
      font-size: .88rem;
      color: #6b7280;
      font-weight: 500;
   }

   /* Job Card */
   .pu-job-card {
      background: #fff;
      border: 1.5px solid #e8edf5;
      border-radius: 16px;
      padding: 28px 28px 24px;
      height: 100%;
      position: relative;
      transition: transform .25s ease, box-shadow .25s ease, border-color .25s ease;
      cursor: pointer;
      display: flex;
      flex-direction: column;
      overflow: hidden;
   }
   .pu-job-card::before {
      content: '';
      position: absolute;
      left: 0;
      top: 0;
      bottom: 0;
      width: 4px;
      background: #2563eb;
      border-radius: 16px 0 0 16px;
      opacity: 0;
      transition: opacity .25s ease;
   }
   .pu-job-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 12px 40px rgba(37,99,235,.15);
      border-color: #bfcfe8;
   }
   .pu-job-card:hover::before { opacity: 1; }

   .pu-card-link {
      position: absolute;
      inset: 0;
      z-index: 1;
   }
   .pu-job-card .pu-apply-btn {
      position: relative;
      z-index: 2;
   }

   .pu-type-badge {
      font-size: .72rem;
      font-weight: 700;
      letter-spacing: .04em;
      text-transform: uppercase;
      padding: 4px 12px;
      border-radius: 50px;
   }
   .pu-badge-fulltime  { background: #dbeafe; color: #1d4ed8; }
   .pu-badge-parttime  { background: #f3e8ff; color: #7c3aed; }
   .pu-badge-contract  { background: #fef3c7; color: #92400e; }
   .pu-badge-internship{ background: #d1fae5; color: #065f46; }

   .pu-job-title {
      font-size: 1.2rem;
      font-weight: 800;
      color: #111827;
      line-height: 1.3;
      margin-bottom: 12px;
   }
   .pu-job-title a { color: inherit; text-decoration: none; }

   .pu-meta-row {
      display: flex;
      flex-wrap: wrap;
      gap: 10px 18px;
      margin-bottom: 16px;
   }
   .pu-meta-item {
      font-size: .82rem;
      color: #6b7280;
      display: flex;
      align-items: center;
      gap: 5px;
   }
   .pu-meta-item i { color: #2563eb; font-size: .78rem; }

   .pu-highlights {
      list-style: none;
      padding: 0;
      margin: 0 0 20px;
      display: flex;
      flex-direction: column;
      gap: 7px;
   }
   .pu-highlights li {
      font-size: .87rem;
      color: #374151;
      display: flex;
      align-items: flex-start;
      gap: 8px;
      line-height: 1.45;
   }
   .pu-highlights li::before {
      content: '✓';
      color: #2563eb;
      font-weight: 700;
      font-size: .82rem;
      flex-shrink: 0;
      margin-top: 1px;
   }

   .pu-card-footer {
      margin-top: auto;
      padding-top: 18px;
      border-top: 1px solid #f1f5f9;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 10px;
   }
   .pu-posted-date {
      font-size: .78rem;
      color: #9ca3af;
   }
   .pu-apply-btn {
      background: #2563eb;
      color: #fff !important;
      font-size: .84rem;
      font-weight: 700;
      padding: 9px 22px;
      border-radius: 50px;
      text-decoration: none !important;
      transition: background .2s ease, transform .2s ease;
      display: inline-flex;
      align-items: center;
      gap: 7px;
   }
   .pu-apply-btn:hover {
      background: #1d4ed8;
      transform: translateX(2px);
   }

   /* Empty state */
   .pu-empty-state {
      padding: 80px 20px;
      text-align: center;
   }
   .pu-empty-state .icon-wrap {
      width: 90px;
      height: 90px;
      border-radius: 50%;
      background: #eef2ff;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 24px;
   }
   .pu-empty-state .icon-wrap i { font-size: 2.2rem; color: #2563eb; }
   .pu-empty-state h4 { font-size: 1.4rem; font-weight: 700; color: #1a2e5a; margin-bottom: 10px; }
   .pu-empty-state p  { color: #6b7280; margin-bottom: 28px; }

   /* No results (filter) */
   #pu-no-results { display: none; }

   /* Load More */
   .pu-load-more-wrap { text-align: center; padding-top: 40px; }
   #pu-load-more-btn {
      background: #1a2e5a;
      color: #fff;
      font-weight: 700;
      font-size: .95rem;
      padding: 14px 36px;
      border-radius: 50px;
      border: none;
      cursor: pointer;
      transition: background .2s ease, transform .2s ease;
      display: inline-flex;
      align-items: center;
      gap: 10px;
   }
   #pu-load-more-btn:hover {
      background: #2563eb;
      transform: translateY(-2px);
   }
   #pu-load-more-btn:disabled {
      opacity: .5;
      cursor: not-allowed;
      transform: none;
   }

   /* Why Join Us */
   .pu-why-section {
      background: linear-gradient(135deg, #f0f4ff 0%, #fafbff 100%);
      padding: 90px 0;
   }
   .pu-why-card {
      background: #fff;
      border-radius: 16px;
      padding: 36px 30px;
      text-align: center;
      height: 100%;
      box-shadow: 0 4px 20px rgba(0,0,0,.06);
      transition: transform .25s ease, box-shadow .25s ease;
   }
   .pu-why-card:hover {
      transform: translateY(-6px);
      box-shadow: 0 12px 36px rgba(37,99,235,.12);
   }
   .pu-why-icon {
      width: 70px;
      height: 70px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 20px;
      font-size: 1.7rem;
   }
   .pu-why-card h5 {
      font-size: 1.08rem;
      font-weight: 800;
      color: #1a2e5a;
      margin-bottom: 10px;
   }
   .pu-why-card p {
      font-size: .88rem;
      color: #6b7280;
      line-height: 1.65;
      margin: 0;
   }

   /* Responsive tweaks */
   @media (max-width: 767px) {
      .pu-jobs-hero  { padding: 70px 0 60px; }
      .pu-filters-bar { margin-top: 28px; border-radius: 12px; }
      .pu-job-card   { padding: 22px 20px 18px; }
      .pu-why-section { padding: 60px 0; }
   }
   </style>

   <main>

   <?php include __DIR__ . '/includes/news-ticker.php'; ?>

   <!-- ── hero-start ─────────────────────────────────────────────── -->
   <section class="pu-jobs-hero">
      <div class="container position-relative z-index-1">
         <!-- breadcrumb nav -->
         <nav class="breadcrumb-nav mb-24" aria-label="breadcrumb">
            <a href="<?= fh(SITE_URL) ?>/index.php">Home</a>
            <span class="sep">/</span>
            <span style="color:rgba(255,255,255,.9);">Career Opportunities</span>
         </nav>

         <div class="row align-items-center">
            <div class="col-lg-8">
               <h1>Join the Prime University Family</h1>
               <p class="tagline">Build your career at one of Bangladesh's most dynamic universities — where talent meets opportunity and every day makes a difference.</p>
               <div class="pu-hero-cta">
                  <a href="#open-positions" class="pu-btn-white">
                     <i class="fas fa-briefcase"></i> View Open Positions
                  </a>
                  <a href="#why-join-us" class="pu-btn-outline-white">
                     <i class="fas fa-users"></i> Join Our Team
                  </a>
               </div>
            </div>
            <div class="col-lg-4 text-center d-none d-lg-block" style="opacity:.15;">
               <i class="fas fa-university" style="font-size:9rem;color:#fff;"></i>
            </div>
         </div>
      </div>
   </section>
   <!-- ── hero-end ───────────────────────────────────────────────── -->

   <!-- ── filters-start ─────────────────────────────────────────── -->
   <div class="container">
      <div class="pu-filters-bar">
         <div class="row g-3 align-items-end">
            <div class="col-md-4">
               <label for="pu-search">Search Positions</label>
               <div class="input-group">
                  <span class="input-group-text"><i class="fas fa-search"></i></span>
                  <input type="search" id="pu-search" class="form-control"
                         placeholder="Search jobs…" autocomplete="off">
               </div>
            </div>
            <div class="col-md-3">
               <label for="pu-filter-dept">Department</label>
               <select id="pu-filter-dept" class="form-select">
                  <option value="">All Departments</option>
                  <?php foreach ($departments as $d): ?>
                  <option value="<?= fh($d) ?>"><?= fh($d) ?></option>
                  <?php endforeach; ?>
               </select>
            </div>
            <div class="col-md-2">
               <label for="pu-filter-type">Job Type</label>
               <select id="pu-filter-type" class="form-select">
                  <option value="">All Types</option>
                  <option value="full-time">Full-time</option>
                  <option value="part-time">Part-time</option>
                  <option value="contract">Contract</option>
                  <option value="internship">Internship</option>
               </select>
            </div>
            <div class="col-md-3">
               <label for="pu-filter-loc">Location</label>
               <select id="pu-filter-loc" class="form-select">
                  <option value="">All Locations</option>
                  <?php foreach ($locations as $l): ?>
                  <option value="<?= fh($l) ?>"><?= fh($l) ?></option>
                  <?php endforeach; ?>
               </select>
            </div>
         </div>
      </div>
   </div>
   <!-- ── filters-end ───────────────────────────────────────────── -->

   <!-- ── jobs-area-start ───────────────────────────────────────── -->
   <section id="open-positions" class="pt-70 pb-90">
      <div class="container">

         <?php if (empty($jobs)): ?>
         <!-- Empty state -->
         <div class="pu-empty-state">
            <div class="icon-wrap"><i class="fas fa-briefcase"></i></div>
            <h4>No Vacancies at the Moment</h4>
            <p>There are currently no open positions.<br>Please check back soon — great opportunities are coming!</p>
            <a href="<?= fh(SITE_URL) ?>/index.php" class="pu-btn-white" style="background:#2563eb;color:#fff;border-color:#2563eb;">
               <i class="fas fa-home"></i> Back to Home
            </a>
         </div>

         <?php else: ?>

         <!-- Section heading -->
         <div class="row mb-40">
            <div class="col-md-8">
               <span class="pu-section-tag"><i class="fas fa-briefcase me-1"></i> Open Positions</span>
               <h2 class="pu-section-title mt-1">We're Hiring!</h2>
            </div>
            <div class="col-md-4 text-md-end d-flex align-items-end justify-content-md-end">
               <span class="pu-results-count" id="pu-visible-count">
                  Showing <strong><?= count($jobs) ?></strong> position<?= count($jobs) !== 1 ? 's' : '' ?>
               </span>
            </div>
         </div>

         <!-- No results message -->
         <div id="pu-no-results" class="pu-empty-state">
            <div class="icon-wrap"><i class="fas fa-search"></i></div>
            <h4>No Matching Positions</h4>
            <p>Try adjusting your search or filters.</p>
         </div>

         <!-- Cards grid -->
         <div class="row g-4" id="pu-jobs-grid">
            <?php
            $type_badge_cls = [
                'full-time'  => 'pu-badge-fulltime',
                'part-time'  => 'pu-badge-parttime',
                'contract'   => 'pu-badge-contract',
                'internship' => 'pu-badge-internship',
            ];
            $type_label = [
                'full-time'  => 'Full-time',
                'part-time'  => 'Part-time',
                'contract'   => 'Contract',
                'internship' => 'Internship',
            ];
            foreach ($jobs as $job):
                $badge_cls  = $type_badge_cls[$job['job_type']] ?? 'pu-badge-parttime';
                $type_lbl   = $type_label[$job['job_type']]    ?? ucfirst($job['job_type']);
                $detail_url = fh(SITE_URL) . '/jobs/' . urlencode($job['slug']);

                // "Posted X days ago"
                $created_ts = strtotime($job['created_at']);
                $days_ago   = (int) floor((time() - $created_ts) / 86400);
                if ($days_ago === 0)      $posted = 'Posted today';
                elseif ($days_ago === 1)  $posted = 'Posted yesterday';
                elseif ($days_ago < 30)  $posted = "Posted {$days_ago} days ago";
                elseif ($days_ago < 60)  $posted = 'Posted about a month ago';
                else                     $posted = 'Posted ' . (int)floor($days_ago/30) . ' months ago';

                // Build bullet highlights
                $highlights = [];
                if (!empty($job['salary_range'])) {
                    $highlights[] = '<i class="fas fa-money-bill-wave"></i> Salary: ' . fh($job['salary_range']);
                }
                if (!empty($job['deadline'])) {
                    $highlights[] = '<i class="fas fa-calendar-alt"></i> Deadline: ' . fh(date('d M Y', strtotime($job['deadline'])));
                }
                if (!empty($job['app_count']) && $job['app_count'] > 0) {
                    $highlights[] = '<i class="fas fa-users"></i> ' . (int)$job['app_count'] . ' application' . ((int)$job['app_count'] !== 1 ? 's' : '') . ' so far';
                }
                if (empty($highlights)) {
                    // Fallback: short excerpt if no structured highlights
                    $excerpt = mb_strimwidth(strip_tags($job['description']), 0, 130, '…');
                    $highlights[] = '<i class="fas fa-align-left"></i> ' . fh($excerpt);
                }

                // data-* attributes for JS filtering
                $data_dept = fh($job['department']);
                $data_type = fh($job['job_type']);
                $data_loc  = fh($job['location']);
                $data_title= fh(strtolower($job['title']));
            ?>
            <div class="col-xl-6 col-lg-6 pu-job-item"
                 data-title="<?= $data_title ?>"
                 data-dept="<?= $data_dept ?>"
                 data-type="<?= $data_type ?>"
                 data-loc="<?= $data_loc ?>"
                 style="display:none;">
               <div class="pu-job-card">
                  <!-- Full card link -->
                  <a href="<?= $detail_url ?>" class="pu-card-link" aria-label="View <?= fh($job['title']) ?>"></a>

                  <!-- Header row -->
                  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-16">
                     <span class="pu-type-badge <?= $badge_cls ?>"><?= $type_lbl ?></span>
                  </div>

                  <!-- Title -->
                  <h3 class="pu-job-title"><?= fh($job['title']) ?></h3>

                  <!-- Meta row -->
                  <div class="pu-meta-row">
                     <?php if ($job['department']): ?>
                     <span class="pu-meta-item"><i class="fas fa-building"></i><?= fh($job['department']) ?></span>
                     <?php endif; ?>
                     <?php if ($job['location']): ?>
                     <span class="pu-meta-item"><i class="fas fa-map-marker-alt"></i><?= fh($job['location']) ?></span>
                     <?php endif; ?>
                  </div>

                  <!-- Bullet highlights -->
                  <ul class="pu-highlights">
                     <?php foreach ($highlights as $hl): ?>
                     <li><?= $hl ?></li>
                     <?php endforeach; ?>
                  </ul>

                  <!-- Footer -->
                  <div class="pu-card-footer">
                     <span class="pu-posted-date"><i class="far fa-clock me-1"></i><?= $posted ?></span>
                     <a href="<?= $detail_url ?>" class="pu-apply-btn">
                        Apply Now <i class="fas fa-arrow-right"></i>
                     </a>
                  </div>
               </div>
            </div>
            <?php endforeach; ?>
         </div>

         <!-- Load More -->
         <div class="pu-load-more-wrap" id="pu-load-more-wrap" style="display:none;">
            <button id="pu-load-more-btn">
               <i class="fas fa-plus-circle"></i> Load More Jobs
            </button>
         </div>

         <?php endif; ?>

      </div>
   </section>
   <!-- ── jobs-area-end ─────────────────────────────────────────── -->

   <!-- ── why-join-us-start ──────────────────────────────────────── -->
   <section id="why-join-us" class="pu-why-section">
      <div class="container">
         <div class="row mb-50">
            <div class="col-12 text-center">
               <span class="pu-section-tag"><i class="fas fa-star me-1"></i> Why Prime University?</span>
               <h2 class="pu-section-title mt-2">Why Join Us?</h2>
               <p class="mt-10" style="color:#6b7280;max-width:560px;margin:10px auto 0;font-size:.95rem;">
                  More than a job — a community that invests in your growth, well-being, and future.
               </p>
            </div>
         </div>

         <div class="row g-4">
            <div class="col-lg-3 col-sm-6">
               <div class="pu-why-card">
                  <div class="pu-why-icon" style="background:#dbeafe;">
                     <i class="fas fa-chart-line" style="color:#2563eb;"></i>
                  </div>
                  <h5>Growth Opportunities</h5>
                  <p>Clear career paths, leadership programmes, and ongoing professional development to take you further.</p>
               </div>
            </div>
            <div class="col-lg-3 col-sm-6">
               <div class="pu-why-card">
                  <div class="pu-why-icon" style="background:#d1fae5;">
                     <i class="fas fa-hands-helping" style="color:#059669;"></i>
                  </div>
                  <h5>Collaborative Culture</h5>
                  <p>A warm, inclusive team where ideas are valued, collaboration thrives, and everyone belongs.</p>
               </div>
            </div>
            <div class="col-lg-3 col-sm-6">
               <div class="pu-why-card">
                  <div class="pu-why-icon" style="background:#fef3c7;">
                     <i class="fas fa-medal" style="color:#d97706;"></i>
                  </div>
                  <h5>Competitive Benefits</h5>
                  <p>Attractive salary packages, festival bonuses, medical coverage, and performance rewards.</p>
               </div>
            </div>
            <div class="col-lg-3 col-sm-6">
               <div class="pu-why-card">
                  <div class="pu-why-icon" style="background:#f3e8ff;">
                     <i class="fas fa-graduation-cap" style="color:#7c3aed;"></i>
                  </div>
                  <h5>Academic Environment</h5>
                  <p>Work at the heart of education — inspire future leaders and grow within a knowledge-rich community.</p>
               </div>
            </div>
         </div>
      </div>
   </section>
   <!-- ── why-join-us-end ────────────────────────────────────────── -->

   </main>

   <script>
   (function () {
      'use strict';

      var CARDS_PER_PAGE = 6;
      var allItems  = Array.from(document.querySelectorAll('.pu-job-item'));
      var visible   = [];        // items passing current filter
      var shown     = 0;

      var searchEl  = document.getElementById('pu-search');
      var deptEl    = document.getElementById('pu-filter-dept');
      var typeEl    = document.getElementById('pu-filter-type');
      var locEl     = document.getElementById('pu-filter-loc');
      var countEl   = document.getElementById('pu-visible-count');
      var noRes     = document.getElementById('pu-no-results');
      var loadMore  = document.getElementById('pu-load-more-btn');
      var loadWrap  = document.getElementById('pu-load-more-wrap');

      function applyFilters() {
         var searchQuery = (searchEl ? searchEl.value.toLowerCase().trim() : '');
         var dept        = (deptEl   ? deptEl.value   : '');
         var type        = (typeEl   ? typeEl.value   : '');
         var loc         = (locEl    ? locEl.value    : '');

         // Hide all
         allItems.forEach(function(el){ el.style.display = 'none'; });

         visible = allItems.filter(function(el) {
            if (searchQuery && el.dataset.title.indexOf(searchQuery) === -1) return false;
            if (dept && el.dataset.dept !== dept)                             return false;
            if (type && el.dataset.type !== type)                             return false;
            if (loc  && el.dataset.loc  !== loc)                              return false;
            return true;
         });

         shown = 0;
         showMore();
      }

      function showMore() {
         var batch = visible.slice(shown, shown + CARDS_PER_PAGE);
         batch.forEach(function(el){ el.style.display = ''; });
         shown += batch.length;

         // update count safely (no innerHTML)
         if (countEl) {
            var s = visible.length;
            var displayed = Math.min(shown, s);
            while (countEl.firstChild) countEl.removeChild(countEl.firstChild);
            var prefix = document.createTextNode('Showing ');
            var strong = document.createElement('strong');
            strong.textContent = displayed + ' of ' + s;
            var suffix = document.createTextNode(' position' + (s !== 1 ? 's' : ''));
            countEl.appendChild(prefix);
            countEl.appendChild(strong);
            countEl.appendChild(suffix);
         }

         // no results
         if (noRes) noRes.style.display = (visible.length === 0) ? 'block' : 'none';

         // load more button
         if (loadWrap) {
            loadWrap.style.display = (shown < visible.length) ? 'block' : 'none';
         }
      }

      if (loadMore) {
         loadMore.addEventListener('click', function() { showMore(); });
      }

      if (searchEl) searchEl.addEventListener('input',  applyFilters);
      if (deptEl)   deptEl.addEventListener('change',   applyFilters);
      if (typeEl)   typeEl.addEventListener('change',   applyFilters);
      if (locEl)    locEl.addEventListener('change',    applyFilters);

      // Smooth scroll for hero CTA
      document.querySelectorAll('a[href^="#"]').forEach(function(anchor) {
         anchor.addEventListener('click', function(e) {
            var target = document.querySelector(this.getAttribute('href'));
            if (target) {
               e.preventDefault();
               target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
         });
      });

      // Initial render
      applyFilters();
   }());
   </script>

<?php include __DIR__ . '/includes/footer.php'; ?>

   <!-- JS Libraries -->
   <?php include __DIR__ . '/includes/scripts.php'; ?>

</body>
</html>
