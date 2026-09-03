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
            "SELECT i.*, v.volume_number, v.year, j.name AS journal_title, j.slug AS journal_slug, j.short_name
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
<?php include __DIR__ . '/includes/journal-styles.php'; ?>
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

   <div class="jm-wrap pt-60 pb-100">
      <div class="container">

         <?php if (!$issue): ?>
         <div class="jm-card"><div class="jm-card-body text-center py-5">
            <i class="far fa-book-open mb-3" style="font-size:2.4rem;color:var(--jm-blue);"></i>
            <h3 class="mb-2">Issue Not Found</h3>
            <p class="jm-meta-line mb-4">The issue you are looking for does not exist or is not published.</p>
            <a class="jm-btn jm-btn-outline" href="<?= fh(SITE_URL) ?>/journal.php"><i class="far fa-arrow-left"></i> Browse all journals</a>
         </div></div>
         <?php else: ?>

         <!-- ── Issue hero ── -->
         <div class="jm-hero mb-4">
            <a class="jm-pill mb-3" style="text-decoration:none;"
               href="<?= fh(SITE_URL) ?>/journal.php?slug=<?= fh(rawurlencode($issue['journal_slug'])) ?>">
               <i class="far fa-book"></i> <?= fh($issue['journal_title']) ?><?= $issue['short_name'] ? ' (' . fh($issue['short_name']) . ')' : '' ?>
            </a>
            <h2 class="mt-3 mb-2" style="font-weight:800;">
               Vol. <?= (int)$issue['volume_number'] ?>, No. <?= (int)$issue['issue_number'] ?> (<?= (int)$issue['year'] ?>)
            </h2>
            <?php if ($issue['title']): ?>
            <div class="mb-2" style="font-size:1.05rem;opacity:.9;"><?= fh($issue['title']) ?></div>
            <?php endif; ?>
            <div class="d-flex flex-wrap gap-2">
               <?php if ($issue['published_date']): ?>
               <span class="jm-pill"><i class="far fa-calendar-check"></i>
                  Published <?= fh(date('d F Y', strtotime($issue['published_date']))) ?></span>
               <?php endif; ?>
               <span class="jm-pill"><i class="far fa-file-lines"></i> <?= count($articles) ?> Article<?= count($articles) === 1 ? '' : 's' ?></span>
            </div>
         </div>

         <div class="jm-section-title mt-4" style="font-size:1.15rem;">Table of Contents</div>

         <?php if (!$articles): ?>
         <div class="jm-card"><div class="jm-card-body jm-meta-line">No articles in this issue yet.</div></div>
         <?php endif; ?>

         <?php foreach ($articles as $k => $a): ?>
         <div class="jm-card jm-card-hover mb-3">
            <div class="jm-art">
               <div class="jm-art-num"><?= $k + 1 ?></div>
               <div class="flex-grow-1">
                  <h5><a href="<?= fh(SITE_URL) ?>/journal-article.php?slug=<?= fh(rawurlencode($a['slug'])) ?>"><?= fh($a['title']) ?></a></h5>
                  <?php if ($a['author_names']): ?>
                  <div class="jm-authors mb-1"><i class="far fa-users me-1" style="color:var(--jm-blue);"></i><?= fh($a['author_names']) ?></div>
                  <?php endif; ?>
                  <?php if ($a['page_from']): ?>
                  <div class="jm-meta-line"><i class="far fa-file-alt"></i>
                     pp. <?= (int)$a['page_from'] ?><?= $a['page_to'] ? '–' . (int)$a['page_to'] : '' ?></div>
                  <?php endif; ?>
               </div>
               <div class="d-flex flex-column gap-2 align-items-end justify-content-center">
                  <a class="jm-btn jm-btn-outline" style="padding:9px 18px;font-size:.82rem;"
                     href="<?= fh(SITE_URL) ?>/journal-article.php?slug=<?= fh(rawurlencode($a['slug'])) ?>">
                     Abstract <i class="far fa-arrow-right"></i></a>
                  <?php if ($a['pdf_file']): ?>
                  <a class="jm-btn jm-btn-pdf" style="padding:9px 18px;font-size:.82rem;"
                     href="<?= fh(SITE_URL) ?>/journal-pdf.php?slug=<?= fh(rawurlencode($a['slug'])) ?>">
                     <i class="far fa-file-pdf"></i> PDF</a>
                  <?php endif; ?>
               </div>
            </div>
         </div>
         <?php endforeach; ?>

         <div class="mt-4">
            <a class="jm-btn jm-btn-outline" href="<?= fh(SITE_URL) ?>/journal.php?slug=<?= fh(rawurlencode($issue['journal_slug'])) ?>">
               <i class="far fa-arrow-left"></i> Back to <?= fh($issue['short_name'] ?: 'Journal') ?></a>
         </div>
         <?php endif; ?>

      </div>
   </div>
   </main>
<?php include __DIR__ . '/includes/footer.php'; ?>
   <?php include __DIR__ . '/includes/scripts.php'; ?>
</body>
</html>
