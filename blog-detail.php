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

// ── Approved comments / recent posts / categories / related / prev-next ─────
$comments      = [];
$recent_posts  = [];
$categories    = [];
$related_posts = [];
$prev_post     = null;
$next_post     = null;
$programs      = [];

// Titles that are obviously test content should never surface in widgets.
$is_test_title = static function (string $t): bool {
    return (bool)preg_match('/^test(\s*(post|article|blog|entry))?\s*\d*$/i', trim($t));
};

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
                 ORDER BY published_at DESC LIMIT 10"
            );
            $st->execute([$post['id']]);
            $recent_posts = array_slice(
                array_values(array_filter($st->fetchAll(), fn($r) => !$is_test_title($r['title']))),
                0, 5
            );

            // Hide categories that have no published posts.
            $categories = $db->query(
                "SELECT c.name, c.slug, COUNT(p.id) AS post_count
                 FROM blog_categories c
                 JOIN blog_posts p ON p.category_id = c.id
                      AND p.status = 'published' AND p.published_at <= NOW()
                 WHERE c.is_active = 1
                 GROUP BY c.id, c.name, c.slug, c.sort_order
                 ORDER BY c.sort_order, c.name"
            )->fetchAll();

            // Related posts: same category, newest first.
            if (!empty($post['category_id'])) {
                $st = $db->prepare(
                    "SELECT title, slug, featured_image, published_at, content
                     FROM blog_posts
                     WHERE status = 'published' AND published_at <= NOW()
                       AND id != ? AND category_id = ?
                     ORDER BY published_at DESC LIMIT 8"
                );
                $st->execute([$post['id'], $post['category_id']]);
                $related_posts = array_slice(
                    array_values(array_filter($st->fetchAll(), fn($r) => !$is_test_title($r['title']))),
                    0, 3
                );
            }

            // Previous / next article by publish date (skipping test posts).
            $st = $db->prepare(
                "SELECT title, slug FROM blog_posts
                 WHERE status = 'published' AND published_at <= NOW()
                   AND (published_at < ? OR (published_at = ? AND id < ?))
                 ORDER BY published_at DESC, id DESC LIMIT 5"
            );
            $st->execute([$post['published_at'], $post['published_at'], $post['id']]);
            foreach ($st->fetchAll() as $r) {
                if (!$is_test_title($r['title'])) { $prev_post = $r; break; }
            }

            $st = $db->prepare(
                "SELECT title, slug FROM blog_posts
                 WHERE status = 'published' AND published_at <= NOW()
                   AND (published_at > ? OR (published_at = ? AND id > ?))
                 ORDER BY published_at ASC, id ASC LIMIT 5"
            );
            $st->execute([$post['published_at'], $post['published_at'], $post['id']]);
            foreach ($st->fetchAll() as $r) {
                if (!$is_test_title($r['title'])) { $next_post = $r; break; }
            }

            // Active academic programmes — used to link course chips in callouts.
            $programs = $db->query(
                "SELECT p.id, p.program_name
                 FROM dept_academic_programs p
                 JOIN dept_departments d ON d.id = p.dept_id
                 WHERE p.is_active = 1 AND d.is_active = 1"
            )->fetchAll();
        }
    } catch (Throwable $e) {}
}

$page_title = $post ? ($post['meta_title'] ?: $post['title']) : 'Post Not Found';

$pub_date     = '';
$pub_date_iso = '';
if ($post && !empty($post['published_at'])) {
    $pub_ts       = strtotime($post['published_at']);
    $pub_date     = date('d F, Y', $pub_ts);
    $pub_date_iso = date('c', $pub_ts);
}

$tags = [];
if ($post && !empty($post['tags'])) {
    $tags = array_filter(array_map('trim', explode(',', $post['tags'])));
}

// Estimated reading time (≈200 words per minute).
$reading_time = 1;
if ($post) {
    $word_count   = str_word_count(strip_tags((string)$post['content']));
    $reading_time = max(1, (int)ceil($word_count / 200));
}
$reading_time_for = static function (?string $html): int {
    return max(1, (int)ceil(str_word_count(strip_tags((string)$html)) / 200));
};

