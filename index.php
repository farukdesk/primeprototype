<?php
require_once __DIR__ . '/includes/config.php';

/* ── Data fetching ─────────────────────────────────────────────────────────── */
$_stats        = [];
$_latest_news  = [];
$_testimonials = [];
$_departments  = [];
$_faculty      = [];

try {
    $db = front_db();
    if ($db) {
        $_stats = $db->query(
            'SELECT icon, value, label, suffix FROM homepage_stats
             WHERE is_active = 1 ORDER BY sort_order, id'
        )->fetchAll();

        $_latest_news = $db->query(
            'SELECT id, title, slug, featured_image, content, published_at
             FROM cms_news WHERE is_published = 1
             ORDER BY published_at DESC, created_at DESC LIMIT 3'
        )->fetchAll();

        $_testimonials = $db->query(
            'SELECT name, designation, quote, photo, rating FROM homepage_testimonials
             WHERE is_active = 1 ORDER BY sort_order, id LIMIT 8'
        )->fetchAll();

        $_departments = $db->query(
            'SELECT id, name, slug, code, hero_icon, hero_subtitle
             FROM dept_departments WHERE is_active = 1
             ORDER BY name LIMIT 6'
        )->fetchAll();

        $_faculty = $db->query(
            "SELECT fp.photo, fp.designation, u.name
             FROM faculty_profiles fp
             JOIN users u ON u.id = fp.user_id
             WHERE fp.photo IS NOT NULL AND fp.photo != ''
             ORDER BY RAND() LIMIT 8"
        )->fetchAll();

        // Why Choose Us features
        $_features = $db->query(
            'SELECT icon, title, description FROM cms_features
             WHERE is_active = 1 ORDER BY sort_order, id LIMIT 12'
        )->fetchAll();

        // About settings
        $_about = [];
        $_about_rows = $db->query('SELECT setting_key, setting_value FROM cms_about_settings')->fetchAll();
        foreach ($_about_rows as $_r) $_about[$_r['setting_key']] = $_r['setting_value'];

        // Admission settings
        $_admission = [];
        $_adm_rows = $db->query('SELECT setting_key, setting_value FROM cms_admission_settings')->fetchAll();
        foreach ($_adm_rows as $_r) $_admission[$_r['setting_key']] = $_r['setting_value'];

        // Contact settings
        $_contact_cfg = [];
        $_con_rows = $db->query('SELECT setting_key, setting_value FROM cms_contact_settings')->fetchAll();
        foreach ($_con_rows as $_r) $_contact_cfg[$_r['setting_key']] = $_r['setting_value'];

        // Campus gallery (cms_campus_items)
        $_campus_items = $db->query(
            'SELECT title, image, link_url FROM cms_campus_items
             WHERE is_active = 1 ORDER BY sort_order, id LIMIT 9'
        )->fetchAll();

        // Notable alumni
        $_alumni = $db->query(
            'SELECT name, designation, organization, photo FROM cms_alumni
             WHERE is_active = 1 ORDER BY sort_order, id LIMIT 8'
        )->fetchAll();

        // Latest notices
        $_notices = $db->query(
            'SELECT id, title, slug, content, published_at FROM cms_notices
             WHERE is_published = 1 ORDER BY published_at DESC, created_at DESC LIMIT 4'
        )->fetchAll();
    }
} catch (Throwable $e) {}

if (empty($_stats)) {
    $_stats = [
        ['icon' => 'fas fa-user-graduate',      'value' => '15000', 'suffix' => '+', 'label' => 'Students Enrolled'],
        ['icon' => 'fas fa-chalkboard-teacher', 'value' => '250',   'suffix' => '+', 'label' => 'Expert Faculty'],
        ['icon' => 'fas fa-book-open',           'value' => '35',    'suffix' => '+', 'label' => 'Academic Programs'],
        ['icon' => 'fas fa-award',               'value' => '32',    'suffix' => '+', 'label' => 'Years of Excellence'],
    ];
}

if (empty($_departments)) {
    $_departments = [
        ['id'=>0,'name'=>'Computer Science & Engineering','slug'=>'bsc-cse',  'hero_icon'=>'fas fa-laptop-code',    'hero_subtitle'=>'AI, Software Engineering & Data Science'],
        ['id'=>0,'name'=>'Business Administration',        'slug'=>'',         'hero_icon'=>'fas fa-briefcase',      'hero_subtitle'=>'BBA & MBA programs with industry focus'],
        ['id'=>0,'name'=>'Law',                            'slug'=>'',         'hero_icon'=>'fas fa-balance-scale',  'hero_subtitle'=>'LLB & LLM with moot court practice'],
        ['id'=>0,'name'=>'Pharmacy',                       'slug'=>'',         'hero_icon'=>'fas fa-pills',          'hero_subtitle'=>'State-of-the-art labs & research centres'],
        ['id'=>0,'name'=>'Architecture',                   'slug'=>'',         'hero_icon'=>'fas fa-drafting-compass','hero_subtitle'=>'Creative design with industry mentors'],
        ['id'=>0,'name'=>'English',                        'slug'=>'',         'hero_icon'=>'fas fa-language',       'hero_subtitle'=>'Language & Literature for global careers'],
    ];
}

