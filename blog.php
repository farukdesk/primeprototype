<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/seo.php';

$page_title = 'Blog';

// ── Filters ───────────────────────────────────────────────────────────────────
$cat_slug = trim($_GET['category'] ?? '');
$q        = trim($_GET['q'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 8;

$posts        = [];
$pinned       = [];
$categories   = [];
$active_cat   = null;
$total_posts  = 0;
$featured     = null;

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

        // Pinned posts for the right rail
        $pinned = $db->query(
            "SELECT p.title, p.slug, p.featured_image, p.published_at, c.name AS category_name
             FROM blog_posts p
             LEFT JOIN blog_categories c ON c.id = p.category_id
             WHERE p.status = 'published' AND p.published_at <= NOW() AND p.is_pinned = 1
             ORDER BY p.published_at DESC
             LIMIT 6"
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
            "SELECT p.id, p.title, p.slug, p.excerpt, p.content, p.content_type, p.is_pinned,
                    p.featured_image, p.published_at, p.views,
                    c.name AS category_name, c.slug AS category_slug
             FROM blog_posts p
             LEFT JOIN blog_categories c ON c.id = p.category_id
             WHERE {$where_sql}
             ORDER BY p.published_at DESC
             LIMIT {$per_page} OFFSET {$offset}"
        );
        $st->execute($params);
        $posts = $st->fetchAll();

        // Hero feature: newest post, only on the unfiltered first page
        if ($page === 1 && $q === '' && !$active_cat && !empty($posts)) {
            $featured = array_shift($posts);
        }
    }
} catch (Throwable $e) {
    // Tables may not exist yet – page renders empty.
}

$total_pages = max(1, (int)ceil($total_posts / $per_page));

if ($active_cat) $page_title = $active_cat['name'] . ' – Blog';

function blog_list_excerpt(array $p, int $len = 140): string
{
    if (!empty($p['excerpt'])) return mb_substr($p['excerpt'], 0, $len);
    $text = strip_tags((string)($p['content'] ?? ''));
    return mb_substr(trim(preg_replace('/\s+/', ' ', $text)), 0, $len);
}

function blog_page_url(int $page_no, string $cat_slug, string $q): string
{
    $qs = array_filter(['category' => $cat_slug ?: null, 'q' => $q ?: null, 'page' => $page_no > 1 ? $page_no : null]);
    return '/blog.php' . ($qs ? '?' . http_build_query($qs) : '');
}

