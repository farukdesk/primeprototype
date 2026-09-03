<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/seo.php';

$slug = trim($_GET['slug'] ?? '');
$article = null;
$authors = [];
$settings = [];

try {
    $db = front_db();
    if ($db && $slug !== '') {
        $stmt = $db->prepare(
            "SELECT a.*, i.issue_number, i.published_date AS issue_date, i.id AS issue_id,
                    v.volume_number, v.year,
                    j.title AS journal_title, j.slug AS journal_slug, j.publisher,
                    j.issn_print, j.issn_online
             FROM journal_articles a
             JOIN journal_issues   i ON i.id = a.issue_id
             JOIN journal_volumes  v ON v.id = i.volume_id
             JOIN journal_journals j ON j.id = v.journal_id
             WHERE a.slug = ? AND a.status = 'published' AND j.is_active = 1
             LIMIT 1"
        );
        $stmt->execute([$slug]);
        $article = $stmt->fetch();
        if ($article) {
            $stmt = $db->prepare(
                'SELECT au.full_name, au.affiliation
                 FROM journal_article_authors aa
                 JOIN journal_authors au ON au.id = aa.author_id
                 WHERE aa.article_id = ?
                 ORDER BY aa.author_order ASC, au.full_name ASC'
            );
            $stmt->execute([$article['id']]);
            $authors = $stmt->fetchAll();

            foreach ($db->query('SELECT setting_key, setting_val FROM journal_settings')->fetchAll() as $r) {
                $settings[$r['setting_key']] = $r['setting_val'];
            }
            $db->prepare('UPDATE journal_articles SET views = views + 1 WHERE id = ?')->execute([$article['id']]);
        }
    }
} catch (Throwable $e) {
}

