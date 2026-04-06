<?php
require_once __DIR__ . '/includes/config.php';

$slug   = trim($_GET['slug'] ?? '');
$id     = (int)($_GET['id']  ?? 0);
$notice = null;

try {
    $db = front_db();
    if ($db && ($slug || $id)) {
        if ($slug) {
            $st = $db->prepare('SELECT * FROM cms_notices WHERE slug = ? AND is_published = 1 AND is_approved = 1 LIMIT 1');
            $st->execute([$slug]);
        } else {
            $st = $db->prepare('SELECT * FROM cms_notices WHERE id = ? AND is_published = 1 AND is_approved = 1 LIMIT 1');
            $st->execute([$id]);
        }
        $notice = $st->fetch();
    }
} catch (Throwable $e) {}

if (!$notice) {
    header('Location: ' . SITE_URL . '/notice-board.php');
    exit;
}

$page_title = $notice['title'];

$pub_date = '';
if (!empty($notice['published_at'])) {
    $pub_date = date('d F, Y', strtotime($notice['published_at']));
} elseif (!empty($notice['created_at'])) {
    $pub_date = date('d F, Y', strtotime($notice['created_at']));
}
?>
<!doctype html>
<html class="no-js" lang="en">

<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <title><?= fh($page_title) ?> – Prime University</title>
   <meta name="description" content="">
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
   <link rel="stylesheet" href="/assets/css/spacing.css">
   <link rel="stylesheet" href="/assets/css/main.css">
<?php include __DIR__ . '/includes/meta-pixel.php'; ?>
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

   <main>

   <?php include __DIR__ . '/includes/news-ticker.php'; ?>

   <!-- breadcrumb-area-start -->
   <div class="it-breadcrumb-area fix it-breadcrumb-style-2 z-index-1" data-background="assets/img/shape/breadcrumb-1-bg.png">
      <img class="it-breadcrumb-shape-1" src="/assets/img/shape/breadcrumb-1-1.png" alt="">
      <img class="it-breadcrumb-shape-3" src="/assets/img/shape/breadcrumb-1-2.png" alt="">
      <div class="container">
         <div class="row align-items-center">
            <div class="col-12">
               <div class="it-breadcrumb-content text-center z-index-1">
                  <div class="it-breadcrumb-title-box">
                     <h3 class="it-breadcrumb-title style-2"><?= fh($notice['title']) ?></h3>
                  </div>
                  <div class="it-breadcrumb-list">
                     <ul>
                        <li><a href="<?= fh(SITE_URL) ?>/index.php">Home</a></li>
                        <li><a href="<?= fh(SITE_URL) ?>/notice-board.php">Notice Board</a></li>
                        <li><span><?= fh(mb_strimwidth($notice['title'], 0, 60, '…')) ?></span></li>
                     </ul>
                  </div>
               </div>
            </div>
         </div>
      </div>
   </div>
   <!-- breadcrumb-area-end -->

   <!-- postbox-area-start -->
   <div class="postbox-area pt-120 pb-120">
      <div class="container">
         <div class="row justify-content-center">
            <div class="col-xl-10">
               <div class="postbox-details-wrapper it-career-details-area">

                  <div class="postbox-content-box">
                     <div class="it-blog-meta mb-20">
                        <?php if ($pub_date): ?>
                        <span>
                           <i class="fa-solid fa-calendar-days me-1"></i>
                           <?= fh($pub_date) ?>
                        </span>
                        <?php endif; ?>
                        <span>
                           <i class="fa-solid fa-bell me-1"></i>
                           Notice
                        </span>
                     </div>

                     <h4 class="it-section-title mb-30"><?= fh($notice['title']) ?></h4>

                     <div class="postbox-dsc">
                        <?php if ($notice['content_type'] === 'html'): ?>
                           <?= $notice['content'] ?>
                        <?php else: ?>
                           <?= nl2br(fh($notice['content'])) ?>
                        <?php endif; ?>
                     </div>

                     <!-- Attachment download -->
                     <?php if ($notice['attachment']): ?>
                     <div class="postbox-attachments mt-40 mb-10">
                        <h5 class="mb-20"><i class="fas fa-paperclip me-2"></i>Attachment</h5>
                        <a href="<?= fh(ADMIN_UPLOAD_URL . '/notices/' . $notice['attachment']) ?>"
                           target="_blank" rel="noopener"
                           class="it-btn-yellow border-radius-100">
                           <span>
                              <span class="text-1">
                                 <i class="fas fa-download me-2"></i>
                                 <?= fh($notice['attachment_original_name'] ?: 'Download Attachment') ?>
                              </span>
                              <span class="text-2">
                                 <i class="fas fa-download me-2"></i>
                                 <?= fh($notice['attachment_original_name'] ?: 'Download Attachment') ?>
                              </span>
                           </span>
                        </a>
                     </div>
                     <?php endif; ?>

                  </div><!-- .postbox-content-box -->

                  <div class="postbox-nav-box mt-60 pt-40" style="border-top:1px solid #e8e8e8;">
                     <a href="<?= fh(SITE_URL) ?>/notice-board.php" class="it-btn-yellow border-radius-100">
                        <span>
                           <span class="text-1">← Back to Notice Board</span>
                           <span class="text-2">← Back to Notice Board</span>
                        </span>
                     </a>
                  </div>

               </div>
            </div><!-- .col -->
         </div><!-- .row -->
      </div><!-- .container -->
   </div>
   <!-- postbox-area-end -->

   </main>

<?php include __DIR__ . '/includes/footer.php'; ?>

   <!-- JS Libraries -->
   <?php include __DIR__ . '/includes/scripts.php'; ?>

</body>
</html>