if (empty($_features)) {
    $_features = [
        ['icon'=>'fas fa-graduation-cap', 'title'=>'Bachelor Programs',  'description'=>'Comprehensive undergraduate degrees in Engineering, Business, Law, Pharmacy, Architecture and more.'],
        ['icon'=>'fas fa-user-tie',        'title'=>'Masters Degrees',    'description'=>'Advance your expertise with research-driven postgraduate programmes taught by internationally qualified faculty.'],
        ['icon'=>'fas fa-flask',           'title'=>'Research Excellence','description'=>'State-of-the-art laboratories and dedicated research centres producing innovation that matters.'],
        ['icon'=>'fas fa-handshake',       'title'=>'Industry Placement', 'description'=>'Strong industry ties and a dedicated career office help graduates secure top positions.'],
        ['icon'=>'fas fa-globe-asia',      'title'=>'Global Network',     'description'=>'International affiliations and exchange programmes connect our students worldwide.'],
        ['icon'=>'fas fa-shield-alt',      'title'=>'Accredited Quality', 'description'=>'UGC-approved and internationally benchmarked — our degrees are recognised by employers worldwide.'],
    ];
}
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <title>Prime University – Empowering Future Leaders</title>
   <meta name="description" content="Prime University Bangladesh – Quality higher education with expert faculty, modern facilities and industry-focused programs.">
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
   <link rel="stylesheet" href="assets/css/prime-home.css">
</head>
<body id="body" class="it-magic-cursor">

<!-- ── Opening Ceremony Overlay ────────────────────────────────────────────── -->
<style>
#pu-launch-overlay {
    position: fixed;
    inset: 0;
    z-index: 99999;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: #002147;
    transition: opacity 0.8s ease, visibility 0.8s ease;
}
#pu-launch-overlay.pu-fade-out {
    opacity: 0;
    visibility: hidden;
}
.pu-launch-inner {
    text-align: center;
    color: #fff;
    padding: 20px;
    animation: puFadeIn 0.8s ease forwards;
}
@keyframes puFadeIn {
    from { opacity: 0; transform: translateY(30px); }
    to   { opacity: 1; transform: translateY(0); }
}
.pu-launch-logo {
    width: 180px;
    margin-bottom: 30px;
}
.pu-launch-tagline {
    font-size: 1.1rem;
    letter-spacing: 3px;
    text-transform: uppercase;
    color: #FFB81C;
    margin-bottom: 16px;
    font-weight: 600;
}
.pu-launch-title {
    font-size: clamp(1.8rem, 5vw, 3rem);
    font-weight: 800;
    margin-bottom: 12px;
    line-height: 1.2;
}
.pu-launch-sub {
    font-size: 1rem;
    color: rgba(255,255,255,0.65);
    margin-bottom: 48px;
    max-width: 460px;
}
.pu-launch-btn {
    display: inline-flex;
    align-items: center;
    gap: 12px;
    background: #FFB81C;
    color: #002147;
    border: none;
    border-radius: 50px;
    padding: 18px 54px;
    font-size: 1.2rem;
    font-weight: 800;
    letter-spacing: 1px;
    cursor: pointer;
    transition: transform 0.2s, box-shadow 0.2s;
    box-shadow: 0 8px 32px rgba(255,184,28,0.35);
}
.pu-launch-btn:hover {
    transform: scale(1.05);
    box-shadow: 0 12px 40px rgba(255,184,28,0.5);
}
.pu-launch-btn:active { transform: scale(0.98); }
.pu-launch-btn svg { flex-shrink: 0; }
#pu-countdown-wrap { display: none; }
.pu-countdown-ring {
    position: relative;
    width: 160px;
    height: 160px;
    margin: 0 auto 32px;
}
.pu-countdown-ring svg {
    transform: rotate(-90deg);
}
.pu-countdown-ring circle.bg {
    fill: none;
    stroke: rgba(255,255,255,0.15);
    stroke-width: 6;
}
.pu-countdown-ring circle.progress {
    fill: none;
    stroke: #FFB81C;
    stroke-width: 6;
    stroke-linecap: round;
    stroke-dasharray: var(--pu-circ, 408);
    stroke-dashoffset: 0;
    transition: stroke-dashoffset 0.9s linear;
}
#pu-countdown-num {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    font-size: 4.5rem;
    font-weight: 900;
    color: #FFB81C;
    line-height: 1;
}
.pu-launch-ready {
    font-size: 1rem;
    color: rgba(255,255,255,0.6);
    letter-spacing: 2px;
    text-transform: uppercase;
}
</style>

<div id="pu-launch-overlay">
    <div class="pu-launch-inner">
        <img src="assets/img/logo/logo.png" alt="Prime University" class="pu-launch-logo" onerror="this.style.display='none'">
        <p class="pu-launch-tagline">Official Launch</p>
        <h1 class="pu-launch-title">Welcome to Prime University</h1>
        <p class="pu-launch-sub">A new digital experience — thoughtfully crafted for students, faculty &amp; staff.</p>

        <div id="pu-btn-wrap">
            <button class="pu-launch-btn" id="pu-launch-btn" type="button" onclick="puStartLaunch()">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M5 3l14 9-14 9V3z" fill="#002147"/></svg>
                Launch Website
            </button>
        </div>

        <div id="pu-countdown-wrap">
            <div class="pu-countdown-ring">
                <svg width="160" height="160" viewBox="0 0 160 160">
                    <circle class="bg" cx="80" cy="80" r="65"/>
                    <circle class="progress" id="pu-ring" cx="80" cy="80" r="65"/>
                </svg>
                <span id="pu-countdown-num">5</span>
            </div>
            <p class="pu-launch-ready">Preparing your experience…</p>
        </div>
    </div>
</div>

