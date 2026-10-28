<?php
/**
 * Blog — posts list.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

auth_check();
blog_ensure_schema();
require_access('blog', 'can_view');

$page_title = 'Blog Posts';

$search   = trim($_GET['search'] ?? '');
$status   = $_GET['status'] ?? '';
$category = (int)($_GET['category'] ?? 0);

$where  = [];
$params = [];

if ($search !== '') {
    $where[]  = '(p.title LIKE ? OR p.content LIKE ? OR p.tags LIKE ?)';
    $like     = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
if ($status === 'published') {
    $where[] = "p.status = 'published'";
} elseif ($status === 'draft') {
    $where[] = "p.status = 'draft'";
}
if ($category > 0) {
    $where[]  = 'p.category_id = ?';
    $params[] = $category;
}

$sql = 'SELECT p.*, c.name AS category_name, u.full_name AS creator_name,
               (SELECT COUNT(*) FROM blog_comments bc
                 WHERE bc.post_id = p.id AND bc.status = "pending") AS pending_comments
        FROM blog_posts p
        LEFT JOIN blog_categories c ON c.id = p.category_id
        LEFT JOIN users u ON u.id = p.created_by'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY p.created_at DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$posts = $stmt->fetchAll();

$cats            = blog_categories();
$pending_total   = blog_pending_comment_count();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Blog</li>
        </ol>
    </nav>
    <div class="d-flex gap-2 align-items-center">
        <a href="<?= APP_URL ?>/blog/comments.php" class="btn btn-outline-secondary btn-sm" style="border-radius:10px;">
            <i class="fas fa-comments me-1"></i> Comments
            <?php if ($pending_total > 0): ?>
            <span class="badge bg-warning text-dark ms-1"><?= $pending_total ?></span>
            <?php endif; ?>
        </a>
        <a href="<?= APP_URL ?>/blog/categories.php" class="btn btn-outline-secondary btn-sm" style="border-radius:10px;">
            <i class="fas fa-folder me-1"></i> Categories
        </a>
        <?php if (blog_can('can_create')): ?>
        <a href="<?= APP_URL ?>/blog/create.php" class="btn btn-primary" style="border-radius:10px;font-size:.875rem;">
            <i class="fas fa-plus me-1"></i> New Post
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Filter bar -->
<div class="card mb-4">
    <div class="card-body py-3 px-4">
        <form method="GET" class="d-flex gap-3 flex-wrap align-items-center">
            <input type="text" name="search" class="form-control" style="max-width:300px;border-radius:10px;"
                   placeholder="Search title, content or tags…" value="<?= h($search) ?>">
            <select name="status" class="form-select" style="max-width:160px;border-radius:10px;">
                <option value="">All statuses</option>
                <option value="published" <?= $status === 'published' ? 'selected' : '' ?>>Published</option>
                <option value="draft"     <?= $status === 'draft'     ? 'selected' : '' ?>>Draft</option>
            </select>
            <select name="category" class="form-select" style="max-width:200px;border-radius:10px;">
                <option value="">All categories</option>
                <?php foreach ($cats as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $category === (int)$c['id'] ? 'selected' : '' ?>>
                    <?= h($c['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-outline-primary" style="border-radius:10px;">
                <i class="fas fa-search me-1"></i>Filter
            </button>
            <?php if ($search || $status || $category): ?>
            <a href="<?= APP_URL ?>/blog/index.php" class="btn btn-light" style="border-radius:10px;">Clear</a>
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
                        <th>Category</th>
                        <th>Status</th>
                        <th>Views</th>
                        <th>Published At</th>
                        <th>Updated</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($posts)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No blog posts found.</td></tr>
                <?php else: ?>
                    <?php foreach ($posts as $idx => $p): ?>
                    <tr>
                        <td class="px-4"><?= $idx + 1 ?></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <?php if ($p['featured_image']): ?>
                                <img src="<?= UPLOAD_URL ?>/blog/<?= h($p['featured_image']) ?>"
                                     style="width:38px;height:38px;object-fit:cover;border-radius:6px;"
                                     alt="" onerror="this.style.display='none'">
                                <?php else: ?>
                                <div style="width:38px;height:38px;border-radius:6px;background:#e8eaf0;
                                    display:flex;align-items:center;justify-content:center;color:#aaa;">
                                    <i class="fas fa-blog fa-sm"></i>
                                </div>
                                <?php endif; ?>
                                <div>
                                    <strong><?= h($p['title']) ?></strong>
                                    <div style="font-size:.75rem;color:#9ca3af;"><?= h($p['slug']) ?></div>
                                    <?php if ($p['creator_name']): ?>
                                    <div style="font-size:.72rem;color:#b0b7c9;">
                                        <i class="fas fa-user fa-xs me-1"></i><?= h($p['creator_name']) ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ((int)$p['pending_comments'] > 0): ?>
                                    <a href="<?= APP_URL ?>/blog/comments.php?post_id=<?= $p['id'] ?>&status=pending"
                                       class="badge bg-warning text-dark text-decoration-none mt-1" style="font-size:.7rem;">
                                        <i class="fas fa-comment me-1"></i><?= (int)$p['pending_comments'] ?> pending comment<?= (int)$p['pending_comments'] > 1 ? 's' : '' ?>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td>
                            <?= $p['category_name']
                                ? '<span class="badge bg-info text-dark">' . h($p['category_name']) . '</span>'
                                : '<span class="text-muted">—</span>' ?>
                        </td>
                        <td>
                            <?= $p['status'] === 'published'
                                ? '<span class="badge bg-success">Published</span>'
                                : '<span class="badge bg-secondary">Draft</span>' ?>
                        </td>
                        <td><?= (int)$p['views'] ?></td>
                        <td>
                            <?= $p['published_at']
                                ? date('M d, Y H:i', strtotime($p['published_at']))
                                : '<span class="text-muted">—</span>' ?>
                        </td>
                        <td><?= date('M d, Y H:i', strtotime($p['updated_at'])) ?></td>
                        <td>
                            <div class="d-flex gap-1 flex-wrap">
                                <?php if ($p['status'] === 'published'): ?>
                                <a href="<?= SITE_URL ?>/blog-detail.php?slug=<?= urlencode($p['slug']) ?>"
                                   target="_blank" rel="noopener"
                                   class="btn btn-sm btn-outline-secondary" title="View on website" style="border-radius:7px;">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                                <?php endif; ?>
                                <?php if (blog_can('can_edit')): ?>
                                <a href="<?= APP_URL ?>/blog/edit.php?id=<?= $p['id'] ?>"
                                   class="btn btn-sm btn-outline-primary" title="Edit" style="border-radius:7px;">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php endif; ?>
                                <?php if (blog_can('can_delete')): ?>
                                <form method="POST" action="<?= APP_URL ?>/blog/delete.php"
                                      onsubmit="return confirm('Delete post \"<?= h(addslashes($p['title'])) ?>\"? Its comments will also be deleted.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" title="Delete" style="border-radius:7px;">
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
