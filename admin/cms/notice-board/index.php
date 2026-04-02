<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$page_title = 'Notice Board';

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
    $where[] = 'is_published = 1';
} elseif ($status === 'draft') {
    $where[] = 'is_published = 0';
}

$sql = 'SELECT * FROM cms_notices'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY created_at DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$notices = $stmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Notice Board</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/cms/notice-board/create.php" class="btn btn-primary" style="border-radius:10px;font-size:.875rem;">
        <i class="fas fa-plus me-1"></i> Add Notice
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
            <a href="<?= APP_URL ?>/cms/notice-board/index.php" class="btn btn-light" style="border-radius:10px;">Clear</a>
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
                        <th>Excerpt</th>
                        <th>Published At</th>
                        <th>Publish as News</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($notices)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No notices found.</td></tr>
                <?php else: ?>
                    <?php foreach ($notices as $idx => $n): ?>
                    <tr>
                        <td class="px-4"><?= $idx + 1 ?></td>
                        <td>
                            <strong><?= h($n['title']) ?></strong>
                            <div style="font-size:.75rem;color:#9ca3af;"><?= h($n['slug']) ?></div>
                        </td>
                        <td style="max-width:220px;">
                            <span class="text-muted" style="font-size:.875rem;">
                                <?= h(mb_strimwidth(strip_tags($n['content']), 0, 50, '…')) ?>
                            </span>
                        </td>
                        <td>
                            <?= $n['published_at']
                                ? date('M d, Y H:i', strtotime($n['published_at']))
                                : '<span class="text-muted">—</span>' ?>
                        </td>
                        <td>
                            <?= $n['publish_as_news']
                                ? '<span class="badge bg-info text-dark">Yes</span>'
                                : '<span class="badge bg-secondary">No</span>' ?>
                        </td>
                        <td>
                            <?= $n['is_published']
                                ? '<span class="badge bg-success">Published</span>'
                                : '<span class="badge bg-warning text-dark">Draft</span>' ?>
                        </td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="<?= APP_URL ?>/cms/notice-board/view.php?id=<?= $n['id'] ?>"
                                   class="btn btn-sm btn-outline-secondary" title="View" style="border-radius:7px;">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="<?= APP_URL ?>/cms/notice-board/edit.php?id=<?= $n['id'] ?>"
                                   class="btn btn-sm btn-outline-primary" title="Edit" style="border-radius:7px;">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form method="POST" action="<?= APP_URL ?>/cms/notice-board/delete.php"
                                      onsubmit="return confirm('Delete notice &quot;<?= h(addslashes($n['title'])) ?>&quot;? This cannot be undone.');">
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