<script>
(function () {
    var STORAGE_KEY = 'pu_launch_done';
    var overlay = document.getElementById('pu-launch-overlay');

    // Skip overlay if already launched before
    var alreadyLaunched = false;
    try { alreadyLaunched = !!localStorage.getItem(STORAGE_KEY); } catch (e) {}
    if (alreadyLaunched) {
        overlay.style.display = 'none';
        return;
    }

    window.puStartLaunch = function () {
        document.getElementById('pu-btn-wrap').style.display = 'none';
        document.getElementById('pu-countdown-wrap').style.display = 'block';

        var total = 5;
        var current = total;
        var numEl = document.getElementById('pu-countdown-num');
        var ring = document.getElementById('pu-ring');
        var circumference = 2 * Math.PI * 65;

        // Sync CSS custom property with the computed circumference
        ring.style.strokeDasharray = circumference;
        ring.style.strokeDashoffset = 0;

        function setProgress(remaining) {
            ring.style.strokeDashoffset = circumference * (1 - remaining / total);
            numEl.textContent = remaining;
        }

        setProgress(current);

        var timer = setInterval(function () {
            current--;
            if (current <= 0) {
                clearInterval(timer);
                numEl.textContent = '🚀';
                ring.style.strokeDashoffset = circumference;
                setTimeout(function () {
                    try { localStorage.setItem(STORAGE_KEY, '1'); } catch (e) {}
                    overlay.classList.add('pu-fade-out');
                    setTimeout(function () {
                        overlay.style.display = 'none';
                    }, 850);
                }, 400);
            } else {
                setProgress(current);
            }
        }, 1000);
    };
}());
</script>
<!-- ── /Opening Ceremony Overlay ─────────────────────────────────────────────── -->

<div id="preloader"><div class="preloader"><span></span><span></span></div></div>
<div id="magic-cursor"><div id="ball"></div></div>
<button class="scroll-top scroll-to-target" data-target="html"><i class="far fa-angle-double-up"></i></button>

<!-- search popup -->
<div class="search-popup">
   <button class="close-search"><span class="flaticon-multiply"><i class="fal fa-times"></i></span></button>
   <form method="post" action="#"><div class="form-group">
      <input type="search" name="search-field" value="" placeholder="Search programs, news, faculty…" required="">
      <button type="submit"><i class="fal fa-search"></i></button>
   </div></form>
</div>

<!-- off-canvas -->
<div class="it-offcanvas-area">
   <div class="itoffcanvas">
      <div class="itoffcanvas__close-btn"><button class="close-btn"><i class="fal fa-times"></i></button></div>
      <div class="itoffcanvas__logo"><a href="index.php"><img src="assets/img/logo/logo-black.png" alt="Prime University"></a></div>
      <div class="it-menu-mobile d-xl-none"></div>
      <div class="itoffcanvas__info">
         <h3 class="offcanva-title">Get In Touch</h3>
         <div class="it-info-wrapper mb-20 d-flex align-items-center">
            <div class="itoffcanvas__info-icon"><a href="#"><i class="fal fa-phone-alt"></i></a></div>
            <div class="itoffcanvas__info-address"><span>Phone</span><a href="tel:+8801710996196">+880-1710-996196</a></div>
         </div>
         <div class="it-info-wrapper mb-20 d-flex align-items-center">
            <div class="itoffcanvas__info-icon"><a href="#"><i class="fal fa-envelope"></i></a></div>
            <div class="itoffcanvas__info-address"><span>Email</span><a href="mailto:info@primeuniversity.edu.bd">info@primeuniversity.edu.bd</a></div>
         </div>
         <div class="it-info-wrapper mb-20 d-flex align-items-center">
            <div class="itoffcanvas__info-icon"><a href="#"><i class="fas fa-map-marker-alt"></i></a></div>
            <div class="itoffcanvas__info-address"><span>Address</span><a href="#">114/116, Mazar Rd, Dhaka-1216</a></div>
         </div>
      </div>
   </div>
</div>
<div class="body-overlay"></div>

<!-- HEADER -->
<header class="it-header-height">
   <?php include __DIR__ . '/includes/header-top.php'; ?>
   <?php include __DIR__ . '/includes/nav-menu.php'; ?>
</header>

<?php include __DIR__ . '/includes/news-ticker.php'; ?>
<?php include __DIR__ . '/includes/slider.php'; ?>

<!-- STATS COUNTER BAR -->
<section class="pu-stats-section" id="pu-stats">
   <div class="container-fluid px-0">
      <div class="pu-stats-inner">
         <?php foreach ($_stats as $i => $stat): ?>
         <div class="pu-stat-item wow itfadeUp" data-wow-duration=".7s" data-wow-delay="<?= .1 * $i ?>s">
            <div class="pu-stat-icon"><i class="<?= fh($stat['icon']) ?>"></i></div>
            <div class="pu-stat-number">
               <span class="purecounter" data-purecounter-start="0"
                     data-purecounter-end="<?= (int)preg_replace('/\D/', '', $stat['value']) ?>"
                     data-purecounter-duration="2"><?= (int)preg_replace('/\D/', '', $stat['value']) ?></span><span class="suffix"><?= fh($stat['suffix']) ?></span>
            </div>
            <div class="pu-stat-label"><?= fh($stat['label']) ?></div>
         </div>
         <?php endforeach; ?>
      </div>
   </div>
</section>

<!-- FEATURE CARDS -->
<section class="pu-features-section pu-section" id="pu-features">
   <div class="container">
      <div class="row justify-content-center mb-60">
         <div class="col-xl-7 col-lg-9 text-center">
            <div class="pu-label wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".1s">Why Choose Us</div>
            <h2 class="pu-section-title wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".2s">A University Built for <span class="accent">Your Success</span></h2>
            <p class="pu-section-sub mx-auto mt-3 wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".3s">Prime University offers a world-class learning environment with expert faculty, state-of-the-art facilities and an industry-connected curriculum designed to launch your career.</p>
         </div>
      </div>
      <div class="row g-4">
         <?php
         $icon_classes = ['pu-feature-icon-1','pu-feature-icon-2','pu-feature-icon-3','pu-feature-icon-4','pu-feature-icon-5','pu-feature-icon-6'];
         foreach ($_features as $i => $f):
         ?>
         <div class="col-xl-4 col-lg-4 col-md-6 wow itfadeUp" data-wow-duration=".7s" data-wow-delay="<?= .1 + .1 * $i ?>s">
            <div class="pu-feature-card">
               <div class="pu-feature-icon <?= $icon_classes[$i % count($icon_classes)] ?>"><i class="<?= fh($f['icon']) ?>"></i></div>
               <h5><?= fh($f['title']) ?></h5>
               <p><?= fh($f['description']) ?></p>
            </div>
         </div>
         <?php endforeach; ?>
      </div>
   </div>
