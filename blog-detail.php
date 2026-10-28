<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/seo.php';
require_once __DIR__ . '/includes/captcha-helpers.php';

$slug = trim($_GET['slug'] ?? '');
$post = null;

if ($slug !== '') {
    try {
        $db = front_db();
        if ($db) {
            $stmt = $db->prepare(
                "SELECT p.*, c.name AS category_name, c.slug AS category_slug
                 FROM blog_posts p
                 LEFT JOIN blog_categories c ON c.id = p.category_id
                 WHERE p.slug = ? AND p.status = 'published' AND p.published_at <= NOW()
                 LIMIT 1"
            );
            $stmt->execute([$slug]);
            $post = $stmt->fetch() ?: null;
        }
    } catch (Throwable $e) {
        // post remains null
    }
}

// ── Comment submission ────────────────────────────────────────────────────────
$comment_errors  = [];
$comment_success = false;

if ($post && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comment_submit'])) {
    $author_name  = trim($_POST['author_name'] ?? '');
    $author_email = trim($_POST['author_email'] ?? '');
    $content      = trim($_POST['comment'] ?? '');
    $honeypot     = trim($_POST['website'] ?? ''); // hidden anti-bot field

    if (!$post['allow_comments']) {
        $comment_errors[] = 'Comments are closed for this post.';
    } elseif ($honeypot !== '') {
        // Bot – silently pretend it succeeded.
        $comment_success = true;
    } else {
        if ($author_name === '' || mb_strlen($author_name) > 190) {
            $comment_errors[] = 'Please enter your name.';
        }
        if ($author_email !== '' && !filter_var($author_email, FILTER_VALIDATE_EMAIL)) {
            $comment_errors[] = 'Please enter a valid email address (or leave it blank).';
        }
        if ($content === '' || mb_strlen($content) > 3000) {
            $comment_errors[] = 'Please write a comment (max 3000 characters).';
        }
        if (!captcha_verify_request()) {
            $comment_errors[] = 'CAPTCHA verification failed. Please try again.';
        }

        if (empty($comment_errors)) {
            try {
                $db = front_db();
                if ($db) {
                    $db->prepare(
                        'INSERT INTO blog_comments (post_id, author_name, author_email, content, status, ip_address)
                         VALUES (?,?,?,?,\'pending\',?)'
                    )->execute([
                        $post['id'], $author_name, $author_email !== '' ? $author_email : null,
                        $content, substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
                    ]);
                    $comment_success = true;
                }
            } catch (Throwable $e) {
                $comment_errors[] = 'Sorry, your comment could not be saved. Please try again later.';
            }
        }
    }
}

// ── Increment view counter (GET only) ────────────────────────────────────────
if ($post && $_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        front_db()?->prepare('UPDATE blog_posts SET views = views + 1 WHERE id = ?')
            ->execute([$post['id']]);
    } catch (Throwable $e) {}
}

// ── Approved comments / recent posts / categories ────────────────────────────
$comments     = [];
$recent_posts = [];
$categories   = [];
if ($post) {
    try {
        $db = front_db();
        if ($db) {
            $st = $db->prepare(
                "SELECT author_name, content, created_at
                 FROM blog_comments
                 WHERE post_id = ? AND status = 'approved'
                 ORDER BY created_at ASC"
            );
            $st->execute([$post['id']]);
            $comments = $st->fetchAll();

            $st = $db->prepare(
                "SELECT title, slug, featured_image, published_at
                 FROM blog_posts
                 WHERE status = 'published' AND published_at <= NOW() AND id != ?
                 ORDER BY published_at DESC LIMIT 5"
            );
            $st->execute([$post['id']]);
            $recent_posts = $st->fetchAll();

            $categories = $db->query(
                "SELECT c.name, c.slug, (SELECT COUNT(*) FROM blog_posts p
                       WHERE p.category_id = c.id AND p.status = 'published'
                         AND p.published_at <= NOW()) AS post_count
                 FROM blog_categories c WHERE c.is_active = 1
                 ORDER BY c.sort_order, c.name"
            )->fetchAll();
        }
    } catch (Throwable $e) {}
}

$page_title = $post ? ($post['meta_title'] ?: $post['title']) : 'Post Not Found';

$pub_date = '';
if ($post && !empty($post['published_at'])) {
    $pub_date = date('d F, Y', strtotime($post['published_at']));
}

