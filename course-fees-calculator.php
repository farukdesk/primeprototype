<?php
require_once __DIR__ . '/includes/config.php';

// ── Fetch settings & active fee structures ────────────────────────────────────
$db = front_db();

$settings = [];
$programs = [];

if ($db) {
    try {
        $settings = $db->query('SELECT * FROM cf_settings WHERE id = 1')->fetch() ?: [];

        if (!($settings['is_published'] ?? 1)) {
            // Page not published – show a coming-soon message instead of a 404
            $programs = []; // empty programs will trigger the "not yet configured" message
        }

        $programs = $db->query(
            "SELECT p.*,
                    d.name           AS dept_name,
                    ap.program_name
             FROM cf_programs p
             LEFT JOIN dept_departments     d  ON d.id  = p.dept_id
             LEFT JOIN dept_academic_programs ap ON ap.id = p.program_id
             WHERE p.is_active = 1
             ORDER BY p.sort_order, d.name, ap.program_name, p.id"
        )->fetchAll();

        // Fixed fees per program
        if ($programs) {
            $ids   = implode(',', array_map(fn($r) => (int)$r['id'], $programs));
            $fees  = $db->query(
                "SELECT * FROM cf_fixed_fees WHERE cf_program_id IN ($ids) ORDER BY sort_order, id"
            )->fetchAll();

            // Index by program id
            $fees_by_prog = [];
            foreach ($fees as $f) {
                $fees_by_prog[$f['cf_program_id']][] = $f;
            }
        }
    } catch (Throwable $e) {
        // fall through silently
    }
}

$page_title = ($settings['page_title'] ?? 'Course Fees Calculator') . ' – Prime University';
$currency   = $settings['currency'] ?? 'BDT';