</section>

<!-- ABOUT SECTION -->
<section class="pu-about-section pu-section" id="pu-about">
   <div class="container">
      <div class="row align-items-center g-5">
         <div class="col-lg-6 wow itfadeLeft" data-wow-duration=".9s" data-wow-delay=".2s">
            <div class="pu-about-img-wrap">
               <img class="pu-about-img-main" src="<?= !empty($_about['main_image']) ? fh($_about['main_image']) : 'assets/img/about/about-1-1.jpg' ?>" alt="Prime University Campus">
               <div class="pu-about-img-badge">
                  <span class="number"><?= fh($_about['badge_number'] ?? '24+') ?></span>
                  <span class="text"><?= nl2br(fh($_about['badge_text'] ?? "Years of" . "\n" . "Excellence")) ?></span>
               </div>
            </div>
         </div>
         <div class="col-lg-6 wow itfadeRight" data-wow-duration=".9s" data-wow-delay=".3s">
            <div class="pu-about-content">
               <div class="pu-label"><?= fh($_about['about_section_subtitle'] ?? 'About the University') ?></div>
               <h2 class="pu-section-title mb-3"><?= fh($_about['about_section_title'] ?? 'Shaping Leaders Since') ?> <span class="accent"><?= fh($_about['about_section_title_accent'] ?? '2002') ?></span></h2>
               <p class="pu-section-sub mb-4"><?= fh($_about['description'] ?? 'Prime University is a premier private university in Bangladesh, committed to quality higher education through academic rigour, research innovation and industry relevance.') ?></p>
               <?php
               $list_items = [];
               for ($li = 1; $li <= 5; $li++) {
                   $val = $_about['list_item_' . $li] ?? '';
                   if ($val !== '') $list_items[] = $val;
               }
               if (empty($list_items)) {
                   $list_items = [
                       'UGC-approved with internationally recognised degree programs',
                       '100+ highly qualified and research-active faculty members',
                       'Modern libraries, labs and digital learning infrastructure',
                       'Dedicated career centre with industry placement programmes',
                       'Active student clubs, sports and cultural programmes',
                   ];
               }
               ?>
               <ul class="pu-about-list mb-4">
                  <?php foreach ($list_items as $li_text): ?>
                  <li><div class="check"><i class="fas fa-check"></i></div><?= fh($li_text) ?></li>
                  <?php endforeach; ?>
               </ul>
               <div class="d-flex flex-wrap gap-3">
                  <a href="<?= fh($_about['apply_url'] ?? 'admission.php') ?>" class="pu-btn pu-btn-primary"><i class="fas fa-paper-plane"></i> Apply Now</a>
                  <a href="<?= fh($_about['contact_url'] ?? 'contact.php') ?>" class="pu-btn pu-btn-outline"><i class="fas fa-phone-alt"></i> Contact Us</a>
               </div>
            </div>
         </div>
      </div>
   </div>
</section>

<!-- ACADEMIC PROGRAMS GRID -->
<section class="pu-programs-section pu-section" id="pu-programs">
   <div class="container">
      <div class="row justify-content-between align-items-end mb-50">
         <div class="col-lg-7">
            <div class="pu-label wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".1s">Our Departments</div>
            <h2 class="pu-section-title wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".2s">Explore Our <span class="accent">Academic Programs</span></h2>
         </div>
         <div class="col-lg-auto wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".3s">
            <a href="department.php" class="pu-view-all">View All Departments <i class="fas fa-arrow-right"></i></a>
         </div>
      </div>
      <?php
      $dept_bg_images = [
         'assets/img/about/about-5-1.jpg',
         'assets/img/about/about-10-1.jpg',
         'assets/img/about/about-12-1.jpg',
         'assets/img/about/about-2-1.jpg',
         'assets/img/about/about-4-1.jpg',
         'assets/img/about/about-13-1.jpg',
      ];
      ?>
      <div class="row g-3">
         <?php foreach ($_departments as $idx => $dept):
            $dept_url  = $dept['slug'] ? 'department.php?dept=' . urlencode($dept['slug']) : 'department.php';
            $dept_img  = !empty($dept['image'])
                ? ADMIN_UPLOAD_URL . '/departments/' . $dept['image']
                : $dept_bg_images[$idx % count($dept_bg_images)];
            $dept_icon = $dept['hero_icon'] ?: 'fas fa-graduation-cap';
            $dept_sub  = $dept['hero_subtitle'] ?? '';
         ?>
         <div class="col-xl-4 col-lg-4 col-md-6 wow itfadeUp" data-wow-duration=".7s" data-wow-delay="<?= .1 + .1 * $idx ?>s">
            <a href="<?= fh($dept_url) ?>" class="pu-program-card">
               <div class="pu-program-bg"><img src="<?= fh($dept_img) ?>" alt="<?= fh($dept['name']) ?>"></div>
               <div class="pu-program-content">
                  <div class="pu-program-icon"><i class="<?= fh($dept_icon) ?>"></i></div>
                  <h5><?= fh($dept['name']) ?></h5>
                  <?php if ($dept_sub): ?><p><?= fh($dept_sub) ?></p><?php endif; ?>
               </div>
               <div class="pu-program-arrow"><i class="fas fa-arrow-right"></i></div>
            </a>
         </div>
         <?php endforeach; ?>
      </div>
   </div>