$page_title    = $article ? $article['title'] : 'Article Not Found';
$canonical_url = SITE_URL . '/journal-article.php?slug=' . rawurlencode($slug);
$pdf_url       = ($article && $article['pdf_file']) ? SITE_URL . '/journal-pdf.php?slug=' . rawurlencode($slug) : null;
$citation_date = '';
if ($article) {
    $d = $article['published_date'] ?: $article['issue_date'];
    $citation_date = $d ? date('Y/m/d', strtotime($d)) : (string)$article['year'];
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
$_desc = $article ? mb_substr(strip_tags((string)$article['abstract']), 0, 160) : null;
render_seo_meta('/journal-article.php?slug=' . urlencode($slug), $page_title, $_desc, null);
?>
<?php if ($article): ?>
   <!-- Google Scholar (Highwire Press) citation metadata -->
   <link rel="canonical" href="<?= fh($canonical_url) ?>">
   <meta name="citation_journal_title" content="<?= fh($article['journal_title']) ?>">
   <meta name="citation_publisher" content="<?= fh($article['publisher'] ?: ($settings['publisher_name'] ?? 'Prime University')) ?>">
   <?php if ($article['issn_online'] || $article['issn_print']): ?>
   <meta name="citation_issn" content="<?= fh($article['issn_online'] ?: $article['issn_print']) ?>">
   <?php endif; ?>
   <meta name="citation_title" content="<?= fh($article['title']) ?>">
   <?php foreach ($authors as $au): ?>
   <meta name="citation_author" content="<?= fh($au['full_name']) ?>">
   <?php if ($au['affiliation']): ?>
   <meta name="citation_author_institution" content="<?= fh($au['affiliation']) ?>">
   <?php endif; ?>
   <?php endforeach; ?>
   <meta name="citation_publication_date" content="<?= fh($citation_date) ?>">
   <meta name="citation_volume" content="<?= (int)$article['volume_number'] ?>">
   <meta name="citation_issue" content="<?= (int)$article['issue_number'] ?>">
   <?php if ($article['page_from']): ?>
   <meta name="citation_firstpage" content="<?= (int)$article['page_from'] ?>">
   <?php endif; ?>
   <?php if ($article['page_to']): ?>
   <meta name="citation_lastpage" content="<?= (int)$article['page_to'] ?>">
   <?php endif; ?>
   <?php if ($pdf_url): ?>
   <meta name="citation_pdf_url" content="<?= fh($pdf_url) ?>">
   <?php endif; ?>
   <meta name="citation_abstract_html_url" content="<?= fh($canonical_url) ?>">
   <?php if ($article['doi']): ?>
   <meta name="citation_doi" content="<?= fh($article['doi']) ?>">
   <?php endif; ?>
   <?php if ($article['keywords']): ?>
   <meta name="citation_keywords" content="<?= fh($article['keywords']) ?>">
   <?php endif; ?>
   <meta name="citation_language" content="<?= fh($settings['gs_language'] ?? 'en') ?>">
<?php endif; ?>
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
               <h3 class="it-breadcrumb-title style-2"><?= $article ? fh($article['journal_title']) : 'Article Not Found' ?></h3>
            </div>
            <div class="it-breadcrumb-list"><ul>
               <li><a href="<?= fh(SITE_URL) ?>/index.php">Home</a></li>
               <?php if ($article): ?>
               <li><a href="<?= fh(SITE_URL) ?>/journal.php?slug=<?= fh(rawurlencode($article['journal_slug'])) ?>">Journal</a></li>
               <?php endif; ?>
               <li><span>Article</span></li>
            </ul></div>
         </div>
      </div></div></div>
   </div>

   <div class="postbox-area pt-100 pb-100">
      <div class="container">
         <?php if (!$article): ?>
         <div class="text-center py-5">
            <h3 class="mb-3">Article Not Found</h3>
            <p>The article you are looking for does not exist or is not published.</p>
            <a href="<?= fh(SITE_URL) ?>/journal.php">Browse all journals</a>
         </div>
         <?php else: ?>
         <div class="row">
            <div class="col-lg-8">
               <h3 class="mb-3"><?= fh($article['title']) ?></h3>
               <?php if ($authors): ?>
               <div class="mb-3">
                  <?php foreach ($authors as $k => $au): ?>
                  <span class="fw-semibold"><?= fh($au['full_name']) ?></span><?php
                     if ($au['affiliation']): ?><span class="text-muted small"> (<?= fh($au['affiliation']) ?>)</span><?php endif;
                     echo $k < count($authors) - 1 ? ', ' : ''; ?>
                  <?php endforeach; ?>
               </div>
               <?php endif; ?>

               <?php if ($article['abstract']): ?>
               <h5>Abstract</h5>
               <p><?= nl2br(fh($article['abstract'])) ?></p>
               <?php endif; ?>

               <?php if ($article['keywords']): ?>
               <p><strong>Keywords:</strong> <?= fh($article['keywords']) ?></p>
               <?php endif; ?>

               <?php if ($pdf_url): ?>
               <a class="btn btn-danger mt-3" href="<?= fh($pdf_url) ?>">
                  <i class="far fa-file-pdf me-1"></i> Download Full Text PDF
               </a>
               <?php endif; ?>
            </div>
            <div class="col-lg-4">
               <div class="card" style="border-radius:12px;">
                  <div class="card-body small">
                     <h5 class="mb-3">Article Details</h5>
                     <ul class="list-unstyled mb-0">
                        <li class="mb-1"><strong>Journal:</strong>
                           <a href="<?= fh(SITE_URL) ?>/journal.php?slug=<?= fh(rawurlencode($article['journal_slug'])) ?>"><?= fh($article['journal_title']) ?></a></li>
                        <li class="mb-1"><strong>Issue:</strong>
                           <a href="<?= fh(SITE_URL) ?>/journal-issue.php?id=<?= (int)$article['issue_id'] ?>">
                              Vol. <?= (int)$article['volume_number'] ?>, No. <?= (int)$article['issue_number'] ?> (<?= (int)$article['year'] ?>)</a></li>
                        <?php if ($article['page_from']): ?>
                        <li class="mb-1"><strong>Pages:</strong> <?= (int)$article['page_from'] ?><?= $article['page_to'] ? '–' . (int)$article['page_to'] : '' ?></li>
                        <?php endif; ?>
                        <?php if ($article['published_date']): ?>
                        <li class="mb-1"><strong>Published:</strong> <?= fh(date('d F Y', strtotime($article['published_date']))) ?></li>
                        <?php endif; ?>
                        <?php if ($article['doi']): ?>
                        <li class="mb-1"><strong>DOI:</strong>
                           <a href="https://doi.org/<?= fh($article['doi']) ?>" target="_blank" rel="noopener"><?= fh($article['doi']) ?></a></li>
                        <?php endif; ?>
                        <?php if ($article['issn_online'] || $article['issn_print']): ?>
                        <li class="mb-1"><strong>ISSN:</strong> <?= fh($article['issn_online'] ?: $article['issn_print']) ?></li>
                        <?php endif; ?>
                        <li class="mb-1"><strong>Views:</strong> <?= (int)$article['views'] + 1 ?>
                            &nbsp; <strong>Downloads:</strong> <?= (int)$article['downloads'] ?></li>
                     </ul>
                  </div>
               </div>
            </div>
         </div>
         <?php endif; ?>
      </div>
   </div>
   </main>
<?php include __DIR__ . '/includes/footer.php'; ?>
   <?php include __DIR__ . '/includes/scripts.php'; ?>
</body>
</html>
