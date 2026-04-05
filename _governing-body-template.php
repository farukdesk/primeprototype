<?php
/**
 * Shared template for all Governing Body frontend pages.
 * Expects $page_type to be set before including this file.
 */
require_once __DIR__ . '/includes/config.php';

$valid_types = ['board-of-trustees', 'pu-syndicates', 'deans', 'head-of-departments'];
if (!isset($page_type) || !in_array($page_type, $valid_types, true)) {
    header('Location: index.php');
    exit;
}

$settings = null;
$members  = [];

try {
    $db = front_db();
    if ($db) {
        $st = $db->prepare('SELECT * FROM governing_body_pages WHERE page_type = ? LIMIT 1');
        $st->execute([$page_type]);
        $settings = $st->fetch() ?: null;

        $st2 = $db->prepare(
            'SELECT * FROM governing_body_members WHERE page_type = ? ORDER BY sort_order ASC, id ASC'
        );
        $st2->execute([$page_type]);
        $members = $st2->fetchAll();
    }
} catch (Throwable $e) {}

// Group members by section, preserving first-appearance order
$sections = [];
foreach ($members as $m) {
    $sec = trim($m['section']) ?: 'member';
    if (!isset($sections[$sec])) $sections[$sec] = [];
    $sections[$sec][] = $m;
}

$hero_title    = $settings ? fh($settings['title'])       : fh(ucwords(str_replace('-', ' ', $page_type)));
$hero_subtitle = $settings ? fh($settings['subtitle'])    : '';
$hero_intro    = $settings ? fh($settings['hero_intro'])  : '';
$meta_desc     = $settings ? fh($settings['meta_description']) : '';

$page_icons = [
    'board-of-trustees'   => 'fas fa-landmark',
    'pu-syndicates'       => 'fas fa-balance-scale',
    'deans'               => 'fas fa-user-tie',
    'head-of-departments' => 'fas fa-chalkboard-teacher',
];
$hero_icon = $page_icons[$page_type] ?? 'fas fa-university';