$tags = [];
if ($post && !empty($post['tags'])) {
    $tags = array_filter(array_map('trim', explode(',', $post['tags'])));
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
$_blog_img  = !empty($post['featured_image']) ? ADMIN_UPLOAD_URL . '/blog/' . $post['featured_image'] : null;
$_blog_desc = null;
if ($post) {
    $_blog_desc = $post['meta_description']
        ?: ($post['excerpt'] ?: mb_substr(strip_tags((string)$post['content']), 0, 160));
}
render_seo_meta(
    '/blog-detail.php?slug=' . urlencode($slug),
    $page_title,
    $_blog_desc,
    $_blog_img
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
<?php captcha_render_script(); ?>
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
                     <h3 class="it-breadcrumb-title style-2">
                        <?= $post ? fh($post['title']) : 'Post Not Found' ?>
                     </h3>
                  </div>
                  <div class="it-breadcrumb-list">
                     <ul>
                        <li><a href="<?= fh(SITE_URL) ?>/index.php">Home</a></li>
                        <li><a href="/blog.php">Blog</a></li>
                        <li><span><?= $post ? 'Post' : 'Not Found' ?></span></li>
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
            <?php if ($post): ?>

            <div class="col-xl-8 col-lg-8">
               <div class="postbox-details-wrapper it-career-details-area">

                  <?php if (!empty($post['featured_image'])): ?>
                  <div class="postbox-thumb-box mb-40">
                     <div class="postbox-main-thumb border-radius-20">
                        <img class="w-100"
                             src="<?= fh(ADMIN_UPLOAD_URL . '/blog/' . $post['featured_image']) ?>"
                             alt="<?= fh($post['title']) ?>">
                     </div>
                  </div>
                  <?php endif; ?>

                  <div class="postbox-content-box">
                     <div class="it-blog-meta mb-20">
                        <?php if ($pub_date): ?>
                        <span class="me-3">
                           <i class="fa-solid fa-calendar-days me-1"></i>
                           <?= fh($pub_date) ?>
                        </span>
                        <?php endif; ?>
                        <?php if (!empty($post['category_name'])): ?>
                        <a href="/blog.php?category=<?= urlencode($post['category_slug']) ?>" class="me-3">
                           <i class="fa-solid fa-folder me-1"></i><?= fh($post['category_name']) ?>
                        </a>
                        <?php endif; ?>
                        <span>
                           <i class="fa-solid fa-eye me-1"></i><?= (int)$post['views'] ?> views
                        </span>
                     </div>
                     <h4 class="it-section-title mb-30"><?= fh($post['title']) ?></h4>
                     <div class="postbox-dsc">
                        <?php if ($post['content_type'] === 'html'): ?>
                           <?= $post['content'] ?>
                        <?php else: ?>
                           <?= nl2br(fh($post['content'])) ?>
                        <?php endif; ?>
                     </div>

                     <?php if (!empty($tags)): ?>
                     <div class="postbox-tags mt-40">
                        <?php foreach ($tags as $tag): ?>
                        <span style="display:inline-block;background:#f0f3f8;border-radius:30px;
                                     padding:6px 16px;margin:0 6px 8px 0;font-size:.85rem;">
                           #<?= fh($tag) ?>
                        </span>
                        <?php endforeach; ?>
                     </div>
                     <?php endif; ?>
                  </div><!-- .postbox-content-box -->

                  <!-- Comments -->
                  <div class="postbox-comments mt-60 pt-40" style="border-top:1px solid #e8e8e8;">
                     <h5 class="mb-30">
                        <i class="fa-regular fa-comments me-2"></i>
                        Comments (<?= count($comments) ?>)
                     </h5>

                     <?php if (empty($comments)): ?>
                     <p style="color:#8a93a6;">No comments yet. Be the first to share your thoughts!</p>
                     <?php else: ?>
                     <?php foreach ($comments as $cm): ?>
                     <div class="mb-25" style="background:#f6f8fb;border-radius:14px;padding:20px 24px;">
                        <div class="d-flex justify-content-between align-items-center mb-10">
                           <strong><?= fh($cm['author_name']) ?></strong>
                           <span style="font-size:.8rem;color:#8a93a6;">
                              <?= date('d M, Y', strtotime($cm['created_at'])) ?>
                           </span>
                        </div>
                        <p class="mb-0" style="font-size:.95rem;"><?= nl2br(fh($cm['content'])) ?></p>
                     </div>
                     <?php endforeach; ?>
                     <?php endif; ?>

                     <!-- Comment form -->
                     <?php if ($post['allow_comments']): ?>
                     <div class="mt-40">
                        <h5 class="mb-20">Leave a Comment</h5>

                        <?php if ($comment_success): ?>
                        <div class="alert alert-success" style="border-radius:12px;">
                           Thank you! Your comment has been submitted and is awaiting moderation.
                        </div>
                        <?php endif; ?>

                        <?php if ($comment_errors): ?>
                        <div class="alert alert-danger" style="border-radius:12px;">
                           <ul class="mb-0 ps-3">
                              <?php foreach ($comment_errors as $e): ?><li><?= fh($e) ?></li><?php endforeach; ?>
                           </ul>
                        </div>
                        <?php endif; ?>

                        <?php if (!$comment_success): ?>
                        <form method="POST" action="/blog-detail.php?slug=<?= urlencode($slug) ?>#comment-form" id="comment-form">
                           <input type="hidden" name="comment_submit" value="1">
                           <!-- Honeypot: humans never see or fill this -->
                           <input type="text" name="website" value="" tabindex="-1" autocomplete="off"
                                  style="position:absolute;left:-9999px;" aria-hidden="true">
                           <div class="row g-3">
                              <div class="col-md-6">
                                 <input type="text" name="author_name" required maxlength="190"
                                        value="<?= fh($_POST['author_name'] ?? '') ?>" placeholder="Your Name *"
                                        style="width:100%;border:1px solid #e3e6ec;border-radius:10px;padding:12px 16px;">
                              </div>
                              <div class="col-md-6">
                                 <input type="email" name="author_email" maxlength="190"
                                        value="<?= fh($_POST['author_email'] ?? '') ?>" placeholder="Your Email (optional)"
                                        style="width:100%;border:1px solid #e3e6ec;border-radius:10px;padding:12px 16px;">
                              </div>
                              <div class="col-12">
                                 <textarea name="comment" required rows="5" maxlength="3000"
                                           placeholder="Your comment… *"
                                           style="width:100%;border:1px solid #e3e6ec;border-radius:10px;padding:12px 16px;"><?= fh($_POST['comment'] ?? '') ?></textarea>
                              </div>
                              <div class="col-12">
                                 <?php captcha_render_widget(); ?>
                              </div>
                              <div class="col-12">
                                 <button type="submit" class="it-btn-yellow border-radius-100">
                                    <span>
                                       <span class="text-1">Submit Comment</span>
                                       <span class="text-2">Submit Comment</span>
                                    </span>
                                 </button>
                              </div>
                           </div>
                        </form>
                        <?php endif; ?>
                     </div>
                     <?php else: ?>
                     <p class="mt-30" style="color:#8a93a6;"><i class="fa-solid fa-lock me-1"></i>Comments are closed for this post.</p>
                     <?php endif; ?>
                  </div>

                  <div class="postbox-nav-box mt-60 pt-40" style="border-top:1px solid #e8e8e8;">
                     <a href="/blog.php" class="it-btn-yellow border-radius-100">
                        <span>
                           <span class="text-1">← Back to Blog</span>
                           <span class="text-2">← Back to Blog</span>
                        </span>
                     </a>
                  </div>

               </div>
            </div><!-- .col -->

            <!-- Sidebar -->
            <div class="col-xl-4 col-lg-4">
               <div class="sidebar-wrapper" style="padding-left:20px;">

                  <?php if (!empty($recent_posts)): ?>
                  <div class="sidebar-widget mb-40" style="background:#f6f8fb;border-radius:16px;padding:28px;">
                     <h5 class="mb-20">Recent Posts</h5>
                     <?php foreach ($recent_posts as $rp): ?>
                     <div class="d-flex gap-3 mb-20">
                        <a href="/blog-detail.php?slug=<?= urlencode($rp['slug']) ?>" class="flex-shrink-0">
                           <?php if (!empty($rp['featured_image'])): ?>
                           <img src="<?= fh(ADMIN_UPLOAD_URL . '/blog/' . $rp['featured_image']) ?>" alt=""
                                style="width:70px;height:70px;object-fit:cover;border-radius:10px;">
                           <?php else: ?>
                           <div style="width:70px;height:70px;border-radius:10px;background:#e8ecf3;
                                       display:flex;align-items:center;justify-content:center;color:#b6bdcc;">
                              <i class="fa-solid fa-blog"></i>
                           </div>
                           <?php endif; ?>
                        </a>
                        <div>
                           <a href="/blog-detail.php?slug=<?= urlencode($rp['slug']) ?>"
                              style="font-weight:600;font-size:.9rem;line-height:1.4;">
                              <?= fh(mb_substr($rp['title'], 0, 70)) ?>
                           </a>
                           <div style="font-size:.78rem;color:#8a93a6;margin-top:4px;">
                              <i class="fa-solid fa-calendar-days me-1"></i>
                              <?= $rp['published_at'] ? date('d M, Y', strtotime($rp['published_at'])) : '' ?>
                           </div>
                        </div>
                     </div>
                     <?php endforeach; ?>
                  </div>
                  <?php endif; ?>

                  <?php if (!empty($categories)): ?>
                  <div class="sidebar-widget mb-40" style="background:#f6f8fb;border-radius:16px;padding:28px;">
                     <h5 class="mb-20">Categories</h5>
                     <ul style="list-style:none;padding:0;margin:0;">
                        <?php foreach ($categories as $c): ?>
                        <li style="padding:8px 0;border-bottom:1px solid #e9edf3;display:flex;justify-content:space-between;">
                           <a href="/blog.php?category=<?= urlencode($c['slug']) ?>"><?= fh($c['name']) ?></a>
                           <span style="color:#8a93a6;font-size:.85rem;">(<?= (int)$c['post_count'] ?>)</span>
                        </li>
                        <?php endforeach; ?>
                     </ul>
                  </div>
                  <?php endif; ?>

               </div>
            </div>

            <?php else: ?>

            <div class="col-xl-12">
               <div class="text-center py-80">
                  <h3 class="mb-20">Post Not Found</h3>
                  <p class="mb-30">The blog post you are looking for does not exist or is no longer available.</p>
                  <a href="/blog.php" class="it-btn-yellow border-radius-100">
                     <span>
                        <span class="text-1">← Back to Blog</span>
                        <span class="text-2">← Back to Blog</span>
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
