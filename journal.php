<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/seo.php';

$slug    = trim($_GET['slug'] ?? '');
$journal = null;
$journals = [];
$board = [];
$current_issue = null;
$volumes = [];
$stats = ['articles' => 0, 'issues' => 0];

try {
    $db = front_db();
    if ($db) {
        if ($slug !== '') {
            $stmt = $db->prepare("SELECT * FROM journal_journals WHERE slug = ? AND status = 'active' LIMIT 1");
            $stmt->execute([$slug]);
            $journal = $stmt->fetch();
            if ($journal) {
                $stmt = $db->prepare(
                    'SELECT * FROM journal_editorial_board WHERE journal_id = ? AND is_active = 1
                     ORDER BY sort_order ASC, name ASC'
                );
                $stmt->execute([$journal['id']]);
                $board = $stmt->fetchAll();

                $stmt = $db->prepare(
                    'SELECT i.*, v.volume_number, v.year
                     FROM journal_issues i JOIN journal_volumes v ON v.id = i.volume_id
                     WHERE v.journal_id = ? AND i.is_published = 1 AND i.is_current = 1
                     LIMIT 1'
                );
                $stmt->execute([$journal['id']]);
                $current_issue = $stmt->fetch();

                $stmt = $db->prepare(
                    'SELECT v.id, v.volume_number, v.year
                     FROM journal_volumes v WHERE v.journal_id = ? ORDER BY v.volume_number DESC'
                );
                $stmt->execute([$journal['id']]);
                $volumes = $stmt->fetchAll();
                $istmt = $db->prepare(
                    'SELECT id, issue_number, title, published_date FROM journal_issues
                     WHERE volume_id = ? AND is_published = 1 ORDER BY issue_number DESC'
                );
                foreach ($volumes as &$v) {
                    $istmt->execute([$v['id']]);
                    $v['issues'] = $istmt->fetchAll();
                    $stats['issues'] += count($v['issues']);
                }
                unset($v);

                $stmt = $db->prepare(
                    "SELECT COUNT(*) FROM journal_articles a
                     JOIN journal_issues i  ON i.id = a.issue_id
                     JOIN journal_volumes v ON v.id = i.volume_id
                     WHERE v.journal_id = ? AND a.status = 'published' AND i.is_published = 1"
                );
                $stmt->execute([$journal['id']]);
                $stats['articles'] = (int)$stmt->fetchColumn();
            }
        } else {
            $journals = $db->query(
                "SELECT j.*,
                        (SELECT COUNT(*) FROM journal_volumes v WHERE v.journal_id = j.id) AS volume_count
                 FROM journal_journals j WHERE j.status = 'active' ORDER BY j.sort_order ASC, j.name ASC"
            )->fetchAll();
        }
    }
} catch (Throwable $e) {
    // tables may not exist yet - fall through with empty data
}

$page_title = $journal ? $journal['name'] : 'University Journals';

