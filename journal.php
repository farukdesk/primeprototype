<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/seo.php';

$slug    = trim($_GET['slug'] ?? '');
$journal = null;
$journals = [];
$board = [];
$current_issue = null;
$volumes = [];

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
                }
                unset($v);
            }
        } else {
            $journals = $db->query(
                "SELECT * FROM journal_journals WHERE status = 'active' ORDER BY sort_order ASC, name ASC"
            )->fetchAll();
        }
    }
} catch (Throwable $e) {
    // tables may not exist yet - fall through with empty data
}

$page_title = $journal ? $journal['name'] : 'University Journals';
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
               <li><span>Journals</span></li>
            </ul></div>
         </div>
      </div></div></div>
   </div>

   <div class="postbox-area pt-100 pb-100">
      <div class="container">

         <?php if ($journal): ?>
         <div class="row">
            <div class="col-lg-8">
               <?php if (!empty($journal['description'])): ?>
               <h4 class="mb-3">About the Journal</h4>
               <div class="mb-4"><?= nl2br(fh($journal['description'])) ?></div>
               <?php endif; ?>

               <?php if ($current_issue): ?>
               <h4 class="mb-3">Current Issue</h4>
               <div class="card mb-4" style="border-radius:12px;">
                  <div class="card-body">
                     <a class="fw-bold" href="<?= fh(SITE_URL) ?>/journal-issue.php?id=<?= (int)$current_issue['id'] ?>">
                        Vol. <?= (int)$current_issue['volume_number'] ?>, No. <?= (int)$current_issue['issue_number'] ?>
                        (<?= (int)$current_issue['year'] ?>)
                        <?= $current_issue['title'] ? ' – ' . fh($current_issue['title']) : '' ?>
                     </a>
                     <?php if ($current_issue['published_date']): ?>
                     <div class="small text-muted">Published: <?= fh(date('d F Y', strtotime($current_issue['published_date']))) ?></div>
                     <?php endif; ?>
                  </div>
               </div>
               <?php endif; ?>

               <h4 class="mb-3">Archives</h4>
               <?php if (!$volumes): ?><p class="text-muted">No published issues yet.</p><?php endif; ?>
               <?php foreach ($volumes as $v): if (empty($v['issues'])) continue; ?>
               <div class="mb-3">
                  <div class="fw-semibold mb-1">Volume <?= (int)$v['volume_number'] ?> (<?= (int)$v['year'] ?>)</div>
                  <ul class="list-unstyled ms-3">
                     <?php foreach ($v['issues'] as $i): ?>
                     <li class="mb-1">
                        <a href="<?= fh(SITE_URL) ?>/journal-issue.php?id=<?= (int)$i['id'] ?>">
                           <i class="far fa-book-open me-1"></i>
                           No. <?= (int)$i['issue_number'] ?><?= $i['title'] ? ' – ' . fh($i['title']) : '' ?>
                        </a>
                        <?php if ($i['published_date']): ?>
                        <span class="small text-muted">(<?= fh(date('M Y', strtotime($i['published_date']))) ?>)</span>
                        <?php endif; ?>
                     </li>
                     <?php endforeach; ?>
                  </ul>
               </div>
               <?php endforeach; ?>
            </div>

            <div class="col-lg-4">
               <div class="card mb-4" style="border-radius:12px;">
                  <div class="card-body">
                     <h5 class="mb-3">Journal Information</h5>
                     <ul class="list-unstyled small mb-0">
                        <?php if ($journal['short_name']): ?><li><strong>Short Name:</strong> <?= fh($journal['short_name']) ?></li><?php endif; ?>
                        <?php if ($journal['issn']): ?><li><strong>ISSN:</strong> <?= fh($journal['issn']) ?></li><?php endif; ?>
                        <?php if ($journal['e_issn']): ?><li><strong>e-ISSN:</strong> <?= fh($journal['e_issn']) ?></li><?php endif; ?>
                        <?php if ($journal['publisher']): ?><li><strong>Publisher:</strong> <?= fh($journal['publisher']) ?></li><?php endif; ?>
                        <?php if ($journal['department']): ?><li><strong>Department:</strong> <?= fh($journal['department']) ?></li><?php endif; ?>
                        <?php if ($journal['frequency']): ?><li><strong>Frequency:</strong> <?= fh($journal['frequency']) ?></li><?php endif; ?>
                        <?php if ($journal['language']): ?><li><strong>Language:</strong> <?= fh($journal['language']) ?></li><?php endif; ?>
                        <?php if ($journal['contact_email']): ?><li><strong>Contact:</strong> <?= fh($journal['contact_email']) ?></li><?php endif; ?>
                        <?php if ($journal['website_url']): ?><li><strong>Website:</strong>
                           <a href="<?= fh($journal['website_url']) ?>" target="_blank" rel="noopener"><?= fh($journal['website_url']) ?></a></li><?php endif; ?>
                     </ul>
                  </div>
               </div>
               <?php if ($board): ?>
               <div class="card" style="border-radius:12px;">
                  <div class="card-body">
                     <h5 class="mb-3">Editorial Board</h5>
                     <ul class="list-unstyled small mb-0">
                        <?php foreach ($board as $m): ?>
                        <li class="mb-2">
                           <div class="fw-semibold"><?= fh($m['name']) ?></div>
                           <div class="text-muted"><?= fh($m['role']) ?><?= $m['affiliation'] ? ', ' . fh($m['affiliation']) : '' ?></div>
                        </li>
                        <?php endforeach; ?>
                     </ul>
                  </div>
               </div>
               <?php endif; ?>
            </div>
         </div>

         <?php elseif ($slug !== ''): ?>
         <div class="text-center py-5">
            <h3 class="mb-3">Journal Not Found</h3>
            <p>The journal you are looking for does not exist or is not available.</p>
            <a href="<?= fh(SITE_URL) ?>/journal.php">Browse all journals</a>
         </div>

         <?php else: ?>
         <div class="row">
            <?php if (!$journals): ?>
            <div class="col-12 text-center py-5 text-muted">No journals are available yet.</div>
            <?php endif; ?>
            <?php foreach ($journals as $j): ?>
            <div class="col-md-6 col-lg-4 mb-4">
               <div class="card h-100" style="border-radius:12px;">
                  <div class="card-body">
                     <h5><a href="<?= fh(SITE_URL) ?>/journal.php?slug=<?= fh(rawurlencode($j['slug'])) ?>"><?= fh($j['name']) ?><?= $j['short_name'] ? ' (' . fh($j['short_name']) . ')' : '' ?></a></h5>
                     <?php if ($j['issn'] || $j['e_issn']): ?>
                     <div class="small text-muted mb-2">
                        <?= $j['issn'] ? 'ISSN: ' . fh($j['issn']) : '' ?>
                        <?= $j['e_issn'] ? ' e-ISSN: ' . fh($j['e_issn']) : '' ?>
                     </div>
                     <?php endif; ?>
                     <p class="small mb-0"><?= fh(mb_substr(strip_tags((string)$j['description']), 0, 180)) ?></p>
                  </div>
               </div>
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