function blog_read_minutes(array $p): int
{
    $words = str_word_count(strip_tags((string)($p['content'] ?? '')));
    return max(1, (int)ceil($words / 200));
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

   <style>
   /* ── Prime University Blog ─────────────────────────────────────────── */
   .pub-hero{
      position:relative;overflow:hidden;
      background:linear-gradient(135deg,#061c3f 0%,#0a2c61 55%,#123d82 100%);
      padding:90px 0 120px;
   }
   .pub-hero::before{
      content:"";position:absolute;inset:0;opacity:.35;
      background:
        radial-gradient(circle at 15% 20%, rgba(255,199,44,.25) 0, transparent 30%),
        radial-gradient(circle at 85% 80%, rgba(79,142,247,.35) 0, transparent 35%),
        radial-gradient(circle at 70% 10%, rgba(255,255,255,.08) 0, transparent 25%);
   }
   .pub-hero .pub-kicker{
      display:inline-flex;align-items:center;gap:8px;
      background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);
      color:#ffc72c;font-size:.8rem;font-weight:700;letter-spacing:.18em;
      text-transform:uppercase;border-radius:40px;padding:8px 20px;margin-bottom:18px;
   }
   .pub-hero h1{color:#fff;font-size:clamp(2rem,4.5vw,3.4rem);font-weight:800;margin-bottom:14px;}
   .pub-hero p{color:rgba(255,255,255,.75);max-width:560px;margin:0 auto 34px;}
   .pub-search{max-width:560px;margin:0 auto;position:relative;z-index:2;}
   .pub-search input{
      width:100%;border:none;border-radius:60px;padding:18px 150px 18px 28px;
      font-size:1rem;box-shadow:0 20px 50px rgba(3,15,39,.45);
   }
   .pub-search button{
      position:absolute;right:7px;top:7px;bottom:7px;border:none;border-radius:50px;
      background:#ffc72c;color:#06224e;font-weight:700;padding:0 30px;transition:.25s;
   }
   .pub-search button:hover{background:#ffd75e;}
   .pub-chips{display:flex;flex-wrap:wrap;gap:10px;justify-content:center;margin-top:26px;position:relative;z-index:2;}
   .pub-chips a{
      color:rgba(255,255,255,.85);border:1px solid rgba(255,255,255,.28);
      border-radius:40px;padding:7px 18px;font-size:.85rem;transition:.25s;
   }
   .pub-chips a:hover,.pub-chips a.active{background:#ffc72c;border-color:#ffc72c;color:#06224e;font-weight:600;}

   .pub-body{background:#f4f7fc;padding:0 0 110px;}
   .pub-shell{margin-top:-55px;position:relative;z-index:3;}

   /* Featured card */
   .pub-featured{
      display:grid;grid-template-columns:1.15fr 1fr;background:#fff;border-radius:24px;
      overflow:hidden;box-shadow:0 24px 60px rgba(13,38,76,.12);margin-bottom:44px;
   }
   .pub-featured .pf-media{position:relative;min-height:320px;}
   .pub-featured .pf-media img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;}
   .pub-featured .pf-media .pf-placeholder{
      position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
      background:linear-gradient(135deg,#dfe7f5,#c6d4ec);color:#8fa3c4;font-size:3rem;
   }
   .pub-featured .pf-body{padding:42px 44px;display:flex;flex-direction:column;justify-content:center;}
   .pf-flag{
      display:inline-flex;align-items:center;gap:6px;background:#eaf1ff;color:#0a2c61;
      font-size:.75rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;
      border-radius:30px;padding:6px 14px;margin-bottom:16px;width:max-content;
   }
   .pub-featured h2{font-size:clamp(1.3rem,2.2vw,1.85rem);font-weight:800;line-height:1.3;margin-bottom:14px;}
   .pub-featured h2 a{color:#0d1b33;}
   .pub-featured h2 a:hover{color:#0a2c61;}
   .pub-featured p{color:#5f6b7d;margin-bottom:22px;}
   .pub-meta{display:flex;flex-wrap:wrap;gap:16px;color:#8a93a6;font-size:.83rem;}
   .pub-meta i{margin-right:5px;color:#ffb400;}

   /* Post grid */
   .pub-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:26px;}
   .pub-card{
      background:#fff;border-radius:20px;overflow:hidden;display:flex;flex-direction:column;
      box-shadow:0 10px 30px rgba(13,38,76,.07);transition:transform .3s,box-shadow .3s;
   }
   .pub-card:hover{transform:translateY(-6px);box-shadow:0 24px 50px rgba(13,38,76,.14);}
   .pub-card .pc-media{position:relative;height:200px;overflow:hidden;}
   .pub-card .pc-media img{width:100%;height:100%;object-fit:cover;transition:transform .5s;}
   .pub-card:hover .pc-media img{transform:scale(1.07);}
   .pub-card .pc-media .pc-placeholder{
      height:100%;display:flex;align-items:center;justify-content:center;
      background:linear-gradient(135deg,#e8eef8,#d5e0f1);color:#9fb2d0;font-size:2.2rem;
   }
   .pc-cat{
      position:absolute;left:16px;bottom:16px;background:rgba(6,28,63,.85);color:#ffc72c;
      font-size:.72rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;
      border-radius:30px;padding:6px 14px;backdrop-filter:blur(4px);
   }
   .pc-pin{
      position:absolute;right:14px;top:14px;width:34px;height:34px;border-radius:50%;
      background:#ffc72c;color:#06224e;display:flex;align-items:center;justify-content:center;
      box-shadow:0 6px 16px rgba(0,0,0,.25);
   }
   .pub-card .pc-body{padding:24px 26px 26px;display:flex;flex-direction:column;flex:1;}
   .pub-card h3{font-size:1.08rem;font-weight:700;line-height:1.45;margin-bottom:10px;}
   .pub-card h3 a{color:#0d1b33;}
   .pub-card h3 a:hover{color:#0a2c61;}
   .pub-card .pc-excerpt{color:#5f6b7d;font-size:.9rem;flex:1;margin-bottom:16px;}
   .pub-card .pub-meta{border-top:1px solid #eef1f6;padding-top:14px;}

   /* Right rail */
   .pub-rail{position:sticky;top:110px;}
   .pub-widget{background:#fff;border-radius:20px;box-shadow:0 10px 30px rgba(13,38,76,.07);
      padding:26px 26px 20px;margin-bottom:26px;}
   .pub-widget h4{
      font-size:1rem;font-weight:800;color:#0d1b33;margin-bottom:18px;
      display:flex;align-items:center;gap:10px;
   }
   .pub-widget h4 .pw-ic{
      width:34px;height:34px;border-radius:10px;background:#fff3d1;color:#c98a00;
      display:flex;align-items:center;justify-content:center;font-size:.9rem;
   }
   .pw-pin-item{display:flex;gap:14px;padding:12px 0;border-bottom:1px dashed #e7ecf4;}
   .pw-pin-item:last-child{border-bottom:none;}
   .pw-pin-item .pw-thumb{
      width:62px;height:62px;border-radius:12px;object-fit:cover;flex-shrink:0;
   }
   .pw-pin-item .pw-thumb-ph{
      width:62px;height:62px;border-radius:12px;flex-shrink:0;
      background:linear-gradient(135deg,#e8eef8,#d5e0f1);color:#9fb2d0;
      display:flex;align-items:center;justify-content:center;
   }
   .pw-pin-item a.pw-title{
      font-weight:600;font-size:.86rem;line-height:1.4;color:#0d1b33;display:block;
   }
   .pw-pin-item a.pw-title:hover{color:#0a2c61;}
   .pw-pin-item .pw-date{font-size:.75rem;color:#8a93a6;margin-top:4px;}
   .pw-cats{list-style:none;padding:0;margin:0;}
   .pw-cats li{border-bottom:1px dashed #e7ecf4;}
   .pw-cats li:last-child{border-bottom:none;}
   .pw-cats a{
      display:flex;justify-content:space-between;align-items:center;
      padding:11px 2px;color:#44506a;font-size:.9rem;transition:.2s;
   }
   .pw-cats a:hover,.pw-cats a.active{color:#0a2c61;font-weight:700;padding-left:8px;}
   .pw-cats .pw-count{
      background:#f0f4fb;color:#6a7894;border-radius:20px;font-size:.73rem;padding:3px 10px;
   }

   /* Pagination */
   .pub-pagination{display:flex;gap:10px;flex-wrap:wrap;margin-top:44px;}
   .pub-pagination a{
      display:inline-flex;align-items:center;justify-content:center;min-width:44px;height:44px;
      border-radius:14px;background:#fff;color:#44506a;font-weight:600;
      box-shadow:0 6px 18px rgba(13,38,76,.08);transition:.2s;padding:0 14px;
   }
   .pub-pagination a:hover{color:#0a2c61;transform:translateY(-2px);}
   .pub-pagination a.active{background:#0a2c61;color:#fff;}

   .pub-empty{background:#fff;border-radius:20px;padding:70px 30px;text-align:center;
      box-shadow:0 10px 30px rgba(13,38,76,.07);}
   .pub-empty i{font-size:2.6rem;color:#c9d4e6;margin-bottom:18px;display:block;}

   @media (max-width: 991px){
      .pub-featured{grid-template-columns:1fr;}
      .pub-featured .pf-media{min-height:230px;}
      .pub-rail{position:static;margin-top:40px;}
   }
   @media (max-width: 767px){
      .pub-grid{grid-template-columns:1fr;}
      .pub-hero{padding:70px 0 100px;}
      .pub-featured .pf-body{padding:30px 26px;}
   }
   </style>
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

   <!-- hero -->
   <section class="pub-hero">
      <div class="container text-center" style="position:relative;z-index:2;">
         <span class="pub-kicker"><i class="fa-solid fa-feather-pointed"></i> Prime University Blog</span>
         <h1><?= $active_cat ? fh($active_cat['name']) : 'Stories, Ideas &amp; Insights' ?></h1>
         <p>
            <?= $active_cat && !empty($active_cat['description'])
               ? fh($active_cat['description'])
               : 'Explore articles from our faculty, students and community — research, campus life, technology and beyond.' ?>
         </p>
         <form class="pub-search" method="GET" action="/blog.php">
            <?php if ($active_cat): ?>
            <input type="hidden" name="category" value="<?= fh($active_cat['slug']) ?>">
            <?php endif; ?>
            <input type="text" name="q" value="<?= fh($q) ?>" placeholder="Search articles, topics or tags…">
            <button type="submit"><i class="fal fa-search me-1"></i> Search</button>
         </form>
         <?php if (!empty($categories)): ?>
         <div class="pub-chips">
            <a href="/blog.php" class="<?= !$active_cat ? 'active' : '' ?>">All</a>
            <?php foreach ($categories as $c): ?>
            <a href="/blog.php?category=<?= urlencode($c['slug']) ?>"
               class="<?= $active_cat && $active_cat['id'] === $c['id'] ? 'active' : '' ?>">
               <?= fh($c['name']) ?>
            </a>
            <?php endforeach; ?>
         </div>
         <?php endif; ?>
      </div>
   </section>

   <!-- body -->
   <section class="pub-body">
      <div class="container pub-shell">

         <?php if ($featured): ?>
         <!-- featured post -->
         <article class="pub-featured">
            <div class="pf-media">
               <?php if (!empty($featured['featured_image'])): ?>
               <img src="<?= fh(ADMIN_UPLOAD_URL . '/blog/' . $featured['featured_image']) ?>"
                    alt="<?= fh($featured['title']) ?>">
               <?php else: ?>
               <div class="pf-placeholder"><i class="fa-solid fa-feather-pointed"></i></div>
               <?php endif; ?>
            </div>
            <div class="pf-body">
               <span class="pf-flag"><i class="fa-solid fa-bolt"></i> Latest Story</span>
               <h2>
                  <a href="/blog-detail.php?slug=<?= urlencode($featured['slug']) ?>">
                     <?= fh($featured['title']) ?>
                  </a>
               </h2>
               <p><?= fh(blog_list_excerpt($featured, 190)) ?>…</p>
               <div class="pub-meta mb-20">
                  <span><i class="fa-solid fa-calendar-days"></i>
                     <?= $featured['published_at'] ? date('d M, Y', strtotime($featured['published_at'])) : '' ?></span>
                  <?php if (!empty($featured['category_name'])): ?>
                  <span><i class="fa-solid fa-folder"></i><?= fh($featured['category_name']) ?></span>
                  <?php endif; ?>
                  <span><i class="fa-regular fa-clock"></i><?= blog_read_minutes($featured) ?> min read</span>
               </div>
               <a href="/blog-detail.php?slug=<?= urlencode($featured['slug']) ?>"
                  class="it-btn-yellow border-radius-100" style="width:max-content;">
                  <span>
                     <span class="text-1">Read Article</span>
                     <span class="text-2">Read Article</span>
                  </span>
               </a>
            </div>
         </article>
         <?php endif; ?>

         <div class="row">
            <!-- posts -->
            <div class="col-lg-8 col-xl-9">
               <?php if (empty($posts) && !$featured): ?>
               <div class="pub-empty">
                  <i class="fa-regular fa-face-thinking"></i>
                  <h3 class="mb-15">No posts found</h3>
                  <p class="mb-25" style="color:#5f6b7d;">
                     <?= ($q !== '' || $active_cat)
                        ? 'Nothing matches your filter. Try another keyword or category.'
                        : 'There are no blog posts yet. Please check back soon.' ?>
                  </p>
                  <?php if ($q !== '' || $active_cat): ?>
                  <a href="/blog.php" class="it-btn-yellow border-radius-100" style="display:inline-block;">
                     <span>
                        <span class="text-1">View All Posts</span>
                        <span class="text-2">View All Posts</span>
                     </span>
                  </a>
                  <?php endif; ?>
               </div>
               <?php else: ?>

               <div class="pub-grid">
                  <?php foreach ($posts as $p): ?>
                  <article class="pub-card">
                     <div class="pc-media">
                        <a href="/blog-detail.php?slug=<?= urlencode($p['slug']) ?>">
                           <?php if (!empty($p['featured_image'])): ?>
                           <img src="<?= fh(ADMIN_UPLOAD_URL . '/blog/' . $p['featured_image']) ?>"
                                alt="<?= fh($p['title']) ?>" loading="lazy">
                           <?php else: ?>
                           <div class="pc-placeholder"><i class="fa-solid fa-feather-pointed"></i></div>
                           <?php endif; ?>
                        </a>
                        <?php if (!empty($p['category_name'])): ?>
                        <a class="pc-cat" href="/blog.php?category=<?= urlencode($p['category_slug']) ?>">
                           <?= fh($p['category_name']) ?>
                        </a>
                        <?php endif; ?>
                        <?php if (!empty($p['is_pinned'])): ?>
                        <span class="pc-pin" title="Pinned post"><i class="fa-solid fa-thumbtack"></i></span>
                        <?php endif; ?>
                     </div>
                     <div class="pc-body">
                        <h3>
                           <a href="/blog-detail.php?slug=<?= urlencode($p['slug']) ?>">
                              <?= fh($p['title']) ?>
                           </a>
                        </h3>
                        <p class="pc-excerpt"><?= fh(blog_list_excerpt($p)) ?>…</p>
                        <div class="pub-meta">
                           <span><i class="fa-solid fa-calendar-days"></i>
                              <?= $p['published_at'] ? date('d M, Y', strtotime($p['published_at'])) : '' ?></span>
                           <span><i class="fa-regular fa-clock"></i><?= blog_read_minutes($p) ?> min</span>
                           <span><i class="fa-regular fa-eye"></i><?= (int)$p['views'] ?></span>
                        </div>
                     </div>
                  </article>
                  <?php endforeach; ?>
               </div>

               <?php if ($total_pages > 1): ?>
               <nav class="pub-pagination">
                  <?php if ($page > 1): ?>
                  <a href="<?= fh(blog_page_url($page - 1, $active_cat['slug'] ?? '', $q)) ?>">
                     <i class="fa-solid fa-arrow-left"></i>
                  </a>
                  <?php endif; ?>
                  <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                  <a href="<?= fh(blog_page_url($i, $active_cat['slug'] ?? '', $q)) ?>"
                     class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                  <?php endfor; ?>
                  <?php if ($page < $total_pages): ?>
                  <a href="<?= fh(blog_page_url($page + 1, $active_cat['slug'] ?? '', $q)) ?>">
                     <i class="fa-solid fa-arrow-right"></i>
                  </a>
                  <?php endif; ?>
               </nav>
               <?php endif; ?>

               <?php endif; ?>
            </div>

            <!-- right rail -->
            <aside class="col-lg-4 col-xl-3">
               <div class="pub-rail">

                  <?php if (!empty($pinned)): ?>
                  <div class="pub-widget">
                     <h4><span class="pw-ic"><i class="fa-solid fa-thumbtack"></i></span> Pinned Blogs</h4>
                     <?php foreach ($pinned as $pp): ?>
                     <div class="pw-pin-item">
                        <?php if (!empty($pp['featured_image'])): ?>
                        <img class="pw-thumb" loading="lazy"
                             src="<?= fh(ADMIN_UPLOAD_URL . '/blog/' . $pp['featured_image']) ?>"
                             alt="<?= fh($pp['title']) ?>">
                        <?php else: ?>
                        <div class="pw-thumb-ph"><i class="fa-solid fa-feather-pointed"></i></div>
                        <?php endif; ?>
                        <div>
                           <a class="pw-title" href="/blog-detail.php?slug=<?= urlencode($pp['slug']) ?>">
                              <?= fh(mb_substr($pp['title'], 0, 72)) ?>
                           </a>
                           <div class="pw-date">
                              <i class="fa-solid fa-calendar-days me-1"></i>
                              <?= $pp['published_at'] ? date('d M, Y', strtotime($pp['published_at'])) : '' ?>
                              <?php if (!empty($pp['category_name'])): ?>
                              · <?= fh($pp['category_name']) ?>
                              <?php endif; ?>
                           </div>
                        </div>
                     </div>
                     <?php endforeach; ?>
                  </div>
                  <?php endif; ?>

                  <?php if (!empty($categories)): ?>
                  <div class="pub-widget">
                     <h4><span class="pw-ic"><i class="fa-solid fa-folder-open"></i></span> Categories</h4>
                     <ul class="pw-cats">
                        <li>
                           <a href="/blog.php" class="<?= !$active_cat ? 'active' : '' ?>">
                              All Posts
                           </a>
                        </li>
                        <?php foreach ($categories as $c): ?>
                        <li>
                           <a href="/blog.php?category=<?= urlencode($c['slug']) ?>"
                              class="<?= $active_cat && $active_cat['id'] === $c['id'] ? 'active' : '' ?>">
                              <?= fh($c['name']) ?>
                              <span class="pw-count"><?= (int)$c['post_count'] ?></span>
                           </a>
                        </li>
                        <?php endforeach; ?>
                     </ul>
                  </div>
                  <?php endif; ?>

               </div>
            </aside>
         </div><!-- .row -->

      </div><!-- .container -->
   </section>

   </main>

<?php include __DIR__ . '/includes/footer.php'; ?>

   <!-- JS Libraries -->
   <?php include __DIR__ . '/includes/scripts.php'; ?>

</body>
</html>