$total_members = count($members);
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <title><?= $hero_title ?> – Prime University</title>
   <meta name="description" content="<?= $meta_desc ?>">
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
   <style>
      /* ── Hero ── */
      .gb-hero {
         background: linear-gradient(135deg, #002147 0%, #1a3d6e 50%, #003d73 100%);
         padding: 80px 0 70px;
         position: relative;
         overflow: hidden;
      }
      .gb-hero::before {
         content: '';
         position: absolute;
         top: -50%; left: -50%;
         width: 200%; height: 200%;
         background: radial-gradient(circle, rgba(255,184,28,.06) 0%, transparent 60%);
         pointer-events: none;
      }
      .gb-hero::after {
         content: '';
         position: absolute;
         inset: 0;
         background: url('assets/img/shape/footer-bg-3-1.jpg') center/cover no-repeat;
         opacity: .05;
         pointer-events: none;
      }
      .gb-hero-content { position: relative; z-index: 2; }
      .gb-particle {
         position: absolute;
         border-radius: 50%;
         background: rgba(255,184,28,.15);
         animation: gbFloat 6s ease-in-out infinite;
         pointer-events: none;
         z-index: 1;
      }
      @keyframes gbFloat {
         0%, 100% { transform: translateY(0) rotate(0deg); }
         50%       { transform: translateY(-20px) rotate(10deg); }
      }
      .gb-hero-title {
         color: #fff;
         font-size: clamp(28px, 5vw, 48px);
         font-weight: 700;
         line-height: 1.2;
         animation: gbSlideDown .8s ease both;
      }
      @keyframes gbSlideDown {
         from { opacity: 0; transform: translateY(-30px); }
         to   { opacity: 1; transform: translateY(0); }
      }

      /* ── Quick nav pills ── */
      .gb-nav-pill {
         display: inline-block;
         padding: 8px 20px;
         border-radius: 30px;
         font-size: .82rem;
         font-weight: 600;
         border: 2px solid rgba(255,255,255,.25);
         color: rgba(255,255,255,.8);
         text-decoration: none;
         transition: all .3s;
      }
      .gb-nav-pill:hover, .gb-nav-pill.active {
         background: #FFB81C;
         border-color: #FFB81C;
         color: #002147;
         text-decoration: none;
      }

      /* ── Stats bar ── */
      .gb-stats-bar {
         background: rgba(255,255,255,.08);
         border-top: 1px solid rgba(255,255,255,.12);
         margin-top: 40px;
         padding: 16px 0;
         position: relative;
         z-index: 2;
      }
      .gb-stat-item { text-align: center; }
      .gb-stat-num  { font-size: 2rem; font-weight: 700; color: #FFB81C; line-height: 1; }
      .gb-stat-lbl  { font-size: .78rem; color: rgba(255,255,255,.7); text-transform: uppercase; letter-spacing: .5px; margin-top: 4px; }

      /* ── Section divider ── */
      .gb-section-divider {
         display: flex;
         align-items: center;
         margin: 50px 0 40px;
         gap: 20px;
      }
      .gb-section-divider::before,
      .gb-section-divider::after {
         content: '';
         flex: 1;
         height: 1px;
         background: linear-gradient(to right, transparent, #e2e8f0, transparent);
      }
      .gb-section-badge {
         display: inline-flex;
         align-items: center;
         gap: 6px;
         background: rgba(210,16,52,.08);
         color: #D21034;
         border-radius: 30px;
         padding: 6px 18px;
         font-size: .8rem;
         font-weight: 600;
         letter-spacing: .5px;
         text-transform: uppercase;
         white-space: nowrap;
      }

      /* ── Cards ── */
      .gb-card {
         transition: all .3s ease;
         border: none !important;
         border-radius: 16px !important;
      }
      .gb-card:hover {
         transform: translateY(-6px);
         box-shadow: 0 20px 40px rgba(0,33,71,.15) !important;
      }
      .gb-featured-card {
         border-top: 4px solid #FFB81C !important;
      }
      .gb-featured-img {
         width: 160px; height: 160px;
         object-fit: cover;
         border-radius: 50%;
         border: 5px solid #FFB81C;
         box-shadow: 0 8px 25px rgba(0,33,71,.2);
      }
      .gb-member-img {
         width: 120px; height: 120px;
         object-fit: cover;
         border-radius: 50%;
         border: 4px solid #F8FAFC;
         box-shadow: 0 4px 15px rgba(0,33,71,.12);
         background: #E8EEF4;
      }
      .gb-designation { color: #D21034; font-weight: 600; }

      /* ── Breadcrumb ── */
      .gb-breadcrumb .breadcrumb-item a { color: #FFB81C; }
      .gb-breadcrumb .breadcrumb-item.active { color: rgba(255,255,255,.75); }
      .gb-breadcrumb .breadcrumb-item + .breadcrumb-item::before { color: rgba(255,255,255,.4); }
   </style>
</head>
<body id="body" class="it-magic-cursor">

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

   <div class="it-offcanvas-area">
      <div class="itoffcanvas">
         <div class="itoffcanvas__close-btn">
            <button class="close-btn"><i class="fal fa-times"></i></button>
         </div>
         <div class="itoffcanvas__logo">
            <a href="<?= fh(SITE_URL) ?>/index.php">
               <img src="/assets/img/logo/logo-black.png" alt="">
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
         </div>
      </div>
   </div>
   <div class="body-overlay"></div>

   <header class="it-header-height">
      <?php include __DIR__ . '/includes/header-top.php'; ?>
      <?php include __DIR__ . '/includes/nav-menu.php'; ?>
   </header>

   <main>

   <!-- ── Hero ── -->
   <section class="gb-hero">
      <!-- Decorative floating particles -->
      <div class="gb-particle" style="width:80px;height:80px;top:10%;left:5%;animation-delay:0s;"></div>
      <div class="gb-particle" style="width:50px;height:50px;top:60%;left:2%;animation-delay:1.5s;"></div>
      <div class="gb-particle" style="width:60px;height:60px;top:20%;right:8%;animation-delay:3s;"></div>
      <div class="gb-particle" style="width:35px;height:35px;top:70%;right:5%;animation-delay:2s;"></div>
      <div class="gb-particle" style="width:100px;height:100px;bottom:5%;left:40%;animation-delay:4s;opacity:.08;"></div>

      <div class="container">
         <div class="gb-hero-content text-center">

            <!-- Breadcrumb -->
            <nav aria-label="breadcrumb" class="mb-3">
               <ol class="breadcrumb justify-content-center gb-breadcrumb">
                  <li class="breadcrumb-item"><a href="<?= fh(SITE_URL) ?>/index.php">Home</a></li>
                  <li class="breadcrumb-item" style="color:rgba(255,255,255,.5);">Governance</li>
                  <li class="breadcrumb-item active"><?= $hero_title ?></li>
               </ol>
            </nav>

            <span style="color:#FFB81C;font-weight:600;font-size:.9rem;letter-spacing:.5px;display:block;margin-bottom:12px;">
               <i class="<?= $hero_icon ?>" style="margin-right:8px;"></i>Prime University
            </span>

            <h1 class="gb-hero-title mb-3"><?= $hero_title ?></h1>

            <?php if ($hero_subtitle): ?>
            <p style="color:rgba(255,255,255,.7);font-size:1.05rem;margin-bottom:16px;"><?= $hero_subtitle ?></p>
            <?php endif; ?>

            <?php if ($hero_intro): ?>
            <p style="color:rgba(255,255,255,.65);font-size:.95rem;max-width:680px;margin:0 auto 24px;line-height:1.8;">
               <?= $hero_intro ?>
            </p>
            <?php endif; ?>

            <!-- Quick navigation -->
            <div class="d-flex flex-wrap gap-2 justify-content-center mt-4">
               <a href="<?= fh(SITE_URL) ?>/board-of-trustees.php"
                  class="gb-nav-pill <?= $page_type === 'board-of-trustees' ? 'active' : '' ?>">
                  <i class="fas fa-landmark me-1"></i> Board of Trustees
               </a>
               <a href="<?= fh(SITE_URL) ?>/pu-syndicates.php"
                  class="gb-nav-pill <?= $page_type === 'pu-syndicates' ? 'active' : '' ?>">
                  <i class="fas fa-balance-scale me-1"></i> PU Syndicates
               </a>
               <a href="<?= fh(SITE_URL) ?>/deans.php"
                  class="gb-nav-pill <?= $page_type === 'deans' ? 'active' : '' ?>">
                  <i class="fas fa-user-tie me-1"></i> Deans
               </a>
               <a href="<?= fh(SITE_URL) ?>/head-of-departments.php"
                  class="gb-nav-pill <?= $page_type === 'head-of-departments' ? 'active' : '' ?>">
                  <i class="fas fa-chalkboard-teacher me-1"></i> Head of Departments
               </a>
            </div>

         </div>
      </div>

      <?php if ($page_type === 'board-of-trustees'): ?>
      <!-- Stats bar (Board of Trustees only) -->
      <div class="gb-stats-bar">
         <div class="container">
            <div class="row justify-content-center g-3">
               <div class="col-auto">
                  <div class="gb-stat-item">
                     <div class="gb-stat-num"><?= $total_members ?></div>
                     <div class="gb-stat-lbl">Total Members</div>
                  </div>
               </div>
               <?php
               $feat_count = count(array_filter($members, fn($m) => (int)$m['is_featured'] === 1));
               $sec_count  = count($sections);
               ?>
               <?php if ($feat_count > 0): ?>
               <div class="col-auto">
                  <div class="gb-stat-item">
                     <div class="gb-stat-num"><?= $feat_count ?></div>
                     <div class="gb-stat-lbl">Leadership</div>
                  </div>
               </div>
               <?php endif; ?>
               <?php if ($sec_count > 1): ?>
               <div class="col-auto">
                  <div class="gb-stat-item">
                     <div class="gb-stat-num"><?= $sec_count ?></div>
                     <div class="gb-stat-lbl">Sections</div>
                  </div>
               </div>
               <?php endif; ?>
            </div>
         </div>
      </div>
      <?php endif; ?>
   </section>

   <!-- ── Sections ── -->
   <?php if (empty($members)): ?>
   <section class="pt-100 pb-100">
      <div class="container text-center">
         <div style="width:80px;height:80px;border-radius:50%;background:#f1f5f9;display:inline-flex;align-items:center;justify-content:center;margin-bottom:20px;">
            <i class="fas fa-users" style="font-size:2rem;color:#94a3b8;"></i>
         </div>
         <h5 style="color:#64748b;">No members have been added yet.</h5>
         <p style="color:#94a3b8;">Check back soon for updates.</p>
      </div>
   </section>
   <?php else: ?>

   <?php
   $sec_index = 0;
   foreach ($sections as $sec_name => $sec_members):
      $sec_index++;
      $sec_label    = ucwords(str_replace('-', ' ', $sec_name));
      $is_first_sec = ($sec_index === 1);
      $bg_color     = $is_first_sec ? '#fff' : ($sec_index % 2 === 0 ? '#F8FAFC' : '#fff');

      // Determine if this section has a single featured member → large centered card
      $has_one_featured = (count($sec_members) === 1 && (int)$sec_members[0]['is_featured'] === 1);
      // Or all members in this section are featured (chairman-style section)
      $all_featured = !empty($sec_members) && count(array_filter($sec_members, fn($m) => (int)$m['is_featured'] === 1)) === count($sec_members);
   ?>

   <?php if ($sec_index > 1): ?>
   <div class="container">
      <div class="gb-section-divider">
         <span class="gb-section-badge"><i class="fas fa-circle" style="font-size:.4rem;"></i> <?= fh($sec_label) ?></span>
      </div>
   </div>
   <?php endif; ?>

   <section style="background:<?= $bg_color ?>;padding:<?= $is_first_sec ? '60px 0 80px' : '20px 0 80px' ?>;">
      <div class="container">

         <!-- Section heading -->
         <div class="row justify-content-center">
            <div class="col-12 text-center mb-50">
               <span class="it-section-subtitle" style="color:#D21034;">
                  <i class="<?= $hero_icon ?> me-1"></i><?= fh($sec_label) ?>
               </span>
               <h4 class="it-section-title" style="color:#002147;"><?= $hero_title ?> – <?= fh($sec_label) ?></h4>
            </div>
         </div>

         <?php if ($has_one_featured): ?>
         <!-- Single featured member: centred large card -->
         <?php $fm = $sec_members[0]; ?>
         <div class="row justify-content-center">
            <div class="col-xl-5 col-lg-6 wow itfadeUp" data-wow-duration=".9s" data-wow-delay=".3s">
               <div class="card gb-card gb-featured-card shadow-sm text-center">
                  <div class="card-body p-50">
                     <div class="mb-25">
                        <?php if ($fm['photo']): ?>
                        <img src="<?= fh(ADMIN_UPLOAD_URL) ?>/governing-body/<?= fh($fm['photo']) ?>"
                             alt="<?= fh($fm['full_name']) ?>" class="gb-featured-img">
                        <?php else: ?>
                        <div class="gb-featured-img d-inline-flex align-items-center justify-content-center"
                             style="background:#E8EEF4;font-size:3rem;color:#002147;">
                           <i class="fas fa-user"></i>
                        </div>
                        <?php endif; ?>
                     </div>
                     <h4 style="color:#002147;font-weight:700;margin-bottom:8px;"><?= fh($fm['full_name']) ?></h4>
                     <?php if ($fm['designation']): ?>
                     <p class="gb-designation" style="font-size:15px;margin-bottom:0;"><?= fh($fm['designation']) ?></p>
                     <?php endif; ?>
                     <?php if ($fm['department']): ?>
                     <p style="color:#64748b;font-size:.85rem;margin-top:6px;"><?= fh($fm['department']) ?></p>
                     <?php endif; ?>
                     <?php if ($fm['bio']): ?>
                     <p style="color:#475569;font-size:.9rem;margin-top:14px;line-height:1.7;"><?= fh($fm['bio']) ?></p>
                     <?php endif; ?>
                     <?php if ($fm['email'] || $fm['phone']): ?>
                     <div class="mt-3 d-flex gap-3 justify-content-center flex-wrap" style="font-size:.85rem;">
                        <?php if ($fm['email']): ?>
                        <a href="mailto:<?= fh($fm['email']) ?>" style="color:#4f8ef7;">
                           <i class="fas fa-envelope me-1"></i><?= fh($fm['email']) ?>
                        </a>
                        <?php endif; ?>
                        <?php if ($fm['phone']): ?>
                        <a href="tel:<?= fh(preg_replace('/[^0-9+]/', '', $fm['phone'])) ?>" style="color:#4f8ef7;">
                           <i class="fas fa-phone me-1"></i><?= fh($fm['phone']) ?>
                        </a>
                        <?php endif; ?>
                     </div>
                     <?php endif; ?>
                  </div>
               </div>
            </div>
         </div>

         <?php elseif ($all_featured && count($sec_members) <= 4): ?>
         <!-- Small group of featured members: wider centered cards -->
         <div class="row g-4 justify-content-center">
            <?php foreach ($sec_members as $di => $fm): ?>
            <?php $delay = ['.3s', '.4s', '.5s', '.6s'][$di % 4]; ?>
            <div class="col-xl-4 col-lg-5 col-md-6 wow itfadeUp" data-wow-duration=".9s" data-wow-delay="<?= $delay ?>">
               <div class="card gb-card gb-featured-card shadow-sm text-center">
                  <div class="card-body p-40">
                     <div class="mb-20">
                        <?php if ($fm['photo']): ?>
                        <img src="<?= fh(ADMIN_UPLOAD_URL) ?>/governing-body/<?= fh($fm['photo']) ?>"
                             alt="<?= fh($fm['full_name']) ?>" class="gb-featured-img">
                        <?php else: ?>
                        <div class="gb-featured-img d-inline-flex align-items-center justify-content-center"
                             style="background:#E8EEF4;font-size:2.5rem;color:#002147;">
                           <i class="fas fa-user"></i>
                        </div>
                        <?php endif; ?>
                     </div>
                     <h5 style="color:#002147;font-weight:700;margin-bottom:6px;"><?= fh($fm['full_name']) ?></h5>
                     <?php if ($fm['designation']): ?>
                     <p class="gb-designation" style="font-size:14px;margin-bottom:0;"><?= fh($fm['designation']) ?></p>
                     <?php endif; ?>
                     <?php if ($fm['department']): ?>
                     <p style="color:#64748b;font-size:.82rem;margin-top:5px;"><?= fh($fm['department']) ?></p>
                     <?php endif; ?>
                  </div>
               </div>
            </div>
            <?php endforeach; ?>
         </div>

         <?php else: ?>
         <!-- Regular members grid: 3-column responsive -->
         <div class="row g-4">
            <?php
            $delays = ['.3s', '.4s', '.5s'];
            $gi = 0;
            foreach ($sec_members as $m):
               $delay = $delays[$gi % 3];
               $gi++;
            ?>
            <div class="col-xl-4 col-lg-4 col-md-6 wow itfadeUp" data-wow-duration=".9s" data-wow-delay="<?= $delay ?>">
               <div class="card gb-card shadow-sm text-center h-100"
                    style="background:#fff;<?= (int)$m['is_featured'] === 1 ? 'border-top:4px solid #FFB81C!important;' : '' ?>">
                  <div class="card-body p-30">
                     <div class="mb-20">
                        <?php if ($m['photo']): ?>
                        <img src="<?= fh(ADMIN_UPLOAD_URL) ?>/governing-body/<?= fh($m['photo']) ?>"
                             alt="<?= fh($m['full_name']) ?>"
                             class="<?= (int)$m['is_featured'] === 1 ? 'gb-featured-img' : 'gb-member-img' ?>">
                        <?php else: ?>
                        <div class="<?= (int)$m['is_featured'] === 1 ? 'gb-featured-img' : 'gb-member-img' ?> d-inline-flex align-items-center justify-content-center"
                             style="background:#E8EEF4;font-size:2rem;color:#002147;">
                           <i class="fas fa-user"></i>
                        </div>
                        <?php endif; ?>
                     </div>
                     <h6 style="color:#002147;font-weight:700;margin-bottom:5px;"><?= fh($m['full_name']) ?></h6>
                     <?php if ($m['designation']): ?>
                     <p class="gb-designation" style="font-size:13px;margin-bottom:0;"><?= fh($m['designation']) ?></p>
                     <?php endif; ?>
                     <?php if ($m['department']): ?>
                     <p style="color:#64748b;font-size:.8rem;margin-top:4px;margin-bottom:0;"><?= fh($m['department']) ?></p>
                     <?php endif; ?>
                     <?php if ($m['bio']): ?>
                     <p style="color:#64748b;font-size:.82rem;margin-top:10px;line-height:1.65;"><?= fh($m['bio']) ?></p>
                     <?php endif; ?>
                     <?php if ($m['email'] || $m['phone']): ?>
                     <div class="mt-2" style="font-size:.8rem;">
                        <?php if ($m['email']): ?>
                        <a href="mailto:<?= fh($m['email']) ?>" style="color:#4f8ef7;display:block;">
                           <i class="fas fa-envelope me-1"></i><?= fh($m['email']) ?>
                        </a>
                        <?php endif; ?>
                        <?php if ($m['phone']): ?>
                        <a href="tel:<?= fh(preg_replace('/[^0-9+]/', '', $m['phone'])) ?>" style="color:#4f8ef7;display:block;margin-top:3px;">
                           <i class="fas fa-phone me-1"></i><?= fh($m['phone']) ?>
                        </a>
                        <?php endif; ?>
                     </div>
                     <?php endif; ?>
                  </div>
               </div>
            </div>
            <?php endforeach; ?>
         </div>
         <?php endif; ?>

      </div>
   </section>

   <?php endforeach; ?>
   <?php endif; ?>

   </main>

<?php include __DIR__ . '/includes/footer.php'; ?>

   <?php include __DIR__ . '/includes/scripts.php'; ?>
</body>
</html>
