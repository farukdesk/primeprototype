<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/seo.php';
$slug    = trim($_GET['slug'] ?? '');
$article = null;

if ($slug !== '') {
    try {
        $db = front_db();
        if ($db) {
            $stmt = $db->prepare(
                'SELECT * FROM cms_news
                 WHERE slug = ? AND is_published = 1 AND is_approved = 1
                 LIMIT 1'
            );
            $stmt->execute([$slug]);
            $article = $stmt->fetch();
        }
    } catch (Throwable $e) {
        // silently fall through – article remains null
    }
}

$page_title = $article ? $article['title'] : 'News Not Found';

// Formatted publish date
$pub_date = '';
if ($article && !empty($article['published_at'])) {
    $pub_date = date('d F, Y', strtotime($article['published_at']));
} elseif ($article && !empty($article['created_at'])) {
    $pub_date = date('d F, Y', strtotime($article['created_at']));
}
?>
<!doctype html>
<html class="no-js" lang="en">

<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <meta name="viewport" content="width=device-width, initial-scale=1">
   <link rel="shortcut icon" type="image/x-icon" href="/assets/img/logo/favicon.png">
<?php
$_news_img = !empty($article['featured_image']) ? ADMIN_UPLOAD_URL . '/news/' . $article['featured_image'] : null;
$_news_desc = !empty($article['content']) ? mb_substr(strip_tags($article['content']), 0, 160) : null;
render_seo_meta(
    '/news-detail.php?slug=' . urlencode($slug),
    $page_title,
    $_news_desc,
    $_news_img
); ?>

   <!-- CSS Here -->
   <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
   <link rel="stylesheet" href="/assets/css/font-awesome-pro.css">
   <link rel="stylesheet" href="/assets/css/swiper-bundle.min.css">
   <link rel="stylesheet" href="/assets/css/slick.css">
   <link rel="stylesheet" href="/assets/css/magnific-popup.css">
   <link rel="stylesheet" href="/assets/css/nice-select.css">
   <link rel="stylesheet" href="/assets/css/custom-animation.css">

   <!-- Theme / Main CSS -->
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
   <!-- preloader end  -->

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
                     <h3 class="it-breadcrumb-title style-2">
                        <?= $article ? fh($article['title']) : 'News Not Found' ?>
                     </h3>
                  </div>
                  <div class="it-breadcrumb-list">
                     <ul>
                        <li><a href="<?= fh(SITE_URL) ?>/index.php">Home</a></li>
                        <li><span><?= $article ? 'News Detail' : 'Not Found' ?></span></li>
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
         <div class="row">
            <?php if ($article): ?>
            <div class="col-xl-12">
               <div class="postbox-details-wrapper it-career-details-area">

                  <?php if (!empty($article['featured_image'])): ?>
                  <div class="postbox-thumb-box mb-60">
                     <div class="postbox-main-thumb border-radius-20 mb-35">
                        <img class="w-100"
                             src="<?= fh(ADMIN_UPLOAD_URL . '/news/' . $article['featured_image']) ?>"
                             alt="<?= fh($article['title']) ?>">
                     </div>
                  </div>
                  <?php endif; ?>

                  <div class="postbox-content-box">
                     <div class="it-blog-meta mb-20">
                        <?php if ($pub_date): ?>
                        <span>
                           <i class="fa-solid fa-calendar-days me-1"></i>
                           <?= fh($pub_date) ?>
                        </span>
                        <?php endif; ?>
                        <span>
                           <i class="fa-solid fa-newspaper me-1"></i>
                           Latest News
                        </span>
                     </div>
                     <h4 class="it-section-title mb-30"><?= fh($article['title']) ?></h4>
                     <div class="postbox-dsc">
                        <?php if ($article['content_type'] === 'html'): ?>
                           <?= $article['content'] ?>
                        <?php else: ?>
                           <?= nl2br(fh($article['content'])) ?>
                        <?php endif; ?>
                     </div>

                     <?php
                     // ── Attachments ─────────────────────────────────────────────────────────
                     $attachments = [];
                     try {
                         $db = front_db();
                         if ($db) {
                             $stmt = $db->prepare(
                                 'SELECT * FROM cms_news_attachments WHERE news_id = ? ORDER BY id'
                             );
                             $stmt->execute([$article['id']]);
                             $attachments = $stmt->fetchAll();
                         }
                     } catch (Throwable $e) {}
                     ?>
                     <?php if (!empty($attachments)): ?>
                     <div class="postbox-attachments mt-40 mb-30">
                        <h5 class="mb-20"><i class="fas fa-paperclip me-2"></i>Attachments</h5>
                        <ul class="list-unstyled">
                           <?php foreach ($attachments as $att): ?>
                           <li class="mb-10">
                              <a href="<?= fh(ADMIN_UPLOAD_URL . '/news/' . $att['stored_name']) ?>"
                                 target="_blank" rel="noopener">
                                 <i class="fas fa-download me-2"></i><?= fh($att['original_name']) ?>
                              </a>
                           </li>
                           <?php endforeach; ?>
                        </ul>
                     </div>
                     <?php endif; ?>

                  </div><!-- .postbox-content-box -->

                  <div class="postbox-nav-box mt-60 pt-40" style="border-top:1px solid #e8e8e8;">
                     <a href="<?= fh(SITE_URL) ?>/index.php" class="it-btn-yellow border-radius-100">
                        <span>
                           <span class="text-1">← Back to Home</span>
                           <span class="text-2">← Back to Home</span>
                        </span>
                     </a>
                  </div>

               </div>
            </div><!-- .col -->

            <?php else: ?>

            <div class="col-xl-12">
               <div class="text-center py-80">
                  <h3 class="mb-20">Article Not Found</h3>
                  <p class="mb-30">The news article you are looking for does not exist or is no longer available.</p>
                  <a href="<?= fh(SITE_URL) ?>/index.php" class="it-btn-yellow border-radius-100">
                     <span>
                        <span class="text-1">← Back to Home</span>
                        <span class="text-2">← Back to Home</span>
                     </span>
                  </a>
               </div>
            </div>

            <?php endif; ?>

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
