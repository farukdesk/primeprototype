<?php
require_once __DIR__ . '/includes/config.php';

/* ── Data fetching ─────────────────────────────────────────────────────────── */
$_stats        = [];
$_latest_news  = [];
$_testimonials = [];
$_departments  = [];
$_faculty      = [];
$_features     = [];
$_about        = [];
$_admission    = [];
$_contact_cfg  = [];
$_campus_items = [];
$_alumni       = [];
$_notices      = [];

$db = null;
try { $db = front_db(); } catch (Throwable $e) {}

if ($db) {
    try {
        $_stats = $db->query(
            'SELECT icon, value, label, suffix FROM homepage_stats
             WHERE is_active = 1 ORDER BY sort_order, id'
        )->fetchAll();
    } catch (Throwable $e) {}

    try {
        $_latest_news = $db->query(
            'SELECT id, title, slug, featured_image, content, published_at
             FROM cms_news WHERE is_published = 1
             ORDER BY published_at DESC, created_at DESC LIMIT 5'
        )->fetchAll();
    } catch (Throwable $e) {}

    try {
        $_testimonials = $db->query(
            'SELECT name, designation, quote, photo, rating FROM homepage_testimonials
             WHERE is_active = 1 ORDER BY sort_order, id LIMIT 8'
        )->fetchAll();
    } catch (Throwable $e) {}

    try {
        $_departments = $db->query(
            'SELECT id, name, slug, code, hero_icon, hero_subtitle
             FROM dept_departments WHERE is_active = 1
             ORDER BY name LIMIT 6'
        )->fetchAll();
    } catch (Throwable $e) {}

    try {
        $_faculty = $db->query(
            "SELECT fp.photo, fp.designation, u.name
             FROM faculty_profiles fp
             JOIN users u ON u.id = fp.user_id
             WHERE fp.photo IS NOT NULL AND fp.photo != ''
             ORDER BY RAND() LIMIT 8"
        )->fetchAll();
    } catch (Throwable $e) {}

    // Why Choose Us features
    try {
        $_features = $db->query(
            'SELECT icon, title, description FROM cms_features
             WHERE is_active = 1 ORDER BY sort_order, id LIMIT 12'
        )->fetchAll();
    } catch (Throwable $e) {}

    // About settings
    try {
        $_about_rows = $db->query('SELECT setting_key, setting_value FROM cms_about_settings')->fetchAll();
        foreach ($_about_rows as $_r) $_about[$_r['setting_key']] = $_r['setting_value'];
    } catch (Throwable $e) {}

    // Admission settings
    try {
        $_adm_rows = $db->query('SELECT setting_key, setting_value FROM cms_admission_settings')->fetchAll();
        foreach ($_adm_rows as $_r) $_admission[$_r['setting_key']] = $_r['setting_value'];
    } catch (Throwable $e) {}

    // Contact settings
    try {
        $_con_rows = $db->query('SELECT setting_key, setting_value FROM cms_contact_settings')->fetchAll();
        foreach ($_con_rows as $_r) $_contact_cfg[$_r['setting_key']] = $_r['setting_value'];
    } catch (Throwable $e) {}

    // Campus gallery (cms_campus_items)
    try {
        $_campus_items = $db->query(
            'SELECT title, image, link_url FROM cms_campus_items
             WHERE is_active = 1 ORDER BY sort_order, id LIMIT 9'
        )->fetchAll();
    } catch (Throwable $e) {}

    // Notable alumni
    try {
        $_alumni = $db->query(
            'SELECT name, designation, organization, photo FROM cms_alumni
             WHERE is_active = 1 ORDER BY sort_order, id LIMIT 8'
        )->fetchAll();
    } catch (Throwable $e) {}

    // Latest notices
    try {
        $_notices = $db->query(
            'SELECT id, title, slug, content, published_at FROM cms_notices
             WHERE is_published = 1 ORDER BY published_at DESC, created_at DESC LIMIT 6'
        )->fetchAll();
    } catch (Throwable $e) {}
}

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
   <link rel="shortcut icon" type="image/x-icon" href="/assets/img/logo/favicon.png">
   <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
   <link rel="stylesheet" href="/assets/css/font-awesome-pro.css">
   <link rel="stylesheet" href="/assets/css/swiper-bundle.min.css">
   <link rel="stylesheet" href="/assets/css/slick.css">
   <link rel="stylesheet" href="/assets/css/magnific-popup.css">
   <link rel="stylesheet" href="/assets/css/nice-select.css">
   <link rel="stylesheet" href="/assets/css/custom-animation.css">
   <link rel="stylesheet" href="/assets/css/spacing.css">
   <link rel="stylesheet" href="/assets/css/main.css">
   <link rel="stylesheet" href="/assets/css/prime-home.css">
