<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';

$page_title = 'Knowledge Base';
$user       = auth_user();

// ── Filters & Pagination ──────────────────────────────────────────────────────
$search  = trim($_GET['search'] ?? '');
$f_type  = $_GET['type'] ?? '';
$page    = max(1, (int)($_GET['page'] ?? 1));
$per_page = 12;

$valid_types = ['video', 'document'];

$where  = [];
$params = [];

if ($search !== '') {
    $like     = '%' . $search . '%';
    $where[]  = '(a.title LIKE ? OR a.description LIKE ?)';
    $params[] = $like;
    $params[] = $like;
}
if (in_array($f_type, $valid_types, true)) {
    $where[]  = 'a.type = ?';
    $params[] = $f_type;
}

$base_sql = 'FROM kb_articles a JOIN users u ON u.id = a.created_by'
          . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

// Total count for pagination
$count_stmt = db()->prepare('SELECT COUNT(*) ' . $base_sql);
$count_stmt->execute($params);
$total_rows = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$data_params   = $params;
$data_params[] = $per_page;
$data_params[] = $offset;

$stmt = db()->prepare(
    'SELECT a.*, u.full_name AS author_name ' . $base_sql
    . ' ORDER BY a.created_at DESC LIMIT ? OFFSET ?'
);
$stmt->execute($data_params);
$articles = $stmt->fetchAll();

// ── Stats ─────────────────────────────────────────────────────────────────────
$type_counts = db()->query(
    'SELECT type, COUNT(*) AS cnt FROM kb_articles GROUP BY type'
)->fetchAll();
$counts = array_column($type_counts, 'cnt', 'type');

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Knowledge Base</li>
        </ol>
    </nav>
    <?php if (is_super_admin()): ?>
    <a href="<?= APP_URL ?>/knowledge-base/create.php" class="btn btn-primary" style="border-radius:10px;font-size:.875rem;">
        <i class="fas fa-plus me-1"></i> New Article
    </a>
    <?php endif; ?>
</div>

<!-- Stats cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="card text-center h-100" style="border-radius:12px;border-left:4px solid #4f8ef7;">
            <div class="card-body py-3">
                <div style="font-size:1.8rem;font-weight:700;color:#4f8ef7;"><?= $total_rows ?></div>
                <div class="text-muted" style="font-size:.8rem;">Total Articles</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card text-center h-100" style="border-radius:12px;border-left:4px solid #dc3545;">
            <div class="card-body py-3">
                <div style="font-size:1.8rem;font-weight:700;color:#dc3545;"><?= $counts['video'] ?? 0 ?></div>
                <div class="text-muted" style="font-size:.8rem;">Videos</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card text-center h-100" style="border-radius:12px;border-left:4px solid #198754;">
            <div class="card-body py-3">
                <div style="font-size:1.8rem;font-weight:700;color:#198754;"><?= $counts['document'] ?? 0 ?></div>
                <div class="text-muted" style="font-size:.8rem;">Documents</div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-body py-3 px-4">
        <form method="GET" class="d-flex gap-2 flex-wrap align-items-center">
            <input type="text" name="search" class="form-control" style="max-width:260px;border-radius:10px;"
                   placeholder="Search articles…" value="<?= h($search) ?>">
            <select name="type" class="form-select" style="max-width:160px;border-radius:10px;">
                <option value="">All Types</option>
                <option value="video"    <?= $f_type === 'video'    ? 'selected' : '' ?>>Video</option>
                <option value="document" <?= $f_type === 'document' ? 'selected' : '' ?>>Document</option>
            </select>
            <button class="btn btn-outline-primary" style="border-radius:10px;">
                <i class="fas fa-search me-1"></i> Filter
            </button>
            <?php if ($search || $f_type): ?>
            <a href="<?= APP_URL ?>/knowledge-base/index.php" class="btn btn-light" style="border-radius:10px;">Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Articles grid -->
<?php if (empty($articles)): ?>
<div class="card" style="border-radius:12px;">
    <div class="card-body text-center text-muted py-5">
        <i class="fas fa-book-open fa-2x mb-3 d-block opacity-25"></i>
        No articles found.
    </div>