</section>

<!-- ADMISSION CTA BANNER -->
<section class="pu-admission-section pu-section-sm" id="pu-admission">
   <div class="container">
      <div class="row align-items-center g-4">
         <div class="col-lg-7 wow itfadeLeft" data-wow-duration=".9s" data-wow-delay=".2s">
            <div class="pu-admission-badge"><span class="dot"></span> <?= fh($_admission['badge_text'] ?? 'Admissions Open') ?></div>
            <h2 class="pu-admission-title"><?= fh($_admission['title'] ?? 'Begin Your Journey at') ?><br><span class="gold"><?= fh($_admission['title_accent'] ?? 'Prime University') ?></span></h2>
            <p class="pu-admission-text"><?= fh($_admission['description'] ?? 'Applications are now open. Secure your place in one of our prestigious programmes.') ?></p>
            <div class="d-flex flex-wrap gap-3">
               <a href="<?= fh($_admission['btn1_url'] ?? 'admission.php') ?>" class="pu-btn pu-btn-primary"><i class="fas fa-paper-plane"></i> <?= fh($_admission['btn1_text'] ?? 'Apply Now') ?></a>
               <a href="<?= fh($_admission['btn2_url'] ?? 'scholarships-waivers.php') ?>" class="pu-btn pu-btn-outline-white"><i class="fas fa-award"></i> <?= fh($_admission['btn2_text'] ?? 'Scholarships') ?></a>
            </div>
         </div>
         <div class="col-lg-5 wow itfadeRight" data-wow-duration=".9s" data-wow-delay=".3s">
            <div class="d-flex flex-column gap-3">
               <?php for ($ii = 1; $ii <= 3; $ii++): 
                   $i_icon  = $_admission['info_' . $ii . '_icon']  ?? '';
                   $i_title = $_admission['info_' . $ii . '_title'] ?? '';
                   $i_text  = $_admission['info_' . $ii . '_text']  ?? '';
                   if ($i_title === '') continue;
               ?>
               <div class="pu-admission-deadline">
                  <?php if ($i_icon): ?><i class="<?= fh($i_icon) ?>"></i><?php endif; ?>
                  <div>
                     <div style="font-weight:700;font-size:.95rem;"><?= fh($i_title) ?></div>
                     <div style="font-size:.85rem;opacity:.75;"><?= fh($i_text) ?></div>
                  </div>
               </div>
               <?php endfor; ?>
            </div>
         </div>
      </div>
   </div>
</section>

<!-- LATEST NEWS & EVENTS -->
<section class="pu-news-section pu-section" id="pu-news">
   <div class="container">
      <div class="row justify-content-between align-items-end mb-50">
         <div class="col-lg-7">
            <div class="pu-label wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".1s">What's Happening</div>
            <h2 class="pu-section-title wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".2s">Latest <span class="accent">News & Events</span></h2>
         </div>
         <div class="col-lg-auto wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".3s">
            <a href="news-detail.php" class="pu-view-all">All News <i class="fas fa-arrow-right"></i></a>
         </div>
      </div>
      <div class="row g-4">
         <?php if (!empty($_latest_news)): ?>
            <?php foreach ($_latest_news as $ni => $news): ?>
            <div class="col-lg-4 col-md-6 wow itfadeUp" data-wow-duration=".7s" data-wow-delay="<?= .1 + .1 * $ni ?>s">
               <article class="pu-news-card">
                  <div class="pu-news-img-wrap">
                     <?php if ($news['featured_image']): ?>
                     <img class="pu-news-img" src="<?= fh(ADMIN_UPLOAD_URL . '/news/' . basename($news['featured_image'])) ?>" alt="<?= fh($news['title']) ?>">
                     <?php else: ?>
                     <div class="pu-news-placeholder"><i class="fas fa-newspaper"></i></div>
                     <?php endif; ?>
                     <span class="pu-news-category">News</span>
                  </div>
                  <div class="pu-news-body">
                     <div class="pu-news-date"><i class="fas fa-calendar-alt"></i> <?= $news['published_at'] ? date('d M Y', strtotime($news['published_at'])) : '' ?></div>
                     <a href="news-detail.php?slug=<?= urlencode($news['slug'] ?? '') ?>" class="pu-news-title"><?= fh($news['title']) ?></a>
                     <?php
                     $excerpt = strip_tags($news['content'] ?? '');
                     $excerpt = mb_strlen($excerpt) > 100 ? mb_substr($excerpt, 0, 100) . '…' : $excerpt;
                     if ($excerpt): ?><p class="pu-news-excerpt"><?= fh($excerpt) ?></p><?php endif; ?>
                     <a href="news-detail.php?slug=<?= urlencode($news['slug'] ?? '') ?>" class="pu-news-link">Read More <i class="fas fa-arrow-right"></i></a>
                  </div>
               </article>
            </div>
            <?php endforeach; ?>
         <?php else: ?>
            <?php
            $placeholder_news = [
               ['title'=>'Prime University Hosts Annual Research Symposium 2026', 'date'=>'March 2026', 'cat'=>'Research'],
               ['title'=>'New Computer Science Lab Inaugurated with State-of-the-Art Equipment', 'date'=>'February 2026', 'cat'=>'Facilities'],
               ['title'=>'Prime University Students Win National Moot Court Competition', 'date'=>'January 2026', 'cat'=>'Achievement'],
            ];
            foreach ($placeholder_news as $pi => $pn):
            ?>
            <div class="col-lg-4 col-md-6 wow itfadeUp" data-wow-duration=".7s" data-wow-delay="<?= .1 + .1 * $pi ?>s">
               <article class="pu-news-card">
                  <div class="pu-news-img-wrap">
                     <div class="pu-news-placeholder"><i class="fas fa-newspaper"></i></div>
                     <span class="pu-news-category"><?= $pn['cat'] ?></span>
                  </div>
                  <div class="pu-news-body">
                     <div class="pu-news-date"><i class="fas fa-calendar-alt"></i> <?= $pn['date'] ?></div>
                     <span class="pu-news-title d-block"><?= $pn['title'] ?></span>
                     <a href="#" class="pu-news-link">Read More <i class="fas fa-arrow-right"></i></a>
                  </div>
               </article>
            </div>
            <?php endforeach; ?>
         <?php endif; ?>
      </div>
   </div>
