<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/seo.php';

$id = (int)($_GET['id'] ?? 0);
$issue = null;
$articles = [];

try {
    $db = front_db();
    if ($db && $id > 0) {
        $stmt = $db->prepare(
            "SELECT i.*, v.volume_number, v.year, j.name AS journal_title, j.slug AS journal_slug
             FROM journal_issues i
             JOIN journal_volumes  v ON v.id = i.volume_id
             JOIN journal_journals j ON j.id = v.journal_id
             WHERE i.id = ? AND i.is_published = 1 AND j.status = 'active'
             LIMIT 1"
        );
        $stmt->execute([$id]);
        $issue = $stmt->fetch();
        if ($issue) {
            $stmt = $db->prepare(
                "SELECT a.id, a.title, a.slug, a.page_from, a.page_to, a.pdf_file,
                        (SELECT GROUP_CONCAT(au.full_name ORDER BY aa.author_order SEPARATOR ', ')
                         FROM journal_article_authors aa JOIN journal_authors au ON au.id = aa.author_id
                         WHERE aa.article_id = a.id) AS author_names
                 FROM journal_articles a
                 WHERE a.issue_id = ? AND a.status = 'published'
                 ORDER BY a.page_from ASC, a.id ASC"
            );
            $stmt->execute([$id]);
            $articles = $stmt->fetchAll();
        }
    }
} catch (Throwable $e) {
}

$page_title = $issue
    ? $issue['journal_title'] . ' – Vol. ' . $issue['volume_number'] . ', No. ' . $issue['issue_number']
    : 'Issue Not Found';
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <meta name="viewport" content="width=device-width, initial-scale=1">
   <link rel="shortcut icon" type="image/x-icon" href="/assets/img/logo/favicon.png">
<?php render_seo_meta('/journal-issue.php?id=' . $id, $page_title, 'Table of contents.', null); ?>
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
   <div id="preloader"><div class="preloader"><span></span><span></span></div></div>
   <div id="magic-cursor"><div id="ball"></div></div>
   <button class="scroll-top scroll-to-target" data-target="html"><i class="far fa-angle-double-up"></i></button>
<?php include __DIR__ . '/includes/offcanvas.php'; ?>
   <header class="it-header-height">
      <?php include __DIR__ . '/includes/header-top.php'; ?>
      <?php include __DIR__ . '/includes/nav-menu.php'; ?>
   </header>
   <main>
   <div class="it-breadcrumb-area fix it-breadcrumb-style-2 z-index-1" data-background="assets/img/shape/breadcrumb-1-bg.png">
      <div class="container"><div class="row align-items-center"><div class="col-12">
         <div class="it-breadcrumb-content text-center z-index-1">
            <div class="it-breadcrumb-title-box">
               <h3 class="it-breadcrumb-title style-2"><?= fh($page_title) ?></h3>
            </div>
            <div class="it-breadcrumb-list"><ul>
               <li><a href="<?= fh(SITE_URL) ?>/index.php">Home</a></li>
               <?php if ($issue): ?>
               <li><a href="<?= fh(SITE_URL) ?>/journal.php?slug=<?= fh(rawurlencode($issue['journal_slug'])) ?>"><?= fh($issue['journal_title']) ?></a></li>
               <?php endif; ?>
               <li><span>Issue</span></li>
            </ul></div>
         </div>
      </div></div></div>
   </div>

   <div class="postbox-area pt-100 pb-100">
      <div class="container">
         <?php if (!$issue): ?>
         <div class="text-center py-5">
            <h3 class="mb-3">Issue Not Found</h3>
            <a href="<?= fh(SITE_URL) ?>/journal.php">Browse all journals</a>
         </div>
         <?php else: ?>
         <h4 class="mb-1">Table of Contents</h4>
         <?php if ($issue['published_date']): ?>
         <p class="text-muted">Published: <?= fh(date('d F Y', strtotime($issue['published_date']))) ?></p>
         <?php endif; ?>

         <?php if (!$articles): ?>
         <p class="text-muted">No articles in this issue yet.</p>
         <?php endif; ?>

         <?php foreach ($articles as $a): ?>
         <div class="card mb-3" style="border-radius:12px;">
            <div class="card-body d-flex justify-content-between align-items-start flex-wrap gap-2">
               <div>
                  <h5 class="mb-1">
                     <a href="<?= fh(SITE_URL) ?>/journal-article.php?slug=<?= fh(rawurlencode($a['slug'])) ?>"><?= fh($a['title']) ?></a>
                  </h5>
                  <div class="small text-muted">
                     <?= fh($a['author_names'] ?: '') ?>
                     <?php if ($a['page_from']): ?>
                        &nbsp;|&nbsp; pp. <?= (int)$a['page_from'] ?><?= $a['page_to'] ? '–' . (int)$a['page_to'] : '' ?>
                     <?php endif; ?>
                  </div>
               </div>
               <?php if ($a['pdf_file']): ?>
               <a class="btn btn-sm btn-outline-danger" href="<?= fh(SITE_URL) ?>/journal-pdf.php?slug=<?= fh(rawurlencode($a['slug'])) ?>">
                  <i class="far fa-file-pdf me-1"></i> PDF
               </a>
               <?php endif; ?>
            </div>
         </div>
         <?php endforeach; ?>
         <?php endif; ?>
      </div>
   </div>
   </main>
<?php include __DIR__ . '/includes/footer.php'; ?>
   <?php include __DIR__ . '/includes/scripts.php'; ?>
</body>
</html>
