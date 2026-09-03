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
                    j.name AS journal_title, j.slug AS journal_slug, j.publisher,
                    j.issn, j.e_issn, j.short_name
             FROM journal_articles a
             JOIN journal_issues   i ON i.id = a.issue_id
             JOIN journal_volumes  v ON v.id = i.volume_id
             JOIN journal_journals j ON j.id = v.journal_id
             WHERE a.slug = ? AND a.status = 'published' AND j.status = 'active'
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
$cite_year     = '';
if ($article) {
    $d = $article['published_date'] ?: $article['issue_date'];
    $citation_date = $d ? date('Y/m/d', strtotime($d)) : (string)$article['year'];
    $cite_year     = $d ? date('Y', strtotime($d)) : (string)$article['year'];
}

/** Author initials for avatar chips. */
function jm_author_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $parts = array_values(array_filter($parts, fn($p) => $p !== '' && ctype_alpha($p[0]) && !in_array(strtolower(rtrim($p, '.')), ['dr', 'prof', 'md', 'mr', 'mrs', 'ms'], true)));
    $first = $parts[0][0] ?? 'A';
    $last  = count($parts) > 1 ? $parts[count($parts) - 1][0] : '';
    return strtoupper($first . $last);
}

// Build a simple APA-like citation string.
$cite_text = '';
if ($article) {
    $names = implode(', ', array_column($authors, 'full_name'));
    $pages = $article['page_from']
        ? 'pp. ' . (int)$article['page_from'] . ($article['page_to'] ? '–' . (int)$article['page_to'] : '') . '.'
        : '';
    $cite_text = trim(
        ($names !== '' ? $names . ' ' : '') . '(' . $cite_year . '). ' . $article['title'] . '. ' .
        $article['journal_title'] . ', ' . (int)$article['volume_number'] . '(' . (int)$article['issue_number'] . '), ' . $pages
    );
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
   <?php if ($article['e_issn'] || $article['issn']): ?>
   <meta name="citation_issn" content="<?= fh($article['e_issn'] ?: $article['issn']) ?>">
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

         <?php if (!$article): ?>
         <div class="jm-card"><div class="jm-card-body text-center py-5">
            <i class="far fa-file-lines mb-3" style="font-size:2.4rem;color:var(--jm-blue);"></i>
            <h3 class="mb-2">Article Not Found</h3>
            <p class="jm-meta-line mb-4">The article you are looking for does not exist or is not published.</p>
            <a class="jm-btn jm-btn-outline" href="<?= fh(SITE_URL) ?>/journal.php"><i class="far fa-arrow-left"></i> Browse all journals</a>
         </div></div>
         <?php else: ?>

         <!-- ── Article hero ── -->
         <div class="jm-hero mb-4">
            <div class="d-flex flex-wrap gap-2 mb-3">
               <a class="jm-pill" style="text-decoration:none;"
                  href="<?= fh(SITE_URL) ?>/journal.php?slug=<?= fh(rawurlencode($article['journal_slug'])) ?>">
                  <i class="far fa-book"></i> <?= fh($article['short_name'] ?: $article['journal_title']) ?></a>
               <a class="jm-pill" style="text-decoration:none;"
                  href="<?= fh(SITE_URL) ?>/journal-issue.php?id=<?= (int)$article['issue_id'] ?>">
                  <i class="far fa-book-open"></i> Vol. <?= (int)$article['volume_number'] ?>, No. <?= (int)$article['issue_number'] ?> (<?= (int)$article['year'] ?>)</a>
               <?php if ($article['published_date']): ?>
               <span class="jm-pill"><i class="far fa-calendar-check"></i> <?= fh(date('d F Y', strtotime($article['published_date']))) ?></span>
               <?php endif; ?>
            </div>
            <h1 class="mb-3" style="font-weight:800;font-size:1.7rem;line-height:1.4;"><?= fh($article['title']) ?></h1>
            <?php if ($authors): ?>
            <div>
               <?php foreach ($authors as $au): ?>
               <span class="jm-author-chip" style="background:rgba(255,255,255,.12);border-color:rgba(255,255,255,.3);color:#fff;">
                  <span class="jm-avatar"><?= fh(jm_author_initials($au['full_name'])) ?></span>
                  <span><?= fh($au['full_name']) ?>
                     <?php if ($au['affiliation']): ?><small style="color:rgba(255,255,255,.7);"> · <?= fh($au['affiliation']) ?></small><?php endif; ?>
                  </span>
               </span>
               <?php endforeach; ?>
            </div>
            <?php endif; ?>
         </div>

         <div class="row g-4">
            <div class="col-lg-8">
               <?php if ($article['abstract']): ?>
               <div class="jm-card mb-4"><div class="jm-card-body">
                  <div class="jm-section-title">Abstract</div>
                  <div class="jm-abstract"><?= nl2br(fh($article['abstract'])) ?></div>
               </div></div>
               <?php endif; ?>

               <?php if ($article['keywords']): ?>
               <div class="jm-card mb-4"><div class="jm-card-body">
                  <div class="jm-section-title">Keywords</div>
                  <div>
                     <?php foreach (array_filter(array_map('trim', explode(',', $article['keywords']))) as $kw): ?>
                     <span class="jm-tag"><i class="far fa-tag me-1" style="color:var(--jm-blue);"></i><?= fh($kw) ?></span>
                     <?php endforeach; ?>
                  </div>
               </div></div>
               <?php endif; ?>

               <?php if ($cite_text): ?>
               <div class="jm-card mb-4"><div class="jm-card-body">
                  <div class="jm-section-title">How to Cite</div>
                  <div class="jm-cite-box" id="jm-citation"><?= fh($cite_text) ?></div>
                  <button class="jm-btn jm-btn-outline mt-3" style="padding:9px 18px;font-size:.82rem;"
                          onclick="navigator.clipboard.writeText(document.getElementById('jm-citation').innerText).then(()=>{this.innerHTML='<i class\u003d\u0022far fa-check\u0022></i> Copied!';setTimeout(()=>{this.innerHTML='<i class\u003d\u0022far fa-copy\u0022></i> Copy Citation';},2000);});">
                     <i class="far fa-copy"></i> Copy Citation</button>
               </div></div>
               <?php endif; ?>
            </div>

            <div class="col-lg-4">
               <div class="jm-sticky">
                  <?php if ($pdf_url): ?>
                  <a class="jm-btn jm-btn-pdf w-100 mb-3" style="padding:15px 24px;" href="<?= fh($pdf_url) ?>">
                     <i class="far fa-file-pdf"></i> Download Full Text PDF</a>
                  <?php endif; ?>

                  <div class="row g-3 mb-3">
                     <div class="col-6"><div class="jm-stat"><i class="far fa-eye"></i>
                        <div><div class="n"><?= number_format((int)$article['views'] + 1) ?></div><div class="l">Views</div></div></div></div>
                     <div class="col-6"><div class="jm-stat"><i class="far fa-download"></i>
                        <div><div class="n"><?= number_format((int)$article['downloads']) ?></div><div class="l">Downloads</div></div></div></div>
                  </div>

                  <div class="jm-card"><div class="jm-card-body">
                     <div class="jm-section-title">Article Details</div>
                     <ul class="jm-info-list">
                        <li><span class="k">Journal</span>
                            <span class="v"><a href="<?= fh(SITE_URL) ?>/journal.php?slug=<?= fh(rawurlencode($article['journal_slug'])) ?>"><?= fh($article['short_name'] ?: $article['journal_title']) ?></a></span></li>
                        <li><span class="k">Issue</span>
                            <span class="v"><a href="<?= fh(SITE_URL) ?>/journal-issue.php?id=<?= (int)$article['issue_id'] ?>">Vol. <?= (int)$article['volume_number'] ?>, No. <?= (int)$article['issue_number'] ?></a></span></li>
                        <li><span class="k">Year</span><span class="v"><?= (int)$article['year'] ?></span></li>
                        <?php if ($article['page_from']): ?>
                        <li><span class="k">Pages</span><span class="v"><?= (int)$article['page_from'] ?><?= $article['page_to'] ? '–' . (int)$article['page_to'] : '' ?></span></li>
                        <?php endif; ?>
                        <?php if ($article['published_date']): ?>
                        <li><span class="k">Published</span><span class="v"><?= fh(date('d M Y', strtotime($article['published_date']))) ?></span></li>
                        <?php endif; ?>
                        <?php if ($article['doi']): ?>
                        <li><span class="k">DOI</span>
                            <span class="v"><a href="https://doi.org/<?= fh($article['doi']) ?>" target="_blank" rel="noopener"><?= fh($article['doi']) ?></a></span></li>
                        <?php endif; ?>
                        <?php if ($article['issn']): ?>
                        <li><span class="k">ISSN</span><span class="v"><?= fh($article['issn']) ?></span></li>
                        <?php endif; ?>
                        <?php if ($article['e_issn']): ?>
                        <li><span class="k">e-ISSN</span><span class="v"><?= fh($article['e_issn']) ?></span></li>
                        <?php endif; ?>
                     </ul>
                  </div></div>

                  <a class="jm-btn jm-btn-outline w-100 mt-3" href="<?= fh(SITE_URL) ?>/journal-issue.php?id=<?= (int)$article['issue_id'] ?>">
                     <i class="far fa-arrow-left"></i> Back to Issue</a>
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