$author_name = $post ? trim((string)($post['author_name'] ?? '')) : '';
$author_role = $post ? trim((string)($post['author_role'] ?? '')) : '';
$author_dept = $post ? trim((string)($post['author_department'] ?? '')) : '';
$author_bio  = $post ? trim((string)($post['author_bio'] ?? '')) : '';
$display_author = $author_name !== '' ? $author_name : 'Prime University';

$canonical_url = SITE_URL . '/blog-detail.php?slug=' . urlencode($slug);
$share_title   = $post ? $post['title'] : '';
$featured_url  = ($post && !empty($post['featured_image']))
    ? ADMIN_UPLOAD_URL . '/blog/' . $post['featured_image'] : null;
$image_caption = $post ? trim((string)($post['image_caption'] ?? '')) : '';

// Programme name → URL map for course chips (consumed by blog-detail.js).
$programs_js = array_map(static fn($p) => [
    'name' => $p['program_name'],
    'url'  => '/program/' . (int)$p['id'] . '/' . make_slug($p['program_name']),
], $programs);

// ── JSON-LD: Article + BreadcrumbList ────────────────────────────────────────
$jsonld = [];
if ($post) {
    $article_ld = [
        '@context'      => 'https://schema.org',
        '@type'         => 'Article',
        'headline'      => $post['title'],
        'description'   => $post['meta_description']
            ?: ($post['excerpt'] ?: mb_substr(strip_tags((string)$post['content']), 0, 160)),
        'datePublished' => $pub_date_iso,
        'dateModified'  => !empty($post['updated_at']) ? date('c', strtotime($post['updated_at'])) : $pub_date_iso,
        'author'        => [
            '@type' => $author_name !== '' ? 'Person' : 'Organization',
            'name'  => $display_author,
        ],
        'publisher'     => [
            '@type' => 'Organization',
            'name'  => 'Prime University',
            'logo'  => ['@type' => 'ImageObject', 'url' => SITE_URL . '/assets/img/logo/favicon.png'],
        ],
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $canonical_url],
    ];
    if ($featured_url) $article_ld['image'] = [$featured_url];
    $jsonld[] = $article_ld;

    $jsonld[] = [
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => SITE_URL . '/index.php'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Blog', 'item' => SITE_URL . '/blog.php'],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $post['title'], 'item' => $canonical_url],
        ],
    ];
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

   <!-- Blog detail: readable body font (Inter; Noto Sans Bengali for Bangla articles) -->
   <link rel="preconnect" href="https://fonts.googleapis.com">
   <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
   <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Noto+Sans+Bengali:wght@400;500;600;700&display=swap">
   <link rel="stylesheet" href="/assets/css/blog-detail.css">
<?php foreach ($jsonld as $ld): ?>
   <script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<?php endforeach; ?>
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

   <!-- Reading progress bar -->
   <div class="bd-progress" aria-hidden="true"></div>

