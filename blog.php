<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/seo.php';

$page_title = 'Blog';

// ── Filters ───────────────────────────────────────────────────────────────────
$cat_slug = trim($_GET['category'] ?? '');
$q        = trim($_GET['q'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 9;

$posts       = [];
$categories  = [];
$active_cat  = null;
$total_posts = 0;

try {
    $db = front_db();
    if ($db) {
        // Categories (active, with published-post counts)
        $categories = $db->query(
            "SELECT c.*, (SELECT COUNT(*) FROM blog_posts p
                           WHERE p.category_id = c.id AND p.status = 'published'
                             AND p.published_at <= NOW()) AS post_count
             FROM blog_categories c
             WHERE c.is_active = 1
             ORDER BY c.sort_order, c.name"
        )->fetchAll();

        $where  = ["p.status = 'published'", 'p.published_at <= NOW()'];
        $params = [];

        if ($cat_slug !== '') {
            foreach ($categories as $c) {
                if ($c['slug'] === $cat_slug) { $active_cat = $c; break; }
            }
            if ($active_cat) {
                $where[]  = 'p.category_id = ?';
                $params[] = $active_cat['id'];
            }
        }
        if ($q !== '') {
            $where[]  = '(p.title LIKE ? OR p.excerpt LIKE ? OR p.tags LIKE ?)';
            $like     = '%' . $q . '%';
            array_push($params, $like, $like, $like);
        }

        $where_sql = implode(' AND ', $where);

        $st = $db->prepare("SELECT COUNT(*) FROM blog_posts p WHERE {$where_sql}");
        $st->execute($params);
        $total_posts = (int)$st->fetchColumn();

        $offset = ($page - 1) * $per_page;
        $st = $db->prepare(
            "SELECT p.id, p.title, p.slug, p.excerpt, p.content, p.content_type,
                    p.featured_image, p.published_at, c.name AS category_name, c.slug AS category_slug
             FROM blog_posts p
             LEFT JOIN blog_categories c ON c.id = p.category_id
             WHERE {$where_sql}
             ORDER BY p.published_at DESC
             LIMIT {$per_page} OFFSET {$offset}"
        );
        $st->execute($params);
        $posts = $st->fetchAll();
    }
} catch (Throwable $e) {
    // Tables may not exist yet – page renders empty.
}

$total_pages = max(1, (int)ceil($total_posts / $per_page));

if ($active_cat) $page_title = $active_cat['name'] . ' – Blog';

function blog_list_excerpt(array $p): string
{
    if (!empty($p['excerpt'])) return $p['excerpt'];
    $text = strip_tags((string)($p['content'] ?? ''));
    return mb_substr(trim(preg_replace('/\s+/', ' ', $text)), 0, 140);
}

function blog_page_url(int $page_no, string $cat_slug, string $q): string
{
    $qs = array_filter(['category' => $cat_slug ?: null, 'q' => $q ?: null, 'page' => $page_no > 1 ? $page_no : null]);
    return '/blog.php' . ($qs ? '?' . http_build_query($qs) : '');
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
render_seo_meta(
    '/blog.php',
    $page_title,
    'Read the latest articles, stories and insights on the Prime University blog.',
    null
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
                        <?= $active_cat ? fh($active_cat['name']) : 'Blog' ?>
                     </h3>
                  </div>
                  <div class="it-breadcrumb-list">
                     <ul>
                        <li><a href="<?= fh(SITE_URL) ?>/index.php">Home</a></li>
                        <li><span>Blog</span></li>
                     </ul>
                  </div>
               </div>
            </div>
         </div>
      </div>
   </div>
   <!-- breadcrumb-area-end -->

   <!-- blog-area-start -->
   <div class="postbox-area pt-120 pb-120">
      <div class="container">
         <div class="row">

            <!-- Posts -->
            <div class="col-xl-8 col-lg-8">

               <?php if (empty($posts)): ?>
               <div class="text-center py-80">
                  <h3 class="mb-20">No posts found</h3>
                  <p class="mb-30">
                     <?= ($q !== '' || $active_cat)
                        ? 'No blog posts match your filter. Try clearing the search or choosing another category.'
                        : 'There are no blog posts yet. Please check back soon.' ?>
                  </p>
                  <?php if ($q !== '' || $active_cat): ?>
                  <a href="/blog.php" class="it-btn-yellow border-radius-100">
                     <span>
                        <span class="text-1">View All Posts</span>
                        <span class="text-2">View All Posts</span>
                     </span>
                  </a>
                  <?php endif; ?>
               </div>
               <?php else: ?>

               <div class="row">
                  <?php foreach ($posts as $p): ?>
                  <div class="col-md-6 mb-40">
                     <div class="it-blog-item" style="height:100%;border:1px solid #eee;border-radius:16px;overflow:hidden;background:#fff;">
                        <a href="/blog-detail.php?slug=<?= urlencode($p['slug']) ?>" class="d-block">
                           <?php if (!empty($p['featured_image'])): ?>
                           <img src="<?= fh(ADMIN_UPLOAD_URL . '/blog/' . $p['featured_image']) ?>"
                                alt="<?= fh($p['title']) ?>"
                                style="width:100%;height:210px;object-fit:cover;">
                           <?php else: ?>
                           <div style="width:100%;height:210px;background:#eef1f6;display:flex;align-items:center;justify-content:center;color:#b6bdcc;">
                              <i class="fa-solid fa-blog fa-2x"></i>
                           </div>
                           <?php endif; ?>
                        </a>
                        <div class="p-4">
                           <div class="it-blog-meta mb-10" style="font-size:.85rem;color:#8a93a6;">
                              <span class="me-3">
                                 <i class="fa-solid fa-calendar-days me-1"></i>
                                 <?= $p['published_at'] ? date('d M, Y', strtotime($p['published_at'])) : '' ?>
                              </span>
                              <?php if (!empty($p['category_name'])): ?>
                              <a href="/blog.php?category=<?= urlencode($p['category_slug']) ?>" style="color:#8a93a6;">
                                 <i class="fa-solid fa-folder me-1"></i><?= fh($p['category_name']) ?>
                              </a>
                              <?php endif; ?>
                           </div>
                           <h5 class="mb-15" style="line-height:1.4;">
                              <a href="/blog-detail.php?slug=<?= urlencode($p['slug']) ?>">
                                 <?= fh($p['title']) ?>
                              </a>
                           </h5>
                           <p style="font-size:.92rem;color:#5f6b7d;"><?= fh(blog_list_excerpt($p)) ?>…</p>
                           <a href="/blog-detail.php?slug=<?= urlencode($p['slug']) ?>"
                              style="font-weight:600;font-size:.9rem;">
                              Read More <i class="fa-regular fa-arrow-right ms-1"></i>
                           </a>
                        </div>
                     </div>
                  </div>
                  <?php endforeach; ?>
               </div>

               <!-- Pagination -->
               <?php if ($total_pages > 1): ?>
               <div class="basic-pagination mt-30">
                  <nav>
                     <ul style="display:flex;gap:8px;list-style:none;padding:0;flex-wrap:wrap;">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li>
                           <a href="<?= fh(blog_page_url($i, $active_cat['slug'] ?? '', $q)) ?>"
                              style="display:inline-flex;align-items:center;justify-content:center;width:42px;height:42px;
                                     border-radius:50%;border:1px solid #e3e6ec;
                                     <?= $i === $page ? 'background:#0a2c61;color:#fff;' : '' ?>">
                              <?= $i ?>
                           </a>
                        </li>
                        <?php endfor; ?>
                     </ul>
                  </nav>
               </div>
               <?php endif; ?>

               <?php endif; ?>
            </div>

            <!-- Sidebar -->
            <div class="col-xl-4 col-lg-4">
               <div class="sidebar-wrapper" style="padding-left:20px;">

                  <!-- Search -->
                  <div class="sidebar-widget mb-40" style="background:#f6f8fb;border-radius:16px;padding:28px;">
                     <h5 class="mb-20">Search</h5>
                     <form method="GET" action="/blog.php">
                        <?php if ($active_cat): ?>
                        <input type="hidden" name="category" value="<?= fh($active_cat['slug']) ?>">
                        <?php endif; ?>
                        <div style="display:flex;gap:8px;">
                           <input type="text" name="q" value="<?= fh($q) ?>" placeholder="Search posts…"
                                  style="flex:1;border:1px solid #e3e6ec;border-radius:10px;padding:10px 14px;background:#fff;">
                           <button type="submit"
                                   style="border:none;background:#0a2c61;color:#fff;border-radius:10px;padding:0 16px;">
                              <i class="fal fa-search"></i>
                           </button>
                        </div>
                     </form>
                  </div>

                  <!-- Categories -->
                  <?php if (!empty($categories)): ?>
                  <div class="sidebar-widget mb-40" style="background:#f6f8fb;border-radius:16px;padding:28px;">
                     <h5 class="mb-20">Categories</h5>
                     <ul style="list-style:none;padding:0;margin:0;">
                        <li style="padding:8px 0;border-bottom:1px solid #e9edf3;">
                           <a href="/blog.php" style="<?= !$active_cat ? 'font-weight:700;' : '' ?>">
                              All Posts
                           </a>
                        </li>
                        <?php foreach ($categories as $c): ?>
                        <li style="padding:8px 0;border-bottom:1px solid #e9edf3;display:flex;justify-content:space-between;">
                           <a href="/blog.php?category=<?= urlencode($c['slug']) ?>"
                              style="<?= $active_cat && $active_cat['id'] === $c['id'] ? 'font-weight:700;' : '' ?>">
                              <?= fh($c['name']) ?>
                           </a>
                           <span style="color:#8a93a6;font-size:.85rem;">(<?= (int)$c['post_count'] ?>)</span>
                        </li>
                        <?php endforeach; ?>
                     </ul>
                  </div>
                  <?php endif; ?>

               </div>
            </div>

         </div><!-- .row -->
      </div><!-- .container -->
   </div>
   <!-- blog-area-end -->

   </main>

<?php include __DIR__ . '/includes/footer.php'; ?>

   <!-- JS Libraries -->
   <?php include __DIR__ . '/includes/scripts.php'; ?>

</body>
</html>
