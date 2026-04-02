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

$_gallery_imgs = [
    ['src'=>'assets/img/campus/campus-3-1.jpg','alt'=>'Prime University Campus'],
    ['src'=>'assets/img/about/about-1-1.jpg',  'alt'=>'Academic Environment'],
    ['src'=>'assets/img/about/about-5-1.jpg',  'alt'=>'Student Activities'],
    ['src'=>'assets/img/campus/campus-3-2.jpg','alt'=>'Campus Life'],
    ['src'=>'assets/img/about/about-10-1.jpg', 'alt'=>'Research Facilities'],
    ['src'=>'assets/img/campus/campus-3-3.jpg','alt'=>'Modern Classrooms'],
];
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
         $features = [
            ['icon'=>'fas fa-graduation-cap',  'cls'=>'pu-feature-icon-1','title'=>'Bachelor Programs',  'desc'=>'Comprehensive undergraduate degrees in Engineering, Business, Law, Pharmacy, Architecture and more.'],
            ['icon'=>'fas fa-user-tie',         'cls'=>'pu-feature-icon-2','title'=>'Masters Degrees',    'desc'=>'Advance your expertise with research-driven postgraduate programmes taught by internationally qualified faculty.'],
            ['icon'=>'fas fa-flask',            'cls'=>'pu-feature-icon-3','title'=>'Research Excellence','desc'=>'State-of-the-art laboratories and dedicated research centres producing innovation that matters.'],
            ['icon'=>'fas fa-handshake',        'cls'=>'pu-feature-icon-4','title'=>'Industry Placement', 'desc'=>'Strong industry ties and a dedicated career office help graduates secure top positions before finishing their final semester.'],
            ['icon'=>'fas fa-globe-asia',       'cls'=>'pu-feature-icon-5','title'=>'Global Network',     'desc'=>'International affiliations and exchange programmes connect our students to a worldwide academic community.'],
            ['icon'=>'fas fa-shield-alt',       'cls'=>'pu-feature-icon-6','title'=>'Accredited Quality', 'desc'=>'UGC-approved and internationally benchmarked — our degrees are recognised by employers worldwide.'],
         ];
         foreach ($features as $i => $f):
         ?>
         <div class="col-xl-4 col-lg-4 col-md-6 wow itfadeUp" data-wow-duration=".7s" data-wow-delay="<?= .1 + .1 * $i ?>s">
            <div class="pu-feature-card">
               <div class="pu-feature-icon <?= $f['cls'] ?>"><i class="<?= $f['icon'] ?>"></i></div>
               <h5><?= $f['title'] ?></h5>
               <p><?= $f['desc'] ?></p>
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
               <img class="pu-about-img-main" src="assets/img/about/about-1-1.jpg" alt="Prime University Campus">
               <div class="pu-about-img-badge">
                  <span class="number">32<span class="pu-text-half">+</span></span>
                  <span class="text">Years of<br>Excellence</span>
               </div>
            </div>
         </div>
         <div class="col-lg-6 wow itfadeRight" data-wow-duration=".9s" data-wow-delay=".3s">
            <div class="pu-about-content">
               <div class="pu-label">About the University</div>
               <h2 class="pu-section-title mb-3">Shaping Leaders Since <span class="accent">1993</span></h2>
               <p class="pu-section-sub mb-4">Prime University is a premier private university in Bangladesh, committed to quality higher education through academic rigour, research innovation and industry relevance. Located in Mirpur, Dhaka, our campus is home to over 15,000 students from across the country and abroad.</p>
               <ul class="pu-about-list mb-4">
                  <li><div class="check"><i class="fas fa-check"></i></div>UGC-approved with internationally recognised degree programs</li>
                  <li><div class="check"><i class="fas fa-check"></i></div>250+ highly qualified and research-active faculty members</li>
                  <li><div class="check"><i class="fas fa-check"></i></div>Modern libraries, labs and digital learning infrastructure</li>
                  <li><div class="check"><i class="fas fa-check"></i></div>Dedicated career centre with industry placement programmes</li>
                  <li><div class="check"><i class="fas fa-check"></i></div>Active student clubs, sports and cultural programmes</li>
               </ul>
               <div class="d-flex flex-wrap gap-3">
                  <a href="admission.php" class="pu-btn pu-btn-primary"><i class="fas fa-paper-plane"></i> Apply Now</a>
                  <a href="contact.php" class="pu-btn pu-btn-outline"><i class="fas fa-phone-alt"></i> Contact Us</a>
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
            $dept_img  = $dept_bg_images[$idx % count($dept_bg_images)];
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
            <div class="pu-admission-badge"><span class="dot"></span> Admissions Open</div>
            <h2 class="pu-admission-title">Begin Your Journey at<br><span class="gold">Prime University</span></h2>
            <p class="pu-admission-text">Applications for Summer 2026 are now open. Secure your place in one of our prestigious undergraduate or postgraduate programmes and start building the future you deserve.</p>
            <div class="d-flex flex-wrap gap-3">
               <a href="admission.php" class="pu-btn pu-btn-primary"><i class="fas fa-paper-plane"></i> Apply Now</a>
               <a href="scholarships-waivers.php" class="pu-btn pu-btn-outline-white"><i class="fas fa-award"></i> Scholarships</a>
            </div>
         </div>
         <div class="col-lg-5 wow itfadeRight" data-wow-duration=".9s" data-wow-delay=".3s">
            <div class="d-flex flex-column gap-3">
               <div class="pu-admission-deadline"><i class="fas fa-calendar-alt"></i><div><div style="font-weight:700;font-size:.95rem;">Application Deadline</div><div style="font-size:.85rem;opacity:.75;">Summer Semester 2026 – Rolling admissions</div></div></div>
               <div class="pu-admission-deadline"><i class="fas fa-graduation-cap"></i><div><div style="font-weight:700;font-size:.95rem;">35+ Programs Available</div><div style="font-size:.85rem;opacity:.75;">Undergraduate, Postgraduate &amp; Diploma</div></div></div>
               <div class="pu-admission-deadline"><i class="fas fa-award"></i><div><div style="font-weight:700;font-size:.95rem;">Scholarships Available</div><div style="font-size:.85rem;opacity:.75;">Merit-based &amp; need-based financial aid</div></div></div>
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
         <?php foreach ($_gallery_imgs as $gimg): ?>
         <div class="pu-gallery-item">
            <a class="popup-image" href="<?= fh($gimg['src']) ?>">
               <img src="<?= fh($gimg['src']) ?>" alt="<?= fh($gimg['alt']) ?>" onerror="this.closest('.pu-gallery-item').style.background='#1e3a5c'">
               <div class="pu-gallery-item-overlay"><i class="fas fa-search-plus"></i></div>
            </a>
         </div>
         <?php endforeach; ?>
      </div>
   </div>