/** Monogram initials for a journal without a logo. */
function jm_initials(string $name, string $short = ''): string
{
    if ($short !== '') return mb_substr($short, 0, 4);
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $ini = '';
    foreach ($parts as $p) {
        if ($p !== '' && ctype_alpha($p[0])) $ini .= strtoupper($p[0]);
        if (mb_strlen($ini) >= 3) break;
    }
    return $ini !== '' ? $ini : 'J';
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
$_desc = $journal ? mb_substr(strip_tags((string)$journal['description']), 0, 160) : 'Academic journals published by the university.';
render_seo_meta('/journal.php' . ($slug !== '' ? '?slug=' . urlencode($slug) : ''), $page_title, $_desc, null);
?>
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

         <?php if ($journal): ?>
         <!-- ── Journal hero ── -->
         <div class="jm-hero mb-4">
            <div class="d-flex flex-wrap align-items-center gap-4">
               <div class="jm-logo">
                  <?php if (!empty($journal['logo'])): ?>
                  <img src="<?= fh(ADMIN_UPLOAD_URL . '/journal/logos/' . rawurlencode($journal['logo'])) ?>" alt="<?= fh($journal['name']) ?>">
                  <?php else: ?>
                  <?= fh(jm_initials($journal['name'], (string)$journal['short_name'])) ?>
                  <?php endif; ?>
               </div>
               <div class="flex-grow-1">
                  <?php if ($journal['short_name']): ?><span class="jm-shortname mb-2"><?= fh($journal['short_name']) ?></span><?php endif; ?>
                  <h2 class="mt-2 mb-2" style="font-weight:800;"><?= fh($journal['name']) ?></h2>
                  <div class="d-flex flex-wrap gap-2">
                     <?php if ($journal['issn']): ?><span class="jm-pill"><i class="far fa-barcode"></i> ISSN <?= fh($journal['issn']) ?></span><?php endif; ?>
                     <?php if ($journal['e_issn']): ?><span class="jm-pill"><i class="far fa-globe"></i> e-ISSN <?= fh($journal['e_issn']) ?></span><?php endif; ?>
                     <?php if ($journal['frequency']): ?><span class="jm-pill"><i class="far fa-calendar"></i> <?= fh($journal['frequency']) ?></span><?php endif; ?>
                     <?php if ($journal['language']): ?><span class="jm-pill"><i class="far fa-language"></i> <?= fh($journal['language']) ?></span><?php endif; ?>
                  </div>
               </div>
               <div class="d-flex flex-column gap-2">
                  <?php if ($current_issue): ?>
                  <a class="jm-btn jm-btn-light" href="<?= fh(SITE_URL) ?>/journal-issue.php?id=<?= (int)$current_issue['id'] ?>">
                     <i class="far fa-book-open"></i> Current Issue</a>
                  <?php endif; ?>
                  <?php if ($journal['contact_email']): ?>
                  <a class="jm-btn jm-btn-light" href="mailto:<?= fh($journal['contact_email']) ?>">
                     <i class="far fa-envelope"></i> Contact</a>
                  <?php endif; ?>
               </div>
            </div>
         </div>

         <div class="row g-4">
            <div class="col-lg-8">
               <?php if (!empty($journal['description'])): ?>
               <div class="jm-card mb-4"><div class="jm-card-body">
                  <div class="jm-section-title">About the Journal</div>
                  <div class="jm-abstract"><?= nl2br(fh($journal['description'])) ?></div>
               </div></div>
               <?php endif; ?>

               <?php if ($current_issue): ?>
               <div class="jm-current-card jm-card mb-4"><div class="jm-card-body pt-4">
                  <span class="jm-current-badge"><i class="far fa-star me-1"></i>Current Issue</span>
                  <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mt-2">
                     <div>
                        <h5 class="mb-1" style="font-weight:700;">
                           Vol. <?= (int)$current_issue['volume_number'] ?>, No. <?= (int)$current_issue['issue_number'] ?>
                           (<?= (int)$current_issue['year'] ?>)
                           <?= $current_issue['title'] ? ' – ' . fh($current_issue['title']) : '' ?>
                        </h5>
                        <?php if ($current_issue['published_date']): ?>
                        <div class="jm-meta-line"><i class="far fa-calendar-check"></i>
                           Published <?= fh(date('d F Y', strtotime($current_issue['published_date']))) ?></div>
                        <?php endif; ?>
                     </div>
                     <a class="jm-btn jm-btn-outline" href="<?= fh(SITE_URL) ?>/journal-issue.php?id=<?= (int)$current_issue['id'] ?>">
                        View Table of Contents <i class="far fa-arrow-right"></i></a>
                  </div>
               </div></div>
               <?php endif; ?>

               <div class="jm-card"><div class="jm-card-body">
                  <div class="jm-section-title">Archives</div>
                  <?php $has_issues = false; foreach ($volumes as $v) { if (!empty($v['issues'])) { $has_issues = true; break; } } ?>
                  <?php if (!$has_issues): ?><p class="jm-meta-line mb-0">No published issues yet.</p><?php endif; ?>
                  <?php foreach ($volumes as $v): if (empty($v['issues'])) continue; ?>
                  <div class="mb-3">
                     <div class="fw-bold mb-2" style="color:var(--jm-ink);">
                        <i class="far fa-layer-group me-1" style="color:var(--jm-blue);"></i>
                        Volume <?= (int)$v['volume_number'] ?> <span class="jm-meta-line">(<?= (int)$v['year'] ?>)</span>
                     </div>
                     <div>
                        <?php foreach ($v['issues'] as $i): ?>
                        <a class="jm-issue-link" href="<?= fh(SITE_URL) ?>/journal-issue.php?id=<?= (int)$i['id'] ?>">
                           <i class="far fa-book-open" style="color:var(--jm-blue);"></i>
                           No. <?= (int)$i['issue_number'] ?><?= $i['title'] ? ' · ' . fh($i['title']) : '' ?>
                           <?php if ($i['published_date']): ?>
                           <span class="jm-issue-date"><?= fh(date('M Y', strtotime($i['published_date']))) ?></span>
                           <?php endif; ?>
                        </a>
                        <?php endforeach; ?>
                     </div>
                  </div>
                  <?php endforeach; ?>
               </div></div>
            </div>

            <div class="col-lg-4">
               <div class="jm-sticky">
                  <div class="row g-3 mb-3">
                     <div class="col-6"><div class="jm-stat"><i class="far fa-file-lines"></i>
                        <div><div class="n"><?= (int)$stats['articles'] ?></div><div class="l">Articles</div></div></div></div>
                     <div class="col-6"><div class="jm-stat"><i class="far fa-book-open"></i>
                        <div><div class="n"><?= (int)$stats['issues'] ?></div><div class="l">Issues</div></div></div></div>
                  </div>

                  <div class="jm-card mb-4"><div class="jm-card-body">
                     <div class="jm-section-title">Journal Information</div>
                     <ul class="jm-info-list">
                        <?php if ($journal['short_name']): ?><li><span class="k">Short Name</span><span class="v"><?= fh($journal['short_name']) ?></span></li><?php endif; ?>
                        <?php if ($journal['issn']): ?><li><span class="k">ISSN</span><span class="v"><?= fh($journal['issn']) ?></span></li><?php endif; ?>
                        <?php if ($journal['e_issn']): ?><li><span class="k">e-ISSN</span><span class="v"><?= fh($journal['e_issn']) ?></span></li><?php endif; ?>
                        <?php if ($journal['publisher']): ?><li><span class="k">Publisher</span><span class="v"><?= fh($journal['publisher']) ?></span></li><?php endif; ?>
                        <?php if ($journal['department']): ?><li><span class="k">Department</span><span class="v"><?= fh($journal['department']) ?></span></li><?php endif; ?>
                        <?php if ($journal['frequency']): ?><li><span class="k">Frequency</span><span class="v"><?= fh($journal['frequency']) ?></span></li><?php endif; ?>
                        <?php if ($journal['language']): ?><li><span class="k">Language</span><span class="v"><?= fh($journal['language']) ?></span></li><?php endif; ?>
                        <?php if ($journal['contact_email']): ?><li><span class="k">Contact</span><span class="v"><a href="mailto:<?= fh($journal['contact_email']) ?>"><?= fh($journal['contact_email']) ?></a></span></li><?php endif; ?>
                        <?php if ($journal['website_url']): ?><li><span class="k">Website</span><span class="v"><a href="<?= fh($journal['website_url']) ?>" target="_blank" rel="noopener">Visit <i class="far fa-external-link"></i></a></span></li><?php endif; ?>
                     </ul>
                  </div></div>

                  <?php if ($board): ?>
                  <div class="jm-card"><div class="jm-card-body">
                     <div class="jm-section-title">Editorial Board</div>
                     <?php foreach ($board as $m): ?>
                     <div class="jm-board-item">
                        <div class="jm-avatar">
                           <?php if (!empty($m['photo'])): ?>
                           <img src="<?= fh(ADMIN_UPLOAD_URL . '/journal/board/' . rawurlencode($m['photo'])) ?>" alt="<?= fh($m['name']) ?>">
                           <?php else: ?><?= fh(jm_initials($m['name'])) ?><?php endif; ?>
                        </div>
                        <div>
                           <div class="fw-semibold" style="font-size:.9rem;color:var(--jm-ink);"><?= fh($m['name']) ?></div>
                           <span class="jm-role-badge"><?= fh($m['role']) ?></span>
                           <?php if ($m['affiliation']): ?>
                           <div class="jm-meta-line mt-1"><?= fh($m['affiliation']) ?></div>
                           <?php endif; ?>
                        </div>
                     </div>
                     <?php endforeach; ?>
                  </div></div>
                  <?php endif; ?>
               </div>
            </div>
         </div>

         <?php elseif ($slug !== ''): ?>
         <div class="jm-card"><div class="jm-card-body text-center py-5">
            <i class="far fa-book-open mb-3" style="font-size:2.4rem;color:var(--jm-blue);"></i>
            <h3 class="mb-2">Journal Not Found</h3>
            <p class="jm-meta-line mb-4">The journal you are looking for does not exist or is not available.</p>
            <a class="jm-btn jm-btn-outline" href="<?= fh(SITE_URL) ?>/journal.php"><i class="far fa-arrow-left"></i> Browse all journals</a>
         </div></div>

         <?php else: ?>
         <!-- ── Journals list ── -->
         <div class="jm-hero mb-5 text-center">
            <h1 class="mb-2" style="font-weight:800;">University Journals</h1>
            <p class="mb-0 mx-auto" style="max-width:640px;opacity:.85;">
               Peer-reviewed academic journals published by the university.
               Browse current issues, explore the archives, and read full-text articles.
            </p>
         </div>
         <div class="row g-4">
            <?php if (!$journals): ?>
            <div class="col-12 text-center py-5 jm-meta-line">No journals are available yet.</div>
            <?php endif; ?>
            <?php foreach ($journals as $j): ?>
            <div class="col-md-6 col-xl-4">
               <div class="jm-card jm-card-hover jm-jcard"><div class="jm-card-body d-flex flex-column h-100">
                  <div class="jm-jcard-top mb-3">
                     <div class="jm-logo">
                        <?php if (!empty($j['logo'])): ?>
                        <img src="<?= fh(ADMIN_UPLOAD_URL . '/journal/logos/' . rawurlencode($j['logo'])) ?>" alt="<?= fh($j['name']) ?>">
                        <?php else: ?><?= fh(jm_initials($j['name'], (string)$j['short_name'])) ?><?php endif; ?>
                     </div>
                     <div>
                        <h5 class="mb-1" style="font-weight:700;">
                           <a href="<?= fh(SITE_URL) ?>/journal.php?slug=<?= fh(rawurlencode($j['slug'])) ?>"><?= fh($j['name']) ?></a>
                        </h5>
                        <?php if ($j['short_name']): ?><span class="jm-chip"><?= fh($j['short_name']) ?></span><?php endif; ?>
                     </div>
                  </div>
                  <div class="mb-3 flex-grow-1">
                     <p class="jm-meta-line mb-2" style="line-height:1.7;">
                        <?= fh(mb_strimwidth(strip_tags((string)$j['description']), 0, 170, '…')) ?></p>
                     <?php if ($j['issn']): ?><div class="jm-meta-line"><i class="far fa-barcode"></i> ISSN: <?= fh($j['issn']) ?></div><?php endif; ?>
                     <?php if ($j['e_issn']): ?><div class="jm-meta-line"><i class="far fa-globe"></i> e-ISSN: <?= fh($j['e_issn']) ?></div><?php endif; ?>
                     <?php if ($j['frequency']): ?><div class="jm-meta-line"><i class="far fa-calendar"></i> <?= fh($j['frequency']) ?></div><?php endif; ?>
                     <?php if ($j['department']): ?><div class="jm-meta-line"><i class="far fa-building-columns"></i> <?= fh($j['department']) ?></div><?php endif; ?>
                  </div>
                  <a class="jm-btn jm-btn-outline w-100" href="<?= fh(SITE_URL) ?>/journal.php?slug=<?= fh(rawurlencode($j['slug'])) ?>">
                     View Journal <i class="far fa-arrow-right"></i></a>
               </div></div>
            </div>
            <?php endforeach; ?>
         </div>
         <?php endif; ?>

      </div>
   </div>
   </main>
<?php include __DIR__ . '/includes/footer.php'; ?>
   <?php include __DIR__ . '/includes/scripts.php'; ?>
</body>
</html>
