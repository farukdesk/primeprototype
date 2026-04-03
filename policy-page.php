<?php
/**
 * Policy page renderer – displays policies & procedures documents.
 * URL: /policy-page.php?slug=page-slug
 */
require_once __DIR__ . '/includes/config.php';

$slug = trim($_GET['slug'] ?? '');
if ($slug === '') { header('Location: index.php'); exit; }

$pg = null;
try {
    $db = front_db();
    if ($db) {
        $st = $db->prepare(
            "SELECT * FROM pages WHERE slug = ? AND category = 'policy' AND is_published = 1 LIMIT 1"
        );
        $st->execute([$slug]);
        $pg = $st->fetch() ?: null;
    }
} catch (Throwable $e) {}

if (!$pg) { header('HTTP/1.1 404 Not Found'); include '404.html'; exit; }

$page_title    = fh($pg['title']);
$policy_type   = $pg['policy_type'] ?? '';
$eff_dt   = $pg['effective_date'] ? DateTime::createFromFormat('Y-m-d', $pg['effective_date']) : null;
$eff_date = $eff_dt ? $eff_dt->format('F j, Y') : null;
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
   <style>
      .policy-hero {
         background: linear-gradient(135deg, #002147 0%, #1a3d6e 100%);
         padding: 70px 0 60px;
         position: relative;
         overflow: hidden;
      }
      .policy-hero::before {
         content: '';
         position: absolute; inset: 0;
         background: url('assets/img/shape/footer-bg-3-1.jpg') center/cover no-repeat;
         opacity: .07;
      }
      .policy-meta-bar {
         background: #fff;
         border-bottom: 3px solid #D21034;
         padding: 14px 0;
         box-shadow: 0 2px 12px rgba(0,33,71,.06);
         position: sticky;
         top: 0;
         z-index: 50;
      }
      .policy-meta-item {
         display: flex; align-items: center; gap: 8px;
         font-size: .875rem; color: #475569;
      }
      .policy-meta-item i { color: #D21034; }
      .policy-body {
         font-family: 'Inter', 'Segoe UI', sans-serif;
         font-size: 16px;
         line-height: 1.85;
         color: #1e293b;
      }
      .policy-body h1, .policy-body h2, .policy-body h3,
      .policy-body h4, .policy-body h5, .policy-body h6 {
         color: #002147;
         margin-top: 2rem;
         margin-bottom: .75rem;
         font-weight: 700;
      }
      .policy-body h2 {
         font-size: 1.35rem;
         border-left: 4px solid #D21034;
         padding-left: 14px;
      }
      .policy-body h3 { font-size: 1.15rem; color: #1a3d6e; }
      .policy-body p  { margin-bottom: 1rem; }
      .policy-body ul, .policy-body ol { margin-bottom: 1rem; padding-left: 1.6rem; }
      .policy-body li { margin-bottom: .35rem; }
      .policy-body a  { color: #2d63e8; text-decoration: underline; }
      .policy-body table {
         width: 100%; border-collapse: collapse; margin-bottom: 1.5rem;
      }
      .policy-body table th {
         background: #002147; color: #fff; padding: 10px 14px;
         font-size: .85rem; text-align: left;
      }
      .policy-body table td { padding: 9px 14px; border-bottom: 1px solid #e8eaf0; font-size: .9rem; }
      .policy-body table tr:nth-child(even) td { background: #f8fafc; }
      .policy-sidebar-card {
         border: none; border-radius: 14px;
         box-shadow: 0 2px 16px rgba(0,33,71,.07);
      }
      .policy-toc a { color: #1a3d6e; text-decoration: none; font-size: .875rem; }
      .policy-toc a:hover { color: #D21034; text-decoration: underline; }
      .policy-toc li { margin-bottom: 6px; padding: 4px 0; border-bottom: 1px dotted #e8eaf0; }
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

   <!-- Hero Banner -->
   <section class="policy-hero">
      <div class="container" style="position:relative;">
         <div class="row justify-content-center text-center">
            <div class="col-lg-8">
               <?php if ($policy_type): ?>
               <span class="it-section-subtitle" style="color:#FFB81C;">
                  <i class="fas fa-file-contract me-1"></i><?= fh($policy_type) ?>
               </span>
               <?php endif; ?>
               <h1 style="color:#fff;font-size:clamp(24px,4vw,44px);font-weight:700;margin-top:10px;">
                  <?= $page_title ?>
               </h1>
               <?php if ($pg['page_intro']): ?>
               <p style="color:rgba(255,255,255,.8);font-size:17px;line-height:1.8;margin-top:12px;">
                  <?= fh($pg['page_intro']) ?>
               </p>
               <?php endif; ?>
            </div>
         </div>
      </div>
   </section>

   <!-- Meta bar -->
   <div class="policy-meta-bar">
      <div class="container">
         <div class="d-flex flex-wrap gap-4 align-items-center">
            <?php if ($policy_type): ?>
            <div class="policy-meta-item">
               <i class="fas fa-tag"></i>
               <strong>Type:</strong> <?= fh($policy_type) ?>
            </div>
            <?php endif; ?>
            <?php if ($eff_date): ?>
            <div class="policy-meta-item">
               <i class="fas fa-calendar-check"></i>
               <strong>Effective:</strong> <?= $eff_date ?>
            </div>
            <?php endif; ?>
            <div class="policy-meta-item ms-auto">
               <i class="fas fa-clock"></i>
               <strong>Updated:</strong> <?= date('M j, Y', strtotime($pg['updated_at'])) ?>
            </div>
         </div>
      </div>
   </div>

   <!-- Main Content Area -->
   <section class="pt-70 pb-100" style="background:#f8fafc;">
      <div class="container">
         <div class="row g-4">

            <!-- Left: Policy Body -->
            <div class="col-lg-8">
               <div class="card" style="border:none;border-radius:14px;box-shadow:0 2px 16px rgba(0,33,71,.07);">
                  <?php if ($pg['featured_image']): ?>
                  <img src="<?= fh(ADMIN_UPLOAD_URL) ?>/pages/<?= fh($pg['featured_image']) ?>"
                       style="width:100%;max-height:400px;object-fit:cover;border-radius:14px 14px 0 0;" alt="">
                  <?php endif; ?>
                  <div class="card-body p-4 p-lg-5">
                     <div class="policy-body">
                        <?php if ($pg['content']): ?>
                           <?= $pg['content'] ?>
                        <?php else: ?>
                        <div class="text-center text-muted py-5">
                           <i class="fas fa-file-alt fa-3x mb-3" style="opacity:.3;"></i>
                           <p>No content has been added to this policy yet.</p>
                        </div>
                        <?php endif; ?>
                     </div>
                  </div>
               </div>
            </div>

            <!-- Right Sidebar -->
            <div class="col-lg-4">

               <!-- Document info card -->
               <div class="card policy-sidebar-card mb-4">
                  <div class="card-body p-4">
                     <h6 class="fw-bold mb-3" style="color:#002147;border-bottom:2px solid #FFB81C;padding-bottom:10px;">
                        <i class="fas fa-info-circle me-2 text-muted"></i>Document Info
                     </h6>
                     <ul class="list-unstyled mb-0" style="font-size:.875rem;">
                        <li class="d-flex gap-2 mb-2">
                           <i class="fas fa-file-contract text-muted mt-1" style="width:16px;"></i>
                           <div><span style="color:#64748b;">Type</span><br>
                           <strong style="color:#1e293b;"><?= $policy_type ? fh($policy_type) : '—' ?></strong></div>
                        </li>
                        <?php if ($eff_date): ?>
                        <li class="d-flex gap-2 mb-2">
                           <i class="fas fa-calendar-alt text-muted mt-1" style="width:16px;"></i>
                           <div><span style="color:#64748b;">Effective Date</span><br>
                           <strong style="color:#1e293b;"><?= $eff_date ?></strong></div>
                        </li>
                        <?php endif; ?>
                        <li class="d-flex gap-2">
                           <i class="fas fa-sync-alt text-muted mt-1" style="width:16px;"></i>
                           <div><span style="color:#64748b;">Last Updated</span><br>
                           <strong style="color:#1e293b;"><?= date('M j, Y', strtotime($pg['updated_at'])) ?></strong></div>
                        </li>
                     </ul>
                  </div>
               </div>

               <!-- Breadcrumb / Back -->
               <div class="card policy-sidebar-card mb-4">
                  <div class="card-body p-4">
                     <h6 class="fw-bold mb-3" style="color:#002147;border-bottom:2px solid #FFB81C;padding-bottom:10px;">
                        <i class="fas fa-link me-2 text-muted"></i>Quick Links
                     </h6>
                     <ul class="list-unstyled mb-0 policy-toc">
                        <li><a href="<?= fh(SITE_URL) ?>"><i class="fas fa-home me-2"></i>Home</a></li>
                        <li><a href="contact-us.html"><i class="fas fa-envelope me-2"></i>Contact Us</a></li>
                        <li><a href="admissions.html"><i class="fas fa-graduation-cap me-2"></i>Admissions</a></li>
                     </ul>
                  </div>
               </div>

               <!-- Share this policy -->
               <div class="card policy-sidebar-card">
                  <div class="card-body p-4">
                     <h6 class="fw-bold mb-3" style="color:#002147;border-bottom:2px solid #FFB81C;padding-bottom:10px;">
                        <i class="fas fa-share-alt me-2 text-muted"></i>Share
                     </h6>
                     <div class="d-flex gap-2 flex-wrap">
                        <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']) ?>"
                           target="_blank" rel="noopener"
                           class="btn btn-sm" style="background:#1877f2;color:#fff;border-radius:8px;">
                           <i class="fab fa-facebook-f me-1"></i>Facebook
                        </a>
                        <a href="https://www.linkedin.com/shareArticle?mini=true&url=<?= urlencode((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']) ?>&title=<?= urlencode($pg['title']) ?>"
                           target="_blank" rel="noopener"
                           class="btn btn-sm" style="background:#0a66c2;color:#fff;border-radius:8px;">
                           <i class="fab fa-linkedin-in me-1"></i>LinkedIn
                        </a>
                        <button onclick="window.print()"
                                class="btn btn-sm btn-outline-secondary" style="border-radius:8px;">
                           <i class="fas fa-print me-1"></i>Print
                        </button>
                     </div>
                  </div>
               </div>

            </div>
         </div>
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