</section>

<!-- CONTACT CTA -->
<section class="pu-contact-section pu-section" id="pu-contact">
   <div class="container">
      <div class="row justify-content-center mb-50">
         <div class="col-xl-7 col-lg-9 text-center">
            <div class="pu-label wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".1s">Get In Touch</div>
            <h2 class="pu-section-title wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".2s">We're Here to <span class="accent">Help You</span></h2>
            <p class="pu-section-sub mx-auto mt-3 wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".3s">Reach out to our admissions team or visit us on campus. We'll guide you through every step of your application.</p>
         </div>
      </div>
      <div class="row g-4">
         <?php
         $contacts = [
            ['icon'=>'fas fa-phone-alt',    'title'=>'Call Us',      'value'=>'+880-1710-996196',           'href'=>'tel:+8801710996196',                'sub'=>'Mon – Fri, 9am – 5pm'],
            ['icon'=>'fas fa-envelope',     'title'=>'Email Us',     'value'=>'info@primeuniversity.edu.bd','href'=>'mailto:info@primeuniversity.edu.bd','sub'=>'We reply within 24 hours'],
            ['icon'=>'fas fa-map-marker-alt','title'=>'Visit Campus','value'=>'114/116, Mazar Rd, Dhaka',  'href'=>'https://maps.google.com/?q=Prime+University+Dhaka','sub'=>'View on Google Maps'],
            ['icon'=>'fas fa-clock',        'title'=>'Office Hours', 'value'=>'Sunday – Thursday',         'href'=>'#',                                'sub'=>'9:00 AM – 5:00 PM'],
         ];
         foreach ($contacts as $ci => $c):
         ?>
         <div class="col-lg-3 col-sm-6 wow itfadeUp" data-wow-duration=".7s" data-wow-delay="<?= .1 + .1 * $ci ?>s">
            <div class="pu-contact-card">
               <div class="pu-contact-card-icon"><i class="<?= $c['icon'] ?>"></i></div>
               <div>
                  <div class="pu-contact-card-title"><?= $c['title'] ?></div>
                  <a class="pu-contact-card-value" href="<?= fh($c['href']) ?>" <?= str_starts_with($c['href'], 'http') ? 'target="_blank" rel="noopener"' : '' ?>><?= fh($c['value']) ?></a>
                  <div class="pu-contact-card-sub"><?= fh($c['sub']) ?></div>
               </div>
            </div>
         </div>
         <?php endforeach; ?>
      </div>
      <div class="text-center mt-5 wow itfadeUp" data-wow-duration=".7s" data-wow-delay=".5s">
         <a href="contact.php" class="pu-btn pu-btn-primary me-3"><i class="fas fa-envelope"></i> Send a Message</a>
         <a href="admission.php" class="pu-btn pu-btn-outline"><i class="fas fa-paper-plane"></i> Apply Online</a>
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