</head>
<body id="body" class="it-magic-cursor">


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
      <div class="itoffcanvas__logo"><a href="/"><img src="/assets/img/logo/logo-black.png" alt="Prime University"></a></div>
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
               <img class="pu-about-img-main" src="<?= !empty($_about['main_image']) ? fh(ADMIN_UPLOAD_URL . '/about/' . basename($_about['main_image'])) : 'assets/img/about/about-1-1.jpg' ?>" alt="Prime University Campus">
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
            $dept_url  = $dept['slug'] ? SITE_URL . '/department/' . urlencode($dept['slug']) : SITE_URL . '/department';
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

<!-- NEWS & NOTICE BOARD (combined) -->
<section class="pu-board-section pu-section" id="pu-news-notice">
   <div class="container">
      <div class="row justify-content-center mb-50">
         <div class="col-lg-8 text-center">
            <div class="pu-label wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".1s">Updates &amp; Announcements</div>
            <h2 class="pu-section-title wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".2s">News <span class="accent">&amp;</span> Notice Board</h2>
            <p class="pu-section-sub mt-3 wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".3s">Stay informed with the latest news, events and official notices from Prime University.</p>
         </div>
      </div>
      <div class="row g-4 pu-board-row">

         <!-- ── Left: Latest News ────────────────────────────── -->
         <div class="col-lg-7 wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".2s">
            <div class="pu-board-panel pu-board-news">
               <div class="pu-board-header">
                  <div class="pu-board-header-inner">
                     <span class="pu-board-icon"><i class="fas fa-newspaper"></i></span>
                     <h3 class="pu-board-title">Latest News</h3>
                  </div>
                  <a href="news-detail.php" class="pu-board-viewall">All News <i class="fas fa-arrow-right"></i></a>
               </div>
               <div class="pu-board-body">
                  <?php
                  $placeholder_news = [
                     ['title'=>'Prime University Hosts Annual Research Symposium 2026','date'=>'March 2026','cat'=>'Research','featured_image'=>null,'slug'=>'#'],
                     ['title'=>'New Computer Science Lab Inaugurated with State-of-the-Art Equipment','date'=>'February 2026','cat'=>'Facilities','featured_image'=>null,'slug'=>'#'],
                     ['title'=>'Prime University Students Win National Moot Court Competition','date'=>'January 2026','cat'=>'Achievement','featured_image'=>null,'slug'=>'#'],
                  ];
                  $news_items = !empty($_latest_news) ? $_latest_news : array_map(function($p){ return ['title'=>$p['title'],'slug'=>$p['slug'],'featured_image'=>$p['featured_image'],'content'=>'','published_at'=>null,'_placeholder'=>true,'_cat'=>$p['cat'],'_date'=>$p['date']]; }, $placeholder_news);
                  foreach ($news_items as $ni => $news):
                     $is_ph   = !empty($news['_placeholder']);
                     $news_url = $is_ph ? '#' : (SITE_URL . '/news/' . urlencode($news['slug'] ?? ''));
                     $cat      = $is_ph ? ($news['_cat'] ?? 'News') : 'News';
                     $exc      = $is_ph ? '' : strip_tags($news['content'] ?? '');
                     if (!$is_ph && mb_strlen($exc) > 90) $exc = mb_substr($exc, 0, 90) . '…';
                  ?>
                  <article class="pu-bcard pu-bcard-news wow itfadeUp" data-wow-duration=".6s" data-wow-delay="<?= .1 + .08 * $ni ?>s">
                     <div class="pu-bcard-img-wrap">
                        <?php if (!$is_ph && $news['featured_image']): ?>
                        <img class="pu-bcard-img" src="<?= fh(ADMIN_UPLOAD_URL . '/news/' . basename($news['featured_image'])) ?>" alt="<?= fh($news['title']) ?>">
                        <?php else: ?>
                        <div class="pu-bcard-img-ph"><i class="fas fa-newspaper"></i></div>
                        <?php endif; ?>
                        <span class="pu-bcard-cat"><?= h($cat) ?></span>
                     </div>
                     <div class="pu-bcard-body">
                        <div class="pu-bcard-date">
                           <i class="fas fa-calendar-alt"></i>
                           <?php if ($is_ph): ?><?= $news['_date'] ?><?php
                           elseif ($news['published_at']): ?><?= date('d M Y', strtotime($news['published_at'])) ?><?php
                           endif; ?>
                        </div>
                        <a href="<?= $news_url ?>" class="pu-bcard-title"><?= fh($news['title']) ?></a>
                        <?php if ($exc): ?><p class="pu-bcard-excerpt"><?= fh($exc) ?></p><?php endif; ?>
                        <a href="<?= $news_url ?>" class="pu-bcard-link">Read More <i class="fas fa-arrow-right"></i></a>
                     </div>
                  </article>
                  <?php endforeach; ?>
               </div>
            </div>
         </div>

         <!-- ── Right: Notice Board ─────────────────────────── -->
         <div class="col-lg-5 wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".35s">
            <div class="pu-board-panel pu-board-notices">
               <div class="pu-board-header pu-board-header--notice">
                  <div class="pu-board-header-inner">
                     <span class="pu-board-icon"><i class="fas fa-bullhorn"></i></span>
                     <h3 class="pu-board-title">Notice Board</h3>
                  </div>
                  <a href="notice-board.php" class="pu-board-viewall pu-board-viewall--notice">All Notices <i class="fas fa-arrow-right"></i></a>
               </div>
               <div class="pu-board-body pu-noticelist">
                  <?php
                  $placeholder_notices = [
                     ['title'=>'Admission Open for Spring Semester 2026','published_at'=>null,'slug'=>'#','content'=>''],
                     ['title'=>'Examination Schedule – Final Term 2026 Released','published_at'=>null,'slug'=>'#','content'=>''],
                     ['title'=>'Library Timing Updated for Ramadan','published_at'=>null,'slug'=>'#','content'=>''],
                     ['title'=>'Fee Submission Deadline – Spring 2026','published_at'=>null,'slug'=>'#','content'=>''],
                     ['title'=>'Campus Closed on National Holiday','published_at'=>null,'slug'=>'#','content'=>''],
                  ];
                  $notice_items = !empty($_notices) ? $_notices : array_map(function($p){ return array_merge($p, ['_placeholder'=>true]); }, $placeholder_notices);
                  foreach ($notice_items as $ni => $notice):
                     $is_ph_n    = !empty($notice['_placeholder']);
                     $notice_url = $is_ph_n ? '#' : (SITE_URL . '/notice/' . urlencode($notice['slug'] ?? ''));
                  ?>
                  <div class="pu-notice-item wow itfadeUp" data-wow-duration=".6s" data-wow-delay="<?= .1 + .07 * $ni ?>s">
                     <div class="pu-notice-num"><?= $ni + 1 ?></div>
                     <div class="pu-notice-content">
                        <a href="<?= $notice_url ?>" class="pu-notice-item-title"><?= fh($notice['title']) ?></a>
                        <?php if (!$is_ph_n && $notice['published_at']): ?>
                        <div class="pu-notice-item-date"><i class="fas fa-calendar-alt"></i> <?= date('d M Y', strtotime($notice['published_at'])) ?></div>
                        <?php endif; ?>
                     </div>
                     <a href="<?= $notice_url ?>" class="pu-notice-arrow" title="View"><i class="fas fa-chevron-right"></i></a>
                  </div>
                  <?php endforeach; ?>
               </div>
            </div>
         </div>

      </div><!-- /.row -->
   </div>
</section>

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
<script src="/assets/js/jquery.js"></script>
<script src="/assets/js/bootstrap.bundle.min.js"></script>
<script src="/assets/js/purecounter.js"></script>
<script src="/assets/js/nice-select.js"></script>
<script src="/assets/js/swiper-bundle.min.js"></script>
<script src="/assets/js/slick.min.js"></script>
<script src="/assets/js/wow.js"></script>
<script src="/assets/js/magnific-popup.js"></script>
<script src="/assets/js/parallax.js"></script>
<script src="/assets/js/slider.js"></script>
<script src="/assets/js/isotope-pkgd.js"></script>
<script src="/assets/js/imagesloaded-pkgd.js"></script>
<script src="/assets/js/main.js"></script>

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
