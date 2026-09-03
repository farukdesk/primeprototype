<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('journal');
require_once __DIR__ . '/helpers.php';

$page_title = 'Journal Management';
$db = db();

$counts = [
    'journals'  => (int)$db->query('SELECT COUNT(*) FROM journal_journals')->fetchColumn(),
    'volumes'   => (int)$db->query('SELECT COUNT(*) FROM journal_volumes')->fetchColumn(),
    'issues'    => (int)$db->query('SELECT COUNT(*) FROM journal_issues')->fetchColumn(),
    'articles'  => (int)$db->query('SELECT COUNT(*) FROM journal_articles')->fetchColumn(),
    'published' => (int)$db->query("SELECT COUNT(*) FROM journal_articles WHERE status = 'published'")->fetchColumn(),
    'authors'   => (int)$db->query('SELECT COUNT(*) FROM journal_authors')->fetchColumn(),
];
$totals = $db->query('SELECT COALESCE(SUM(views),0) AS v, COALESCE(SUM(downloads),0) AS d FROM journal_articles')->fetch();

$recent = $db->query(
    "SELECT a.id, a.title, a.slug, a.status, a.published_date, a.created_at,
            j.title AS journal_title, v.volume_number, i.issue_number
     FROM journal_articles a
     JOIN journal_issues   i ON i.id = a.issue_id
     JOIN journal_volumes  v ON v.id = i.volume_id
     JOIN journal_journals j ON j.id = v.journal_id
     ORDER BY a.id DESC LIMIT 10"
)->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Journal Management</li>
        </ol>
    </nav>
    <?php if (jm_can_create()): ?>
    <a href="<?= APP_URL ?>/journal/article-form.php" class="btn btn-primary btn-sm" style="border-radius:10px;">
        <i class="fas fa-plus me-1"></i> New Article
    </a>
    <?php endif; ?>
</div>

<?php flash_show(); ?>

<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['Journals',           $counts['journals'],  'fa-book',            '#4f8ef7', 'journals.php'],
        ['Volumes',            $counts['volumes'],   'fa-layer-group',     '#9b59b6', 'volumes-issues.php'],
        ['Issues',             $counts['issues'],    'fa-book-open',       '#e67e22', 'volumes-issues.php'],
        ['Articles',           $counts['articles'],  'fa-file-lines',      '#2ecc71', 'articles.php'],
        ['Published Articles', $counts['published'], 'fa-globe',           '#16a085', 'articles.php?status=published'],
        ['Authors',            $counts['authors'],   'fa-users',           '#e74c3c', 'authors.php'],
    ];
    foreach ($cards as [$label, $val, $icon, $color, $link]): ?>
    <div class="col-6 col-md-4 col-xl-2">
        <a href="<?= APP_URL ?>/journal/<?= $link ?>" class="text-decoration-none">
            <div class="card stat-card h-100" style="background:<?= $color ?>;">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-val fs-4 fw-bold"><?= number_format($val) ?></div>
                        <div class="small"><?= h($label) ?></div>
                    </div>
                    <i class="fas <?= $icon ?> stat-icon"></i>
                </div>
            </div>
        </a>
    </div>
    <?php endforeach; ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm" style="border-radius:12px;">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div><div class="text-muted small">Total Article Views</div>
                     <div class="fs-4 fw-bold"><?= number_format((int)$totals['v']) ?></div></div>
                <i class="fas fa-eye fs-3 text-primary"></i>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm" style="border-radius:12px;">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div><div class="text-muted small">Total PDF Downloads</div>
                     <div class="fs-4 fw-bold"><?= number_format((int)$totals['d']) ?></div></div>
                <i class="fas fa-download fs-3 text-success"></i>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm" style="border-radius:12px;">
    <div class="card-header bg-white fw-semibold" style="border-radius:12px 12px 0 0;">Recent Articles</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>Title</th><th>Journal</th><th>Vol / Issue</th><th>Status</th><th>Published</th><th></th></tr>
            </thead>
            <tbody>
                <?php if (!$recent): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No articles yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($recent as $r): ?>
                <tr>
                    <td><?= h(mb_strimwidth($r['title'], 0, 70, '…')) ?></td>
                    <td><?= h($r['journal_title']) ?></td>
                    <td>Vol. <?= (int)$r['volume_number'] ?>, No. <?= (int)$r['issue_number'] ?></td>
                    <td>
                        <span class="badge bg-<?= $r['status'] === 'published' ? 'success' : 'secondary' ?>">
                            <?= h(ucfirst($r['status'])) ?>
                        </span>
                    </td>
                    <td><?= $r['published_date'] ? h(date('d M Y', strtotime($r['published_date']))) : '—' ?></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-primary" href="<?= APP_URL ?>/journal/article-form.php?id=<?= (int)$r['id'] ?>">
                            <i class="fas fa-pen"></i>
                        </a>
                        <?php if ($r['status'] === 'published'): ?>
                        <a class="btn btn-sm btn-outline-secondary" target="_blank" href="<?= h(jm_article_url($r['slug'])) ?>">
                            <i class="fas fa-external-link-alt"></i>
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
