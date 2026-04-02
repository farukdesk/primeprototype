<?php
/**
 * Profile page renderer – displays a leadership / governance listing.
 * Styled after board-of-trustees.html.
 * URL: /profile-page.php?slug=page-slug
 */
require_once __DIR__ . '/includes/config.php';

$slug = trim($_GET['slug'] ?? '');
if ($slug === '') { header('Location: index.php'); exit; }

$pg       = null;
$profiles = [];
try {
    $db = front_db();
    if ($db) {
        $st = $db->prepare(
            "SELECT * FROM pages WHERE slug = ? AND category = 'profile' AND is_published = 1 LIMIT 1"
        );
        $st->execute([$slug]);
        $pg = $st->fetch() ?: null;

        if ($pg) {
            $st2 = $db->prepare(
                'SELECT * FROM page_profiles WHERE page_id = ? ORDER BY is_featured DESC, sort_order ASC, id ASC'
            );
            $st2->execute([$pg['id']]);
            $profiles = $st2->fetchAll();
        }
    }
} catch (Throwable $e) {}

if (!$pg) { header('HTTP/1.1 404 Not Found'); include '404.html'; exit; }

// Separate featured (chairman-style) from regular members in one pass
$featured_members = [];
$regular_members  = [];
foreach ($profiles as $p) {
    if ((int)$p['is_featured'] === 1) {
        $featured_members[] = $p;
    } else {
        $regular_members[] = $p;
    }
}