</section>

<!-- NOTICE BOARD -->
<?php if (!empty($_notices)): ?>
<section class="pu-notices-section pu-section" id="pu-notices">
   <div class="container">
      <div class="row justify-content-between align-items-end mb-50">
         <div class="col-lg-7">
            <div class="pu-label wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".1s">Announcements</div>
            <h2 class="pu-section-title wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".2s">Notice <span class="accent">Board</span></h2>
         </div>
         <div class="col-lg-auto wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".3s">
            <a href="notice-board.php" class="pu-view-all">All Notices <i class="fas fa-arrow-right"></i></a>
         </div>
      </div>
      <div class="row g-4">
         <?php foreach ($_notices as $ni => $notice): ?>
         <div class="col-lg-6 wow itfadeUp" data-wow-duration=".7s" data-wow-delay="<?= .1 + .1 * $ni ?>s">
            <div class="pu-notice-card">
               <div class="pu-notice-icon"><i class="fas fa-bullhorn"></i></div>
               <div class="pu-notice-body">
                  <?php if ($notice['published_at']): ?><div class="pu-notice-date"><i class="fas fa-calendar-alt"></i> <?= date('d M Y', strtotime($notice['published_at'])) ?></div><?php endif; ?>
                  <a href="notice-detail.php?slug=<?= urlencode($notice['slug'] ?? '') ?>" class="pu-notice-title"><?= fh($notice['title']) ?></a>
                  <?php
                  $n_excerpt = strip_tags($notice['content'] ?? '');
                  $n_excerpt = mb_strlen($n_excerpt) > 80 ? mb_substr($n_excerpt, 0, 80) . '…' : $n_excerpt;
                  if ($n_excerpt): ?><p class="pu-notice-excerpt"><?= fh($n_excerpt) ?></p><?php endif; ?>
                  <a href="notice-detail.php?slug=<?= urlencode($notice['slug'] ?? '') ?>" class="pu-news-link">Read More <i class="fas fa-arrow-right"></i></a>
               </div>
            </div>
         </div>
         <?php endforeach; ?>
      </div>
   </div>
</section>
<?php endif; ?>

<!-- FACULTY SPOTLIGHT -->
<?php if (!empty($_faculty)): ?>
<section class="pu-faculty-section pu-section" id="pu-faculty">
   <div class="container">
      <div class="row justify-content-between align-items-end mb-50">
         <div class="col-lg-7">
            <div class="pu-label wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".1s">Our Team</div>
            <h2 class="pu-section-title wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".2s">Meet Our <span class="accent">Expert Faculty</span></h2>
         </div>
         <div class="col-lg-auto wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".3s">
            <a href="faculty-profile.php" class="pu-view-all">All Faculty <i class="fas fa-arrow-right"></i></a>
         </div>
      </div>
      <div class="swiper pu-faculty-swiper wow itfadeUp" data-wow-duration=".9s" data-wow-delay=".2s">
         <div class="swiper-wrapper">
            <?php foreach ($_faculty as $fac): ?>
            <div class="swiper-slide">
               <div class="pu-faculty-card">
                  <div class="pu-faculty-img-wrap">
                     <img class="pu-faculty-img"
                          src="<?= fh(ADMIN_UPLOAD_URL . '/faculty-profiles/photos/' . basename($fac['photo'])) ?>"
                          alt="<?= fh($fac['name']) ?>"
                          onerror="this.style.display='none'">
                     <div class="pu-faculty-overlay">
                        <div class="pu-faculty-social"><a href="faculty-profile.php"><i class="fas fa-user"></i></a></div>
                     </div>
                  </div>
                  <div class="pu-faculty-info">
                     <div class="pu-faculty-name"><?= fh($fac['name']) ?></div>
                     <?php if ($fac['designation']): ?>
                     <div class="pu-faculty-dept"><?= fh($fac['designation']) ?></div>
                     <?php endif; ?>
                  </div>
               </div>
            </div>
            <?php endforeach; ?>
         </div>
      </div>
      <div class="pu-swiper-nav mt-4 wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".4s">
         <button class="pu-swiper-btn pu-faculty-prev"><i class="fas fa-arrow-left"></i></button>
         <button class="pu-swiper-btn pu-faculty-next"><i class="fas fa-arrow-right"></i></button>
      </div>
   </div>
</section>
<?php endif; ?>

