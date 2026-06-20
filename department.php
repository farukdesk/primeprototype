<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/seo.php';

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

$hero_slides = [];
if ($db) {
    try {
        $st = $db->prepare(
            'SELECT image, caption FROM dept_hero_slides
             WHERE dept_id = ? AND is_active = 1
             ORDER BY sort_order ASC, id ASC'
        );
        $st->execute([$dept['id']]);
        $hero_slides = $st->fetchAll();
    } catch (Throwable $e) {}
}

$current_page = 'overview';
$base         = SITE_URL . '/department';
$page_title   = fh($dept['hero_title'] ?? $dept['name'] ?? 'Department');
$apply_now_url = 'https://primeuniversity.ac.bd/apply-now.php';
$dept_cta_text = trim((string)($dept['cta_text'] ?? 'Apply Now'));
$dept_cta_url  = trim((string)($dept['cta_url'] ?? ''));
if ($dept_cta_text !== '' && strcasecmp($dept_cta_text, 'Apply Now') === 0) {
    $dept_cta_url = $apply_now_url;
}
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <meta name="viewport" content="width=device-width, initial-scale=1">
   <link rel="shortcut icon" type="image/x-icon" href="/assets/img/logo/favicon.png">
<?php render_seo_meta('/department.php?slug=' . urlencode($dept['slug']), $dept['name'], $dept['hero_description'] ?? null); ?>
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

   /* ── Department Hero Slider ── */
   .dept-hero-slider-wrap {
      position: relative;
      padding: 20px 0 20px 20px;
   }
   .dept-hero-swiper {
      border-radius: 20px;
      overflow: hidden;
      box-shadow: 0 20px 60px rgba(0, 33, 71, 0.18);
   }
   .dept-hero-slide-inner {
      position: relative;
      overflow: hidden;
      border-radius: 20px;
      aspect-ratio: 4 / 3;
      background: #e8eef4;
   }
   .dept-hero-slide-inner img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
      transition: transform 0.6s ease;
   }
   .dept-hero-swiper .swiper-slide-active .dept-hero-slide-inner img {
      transform: scale(1.04);
   }
   .dept-hero-slide-caption {
      position: absolute;
      bottom: 0;
      left: 0;
      right: 0;
      background: linear-gradient(to top, rgba(0,33,71,0.75) 0%, transparent 100%);
      color: #fff;
      font-size: 13px;
      padding: 28px 14px 12px;
      line-height: 1.4;
   }
   /* floating dot badge */
   .dept-hero-slider-wrap::before {
      content: '';
      position: absolute;
      top: 0; left: 0;
      width: 80px; height: 80px;
      border-radius: 50%;
      background: rgba(210, 16, 52, 0.08);
      z-index: 0;
   }
   .dept-hero-slider-wrap::after {
      content: '';
      position: absolute;
      bottom: 10px; right: 0;
      width: 50px; height: 50px;
      border-radius: 50%;
      background: rgba(0, 33, 71, 0.07);
      z-index: 0;
   }
   /* pagination dots */
   .dept-hero-pagination {
      position: relative !important;
      bottom: unset !important;
      margin-top: 12px;
      text-align: center;
   }
   .dept-hero-pagination .swiper-pagination-bullet {
      width: 6px; height: 6px;
      background: #002147;
      opacity: 0.25;
      transition: all 0.3s;
      border-radius: 3px;
   }
   .dept-hero-pagination .swiper-pagination-bullet-active {
      width: 20px;
      opacity: 1;
      background: #D21034;
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
                  <?php if ($dept_cta_url !== ''): ?>
                  <a href="<?= fh($dept_cta_url) ?>" class="it-btn-yellow theme-bg border-radius-100">
                     <span>
                        <span class="text-1"><?= fh($dept_cta_text ?: 'Apply Now') ?></span>
                        <span class="text-2"><?= fh($dept_cta_text ?: 'Apply Now') ?></span>
                     </span>
                     <i><svg width="16" height="15" viewBox="0 0 16 15" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M15.0544 8.1364C15.4058 7.78492 15.4058 7.21508 15.0544 6.8636L9.3268 1.13604C8.97533 0.784567 8.40548 0.784567 8.05401 1.13604C7.70254 1.48751 7.70254 2.05736 8.05401 2.40883L13.1452 7.5L8.05401 12.5912C7.70254 12.9426 7.70254 13.5125 8.05401 13.864C8.40548 14.2154 8.97533 14.2154 9.3268 13.864L15.0544 8.1364ZM0.417969 7.5V8.4H14.418V7.5V6.6H0.417969V7.5Z" fill="currentcolor"/></svg></i>
                  </a>
                  <?php endif; ?>
               </div>
            </div>
            <div class="col-xxl-4 col-xl-5 col-lg-5 d-none d-lg-block">
               <?php if (!empty($hero_slides)): ?>
               <!-- Department Hero Slider -->
               <div class="dept-hero-slider-wrap">
                  <div class="swiper dept-hero-swiper">
                     <div class="swiper-wrapper">
                        <?php foreach ($hero_slides as $hs): ?>
                        <div class="swiper-slide">
                           <div class="dept-hero-slide-inner">
                              <img src="<?= fh(ADMIN_UPLOAD_URL . '/departments/' . basename($hs['image'])) ?>"
                                   alt="<?= fh($hs['caption'] ?? '') ?>">
                              <?php if (!empty($hs['caption'])): ?>
                              <div class="dept-hero-slide-caption"><?= fh($hs['caption']) ?></div>
                              <?php endif; ?>
                           </div>
                        </div>
                        <?php endforeach; ?>
                     </div>
                     <?php if (count($hero_slides) > 1): ?>
                     <div class="dept-hero-pagination swiper-pagination"></div>
                     <?php endif; ?>
                  </div>
               </div>
               <?php else: ?>
               <div class="text-center">
                  <i class="<?= fh($dept['hero_icon'] ?? 'fas fa-university') ?>" style="font-size: 200px; color: #002147; opacity: 0.1;"></i>
               </div>
               <?php endif; ?>
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
                     <?php if (!empty($alum['batch'])): ?>
                     <p style="color:#D21034; font-size:13px; font-weight:600;">Batch <?= fh($alum['batch']) ?></p>
                     <?php endif; ?>
                     <?php if (!empty($alum['position'])): ?>
                     <p style="color:#334155; font-size:13px;"><?= fh($alum['position']) ?></p>
                     <?php endif; ?>
                     <?php if (!empty($alum['company'])): ?>
                     <p style="color:#334155; font-size:13px; font-weight:500;"><?= fh($alum['company']) ?></p>
                     <?php endif; ?>
                     <?php if (!empty($alum['linkedin_url']) && str_starts_with($alum['linkedin_url'], 'https://')): ?>
                     <a href="<?= fh($alum['linkedin_url']) ?>" target="_blank" rel="noopener noreferrer"
                        style="color:#0A66C2; font-size:13px; text-decoration:none;">
                         <i class="fab fa-linkedin me-1"></i>LinkedIn
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
                <?php if ($dept_cta_url !== ''): ?>
                <a href="<?= fh($dept_cta_url) ?>" class="it-btn-yellow theme-bg border-radius-100">
                   <span>
                     <span class="text-1"><?= fh($dept_cta_text ?: 'Apply Now') ?></span>
                     <span class="text-2"><?= fh($dept_cta_text ?: 'Apply Now') ?></span>
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

<?php include __DIR__ . '/includes/footer.php'; ?>

   <?php include __DIR__ . '/includes/scripts.php'; ?>
   <?php if (!empty($hero_slides) && count($hero_slides) >= 1): ?>
   <script>
   (function () {
      var heroSwiper = new Swiper('.dept-hero-swiper', {
         loop: <?= count($hero_slides) > 1 ? 'true' : 'false' ?>,
         effect: 'fade',
         fadeEffect: { crossFade: true },
         speed: 700,
         autoplay: <?= count($hero_slides) > 1 ? '{ delay: 3500, disableOnInteraction: false }' : 'false' ?>,
         pagination: {
            el: '.dept-hero-pagination',
            clickable: true,
         },
      });
      /* pause autoplay on hover */
      var wrap = document.querySelector('.dept-hero-slider-wrap');
      if (wrap && heroSwiper.autoplay) {
         wrap.addEventListener('mouseenter', function () { heroSwiper.autoplay.stop(); });
         wrap.addEventListener('mouseleave', function () { heroSwiper.autoplay.start(); });
      }
   }());
   </script>
   <?php endif; ?>
</body>
</html>