$page_title = fh($pg['title']);
$subtitle   = fh($pg['profile_subtitle'] ?? 'Leadership');
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <title><?= $page_title ?> – Prime University</title>
   <meta name="description" content="<?= fh($pg['meta_description'] ?? '') ?>">
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
   <style>
      .pp-featured-img {
         width: 160px; height: 160px;
         object-fit: cover; border-radius: 50%;
         border: 5px solid #FFB81C;
         box-shadow: 0 8px 25px rgba(0,33,71,.2);
      }
      .pp-member-img {
         width: 120px; height: 120px;
         object-fit: cover; border-radius: 50%;
         border: 4px solid #F8FAFC;
         box-shadow: 0 4px 15px rgba(0,33,71,.15);
         background: #E8EEF4;
      }
      .pp-featured-card {
         border: none !important;
         border-top: 4px solid #FFB81C !important;
      }
      .pp-member-card { border: none !important; }
      .pp-designation { color: #D21034; font-weight: 600; }
      .pp-hero {
         background: linear-gradient(135deg, #002147 0%, #1a3d6e 100%);
         padding: 80px 0 60px;
         position: relative;
         overflow: hidden;
      }
      .pp-hero::before {
         content: '';
         position: absolute; inset: 0;
         background: url('assets/img/shape/footer-bg-3-1.jpg') center/cover no-repeat;
         opacity: .08;
      }
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
               <img src="assets/img/logo/logo-black.png" alt="">
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

   <!-- Hero Banner -->
   <section class="pp-hero">
      <div class="container" style="position:relative;">
         <div class="row justify-content-center text-center">
            <div class="col-lg-7">
               <span class="it-section-subtitle" style="color:#FFB81C;">
                  <i class="fas fa-crown me-1"></i>
                  <?= $subtitle ?>
               </span>
               <h1 class="it-slider-title" style="color:#fff;font-size:clamp(26px,4vw,46px);font-weight:700;margin-top:10px;">
                  <?= $page_title ?>
               </h1>
               <?php if ($pg['page_intro']): ?>
               <p style="color:rgba(255,255,255,.8);font-size:17px;line-height:1.8;margin-top:14px;">
                  <?= fh($pg['page_intro']) ?>
               </p>
               <?php endif; ?>
            </div>
         </div>
      </div>
   </section>

   <?php if (!empty($featured_members)): ?>
   <!-- Featured Members (Chairman-style) -->
   <section class="pt-80 pb-60" style="background-color:#fff;">
      <div class="container">
         <?php if (count($featured_members) === 1): ?>
         <!-- Single featured member: centred large card -->
         <?php foreach ($featured_members as $fm): ?>
         <div class="row justify-content-center mb-20">
            <div class="col-12 text-center mb-50">
               <span class="it-section-subtitle" style="color:#D21034;">
                  <i class="fas fa-crown me-1"></i><?= $subtitle ?>
               </span>
               <h4 class="it-section-title" style="color:#002147;"><?= fh($fm['designation'] ?? $page_title) ?></h4>
            </div>
         </div>
         <div class="row justify-content-center">
            <div class="col-xl-5 col-lg-6 wow itfadeUp" data-wow-duration=".9s" data-wow-delay=".3s">
               <div class="card pp-featured-card shadow-sm text-center">
                  <div class="card-body p-50">
                     <div class="mb-25">
                        <?php if ($fm['photo']): ?>
                        <img src="<?= fh(ADMIN_UPLOAD_URL) ?>/pages/profiles/<?= fh($fm['photo']) ?>"
                             alt="<?= fh($fm['full_name']) ?>" class="pp-featured-img">
                        <?php else: ?>
                        <div class="pp-featured-img d-inline-flex align-items-center justify-content-center"
                             style="background:#E8EEF4;font-size:3rem;color:#002147;">
                           <i class="fas fa-user"></i>
                        </div>
                        <?php endif; ?>
                     </div>
                     <h4 style="color:#002147;font-weight:700;margin-bottom:8px;"><?= fh($fm['full_name']) ?></h4>
                     <?php if ($fm['designation']): ?>
                     <p class="pp-designation" style="font-size:15px;margin-bottom:0;"><?= fh($fm['designation']) ?></p>
                     <?php endif; ?>
                     <?php if ($fm['bio']): ?>
                     <p style="color:#475569;font-size:.9rem;margin-top:14px;line-height:1.7;"><?= fh($fm['bio']) ?></p>
                     <?php endif; ?>
                     <?php if ($fm['email'] || $fm['phone']): ?>
                     <div class="mt-3 d-flex gap-3 justify-content-center" style="font-size:.85rem;color:#64748b;">
                        <?php if ($fm['email']): ?>
                        <a href="mailto:<?= fh($fm['email']) ?>" style="color:#4f8ef7;">
                           <i class="fas fa-envelope me-1"></i><?= fh($fm['email']) ?>
                        </a>
                        <?php endif; ?>
                        <?php if ($fm['phone']): ?>
                        <a href="tel:<?= fh(preg_replace('/[^0-9+]/','',$fm['phone'])) ?>" style="color:#4f8ef7;">
                           <i class="fas fa-phone me-1"></i><?= fh($fm['phone']) ?>
                        </a>
                        <?php endif; ?>
                     </div>
                     <?php endif; ?>
                  </div>
               </div>
            </div>
         </div>
         <?php endforeach; ?>
         <?php else: ?>
         <!-- Multiple featured members: row of cards -->
         <div class="row justify-content-center mb-20">
            <div class="col-12 text-center mb-50">
               <span class="it-section-subtitle" style="color:#D21034;">
                  <i class="fas fa-crown me-1"></i><?= $subtitle ?>
               </span>
               <h4 class="it-section-title" style="color:#002147;">Featured Members</h4>
            </div>
         </div>
         <div class="row g-4 justify-content-center">
            <?php foreach ($featured_members as $fm): ?>
            <div class="col-xl-4 col-lg-5 col-md-6 wow itfadeUp" data-wow-duration=".9s" data-wow-delay=".3s">
               <div class="card pp-featured-card shadow-sm text-center">
                  <div class="card-body p-40">
                     <div class="mb-20">
                        <?php if ($fm['photo']): ?>
                        <img src="<?= fh(ADMIN_UPLOAD_URL) ?>/pages/profiles/<?= fh($fm['photo']) ?>"
                             alt="<?= fh($fm['full_name']) ?>" class="pp-featured-img">
                        <?php else: ?>
                        <div class="pp-featured-img d-inline-flex align-items-center justify-content-center"
                             style="background:#E8EEF4;font-size:2.5rem;color:#002147;">
                           <i class="fas fa-user"></i>
                        </div>
                        <?php endif; ?>
                     </div>
                     <h5 style="color:#002147;font-weight:700;margin-bottom:8px;"><?= fh($fm['full_name']) ?></h5>
                     <?php if ($fm['designation']): ?>
                     <p class="pp-designation" style="font-size:14px;margin-bottom:0;"><?= fh($fm['designation']) ?></p>
                     <?php endif; ?>
                  </div>
               </div>
            </div>
            <?php endforeach; ?>
         </div>
         <?php endif; ?>
      </div>
   </section>
   <?php endif; ?>

   <?php if (!empty($regular_members)): ?>
   <!-- Regular Members Grid -->
   <section class="pt-60 pb-100" style="background-color:#F8FAFC;">
      <div class="container">
         <div class="row justify-content-center">
            <div class="col-12 text-center mb-60">
               <span class="it-section-subtitle" style="color:#D21034;">
                  <i class="fas fa-users me-1"></i>
                  Members
               </span>
               <h4 class="it-section-title" style="color:#002147;"><?= fh($pg['page_heading'] ?: $pg['title']) ?></h4>
               <?php if ($pg['page_intro']): ?>
               <p style="color:#475569;font-size:16px;max-width:650px;margin:14px auto 0;line-height:1.7;">
                  <?= fh($pg['page_intro']) ?>
               </p>
               <?php endif; ?>
            </div>
         </div>
         <div class="row g-4">
            <?php
            $delays = ['.3s','.4s','.5s'];
            $i = 0;
            foreach ($regular_members as $m):
            $delay = $delays[$i % 3];
            $i++;
            ?>
            <div class="col-xl-4 col-lg-4 col-md-6 wow itfadeUp" data-wow-duration=".9s" data-wow-delay="<?= $delay ?>">
               <div class="card pp-member-card h-100 shadow-sm text-center" style="background:#fff;">
                  <div class="card-body p-30">
                     <div class="mb-20">
                        <?php if ($m['photo']): ?>
                        <img src="<?= fh(ADMIN_UPLOAD_URL) ?>/pages/profiles/<?= fh($m['photo']) ?>"
                             alt="<?= fh($m['full_name']) ?>" class="pp-member-img">
                        <?php else: ?>
                        <div class="pp-member-img d-inline-flex align-items-center justify-content-center"
                             style="background:#E8EEF4;font-size:2rem;color:#002147;">
                           <i class="fas fa-user"></i>
                        </div>
                        <?php endif; ?>
                     </div>
                     <h6 style="color:#002147;font-weight:700;margin-bottom:5px;"><?= fh($m['full_name']) ?></h6>
                     <?php if ($m['designation']): ?>
                     <p class="pp-designation" style="font-size:13px;margin-bottom:0;"><?= fh($m['designation']) ?></p>
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
                        <a href="tel:<?= fh(preg_replace('/[^0-9+]/','',$m['phone'])) ?>" style="color:#4f8ef7;display:block;margin-top:3px;">
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
      </div>
   </section>
   <?php endif; ?>

   <?php if (empty($profiles)): ?>
   <section class="pt-100 pb-100">
      <div class="container text-center">
         <i class="fas fa-users fa-3x" style="color:#cbd5e1;margin-bottom:20px;"></i>
         <h4 style="color:#64748b;">No members have been added to this page yet.</h4>
      </div>
   </section>
   <?php endif; ?>

   </main>

<?php include __DIR__ . '/includes/footer.php'; ?>

   <script src="assets/js/jquery.js"></script>
   <script src="assets/js/bootstrap.bundle.min.js"></script>
   <script src="assets/js/swiper-bundle.min.js"></script>
   <script src="assets/js/nice-select.js"></script>
   <script src="assets/js/slick.min.js"></script>
   <script src="assets/js/wow.js"></script>
   <script src="assets/js/magnific-popup.js"></script>
   <script src="assets/js/parallax.js"></script>
   <script src="assets/js/main.js"></script>
</body>
</html>