<!-- TESTIMONIALS -->
<section class="pu-testimonials-section pu-section" id="pu-testimonials">
   <div class="container">
      <div class="row justify-content-center mb-50">
         <div class="col-xl-7 col-lg-9 text-center">
            <div class="pu-label wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".1s">Student Voices</div>
            <h2 class="pu-section-title wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".2s">What Our <span class="accent">Students Say</span></h2>
         </div>
      </div>
      <div class="swiper pu-testimonials-swiper wow itfadeUp" data-wow-duration=".9s" data-wow-delay=".3s">
         <div class="swiper-wrapper">
            <?php foreach ($_testimonials as $t): ?>
            <div class="swiper-slide">
               <div class="pu-testimonial-card">
                  <div class="pu-testimonial-quote"><i class="fas fa-quote-left"></i></div>
                  <div class="pu-testimonial-stars">
                     <?php for ($s = 1; $s <= 5; $s++): ?>
                     <i class="<?= $s <= (int)$t['rating'] ? 'fas' : 'far' ?> fa-star <?= $s > (int)$t['rating'] ? 'empty' : '' ?>"></i>
                     <?php endfor; ?>
                  </div>
                  <p class="pu-testimonial-text">"<?= fh($t['quote']) ?>"</p>
                  <div class="pu-testimonial-author">
                     <?php if ($t['photo']): ?>
                     <img class="pu-testimonial-avatar"
                          src="<?= fh(SITE_URL . '/admin/uploads/homepage/' . basename($t['photo'])) ?>"
                          alt="<?= fh($t['name']) ?>">
                     <?php else: ?>
                     <div class="pu-testimonial-avatar-placeholder"><i class="fas fa-user"></i></div>
                     <?php endif; ?>
                     <div>
                        <div class="pu-testimonial-name"><?= fh($t['name']) ?></div>
                        <?php if ($t['designation']): ?>
                        <div class="pu-testimonial-designation"><?= fh($t['designation']) ?></div>
                        <?php endif; ?>
                     </div>
                  </div>
               </div>
            </div>
            <?php endforeach; ?>
         </div>
      </div>
      <div class="pu-swiper-nav mt-4 wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".4s">
         <button class="pu-swiper-btn pu-testimonials-prev"><i class="fas fa-arrow-left"></i></button>
         <button class="pu-swiper-btn pu-testimonials-next"><i class="fas fa-arrow-right"></i></button>
      </div>
   </div>
</section>

<!-- CAMPUS GALLERY -->
<section class="pu-gallery-section pu-section" id="pu-gallery">
   <div class="container">
      <div class="row justify-content-center mb-50">
         <div class="col-xl-7 text-center">
            <div class="pu-label wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".1s">Campus Life</div>
            <h2 class="pu-section-title wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".2s">Life at <span class="accent">Prime University</span></h2>
         </div>
      </div>
      <div class="pu-gallery-grid wow itfadeUp" data-wow-duration=".9s" data-wow-delay=".3s">
         <?php
         $static_gallery = [
             ['src'=>'assets/img/campus/campus-3-1.jpg','alt'=>'Prime University Campus'],
             ['src'=>'assets/img/about/about-1-1.jpg',  'alt'=>'Academic Environment'],
             ['src'=>'assets/img/about/about-5-1.jpg',  'alt'=>'Student Activities'],
             ['src'=>'assets/img/campus/campus-3-2.jpg','alt'=>'Campus Life'],
             ['src'=>'assets/img/about/about-10-1.jpg', 'alt'=>'Research Facilities'],
             ['src'=>'assets/img/campus/campus-3-3.jpg','alt'=>'Modern Classrooms'],
         ];
         $gallery_source = !empty($_campus_items) ? $_campus_items : [];
         if (!empty($gallery_source)):
             foreach ($gallery_source as $ci):
                 $img_src = !empty($ci['image'])
                     ? (ADMIN_UPLOAD_URL . '/campus/' . basename($ci['image']))
                     : 'assets/img/campus/campus-3-1.jpg';
         ?>
         <div class="pu-gallery-item">
            <a class="popup-image" href="<?= fh($img_src) ?>">
               <img src="<?= fh($img_src) ?>" alt="<?= fh($ci['title']) ?>" onerror="this.closest('.pu-gallery-item').style.background='#1e3a5c'">
               <div class="pu-gallery-item-overlay"><i class="fas fa-search-plus"></i></div>
            </a>
         </div>
         <?php endforeach; else:
             foreach ($static_gallery as $gimg): ?>
         <div class="pu-gallery-item">
            <a class="popup-image" href="<?= fh($gimg['src']) ?>">
               <img src="<?= fh($gimg['src']) ?>" alt="<?= fh($gimg['alt']) ?>" onerror="this.closest('.pu-gallery-item').style.background='#1e3a5c'">
               <div class="pu-gallery-item-overlay"><i class="fas fa-search-plus"></i></div>
            </a>
         </div>
         <?php endforeach; endif; ?>
      </div>
   </div>
</section>

<!-- NOTABLE ALUMNI -->
<?php if (!empty($_alumni)): ?>
<section class="pu-alumni-section pu-section" id="pu-alumni">
   <div class="container">
      <div class="row justify-content-center mb-50">
         <div class="col-xl-7 col-lg-9 text-center">
            <div class="pu-label wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".1s">Our Legacy</div>
            <h2 class="pu-section-title wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".2s">Notable <span class="accent">Alumni</span></h2>
            <p class="pu-section-sub mx-auto mt-3 wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".3s">Our graduates are making a difference across industries, institutions and borders — carrying the Prime University legacy forward.</p>
         </div>
      </div>
      <div class="row g-4 justify-content-center">
         <?php foreach ($_alumni as $ai => $alum): ?>
         <div class="col-xl-3 col-lg-3 col-md-4 col-sm-6 wow itfadeUp" data-wow-duration=".7s" data-wow-delay="<?= .1 + .1 * $ai ?>s">
            <div class="pu-alumni-card text-center">
               <div class="pu-alumni-photo-wrap">
                  <?php if ($alum['photo']): ?>
                  <img class="pu-alumni-photo"
                       src="<?= fh(ADMIN_UPLOAD_URL . '/alumni/' . basename($alum['photo'])) ?>"
                       alt="<?= fh($alum['name']) ?>"
                       onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                  <div class="pu-alumni-photo-placeholder" style="display:none"><i class="fas fa-user"></i></div>
                  <?php else: ?>
                  <div class="pu-alumni-photo-placeholder"><i class="fas fa-user"></i></div>
                  <?php endif; ?>
               </div>
               <div class="pu-alumni-info mt-3">
                  <div class="pu-alumni-name"><?= fh($alum['name']) ?></div>
                  <?php if ($alum['designation']): ?>
                  <div class="pu-alumni-designation"><?= fh($alum['designation']) ?></div>
                  <?php endif; ?>
                  <?php if ($alum['organization']): ?>
                  <div class="pu-alumni-org"><?= fh($alum['organization']) ?></div>
                  <?php endif; ?>
               </div>
            </div>
         </div>
         <?php endforeach; ?>
      </div>
   </div>