// Build JS-safe programme data
$js_programs = [];
foreach ($programs as $p) {
    $fixed = $fees_by_prog[$p['id']] ?? [];
    $js_programs[] = [
        'id'            => (int)$p['id'],
        'label'         => ($p['program_name'] ?: ($p['dept_name'] ?: 'Programme #' . $p['id'])),
        'dept'          => $p['dept_name'] ?? '',
        'degree'        => $p['degree_type'],
        'total_credits' => $p['total_credits'] ? (int)$p['total_credits'] : null,
        'duration'      => $p['duration_years'] ? (float)$p['duration_years'] : null,
        'fixed_fees'    => array_map(fn($f) => [
            'name'   => $f['fee_name'],
            'amount' => (int)$f['amount'],
            'type'   => $f['fee_type'],
        ], $fixed),
    ];
}
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <title><?= fh($page_title) ?></title>
   <meta name="description" content="<?= fh($settings['page_subtitle'] ?? 'Estimate your tuition and fees at Prime University instantly.') ?>">
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
   /* ===== COURSE FEES CALCULATOR – CUSTOM STYLES ===== */
   :root {
      --pu-navy:    #1a2e5a;
      --pu-blue:    #2563eb;
      --pu-gold:    #f59e0b;
      --pu-green:   #059669;
      --pu-purple:  #7c3aed;
      --pu-teal:    #0891b2;
   }

   /* ── Hero ─────────────────────────────────────────────────────── */
   .cf-hero {
      background: linear-gradient(135deg, var(--pu-navy) 0%, #0f1f40 55%, #1e3a6e 100%);
      padding: 90px 0 80px;
      position: relative;
      overflow: hidden;
   }
   .cf-hero::before {
      content: '';
      position: absolute;
      inset: 0;
      background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.03'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
   }
   .cf-hero-blob {
      position: absolute;
      border-radius: 50%;
      filter: blur(60px);
      opacity: .15;
      animation: blobDrift 12s ease-in-out infinite alternate;
   }
   .cf-hero-blob-1 { width:400px;height:400px;background:var(--pu-blue);top:-120px;right:-100px; }
   .cf-hero-blob-2 { width:300px;height:300px;background:var(--pu-gold);bottom:-80px;left:-60px;animation-delay:-5s; }
   @keyframes blobDrift { from { transform: translate(0,0) scale(1); } to { transform: translate(30px,20px) scale(1.07); } }

   .cf-hero-title {
      font-size: clamp(2rem, 5vw, 3.2rem);
      font-weight: 900;
      color: #fff;
      line-height: 1.15;
   }
   .cf-hero-title span { color: var(--pu-gold); }
   .cf-hero-sub { color: rgba(255,255,255,.72); font-size: 1.05rem; max-width: 580px; line-height: 1.7; }

   .cf-hero-stat {
      background: rgba(255,255,255,.08);
      border: 1px solid rgba(255,255,255,.12);
      border-radius: 12px;
      padding: 16px 20px;
      text-align: center;
      backdrop-filter: blur(8px);
      transition: transform .25s, background .25s;
   }
   .cf-hero-stat:hover { transform: translateY(-4px); background: rgba(255,255,255,.13); }
   .cf-hero-stat-val { font-size: 1.75rem; font-weight: 900; color: var(--pu-gold); line-height: 1; }
   .cf-hero-stat-lbl { font-size: .78rem; color: rgba(255,255,255,.65); margin-top: 4px; }

   /* ── How It Works ─────────────────────────────────────────────── */
   .cf-step-card {
      border-radius: 16px;
      padding: 28px 24px;
      background: #fff;
      border: 1.5px solid #e5e7eb;
      position: relative;
      transition: transform .25s, box-shadow .25s;
   }
   .cf-step-card:hover { transform: translateY(-6px); box-shadow: 0 14px 40px rgba(26,46,90,.1); }
   .cf-step-num {
      width: 52px; height: 52px;
      border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.35rem; font-weight: 900;
      margin-bottom: 14px;
   }

   /* ── Calculator Card ──────────────────────────────────────────── */
   .cf-card {
      border-radius: 20px;
      border: none;
      box-shadow: 0 8px 48px rgba(26,46,90,.13);
      overflow: hidden;
   }
   .cf-card-header {
      background: linear-gradient(135deg, var(--pu-navy), #2563eb);
      color: #fff;
      padding: 28px 32px;
   }
   .cf-card-body { padding: 32px; }

   .cf-select-wrap { position: relative; }
   .cf-select-wrap select {
      appearance: none;
      -webkit-appearance: none;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' fill='none'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%231a2e5a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 16px center;
      padding-right: 40px;
      border: 2px solid #e2e8f0;
      border-radius: 12px;
      font-size: 1rem;
      padding: 14px 40px 14px 16px;
      transition: border-color .2s, box-shadow .2s;
      width: 100%;
      cursor: pointer;
   }
   .cf-select-wrap select:focus {
      outline: none;
      border-color: var(--pu-blue);
      box-shadow: 0 0 0 4px rgba(37,99,235,.12);
   }

   .cf-slider-wrap {
      padding: 8px 0;
   }
   .cf-range {
      -webkit-appearance: none;
      appearance: none;
      width: 100%;
      height: 6px;
      border-radius: 3px;
      background: #e2e8f0;
      outline: none;
      cursor: pointer;
      transition: background .2s;
   }
   .cf-range::-webkit-slider-thumb {
      -webkit-appearance: none;
      appearance: none;
      width: 22px; height: 22px;
      border-radius: 50%;
      background: var(--pu-blue);
      border: 3px solid #fff;
      box-shadow: 0 2px 8px rgba(37,99,235,.4);
      cursor: grab;
      transition: transform .15s;
   }
   .cf-range::-webkit-slider-thumb:active { cursor: grabbing; transform: scale(1.2); }
   .cf-range::-moz-range-thumb {
      width: 22px; height: 22px;
      border-radius: 50%;
      background: var(--pu-blue);
      border: 3px solid #fff;
      box-shadow: 0 2px 8px rgba(37,99,235,.4);
      cursor: grab;
   }
   .cf-credit-display {
      font-size: 2.5rem;
      font-weight: 900;
      color: var(--pu-navy);
      line-height: 1;
   }
   .cf-credit-unit { font-size: .9rem; font-weight: 600; color: #6b7280; margin-left: 4px; }

   /* Waiver selector */
   .cf-waiver-options {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
      gap: 8px;
   }
   .cf-waiver-option {
      border: 2px solid #e2e8f0;
      border-radius: 10px;
      padding: 10px 6px;
      text-align: center;
      cursor: pointer;
      font-size: .85rem;
      font-weight: 700;
      color: #374151;
      transition: border-color .2s, background .2s, color .2s, transform .2s;
      user-select: none;
   }
   .cf-waiver-option:hover { border-color: var(--pu-blue); background: rgba(37,99,235,.04); transform: translateY(-2px); }
   .cf-waiver-option.active {
      border-color: var(--pu-blue);
      background: var(--pu-blue);
      color: #fff;
      transform: translateY(-2px);
   }
   .cf-waiver-option .pct { font-size: 1.1rem; display: block; line-height: 1; }

   /* Results panel */
   .cf-result-panel {
      background: linear-gradient(160deg, #f0f7ff 0%, #ffffff 100%);
      border-radius: 16px;
      border: 1.5px solid #bfdbfe;
      padding: 28px;
      position: sticky;
      top: 100px;
   }
   .cf-result-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 10px 0;
      border-bottom: 1px solid #e5e7eb;
      font-size: .92rem;
      gap: 8px;
   }
   .cf-result-row:last-child { border-bottom: none; }
   .cf-result-row.total {
      font-size: 1.1rem;
      font-weight: 900;
      color: var(--pu-navy);
      border-top: 2px solid var(--pu-blue);
      margin-top: 8px;
      padding-top: 14px;
      border-bottom: none;
   }
   .cf-result-val { font-weight: 700; color: var(--pu-navy); white-space: nowrap; }
   .cf-result-val.discount { color: var(--pu-green); }

   /* Count-up animated number */
   .cf-amount-big {
      font-size: clamp(2rem, 5vw, 3rem);
      font-weight: 900;
      color: var(--pu-blue);
      line-height: 1;
      transition: color .3s;
   }
   .cf-amount-currency { font-size: 1rem; font-weight: 700; color: #6b7280; vertical-align: top; margin-top: 6px; display: inline-block; }

   /* Progress indicator */
   .cf-steps-indicator {
      display: flex;
      gap: 8px;
      margin-bottom: 28px;
   }
   .cf-step-dot {
      flex: 1;
      height: 4px;
      border-radius: 2px;
      background: #e2e8f0;
      transition: background .35s;
   }
   .cf-step-dot.done { background: var(--pu-blue); }

   /* CTA section */
   .cf-cta {
      background: linear-gradient(135deg, #1a2e5a, #2563eb);
      border-radius: 20px;
      padding: 52px 40px;
      color: #fff;
      text-align: center;
   }
   .cf-cta h2 { font-size: clamp(1.6rem, 4vw, 2.4rem); font-weight: 900; margin-bottom: 14px; }
   .cf-cta-btn {
      background: var(--pu-gold);
      color: var(--pu-navy);
      font-weight: 800;
      border: none;
      padding: 14px 36px;
      border-radius: 50px;
      font-size: 1rem;
      text-decoration: none;
      display: inline-block;
      transition: transform .2s, box-shadow .2s;
   }
   .cf-cta-btn:hover { transform: translateY(-3px); box-shadow: 0 10px 30px rgba(245,158,11,.4); color: var(--pu-navy); }

   /* Note box */
   .cf-note {
      background: #fffbeb;
      border: 1.5px solid #fde68a;
      border-radius: 12px;
      padding: 18px 22px;
      font-size: .88rem;
      color: #78350f;
      line-height: 1.7;
   }

   /* Animations */
   @keyframes fadeInUp {
      from { opacity: 0; transform: translateY(24px); }
      to   { opacity: 1; transform: translateY(0); }
   }
   .fade-in-up { animation: fadeInUp .55s ease both; }
   .delay-1 { animation-delay: .1s; }
   .delay-2 { animation-delay: .22s; }
   .delay-3 { animation-delay: .34s; }
   .delay-4 { animation-delay: .46s; }

   @keyframes pulseGlow {
      0%, 100% { box-shadow: 0 0 0 0 rgba(37,99,235,.25); }
      50%       { box-shadow: 0 0 0 10px rgba(37,99,235,0); }
   }
   .cf-result-panel.recalculating { animation: pulseGlow .6s ease; }

   /* ── Responsive ────────────────────────────────────────────────── */
   @media (max-width: 767px) {
      .cf-hero { padding: 60px 0 50px; }
      .cf-card-body { padding: 20px; }
      .cf-result-panel { position: static; }
      .cf-waiver-options { grid-template-columns: repeat(3, 1fr); }
   }
   </style>
<?php include __DIR__ . '/includes/meta-pixel.php'; ?>
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

   <!-- search popup -->
   <div class="search-popup">
      <button class="close-search"><span class="flaticon-multiply"><i class="fal fa-times"></i></span></button>
      <form method="post" action="#">
         <div class="form-group">
            <input type="search" name="search-field" value="" placeholder="Search Here" required>
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
               <div class="itoffcanvas__info-icon"><a href="#"><i class="fal fa-envelope"></i></a></div>
               <div class="itoffcanvas__info-address">
                  <span>Email</span><a href="mailto:info@primeuniversity.ac.bd">info@primeuniversity.ac.bd</a>
               </div>
            </div>
            <div class="it-info-wrapper d-flex align-items-center">
               <div class="itoffcanvas__info-icon"><a href="#"><i class="fal fa-phone-alt"></i></a></div>
               <div class="itoffcanvas__info-address">
                  <span>Phone</span><a href="tel:01969955566">01969-955566</a>
               </div>
            </div>
         </div>
      </div>
   </div>

   <!-- Header -->
   <?php include __DIR__ . '/includes/header-top.php'; ?>
   <?php include __DIR__ . '/includes/nav-menu.php'; ?>

   <!-- news ticker -->
   <?php include __DIR__ . '/includes/news-ticker.php'; ?>

   <!-- ═══════ HERO ═══════ -->
   <section class="cf-hero">
      <div class="cf-hero-blob cf-hero-blob-1"></div>
      <div class="cf-hero-blob cf-hero-blob-2"></div>
      <div class="container position-relative">
         <div class="row align-items-center gy-5">
            <div class="col-lg-7 fade-in-up">
               <p class="text-uppercase fw-bold mb-3" style="color:var(--pu-gold);letter-spacing:.08em;font-size:.85rem;">
                  <i class="fas fa-calculator me-2"></i>Tuition & Fee Estimator
               </p>
               <h1 class="cf-hero-title mb-4">
                  <?= fh($settings['page_title'] ?? 'Course Fees') ?><br>
                  <span>Calculator</span>
               </h1>
               <p class="cf-hero-sub mb-5">
                  <?= fh($settings['page_subtitle'] ?? 'Estimate your tuition and fees at Prime University — transparent, real-time, and personalised.') ?>
               </p>
               <a href="#calculator" class="btn btn-warning fw-bold px-5 py-3 rounded-pill me-3"
                  style="font-size:1rem;color:var(--pu-navy);">
                  <i class="fas fa-calculator me-2"></i>Calculate Now
               </a>
               <a href="/scholarships-waivers.php" class="btn btn-outline-light fw-semibold px-4 py-3 rounded-pill"
                  style="font-size:.95rem;">
                  <i class="fas fa-graduation-cap me-2"></i>Scholarships
               </a>
            </div>

            <!-- Stats -->
            <div class="col-lg-5 fade-in-up delay-2">
               <div class="row g-3">
                  <?php
                  $hero_stats = [
                      ['val' => count($programs), 'lbl' => 'Programmes', 'icon' => 'fas fa-book-open'],
                      ['val' => '100%', 'lbl' => 'Transparent Fees', 'icon' => 'fas fa-check-shield'],
                      ['val' => 'BDT', 'lbl' => 'Currency', 'icon' => 'fas fa-coins'],
                      ['val' => 'Live', 'lbl' => 'Real-Time Estimate', 'icon' => 'fas fa-bolt'],
                  ];
                  foreach ($hero_stats as $hs):
                  ?>
                  <div class="col-6">
                     <div class="cf-hero-stat">
                        <div class="mb-1"><i class="<?= $hs['icon'] ?>" style="color:var(--pu-gold);font-size:1.2rem;"></i></div>
                        <div class="cf-hero-stat-val"><?= $hs['val'] ?></div>
                        <div class="cf-hero-stat-lbl"><?= $hs['lbl'] ?></div>
                     </div>
                  </div>
                  <?php endforeach; ?>
               </div>
            </div>
         </div>
      </div>
   </section>

   <!-- ═══════ HOW IT WORKS ═══════ -->
   <section class="pt-80 pb-60" style="background:#f8fafc;">
      <div class="container">
         <div class="text-center mb-50 fade-in-up">
            <p class="text-uppercase fw-bold mb-2" style="color:var(--pu-blue);letter-spacing:.08em;font-size:.82rem;">Simple Steps</p>
            <h2 class="section-title" style="color:var(--pu-navy);">How It Works</h2>
         </div>
         <div class="row g-4">
            <?php
            $steps = [
               ['n'=>'1','icon'=>'fas fa-graduation-cap','color'=>'#2563eb','bg'=>'#dbeafe','title'=>'Select Programme','desc'=>'Choose your department and target programme from the dropdown list.'],
               ['n'=>'2','icon'=>'fas fa-percentage','color'=>'#7c3aed','bg'=>'#ede9fe','title'=>'Apply Scholarship','desc'=>'Select your scholarship or waiver percentage to see the discounted monthly payment.'],
               ['n'=>'3','icon'=>'fas fa-calendar-alt','color'=>'#059669','bg'=>'#d1fae5','title'=>'View Monthly Payment','desc'=>'Tuition and programme fees divided by total months give your monthly installment.'],
               ['n'=>'4','icon'=>'fas fa-file-invoice-dollar','color'=>'#d97706','bg'=>'#fef3c7','title'=>'See One-Time Fees','desc'=>'Admission and Registration fees are listed separately — paid once at enrolment.'],
            ];
            foreach ($steps as $i => $s):
            ?>
            <div class="col-sm-6 col-lg-3 fade-in-up delay-<?= $i + 1 ?>">
               <div class="cf-step-card h-100">
                  <div class="cf-step-num" style="background:<?= $s['bg'] ?>;color:<?= $s['color'] ?>"><?= $s['n'] ?></div>
                  <i class="<?= $s['icon'] ?> mb-3" style="font-size:1.6rem;color:<?= $s['color'] ?>"></i>
                  <h5 style="font-weight:800;color:var(--pu-navy);margin-bottom:8px;"><?= $s['title'] ?></h5>
                  <p style="font-size:.88rem;color:#6b7280;line-height:1.65;margin:0;"><?= $s['desc'] ?></p>
               </div>
            </div>
            <?php endforeach; ?>
         </div>
      </div>
   </section>

   <!-- ═══════ CALCULATOR ═══════ -->
   <section id="calculator" class="pt-80 pb-80">
      <div class="container">
         <div class="text-center mb-50 fade-in-up">
            <p class="text-uppercase fw-bold mb-2" style="color:var(--pu-blue);letter-spacing:.08em;font-size:.82rem;">
               <i class="fas fa-calculator me-1"></i>Fee Estimator
            </p>
            <h2 class="section-title" style="color:var(--pu-navy);">Calculate Your Fees</h2>
            <p class="mx-auto" style="max-width:540px;color:#6b7280;font-size:.95rem;">
               Get an instant estimate of your monthly fees. Select a programme and apply any scholarship to personalise.
            </p>
         </div>

         <?php if (empty($programs)): ?>
         <div class="alert alert-info text-center py-5">
            <i class="fas fa-info-circle fa-2x mb-3 d-block"></i>
            Fee structures are not yet configured. Please check back later.
         </div>
         <?php else: ?>

         <div class="row g-5 align-items-start">
            <!-- ── Inputs ── -->
            <div class="col-lg-7">
               <div class="cf-card card fade-in-up">
                  <div class="cf-card-header">
                     <h3 class="mb-1 fw-bold" style="font-size:1.3rem;">
                        <i class="fas fa-sliders-h me-2" style="color:var(--pu-gold)"></i>
                        Customise Your Estimate
                     </h3>
                     <p class="mb-0" style="font-size:.88rem;opacity:.75;">Fill in the fields below and the breakdown updates instantly.</p>
                  </div>
                  <div class="cf-card-body">
                     <!-- Progress dots -->
                     <div class="cf-steps-indicator" id="progress-dots">
                        <div class="cf-step-dot"></div>
                        <div class="cf-step-dot"></div>
                     </div>

                     <!-- Step 1: Programme -->
                     <div class="mb-4">
                        <label class="fw-bold mb-2" style="color:var(--pu-navy);font-size:.95rem;">
                           <span class="badge me-2" style="background:var(--pu-blue);border-radius:50%;width:24px;height:24px;font-size:.7rem;line-height:24px;padding:0;display:inline-flex;align-items:center;justify-content:center;">1</span>
                           Select Programme
                        </label>
                        <div class="cf-select-wrap">
                           <select id="programSelect" class="form-select">
                              <option value="">— Choose a programme —</option>
                              <?php
                              $grouped = [];
                              foreach ($programs as $p) {
                                  $grp = $p['dept_name'] ?: 'Other';
                                  $grouped[$grp][] = $p;
                              }
                              foreach ($grouped as $grp => $rows):
                              ?>
                              <optgroup label="<?= fh($grp) ?>">
                                 <?php foreach ($rows as $p): ?>
                                 <option value="<?= $p['id'] ?>">
                                    <?= fh($p['program_name'] ?: $p['dept_name']) ?>
                                    (<?= ucfirst($p['degree_type']) ?>)
                                 </option>
                                 <?php endforeach; ?>
                              </optgroup>
                              <?php endforeach; ?>
                           </select>
                        </div>
                        <div id="prog-meta" class="mt-2 d-none">
                           <div class="d-flex gap-2 flex-wrap">
                              <span class="badge bg-primary" id="meta-degree"></span>
                              <span class="badge bg-secondary" id="meta-credits"></span>
                              <span class="badge bg-info text-dark" id="meta-duration"></span>
                           </div>
                        </div>
                     </div>

                     <!-- Step 2: Waiver -->
                     <div class="mb-2">
                        <label class="fw-bold mb-3" style="color:var(--pu-navy);font-size:.95rem;">
                           <span class="badge me-2" style="background:var(--pu-purple);border-radius:50%;width:24px;height:24px;font-size:.7rem;line-height:24px;padding:0;display:inline-flex;align-items:center;justify-content:center;">2</span>
                           Scholarship / Waiver
                        </label>
                        <div class="cf-waiver-options" id="waiverOptions">
                           <?php foreach ([0,10,15,20,25,33,50,100] as $pct): ?>
                           <div class="cf-waiver-option <?= $pct === 0 ? 'active' : '' ?>"
                                data-waiver="<?= $pct ?>">
                              <span class="pct"><?= $pct ?>%</span>
                              <span style="font-size:.7rem;font-weight:500;display:block;margin-top:2px;opacity:.75;">
                                 <?php if ($pct === 0) echo 'None'; elseif ($pct === 100) echo 'Full'; else echo 'Waiver'; ?>
                              </span>
                           </div>
                           <?php endforeach; ?>
                        </div>
                        <div class="mt-2">
                           <label class="small fw-semibold" style="color:#6b7280;">Or enter custom %:</label>
                           <div class="d-flex align-items-center gap-2 mt-1">
                              <input type="number" id="customWaiver" class="form-control form-control-sm"
                                     style="width:90px;border-radius:8px;" min="0" max="100" value="0">
                              <span class="small text-muted">%</span>
                           </div>
                        </div>
                     </div>
                  </div>
               </div>
            </div>

            <!-- ── Results ── -->
            <div class="col-lg-5 fade-in-up delay-2">
               <div class="cf-result-panel" id="resultPanel">
                  <div class="text-center mb-3" id="resultEmpty">
                     <i class="fas fa-calculator" style="font-size:3rem;color:#cbd5e1;"></i>
                     <p class="mt-2 text-muted">Select a programme to see your estimate.</p>
                  </div>
                  <div id="resultContent" class="d-none">
                     <div class="text-center mb-4">
                        <p class="small text-muted fw-semibold mb-1">MONTHLY INSTALLMENT</p>
                        <div>
                           <span class="cf-amount-currency"><?= fh($currency) ?></span>
                           <span class="cf-amount-big" id="totalAmount">0</span>
                        </div>
                        <p class="small text-muted mt-1 mb-0" id="savedMsg"></p>
                     </div>

                     <div id="breakdownRows"></div>

                     <div class="mt-4 d-grid gap-2">
                        <a href="/apply-now.php" class="btn btn-primary fw-bold py-3 rounded-pill">
                           <i class="fas fa-paper-plane me-2"></i>Apply Now
                        </a>
                        <button class="btn btn-outline-secondary btn-sm rounded-pill" onclick="window.print()">
                           <i class="fas fa-print me-1"></i>Print Estimate
                        </button>
                     </div>
                  </div>
               </div>

               <!-- Quick Info -->
               <div class="mt-4 p-3 rounded-3" style="background:#f8fafc;border:1.5px solid #e5e7eb;">
                  <p class="fw-bold mb-2" style="font-size:.88rem;color:var(--pu-navy);">
                     <i class="fas fa-lightbulb me-1 text-warning"></i>Quick Guide
                  </p>
                  <ul class="mb-0 ps-3" style="font-size:.82rem;color:#6b7280;line-height:1.7;">
                     <li>Monthly fees = total programme fee &divide; programme months</li>
                     <li>Admission &amp; Registration fees are paid once at enrolment</li>
                     <li>Scholarship reduces your monthly installment</li>
                  </ul>
               </div>
            </div>
         </div>

         <?php endif; ?>
      </div>
   </section>

   <!-- ═══════ DISCLAIMER ═══════ -->
   <?php if (!empty($settings['note_text'])): ?>
   <section class="pb-60">
      <div class="container">
         <div class="cf-note fade-in-up">
            <p class="fw-bold mb-1" style="color:#92400e;"><i class="fas fa-exclamation-triangle me-2"></i>Disclaimer</p>
            <p class="mb-0"><?= fh($settings['note_text']) ?></p>
         </div>
      </div>
   </section>
   <?php endif; ?>

   <!-- ═══════ CTA ═══════ -->
   <section class="pb-80">
      <div class="container">
         <div class="cf-cta fade-in-up">
            <i class="fas fa-graduation-cap mb-3" style="font-size:2.5rem;color:var(--pu-gold);"></i>
            <h2>Ready to Join Prime University?</h2>
            <p style="max-width:500px;margin:0 auto 28px;opacity:.8;font-size:.98rem;">
               Start your application today and take the first step toward a world-class education.
            </p>
            <div class="d-flex gap-3 justify-content-center flex-wrap">
               <a href="/apply-now.php" class="cf-cta-btn">
                  <i class="fas fa-paper-plane me-2"></i>Apply Now
               </a>
               <a href="/contact.php" class="btn btn-outline-light fw-semibold px-5 py-3 rounded-pill">
                  <i class="fas fa-phone me-2"></i>Contact Us
               </a>
            </div>
         </div>
      </div>
   </section>

   <?php include __DIR__ . '/includes/footer.php'; ?>
   <?php include __DIR__ . '/includes/scripts.php'; ?>

   <script>
   // ── Programme data from PHP ───────────────────────────────────────────────
   var CF_PROGRAMS = <?= json_encode($js_programs, JSON_UNESCAPED_UNICODE) ?>;
   var CF_CURRENCY = <?= json_encode($currency) ?>;

   // ── State ─────────────────────────────────────────────────────────────────
   var state = {
      programId : null,
      waiver    : 0
   };

   // ── Element refs ─────────────────────────────────────────────────────────
   var elSelect       = document.getElementById('programSelect');
   var elWaiverOpts   = document.querySelectorAll('.cf-waiver-option');
   var elCustomWaiver = document.getElementById('customWaiver');
   var elResultPanel  = document.getElementById('resultPanel');
   var elResultEmpty  = document.getElementById('resultEmpty');
   var elResultCont   = document.getElementById('resultContent');
   var elTotalAmount  = document.getElementById('totalAmount');
   var elBreakdown    = document.getElementById('breakdownRows');
   var elSavedMsg     = document.getElementById('savedMsg');
   var elProgMeta     = document.getElementById('prog-meta');
   var elMetaDegree   = document.getElementById('meta-degree');
   var elMetaCredits  = document.getElementById('meta-credits');
   var elMetaDuration = document.getElementById('meta-duration');
   var elDots         = document.querySelectorAll('.cf-step-dot');

   // ── Helpers ───────────────────────────────────────────────────────────────
   function fmt(n) {
      return CF_CURRENCY + ' ' + n.toLocaleString('en-BD');
   }

   function getProgram() {
      return CF_PROGRAMS.find(function(p) { return p.id === state.programId; }) || null;
   }

   function updateDots() {
      var filled;
      if (!state.programId) {
         filled = 0;
      } else if (state.waiver > 0) {
         filled = 2;
      } else {
         filled = 1;
      }
      elDots.forEach(function(d, i) {
         d.classList.toggle('done', i < filled);
      });
   }

   // Animated count-up
   function animateCount(el, from, to, dur) {
      var start = null;
      var step = function(ts) {
         if (!start) start = ts;
         var progress = Math.min((ts - start) / dur, 1);
         var ease = 1 - Math.pow(1 - progress, 3);
         el.textContent = Math.round(from + (to - from) * ease).toLocaleString('en-BD');
         if (progress < 1) requestAnimationFrame(step);
      };
      requestAnimationFrame(step);
   }

   // ── Calculate & render ────────────────────────────────────────────────────
   function calculate() {
      var prog = getProgram();
      if (!prog) {
         elResultEmpty.classList.remove('d-none');
         elResultCont.classList.add('d-none');
         updateDots();
         return;
      }

      var months = prog.duration ? Math.round(prog.duration * 12) : 0;

      // Separate fees by type
      var monthlyFees  = prog.fixed_fees.filter(function(f) { return f.type === 'monthly'; });
      var oneTimeFees  = prog.fixed_fees.filter(function(f) { return f.type === 'one_time'; });
      var perSemFees   = prog.fixed_fees.filter(function(f) { return f.type === 'per_semester'; });

      // Total of all monthly-type fees
      var totalMonthlyBase = monthlyFees.reduce(function(s, f) { return s + f.amount; }, 0);

      // Monthly installment = total divided by programme months
      var monthlyInstallment = (months > 0) ? Math.round(totalMonthlyBase / months) : totalMonthlyBase;

      // Scholarship applies to monthly installment only
      var waiverAmt    = Math.round(monthlyInstallment * state.waiver / 100);
      var netMonthly   = monthlyInstallment - waiverAmt;

      // One-time fees total
      var oneTimeTotal  = oneTimeFees.reduce(function(s, f) { return s + f.amount; }, 0);
      var perSemTotal   = perSemFees.reduce(function(s, f) { return s + f.amount; }, 0);

      // Build rows HTML
      var rows = '';

      // Monthly installment section
      if (monthlyFees.length > 0) {
         rows += '<div class="cf-result-row" style="background:#f0f7ff;border-radius:8px;margin-bottom:4px;padding:10px 12px;">' +
            '<span class="fw-bold" style="color:var(--pu-navy);">Monthly Fees Breakdown</span>' +
            (months > 0 ? '<span class="badge bg-info text-dark ms-1" style="font-size:.65rem;">' + months + ' months</span>' : '') +
            '</div>';
         monthlyFees.forEach(function(f) {
            var perMonth = (months > 0) ? Math.round(f.amount / months) : f.amount;
            rows += rowHtml(
               f.name,
               '<span class="text-muted small">(' + fmt(f.amount) + ' ÷ ' + (months || '?') + ')</span>',
               fmt(perMonth) + '/mo',
               false
            );
         });
         if (waiverAmt > 0) {
            rows += rowHtml('Scholarship (' + state.waiver + '% off)', '', '− ' + fmt(waiverAmt) + '/mo', true);
         }
         rows += '<div class="cf-result-row" style="font-weight:800;color:var(--pu-blue);border-top:2px solid var(--pu-blue);margin-top:4px;padding-top:10px;">' +
            '<span>Monthly Installment</span>' +
            '<span>' + fmt(netMonthly) + '/mo</span>' +
            '</div>';
      }

      // Per-semester fees section
      if (perSemFees.length > 0) {
         rows += '<div class="cf-result-row mt-3" style="background:#f8fafc;border-radius:8px;padding:8px 12px;">' +
            '<span class="fw-bold" style="color:var(--pu-navy);">Per-Semester Fees</span>' +
            '</div>';
         perSemFees.forEach(function(f) {
            rows += rowHtml(f.name, '<span class="badge bg-info text-dark ms-1" style="font-size:.65rem;">Per Sem</span>', fmt(f.amount), false);
         });
      }

      // One-time fees section
      if (oneTimeFees.length > 0) {
         rows += '<div class="cf-result-row mt-3" style="background:#fff7ed;border-radius:8px;padding:8px 12px;">' +
            '<span class="fw-bold" style="color:#92400e;">One-Time Fees <small style="font-weight:400;font-size:.75rem;">(paid at admission)</small></span>' +
            '</div>';
         oneTimeFees.forEach(function(f) {
            rows += rowHtml(f.name, '<span class="badge bg-secondary ms-1" style="font-size:.65rem;">One-Time</span>', fmt(f.amount), false);
         });
         if (oneTimeFees.length > 1) {
            rows += '<div class="cf-result-row" style="font-weight:700;">' +
               '<span>One-Time Total</span><span>' + fmt(oneTimeTotal) + '</span>' +
               '</div>';
         }
      }

      // Display the monthly installment as the main "big" figure
      var displayTotal = netMonthly;
      var prevTotal = parseInt(elTotalAmount.textContent.replace(/,/g,'')) || 0;
      elBreakdown.innerHTML = rows;
      elResultEmpty.classList.add('d-none');
      elResultCont.classList.remove('d-none');

      animateCount(elTotalAmount, prevTotal, displayTotal, 500);

      if (waiverAmt > 0) {
         elSavedMsg.textContent = 'Saving ' + fmt(waiverAmt) + '/month with scholarship';
         elSavedMsg.style.color = '#059669';
      } else if (oneTimeTotal > 0) {
         elSavedMsg.textContent = 'Plus ' + fmt(oneTimeTotal) + ' one-time at admission';
         elSavedMsg.style.color = '#6b7280';
      } else {
         elSavedMsg.textContent = '';
      }

      // Pulse panel
      elResultPanel.classList.remove('recalculating');
      void elResultPanel.offsetWidth;
      elResultPanel.classList.add('recalculating');

      updateDots();
   }

   function rowHtml(label, sublabel, val, isDiscount) {
      return '<div class="cf-result-row">' +
         '<span>' + label + (sublabel ? ' ' + sublabel : '') + '</span>' +
         '<span class="cf-result-val' + (isDiscount ? ' discount' : '') + '">' + val + '</span>' +
      '</div>';
   }

   // ── Event listeners ───────────────────────────────────────────────────────
   elSelect.addEventListener('change', function() {
      var id = parseInt(this.value);
      state.programId = isNaN(id) ? null : id;

      var prog = getProgram();
      if (prog) {
         elProgMeta.classList.remove('d-none');
         elMetaDegree.textContent = prog.degree.charAt(0).toUpperCase() + prog.degree.slice(1);
         elMetaCredits.textContent = prog.total_credits ? prog.total_credits + ' total credits' : '';
         elMetaDuration.textContent = prog.duration ? prog.duration + ' years' : '';
         if (!prog.total_credits) elMetaCredits.style.display = 'none'; else elMetaCredits.style.display = '';
         if (!prog.duration)      elMetaDuration.style.display = 'none'; else elMetaDuration.style.display = '';
      } else {
         elProgMeta.classList.add('d-none');
      }
      calculate();
   });

   elWaiverOpts.forEach(function(opt) {
      opt.addEventListener('click', function() {
         elWaiverOpts.forEach(function(o) { o.classList.remove('active'); });
         this.classList.add('active');
         state.waiver = parseInt(this.dataset.waiver);
         elCustomWaiver.value = state.waiver;
         calculate();
      });
   });

   elCustomWaiver.addEventListener('input', function() {
      var v = Math.min(100, Math.max(0, parseInt(this.value) || 0));
      state.waiver = v;
      elWaiverOpts.forEach(function(o) {
         o.classList.toggle('active', parseInt(o.dataset.waiver) === v);
      });
      calculate();
   });

   // Smooth scroll for anchor
   document.querySelector('a[href="#calculator"]') &&
   document.querySelector('a[href="#calculator"]').addEventListener('click', function(e) {
      e.preventDefault();
      document.getElementById('calculator').scrollIntoView({ behavior: 'smooth' });
   });

   // Intersection observer for fade-in-up
   if ('IntersectionObserver' in window) {
      var fadeEls = document.querySelectorAll('.fade-in-up');
      var obs = new IntersectionObserver(function(entries) {
         entries.forEach(function(en) {
            if (en.isIntersecting) {
               en.target.style.opacity = '1';
               obs.unobserve(en.target);
            }
         });
      }, { threshold: 0.15 });
      fadeEls.forEach(function(el) {
         el.style.opacity = '0';
         obs.observe(el);
      });
   }
   </script>

</body>
</html>
