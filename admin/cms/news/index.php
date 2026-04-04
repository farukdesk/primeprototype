<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('cms-news', 'can_view');

$page_title  = 'News';
$is_super    = is_super_admin();
$current_user = auth_user();

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';
$approval_filter = $_GET['approval'] ?? '';
$where  = [];
$params = [];

if ($search !== '') {
    $where[]  = '(n.title LIKE ? OR n.content LIKE ?)';
    $like     = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}
if ($status === 'published') {
    $where[]  = 'n.is_published = 1';
} elseif ($status === 'draft') {
    $where[]  = 'n.is_published = 0';
}
if ($is_super) {
    if ($approval_filter === 'pending') {
        $where[] = 'n.is_approved = 0';
    } elseif ($approval_filter === 'approved') {
        $where[] = 'n.is_approved = 1';
    }
} else {
    // Non-super admins only see their own articles
    $where[]  = 'n.created_by = ?';
    $params[] = $current_user['id'];
}

$sql = 'SELECT n.*, u.name AS creator_name
        FROM cms_news n
        LEFT JOIN users u ON u.id = n.created_by'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY n.created_at DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$news_list = $stmt->fetchAll();

// Pending changes count (super admin only)
$pending_count = 0;
if ($is_super) {
    $pending_count = (int)db()->query(
        "SELECT COUNT(*) FROM cms_pending_changes WHERE module='news' AND status='pending'"
    )->fetchColumn();
}

// Pending approval count (super admin only)
$pending_approval_count = 0;
if ($is_super) {
    $pending_approval_count = (int)db()->query(
        "SELECT COUNT(*) FROM cms_news WHERE is_approved = 0"
    )->fetchColumn();
}

// Pending changes counts per record (super admin only) – single query
$pending_per_record = [];
if ($is_super) {
    $prows = db()->query(
        "SELECT record_id, COUNT(*) AS cnt
         FROM cms_pending_changes
         WHERE module='news' AND status='pending'
         GROUP BY record_id"
    )->fetchAll();
    foreach ($prows as $pr) {
        $pending_per_record[(int)$pr['record_id']] = (int)$pr['cnt'];
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">News</li>
        </ol>
    </nav>
    <div class="d-flex gap-2 align-items-center">
        <?php if ($is_super && ($pending_count > 0 || $pending_approval_count > 0)): ?>
        <a href="<?= APP_URL ?>/cms/pending-changes/index.php?module=news"
           class="btn btn-warning btn-sm" style="border-radius:10px;font-size:.875rem;">
            <i class="fas fa-clock me-1"></i>
            Pending
            <?php $total_pending = $pending_count + $pending_approval_count; ?>
            <span class="badge bg-dark ms-1"><?= $total_pending ?></span>
        </a>
        <?php endif; ?>
        <?php if ($is_super || can_access('cms-news', 'can_create')): ?>
        <a href="<?= APP_URL ?>/cms/news/create.php" class="btn btn-primary" style="border-radius:10px;font-size:.875rem;">
            <i class="fas fa-plus me-1"></i> New Article
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$is_super): ?>
<div class="alert alert-info py-2 mb-4" style="border-radius:10px;font-size:.875rem;">
    <i class="fas fa-info-circle me-1"></i>
    Your submissions require super-admin approval before appearing on the website.
    Edit and delete requests are also queued for approval.
</div>
<?php endif; ?>

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
            <?php if ($is_super): ?>
            <select name="approval" class="form-select" style="max-width:180px;border-radius:10px;">
                <option value="">All approval states</option>
                <option value="approved" <?= $approval_filter === 'approved' ? 'selected' : '' ?>>Approved</option>
                <option value="pending"  <?= $approval_filter === 'pending'  ? 'selected' : '' ?>>Pending Approval</option>
            </select>
            <?php endif; ?>
            <button class="btn btn-outline-primary" style="border-radius:10px;">
                <i class="fas fa-search me-1"></i>Filter
            </button>
            <?php if ($search || $status || $approval_filter): ?>
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
                        <th>Approval</th>
                        <th>Status</th>
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
                    <?php $n_pending = $pending_per_record[(int)$n['id']] ?? 0; ?>
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
                                    <?php if ($n['creator_name']): ?>
                                    <div style="font-size:.72rem;color:#b0b7c9;">
                                        <i class="fas fa-user fa-xs me-1"></i><?= h($n['creator_name']) ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($n_pending > 0): ?>
                                    <a href="<?= APP_URL ?>/cms/pending-changes/index.php?module=news&record_id=<?= $n['id'] ?>"
                                       class="badge bg-warning text-dark text-decoration-none mt-1"
                                       style="font-size:.7rem;">
                                        <i class="fas fa-clock me-1"></i><?= $n_pending ?> pending change<?= $n_pending > 1 ? 's' : '' ?>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="badge <?= $n['content_type'] === 'html' ? 'bg-info text-dark' : 'bg-secondary' ?>">
                                <?= strtoupper(h($n['content_type'])) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($n['is_approved']): ?>
                                <span class="badge bg-success"><i class="fas fa-check me-1"></i>Approved</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>Pending</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $n['is_published']
                                ? '<span class="badge bg-success">Published</span>'
                                : '<span class="badge bg-secondary">Draft</span>' ?>
                        </td>
                        <td>
                            <?= $n['published_at']
                                ? date('M d, Y H:i', strtotime($n['published_at']))
                                : '<span class="text-muted">—</span>' ?>
                        </td>
                        <td><?= date('M d, Y H:i', strtotime($n['updated_at'])) ?></td>
                        <td>
                            <div class="d-flex gap-1 flex-wrap">
                                <?php if ($is_super && !$n['is_approved']): ?>
                                <form method="POST" action="<?= APP_URL ?>/cms/pending-changes/approve-create.php">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="module" value="news">
                                    <input type="hidden" name="id" value="<?= $n['id'] ?>">
                                    <button class="btn btn-sm btn-success" title="Approve post" style="border-radius:7px;">
                                        <i class="fas fa-check"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                                <?php if ($is_super || can_access('cms-news', 'can_edit')): ?>
                                <a href="<?= APP_URL ?>/cms/news/edit.php?id=<?= $n['id'] ?>"
                                   class="btn btn-sm btn-outline-primary" title="Edit" style="border-radius:7px;">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php endif; ?>
                                <?php if ($is_super || can_access('cms-news', 'can_delete')): ?>
                                <form method="POST" action="<?= APP_URL ?>/cms/news/delete.php"
                                      onsubmit="return confirm('<?= $is_super ? 'Delete' : 'Request deletion of' ?> article \"<?= h(addslashes($n['title'])) ?>\"?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $n['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" title="<?= $is_super ? 'Delete' : 'Request Deletion' ?>" style="border-radius:7px;">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
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