</section>
<?php endif; ?>

<!-- CONTACT CTA -->
<section class="pu-contact-section pu-section" id="pu-contact">
   <div class="container">
      <div class="row justify-content-center mb-50">
         <div class="col-xl-7 col-lg-9 text-center">
            <div class="pu-label wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".1s"><?= fh($_contact_cfg['section_subtitle'] ?? 'Get In Touch') ?></div>
            <h2 class="pu-section-title wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".2s"><?= fh($_contact_cfg['section_title'] ?? "We're Here to Help You") ?></h2>
            <p class="pu-section-sub mx-auto mt-3 wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".3s"><?= fh($_contact_cfg['section_description'] ?? 'Reach out to our admissions team or visit us on campus.') ?></p>
         </div>
      </div>
      <div class="row g-4">
         <?php for ($ci = 1; $ci <= 4; $ci++):
            $c_icon  = $_contact_cfg['card_' . $ci . '_icon']  ?? '';
            $c_title = $_contact_cfg['card_' . $ci . '_title'] ?? '';
            $c_value = $_contact_cfg['card_' . $ci . '_value'] ?? '';
            $c_href  = $_contact_cfg['card_' . $ci . '_href']  ?? '#';
            $c_sub   = $_contact_cfg['card_' . $ci . '_sub']   ?? '';
            if ($c_title === '' && $c_value === '') continue;
         ?>
         <div class="col-lg-3 col-sm-6 wow itfadeUp" data-wow-duration=".7s" data-wow-delay="<?= .1 + .1 * ($ci - 1) ?>s">
            <div class="pu-contact-card">
               <div class="pu-contact-card-icon"><i class="<?= fh($c_icon) ?>"></i></div>
               <div>
                  <div class="pu-contact-card-title"><?= fh($c_title) ?></div>
                  <a class="pu-contact-card-value" href="<?= fh($c_href) ?>" <?= str_starts_with($c_href, 'http') ? 'target="_blank" rel="noopener"' : '' ?>><?= fh($c_value) ?></a>
                  <div class="pu-contact-card-sub"><?= fh($c_sub) ?></div>
               </div>
            </div>
         </div>
         <?php endfor; ?>
      </div>
      <div class="text-center mt-5 wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".5s">
         <a href="<?= fh($_contact_cfg['btn1_url'] ?? 'contact.php') ?>" class="pu-btn pu-btn-primary me-3"><i class="fas fa-envelope"></i> <?= fh($_contact_cfg['btn1_text'] ?? 'Send a Message') ?></a>
         <a href="<?= fh($_contact_cfg['btn2_url'] ?? 'admission.php') ?>" class="pu-btn pu-btn-outline"><i class="fas fa-paper-plane"></i> <?= fh($_contact_cfg['btn2_text'] ?? 'Apply Online') ?></a>
      </div>
   </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>

<!-- SCRIPTS -->
<script src="assets/js/jquery.js"></script>
<script src="assets/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/purecounter.js"></script>
<script src="assets/js/nice-select.js"></script>
<script src="assets/js/swiper-bundle.min.js"></script>
<script src="assets/js/slick.min.js"></script>
<script src="assets/js/wow.js"></script>
<script src="assets/js/magnific-popup.js"></script>
<script src="assets/js/parallax.js"></script>
<script src="assets/js/slider.js"></script>
<script src="assets/js/main.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {

  /* Faculty spotlight swiper */
  var facultyEl = document.querySelector('.pu-faculty-swiper');
  if (facultyEl) {
    new Swiper('.pu-faculty-swiper', {
      loop: true,
      slidesPerView: 1,
      spaceBetween: 20,
      navigation: { nextEl: '.pu-faculty-next', prevEl: '.pu-faculty-prev' },
      breakpoints: { 480: {slidesPerView:2}, 768: {slidesPerView:3}, 992: {slidesPerView:4} },
    });
  }

  /* Testimonials swiper */
  var testimEl = document.querySelector('.pu-testimonials-swiper');
  if (testimEl) {
    new Swiper('.pu-testimonials-swiper', {
      loop: true,
      slidesPerView: 1,
      spaceBetween: 24,
      autoplay: { delay: 5000, disableOnInteraction: false },
      navigation: { nextEl: '.pu-testimonials-next', prevEl: '.pu-testimonials-prev' },
      breakpoints: { 768: {slidesPerView:2}, 1100: {slidesPerView:3} },
    });
  }

  /* PureCounter */
  if (typeof PureCounter !== 'undefined') { new PureCounter(); }

  /* Gallery lightbox */
  if (typeof $.fn.magnificPopup !== 'undefined') {
    $('.pu-gallery-section .popup-image').magnificPopup({
      type: 'image',
      gallery: { enabled: true },
      image: { titleSrc: function(item){ return item.el.find('img').attr('alt'); } }
    });
  }

  /* Smooth scroll for anchor links */
  document.querySelectorAll('a[href^="#pu-"]').forEach(function(a) {
    a.addEventListener('click', function(e) {
      var target = document.querySelector(this.getAttribute('href'));
      if (target) { e.preventDefault(); target.scrollIntoView({behavior:'smooth'}); }
    });
  });

});
</script>

</body>
</html>