</div>
<?php else: ?>
<div class="row g-3 mb-4">
    <?php foreach ($articles as $art): ?>
    <div class="col-sm-6 col-lg-4">
        <div class="card h-100" style="border-radius:12px;transition:box-shadow .15s;">
            <?php if ($art['thumbnail']): ?>
            <a href="<?= APP_URL ?>/knowledge-base/view.php?id=<?= $art['id'] ?>">
                <img src="<?= UPLOAD_URL ?>/<?= KB_UPLOAD_SUBDIR ?>/<?= h($art['thumbnail']) ?>"
                     class="card-img-top" alt="<?= h($art['title']) ?>"
                     style="height:160px;object-fit:cover;border-radius:12px 12px 0 0;">
            </a>
            <?php else: ?>
            <a href="<?= APP_URL ?>/knowledge-base/view.php?id=<?= $art['id'] ?>"
               class="d-flex align-items-center justify-content-center"
               style="height:120px;background:#f1f3f9;border-radius:12px 12px 0 0;">
                <?php if ($art['type'] === 'video'): ?>
                <i class="fas fa-play-circle fa-3x" style="color:#dc3545;opacity:.6;"></i>
                <?php else: ?>
                <i class="fas fa-file-alt fa-3x" style="color:#4f8ef7;opacity:.6;"></i>
                <?php endif; ?>
            </a>
            <?php endif; ?>

            <div class="card-body d-flex flex-column p-3">
                <div class="mb-2">
                    <?php if ($art['type'] === 'video'): ?>
                    <span class="badge" style="background:#fff0f0;color:#dc3545;font-size:.7rem;">
                        <i class="fas fa-play me-1"></i>Video
                    </span>
                    <?php else: ?>
                    <span class="badge" style="background:#f0f4ff;color:#4f8ef7;font-size:.7rem;">
                        <i class="fas fa-file-alt me-1"></i>Document
                    </span>
                    <?php endif; ?>
                </div>
                <h6 class="card-title fw-semibold mb-1" style="font-size:.95rem;line-height:1.35;">
                    <a href="<?= APP_URL ?>/knowledge-base/view.php?id=<?= $art['id'] ?>"
                       class="text-dark text-decoration-none">
                        <?= h($art['title']) ?>
                    </a>
                </h6>
                <?php if ($art['description']): ?>
                <p class="text-muted mb-2" style="font-size:.8rem;flex-grow:1;
                   overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">
                    <?= h($art['description']) ?>
                </p>
                <?php else: ?>
                <div style="flex-grow:1;"></div>
                <?php endif; ?>
                <div class="d-flex justify-content-between align-items-center mt-auto pt-2"
                     style="border-top:1px solid #f0f0f0;">
                    <span class="text-muted" style="font-size:.75rem;">
                        <?= date('M d, Y', strtotime($art['created_at'])) ?>
                    </span>
                    <div class="d-flex gap-1">
                        <a href="<?= APP_URL ?>/knowledge-base/view.php?id=<?= $art['id'] ?>"
                           class="btn btn-sm btn-outline-primary" title="View" style="border-radius:7px;">
                            <i class="fas fa-eye"></i>
                        </a>
                        <?php if (is_super_admin()): ?>
                        <a href="<?= APP_URL ?>/knowledge-base/edit.php?id=<?= $art['id'] ?>"
                           class="btn btn-sm btn-outline-secondary" title="Edit" style="border-radius:7px;">
                            <i class="fas fa-edit"></i>
                        </a>
                        <form method="POST" action="<?= APP_URL ?>/knowledge-base/delete.php" class="d-inline"
                              onsubmit="return confirm('Permanently delete this article?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $art['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger" title="Delete" style="border-radius:7px;">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Pagination -->
<?php if ($total_pages > 1): ?>
<?php
$qs_base = http_build_query(array_filter(['search' => $search, 'type' => $f_type]));
$qs_base = $qs_base ? '&' . $qs_base : '';
?>
<nav aria-label="Page navigation">
    <ul class="pagination justify-content-center">
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="?page=<?= $page - 1 . $qs_base ?>">
                <i class="fas fa-chevron-left"></i>
            </a>
        </li>
        <?php for ($p = 1; $p <= $total_pages; $p++): ?>
        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
            <a class="page-link" href="?page=<?= $p . $qs_base ?>"><?= $p ?></a>
        </li>
        <?php endfor; ?>
        <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
            <a class="page-link" href="?page=<?= $page + 1 . $qs_base ?>">
                <i class="fas fa-chevron-right"></i>
            </a>
        </li>
    </ul>
</nav>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
