<?php
/**
 * General page renderer – renders GrapesJS-built pages.
 * URL: /page.php?slug=page-slug
 */
require_once __DIR__ . '/includes/config.php';

$slug = trim($_GET['slug'] ?? '');
if ($slug === '') { header('Location: index.php'); exit; }

$pg = null;
try {
    $db = front_db();
    if ($db) {
        $st = $db->prepare(
            "SELECT * FROM pages WHERE slug = ? AND category = 'general' AND is_published = 1 LIMIT 1"
        );
        $st->execute([$slug]);
        $pg = $st->fetch() ?: null;
    }
} catch (Throwable $e) {}

if (!$pg) { header('HTTP/1.1 404 Not Found'); include '404.html'; exit; }

$page_title = fh($pg['title']);
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <title><?= $page_title ?> – Prime University</title>
   <meta name="description" content="<?= fh($pg['meta_description'] ?? '') ?>">
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
   <?php if ($pg['gjs_css']): ?>
   <style><?= $pg['gjs_css'] ?></style>
   <?php endif; ?>
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
            <input type="search" name="search-field" value="" placeholder="Search Here" required="">
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

   <?php if ($pg['featured_image'] || $pg['page_heading']): ?>
   <!-- Hero / Banner -->
   <section class="it-slider-area" style="background: linear-gradient(135deg, #002147 0%, #1a3d6e 100%); padding: 80px 0;">
      <?php if ($pg['featured_image']): ?>
      <div style="position:absolute;inset:0;overflow:hidden;">
         <img src="<?= fh(ADMIN_UPLOAD_URL) ?>/pages/<?= fh($pg['featured_image']) ?>"
              style="width:100%;height:100%;object-fit:cover;opacity:.25;" alt="">
      </div>
      <?php endif; ?>
      <div class="container" style="position:relative;">
         <div class="row justify-content-center text-center">
            <div class="col-lg-8">
               <?php if ($pg['page_heading']): ?>
               <h1 style="color:#fff;font-size:clamp(26px,4vw,48px);font-weight:700;margin-bottom:16px;">
                  <?= fh($pg['page_heading']) ?>
               </h1>
               <?php endif; ?>
               <?php if ($pg['page_intro']): ?>
               <p style="color:rgba(255,255,255,.82);font-size:18px;line-height:1.8;">
                  <?= fh($pg['page_intro']) ?>
               </p>
               <?php endif; ?>
            </div>
         </div>
      </div>
   </section>
   <?php endif; ?>

   <!-- GrapesJS Page Content -->
   <section class="pt-80 pb-100">
      <div class="gjs-page-content">
         <?= $pg['gjs_html'] ?: '<div class="container"><p class="text-muted text-center py-5">This page has no content yet.</p></div>' ?>
      </div>
   </section>

   </main>

<?php include __DIR__ . '/includes/footer.php'; ?>

   <script src="/assets/js/jquery.js"></script>
   <script src="/assets/js/bootstrap.bundle.min.js"></script>
   <script src="/assets/js/swiper-bundle.min.js"></script>
   <script src="/assets/js/nice-select.js"></script>
   <script src="/assets/js/slick.min.js"></script>
   <script src="/assets/js/wow.js"></script>
   <script src="/assets/js/magnific-popup.js"></script>
   <script src="/assets/js/parallax.js"></script>
   <script src="/assets/js/isotope-pkgd.js"></script>
   <script src="/assets/js/imagesloaded-pkgd.js"></script>
   <script src="/assets/js/main.js"></script>
</body>
</html>
