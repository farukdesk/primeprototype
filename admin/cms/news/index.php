<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$page_title = 'Latest News';

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';
$where  = [];
$params = [];

if ($search !== '') {
    $where[]  = '(title LIKE ? OR content LIKE ?)';
    $like     = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}
if ($status === 'published') {
    $where[]  = 'is_published = 1';
} elseif ($status === 'draft') {
    $where[]  = 'is_published = 0';
}

$sql = 'SELECT * FROM cms_news'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY created_at DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$news_list = $stmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Latest News</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/cms/news/create.php" class="btn btn-primary" style="border-radius:10px;font-size:.875rem;">
        <i class="fas fa-plus me-1"></i> New Article
    </a>
</div>

<!-- Filter bar -->
<div class="card mb-4">
    <div class="card-body py-3 px-4">
        <form method="GET" class="d-flex gap-3 flex-wrap align-items-center">
            <input type="text" name="search" class="form-control" style="max-width:300px;border-radius:10px;"
                   placeholder="Search title or content…" value="<?= h($search) ?>">
            <select name="status" class="form-select" style="max-width:160px;border-radius:10px;">
                <option value="">All statuses</option>
                <option value="published" <?= $status === 'published' ? 'selected' : '' ?>>Published</option>
                <option value="draft"     <?= $status === 'draft'     ? 'selected' : '' ?>>Draft</option>
            </select>
            <button class="btn btn-outline-primary" style="border-radius:10px;">
                <i class="fas fa-search me-1"></i>Filter
            </button>
            <?php if ($search || $status): ?>
            <a href="<?= APP_URL ?>/cms/news/index.php" class="btn btn-light" style="border-radius:10px;">Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Title</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Ticker</th>
                        <th>Published At</th>
                        <th>Updated</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($news_list)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No news articles found.</td></tr>
                <?php else: ?>
                    <?php foreach ($news_list as $idx => $n): ?>
                    <tr>
                        <td class="px-4"><?= $idx + 1 ?></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <?php if ($n['featured_image']): ?>
                                <img src="<?= UPLOAD_URL ?>/news/<?= h($n['featured_image']) ?>"
                                     style="width:38px;height:38px;object-fit:cover;border-radius:6px;"
                                     alt="" onerror="this.style.display='none'">
                                <?php else: ?>
                                <div style="width:38px;height:38px;border-radius:6px;background:#e8eaf0;
                                    display:flex;align-items:center;justify-content:center;color:#aaa;">
                                    <i class="fas fa-newspaper fa-sm"></i>
                                </div>
                                <?php endif; ?>
                                <div>
                                    <strong><?= h($n['title']) ?></strong>
                                    <div style="font-size:.75rem;color:#9ca3af;"><?= h($n['slug']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="badge <?= $n['content_type'] === 'html' ? 'bg-info text-dark' : 'bg-secondary' ?>">
                                <?= strtoupper(h($n['content_type'])) ?>
                            </span>
                        </td>
                        <td>
                            <?= $n['is_published']
                                ? '<span class="badge bg-success">Published</span>'
                                : '<span class="badge bg-warning text-dark">Draft</span>' ?>
                        </td>
                        <td>
                            <?= !empty($n['show_in_ticker'])
                                ? '<span class="badge bg-warning text-dark"><i class="fas fa-rss me-1"></i>Ticker</span>'
                                : '<span class="text-muted" style="font-size:.8rem;">—</span>' ?>
                        </td>
                        <td>
                            <?= $n['published_at']
                                ? date('M d, Y H:i', strtotime($n['published_at']))
                                : '<span class="text-muted">—</span>' ?>
                        </td>
                        <td><?= date('M d, Y H:i', strtotime($n['updated_at'])) ?></td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="<?= APP_URL ?>/cms/news/edit.php?id=<?= $n['id'] ?>"
                                   class="btn btn-sm btn-outline-primary" title="Edit" style="border-radius:7px;">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form method="POST" action="<?= APP_URL ?>/cms/news/delete.php"
                                      onsubmit="return confirm('Delete article \"<?= h(addslashes($n['title'])) ?>\"? This will also remove all attachments.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $n['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" title="Delete" style="border-radius:7px;">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
