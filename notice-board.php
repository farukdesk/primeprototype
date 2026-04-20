<?php
require_once __DIR__ . '/includes/config.php';

$notices = [];
try {
    $db = front_db();
    if ($db) {
        $notices = $db->query(
            'SELECT id, title, slug, content, content_type, attachment, attachment_original_name, published_at, created_at
             FROM cms_notices
             WHERE is_published = 1 AND is_approved = 1
             ORDER BY published_at DESC, created_at DESC'
        )->fetchAll();
    }
} catch (Throwable $e) {}

$page_title = 'Notice Board';
?>
<!doctype html>
<html class="no-js" lang="en">

<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <title><?= fh($page_title) ?> – Prime University</title>
   <meta name="description" content="Browse all notices and announcements from Prime University.">
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
<?php include __DIR__ . '/includes/offcanvas.php'; ?>

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
                     <h3 class="it-breadcrumb-title style-2">Notice Board</h3>
                  </div>
                  <div class="it-breadcrumb-list">
                     <ul>
                        <li><a href="<?= fh(SITE_URL) ?>/index.php">Home</a></li>
                        <li><span>Notice Board</span></li>
                     </ul>
                  </div>
               </div>
            </div>
         </div>
      </div>
   </div>
   <!-- breadcrumb-area-end -->

   <!-- notice-board-area-start -->
   <div class="postbox-area pt-120 pb-120">
      <div class="container">

         <div class="row justify-content-center mb-60">
            <div class="col-xl-7 text-center">
               <div class="it-section-title-box">
                  <span class="it-section-subtitle">Notices &amp; Announcements</span>
                  <h3 class="it-section-title">Latest Notices &amp; Announcements</h3>
               </div>
            </div>
         </div>

         <?php if (empty($notices)): ?>
         <div class="row justify-content-center">
            <div class="col-xl-8 text-center py-60">
               <i class="fas fa-bell-slash fa-3x text-muted mb-20"></i>
               <h5 class="text-muted">No notices available at this time.</h5>
               <p class="text-muted">Please check back later for updates.</p>
            </div>
         </div>
         <?php else: ?>

         <div class="row g-4">
            <?php foreach ($notices as $n): ?>

            <?php
            $date_str = '';
            if (!empty($n['published_at'])) {
                $date_str = date('d M, Y', strtotime($n['published_at']));
            } elseif (!empty($n['created_at'])) {
                $date_str = date('d M, Y', strtotime($n['created_at']));
            }
            $excerpt   = mb_strimwidth(strip_tags($n['content']), 0, 100, '…');
            $detail_url = fh(SITE_URL) . '/notice/' . urlencode($n['slug']);
            ?>

            <div class="col-xl-6 col-lg-6">
               <div class="it-blog-item" style="border:1px solid #e8eaf0;border-radius:16px;padding:28px;height:100%;display:flex;flex-direction:column;">

                  <div class="it-blog-meta mb-15 d-flex align-items-center gap-3 flex-wrap">
                     <?php if ($date_str): ?>
                     <span style="font-size:.85rem;color:#6b7280;">
                        <i class="fa-solid fa-calendar-days me-1"></i><?= fh($date_str) ?>
                     </span>
                     <?php endif; ?>
                     <?php if ($n['attachment']): ?>
                     <span style="font-size:.85rem;color:#6b7280;">
                        <i class="fas fa-paperclip me-1"></i>Attachment
                     </span>
                     <?php endif; ?>
                  </div>

                  <h5 class="mb-15" style="flex:1;">
                     <a href="<?= $detail_url ?>"
                        style="color:inherit;text-decoration:none;transition:color .2s;"
                        onmouseover="this.style.color='#e8251a'" onmouseout="this.style.color='inherit'">
                        <?= fh($n['title']) ?>
                     </a>
                  </h5>

                  <?php if ($excerpt): ?>
                  <p class="text-muted mb-20" style="font-size:.9rem;line-height:1.6;"><?= fh($excerpt) ?></p>
                  <?php endif; ?>

                  <div class="mt-auto">
                     <a href="<?= $detail_url ?>" class="it-btn-sm">
                        Read More <i class="fas fa-arrow-right ms-1"></i>
                     </a>
                  </div>

               </div>
            </div>

            <?php endforeach; ?>
         </div><!-- .row -->

         <?php endif; ?>

      </div><!-- .container -->
   </div>
   <!-- notice-board-area-end -->

   </main>

<?php include __DIR__ . '/includes/footer.php'; ?>

   <!-- JS Libraries -->
   <?php include __DIR__ . '/includes/scripts.php'; ?>

</body>
</html>