<?php
// Share button sets (rail on desktop, inline on mobile).
$share_links = [
    ['url' => 'https://www.facebook.com/sharer/sharer.php?u=' . urlencode($canonical_url),
     'icon' => 'fa-brands fa-facebook-f', 'label' => 'Share on Facebook'],
    ['url' => 'https://www.linkedin.com/sharing/share-offsite/?url=' . urlencode($canonical_url),
     'icon' => 'fa-brands fa-linkedin-in', 'label' => 'Share on LinkedIn'],
    ['url' => 'https://twitter.com/intent/tweet?url=' . urlencode($canonical_url) . '&text=' . urlencode($share_title),
     'icon' => 'fa-brands fa-twitter', 'label' => 'Share on X'],
    ['url' => 'https://wa.me/?text=' . urlencode($share_title . ' ' . $canonical_url),
     'icon' => 'fa-brands fa-whatsapp', 'label' => 'Share on WhatsApp'],
];
$render_share_buttons = static function () use ($share_links, $canonical_url): void {
    foreach ($share_links as $s) {
        echo '<a class="bd-share-btn" href="' . fh($s['url']) . '" target="_blank" rel="noopener" aria-label="' . fh($s['label']) . '">'
           . '<i class="' . fh($s['icon']) . '" aria-hidden="true"></i></a>' . "\n";
    }
    echo '<button type="button" class="bd-share-btn bd-copy-link" data-url="' . fh($canonical_url) . '" aria-label="Copy link">'
       . '<i class="fa-solid fa-link" aria-hidden="true"></i></button>' . "\n";
};
?>

   <?php if ($post): ?>

   <!-- hero-start -->
   <section class="bd-hero">
      <div class="container">
         <a class="bd-backlink-top" href="/blog.php">
            <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to Blog
         </a>
         <nav aria-label="Breadcrumb" class="bd-breadcrumb">
            <ol>
               <li><a href="<?= fh(SITE_URL) ?>/index.php">Home</a></li>
               <li><a href="/blog.php">Blog</a></li>
               <li><span aria-current="page"><?= fh($post['title']) ?></span></li>
            </ol>
         </nav>
         <h1 class="bd-hero-title"><?= fh($post['title']) ?></h1>
         <div class="bd-hero-meta">
            <?php if ($pub_date): ?>
            <span><i class="fa-solid fa-calendar-days" aria-hidden="true"></i>
               <time datetime="<?= fh($pub_date_iso) ?>"><?= fh($pub_date) ?></time>
            </span>
            <?php endif; ?>
            <?php if (!empty($post['category_name'])): ?>
            <a class="bd-chip" href="/blog.php?category=<?= urlencode($post['category_slug']) ?>">
               <?= fh($post['category_name']) ?>
            </a>
            <?php endif; ?>
            <span><i class="fa-regular fa-clock" aria-hidden="true"></i><?= (int)$reading_time ?> min read</span>
            <span><i class="fa-solid fa-user-pen" aria-hidden="true"></i><?= fh($display_author) ?></span>
         </div>
      </div>
   </section>
   <!-- hero-end -->

   <!-- post-area-start -->
   <div class="container">
      <div class="bd-layout">

         <article class="bd-article">

            <div class="bd-share-rail" aria-label="Share this article">
               <div class="bd-share-rail-inner">
                  <?php $render_share_buttons(); ?>
               </div>
            </div>

            <!-- Collapsible TOC: tablet / mobile -->
            <div class="bd-toc-mobile">
               <details>
                  <summary>In this article</summary>
                  <nav class="bd-toc" id="bd-toc-mobile" aria-label="Table of contents"></nav>
               </details>
            </div>

            <?php if ($featured_url): ?>
            <figure class="bd-featured">
               <img src="<?= fh($featured_url) ?>"
                    alt="<?= fh($image_caption !== '' ? $image_caption : $post['title']) ?>"
                    width="1200" height="630" fetchpriority="high" decoding="async">
               <?php if ($image_caption !== ''): ?>
               <figcaption><?= fh($image_caption) ?></figcaption>
               <?php endif; ?>
            </figure>
            <?php endif; ?>

            <!-- Meta bar -->
            <div class="bd-hero-meta" style="color:#4B5563;margin-bottom:24px;">
               <?php if ($pub_date): ?>
               <span style="color:#4B5563;"><i class="fa-solid fa-calendar-days" aria-hidden="true" style="color:#002147;"></i>
                  <time datetime="<?= fh($pub_date_iso) ?>"><?= fh($pub_date) ?></time>
               </span>
               <?php endif; ?>
               <?php if (!empty($post['category_name'])): ?>
               <a class="bd-chip" style="background:#F4F6FA;border-color:#E5E7EB;color:#002147;"
                  href="/blog.php?category=<?= urlencode($post['category_slug']) ?>"><?= fh($post['category_name']) ?></a>
               <?php endif; ?>
               <span style="color:#4B5563;"><i class="fa-regular fa-clock" aria-hidden="true" style="color:#002147;"></i><?= (int)$reading_time ?> min read</span>
               <span style="color:#4B5563;"><i class="fa-solid fa-eye" aria-hidden="true" style="color:#002147;"></i><?= (int)$post['views'] ?> views</span>
            </div>

            <div class="bd-content">
               <?php if ($post['content_type'] === 'html'): ?>
                  <?= $post['content'] ?>
               <?php else: ?>
                  <?= nl2br(fh($post['content'])) ?>
               <?php endif; ?>
            </div>

            <?php if (!empty($tags)): ?>
            <div class="bd-tags">
               <?php foreach ($tags as $tag): ?>
               <span>#<?= fh($tag) ?></span>
               <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="bd-share-inline">
               <span class="bd-share-label">Share:</span>
               <?php $render_share_buttons(); ?>
            </div>

            <!-- CTA banner -->
            <section class="bd-cta" aria-label="Admissions call to action">
               <h2 id="bd-cta-headline">Start your journey at Prime University</h2>
               <p>Admissions are open — explore our programmes and take the next step.</p>
               <div class="bd-cta-actions">
                  <a class="bd-btn bd-btn-primary" href="<?= fh(SITE_URL) ?>/apply-now">Apply Now</a>
                  <a class="bd-btn bd-btn-secondary" href="/blog.php<?= !empty($post['category_slug']) ? '?category=' . urlencode($post['category_slug']) : '' ?>">
                     Explore More Articles
                  </a>
               </div>
            </section>

            <?php if ($author_name !== ''): ?>
            <aside class="bd-author-box" aria-label="About the author">
               <div class="bd-author-avatar" aria-hidden="true"><?= fh(mb_strtoupper(mb_substr($author_name, 0, 1))) ?></div>
               <div>
                  <h3><?= fh($author_name) ?></h3>
                  <div class="bd-author-role">
                     <?= fh(implode(' · ', array_filter([$author_role, $author_dept]))) ?>
                  </div>
                  <?php if ($author_bio !== ''): ?>
                  <p><?= fh($author_bio) ?></p>
                  <?php endif; ?>
               </div>
            </aside>
            <?php endif; ?>

            <?php if ($prev_post || $next_post): ?>
            <nav class="bd-prevnext" aria-label="Previous and next articles">
               <?php if ($prev_post): ?>
               <a href="/blog-detail.php?slug=<?= urlencode($prev_post['slug']) ?>">
                  <span class="bd-pn-dir"><i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i> Previous</span>
                  <span class="bd-pn-title"><?= fh($prev_post['title']) ?></span>
               </a>
               <?php else: ?><span></span><?php endif; ?>
               <?php if ($next_post): ?>
               <a class="bd-pn-next" href="/blog-detail.php?slug=<?= urlencode($next_post['slug']) ?>">
                  <span class="bd-pn-dir">Next <i class="fa-solid fa-arrow-right ms-1" aria-hidden="true"></i></span>
                  <span class="bd-pn-title"><?= fh($next_post['title']) ?></span>
               </a>
               <?php endif; ?>
            </nav>
            <?php endif; ?>

            <?php if (!empty($related_posts)): ?>
            <section class="bd-related" aria-label="Related articles">
               <h2>Related articles</h2>
               <div class="bd-related-grid">
                  <?php foreach ($related_posts as $rp): ?>
                  <a class="bd-related-card" href="/blog-detail.php?slug=<?= urlencode($rp['slug']) ?>">
                     <?php if (!empty($rp['featured_image'])): ?>
                     <img src="<?= fh(ADMIN_UPLOAD_URL . '/blog/' . $rp['featured_image']) ?>"
                          alt="" loading="lazy" decoding="async" width="400" height="225">
                     <?php else: ?>
                     <div class="bd-rc-noimg" aria-hidden="true"><i class="fa-solid fa-newspaper"></i></div>
                     <?php endif; ?>
                     <div class="bd-rc-body">
                        <h3><?= fh($rp['title']) ?></h3>
                        <div class="bd-rc-meta">
                           <?php if (!empty($rp['published_at'])): ?>
                           <span><i class="fa-solid fa-calendar-days me-1" aria-hidden="true"></i>
                              <time datetime="<?= date('c', strtotime($rp['published_at'])) ?>"><?= date('d M, Y', strtotime($rp['published_at'])) ?></time>
                           </span>
                           <?php endif; ?>
                           <span><i class="fa-regular fa-clock me-1" aria-hidden="true"></i><?= $reading_time_for($rp['content'] ?? null) ?> min read</span>
                        </div>
                     </div>
                  </a>
                  <?php endforeach; ?>
               </div>
            </section>
            <?php endif; ?>

            <!-- Comments -->
            <section class="bd-comments" id="comments">
               <h2><i class="fa-regular fa-comments me-2" aria-hidden="true"></i>Comments (<?= count($comments) ?>)</h2>

               <?php if (empty($comments)): ?>
               <div class="bd-no-comments">
                  <i class="fa-regular fa-comment-dots" aria-hidden="true"></i>
                  No comments yet — be the first to share your thoughts on this article!
               </div>
               <?php else: ?>
               <?php foreach ($comments as $cm): ?>
               <div class="bd-comment">
                  <div class="bd-comment-head">
                     <strong><?= fh($cm['author_name']) ?></strong>
                     <time datetime="<?= date('c', strtotime($cm['created_at'])) ?>">
                        <?= date('d M, Y', strtotime($cm['created_at'])) ?>
                     </time>
                  </div>
                  <p><?= nl2br(fh($cm['content'])) ?></p>
               </div>
               <?php endforeach; ?>
               <?php endif; ?>

               <?php if ($post['allow_comments']): ?>
               <div class="bd-comment-form">
                  <h3>Leave a Comment</h3>

                  <?php if ($comment_success): ?>
                  <div class="bd-alert bd-alert-success" role="status">
                     <i class="fa-solid fa-circle-check me-2" aria-hidden="true"></i>
                     Thank you! Your comment has been submitted and will appear once approved.
                  </div>
                  <?php endif; ?>

                  <?php if ($comment_errors): ?>
                  <div class="bd-alert bd-alert-danger" role="alert">
                     <ul>
                        <?php foreach ($comment_errors as $e): ?><li><?= fh($e) ?></li><?php endforeach; ?>
                     </ul>
                  </div>
                  <?php endif; ?>

                  <?php if (!$comment_success): ?>
                  <form method="POST" action="/blog-detail.php?slug=<?= urlencode($slug) ?>#comment-form"
                        id="comment-form" novalidate>
                     <input type="hidden" name="comment_submit" value="1">
                     <!-- Honeypot: humans never see or fill this -->
                     <input type="text" name="website" value="" tabindex="-1" autocomplete="off"
                            style="position:absolute;left:-9999px;" aria-hidden="true">
                     <div class="row g-3">
                        <div class="col-md-6">
                           <div class="bd-field">
                              <label for="cf-name">Name <span class="bd-req" aria-hidden="true">*</span></label>
                              <input type="text" id="cf-name" name="author_name" required maxlength="190"
                                     autocomplete="name" value="<?= fh($_POST['author_name'] ?? '') ?>"
                                     aria-describedby="cf-name-err">
                              <p class="bd-field-error" id="cf-name-err">Please enter your name.</p>
                           </div>
                        </div>
                        <div class="col-md-6">
                           <div class="bd-field">
                              <label for="cf-email">Email <span class="bd-opt">(optional, never published)</span></label>
                              <input type="email" id="cf-email" name="author_email" maxlength="190"
                                     autocomplete="email" value="<?= fh($_POST['author_email'] ?? '') ?>"
                                     aria-describedby="cf-email-err">
                              <p class="bd-field-error" id="cf-email-err">Please enter a valid email address (or leave it blank).</p>
                           </div>
                        </div>
                        <div class="col-12">
                           <div class="bd-field">
                              <label for="cf-comment">Comment <span class="bd-req" aria-hidden="true">*</span></label>
                              <textarea id="cf-comment" name="comment" required rows="5" maxlength="3000"
                                        aria-describedby="cf-comment-err"><?= fh($_POST['comment'] ?? '') ?></textarea>
                              <p class="bd-field-error" id="cf-comment-err">Please write a comment (max 3000 characters).</p>
                           </div>
                        </div>
                        <div class="col-12">
                           <?php captcha_render_widget(); ?>
                        </div>
                        <div class="col-12">
                           <button type="submit" class="bd-btn bd-btn-primary bd-submit-btn">
                              <span class="bd-spinner" aria-hidden="true"></span>
                              <span class="bd-submit-label">Submit Comment</span>
                           </button>
                        </div>
                     </div>
                  </form>
                  <?php endif; ?>
               </div>
               <?php else: ?>
               <p class="mt-30" style="color:#4B5563;"><i class="fa-solid fa-lock me-1" aria-hidden="true"></i>Comments are closed for this post.</p>
               <?php endif; ?>
            </section>

            <div style="margin-top:48px;">
               <a href="/blog.php" class="bd-btn bd-btn-outline-navy">
                  <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to Blog
               </a>
            </div>

         </article>

         <!-- Sidebar -->
         <aside class="bd-sidebar" aria-label="Blog sidebar">
            <div class="bd-sidebar-inner">

               <div class="bd-widget bd-toc-widget">
                  <h2 class="bd-widget-title"><i class="fa-solid fa-list-ul me-2" aria-hidden="true"></i>In this article</h2>
                  <nav class="bd-toc" id="bd-toc-desktop" aria-label="Table of contents"></nav>
               </div>

               <?php if (!empty($recent_posts)): ?>
               <div class="bd-widget">
                  <h2 class="bd-widget-title"><i class="fa-regular fa-newspaper me-2" aria-hidden="true"></i>Recent Posts</h2>
                  <?php foreach ($recent_posts as $rp): ?>
                  <div class="bd-recent-item">
                     <a href="/blog-detail.php?slug=<?= urlencode($rp['slug']) ?>" class="flex-shrink-0" tabindex="-1" aria-hidden="true">
                        <?php if (!empty($rp['featured_image'])): ?>
                        <img src="<?= fh(ADMIN_UPLOAD_URL . '/blog/' . $rp['featured_image']) ?>" alt=""
                             loading="lazy" decoding="async" width="64" height="64">
                        <?php else: ?>
                        <div class="bd-rp-noimg"><i class="fa-solid fa-blog"></i></div>
                        <?php endif; ?>
                     </a>
                     <div>
                        <a class="bd-rp-title" href="/blog-detail.php?slug=<?= urlencode($rp['slug']) ?>">
                           <?= fh(mb_substr($rp['title'], 0, 70)) ?>
                        </a>
                        <?php if (!empty($rp['published_at'])): ?>
                        <div class="bd-rp-date">
                           <i class="fa-solid fa-calendar-days me-1" aria-hidden="true"></i>
                           <time datetime="<?= date('c', strtotime($rp['published_at'])) ?>"><?= date('d M, Y', strtotime($rp['published_at'])) ?></time>
                        </div>
                        <?php endif; ?>
                     </div>
                  </div>
                  <?php endforeach; ?>
               </div>
               <?php endif; ?>

               <?php if (!empty($categories)): ?>
               <div class="bd-widget bd-cats">
                  <h2 class="bd-widget-title"><i class="fa-regular fa-folder-open me-2" aria-hidden="true"></i>Categories</h2>
                  <ul>
                     <?php foreach ($categories as $c): ?>
                     <li>
                        <a href="/blog.php?category=<?= urlencode($c['slug']) ?>">
                           <span><?= fh($c['name']) ?></span>
                           <span class="bd-cat-count"><?= (int)$c['post_count'] ?></span>
                        </a>
                     </li>
                     <?php endforeach; ?>
                  </ul>
               </div>
               <?php endif; ?>

               <div class="bd-widget bd-promo">
                  <h2 class="bd-widget-title"><i class="fa-solid fa-graduation-cap me-2" aria-hidden="true"></i>Admissions Open</h2>
                  <p>Join Prime University and build your future with industry-focused programmes.</p>
                  <a class="bd-btn bd-btn-primary" href="<?= fh(SITE_URL) ?>/apply-now">Apply Now</a>
               </div>

            </div>
         </aside>

      </div><!-- .bd-layout -->
   </div><!-- .container -->
   <!-- post-area-end -->

   <?php else: ?>

   <!-- not-found-start -->
   <section class="bd-hero">
      <div class="container">
         <nav aria-label="Breadcrumb" class="bd-breadcrumb">
            <ol>
               <li><a href="<?= fh(SITE_URL) ?>/index.php">Home</a></li>
               <li><a href="/blog.php">Blog</a></li>
               <li><span aria-current="page">Not Found</span></li>
            </ol>
         </nav>
         <h1 class="bd-hero-title">Post Not Found</h1>
      </div>
   </section>
   <div class="container">
      <div class="text-center py-80" style="font-family:'Inter',sans-serif;">
         <p class="mb-30" style="font-size:17px;color:#1F2937;">
            The blog post you are looking for does not exist or is no longer available.
         </p>
         <a href="/blog.php" class="bd-btn bd-btn-outline-navy">
            <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to Blog
         </a>
      </div>
   </div>
   <!-- not-found-end -->

   <?php endif; ?>

   <!-- Copy-link toast -->
   <div class="bd-toast" role="status" aria-live="polite"></div>

   </main>

<?php include __DIR__ . '/includes/footer.php'; ?>

   <!-- JS Libraries -->
   <?php include __DIR__ . '/includes/scripts.php'; ?>

   <!-- Blog detail enhancements -->
   <script>
      window.BD_PROGRAMS = <?= json_encode($programs_js ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
   </script>
   <script src="/assets/js/blog-detail.js"></script>

</body>
</html>
