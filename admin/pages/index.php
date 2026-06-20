<?php
require_once __DIR__ . '/../includes/auth.php';
require_super_admin();

$page_title = 'Pages';

$category = $_GET['category'] ?? '';
$search   = trim($_GET['search'] ?? '');
$where    = [];
$params   = [];

if (in_array($category, ['general','profile','policy'], true)) {
    $where[]  = 'category = ?';
    $params[] = $category;
}
if ($search !== '') {
    $where[]  = '(title LIKE ? OR slug LIKE ?)';
    $like     = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}

$sql = 'SELECT * FROM pages'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY category ASC, sort_order ASC, id DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$pages = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Pages</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/pages/create.php" class="btn btn-primary" style="border-radius:10px;font-size:.875rem;">
        <i class="fas fa-plus me-1"></i> New Page
    </a>
</div>

<!-- Filter bar -->
<div class="card mb-4">
    <div class="card-body py-3 px-4">
        <form method="GET" class="d-flex gap-3 flex-wrap align-items-center">
            <input type="text" name="search" class="form-control" style="max-width:260px;border-radius:10px;"
                   placeholder="Search title or slug…" value="<?= h($search) ?>">
            <select name="category" class="form-select" style="max-width:180px;border-radius:10px;">
                <option value="">All categories</option>
                <option value="general" <?= $category === 'general' ? 'selected' : '' ?>>General</option>
                <option value="profile" <?= $category === 'profile' ? 'selected' : '' ?>>Profile</option>
                <option value="policy"  <?= $category === 'policy'  ? 'selected' : '' ?>>Policy</option>
            </select>
            <button class="btn btn-outline-primary" style="border-radius:10px;">
                <i class="fas fa-search me-1"></i>Filter
            </button>
            <?php if ($search || $category): ?>
            <a href="<?= APP_URL ?>/pages/index.php" class="btn btn-light" style="border-radius:10px;">Clear</a>
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
                        <th>Title / Slug</th>
                        <th>Category</th>
                        <th>Status</th>
                        <th>Updated</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($pages)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No pages found.</td></tr>
                <?php else: ?>
                    <?php foreach ($pages as $idx => $p): ?>
                    <?php
                        $cat_badges = [
                            'general' => ['bg-info text-dark',    'fas fa-columns',       'General'],
                            'profile' => ['bg-primary',           'fas fa-id-card',       'Profile'],
                            'policy'  => ['bg-secondary',         'fas fa-file-contract', 'Policy'],
                        ];
                        [$badge_class, $badge_icon, $badge_label] = $cat_badges[$p['category']] ?? ['bg-light text-dark','fas fa-file',''];
                    ?>
                    <tr>
                        <td class="px-4"><?= $idx + 1 ?></td>
                        <td>
                            <div>
                                <strong><?= h($p['title']) ?></strong>
                                <div style="font-size:.75rem;color:#9ca3af;"><?= h($p['slug']) ?></div>
                            </div>
                        </td>
                        <td>
                            <span class="badge <?= $badge_class ?>">
                                <i class="<?= $badge_icon ?> me-1"></i><?= $badge_label ?>
                            </span>
                        </td>
                        <td>
                            <?= $p['is_published']
                                ? '<span class="badge bg-success">Published</span>'
                                : '<span class="badge bg-warning text-dark">Draft</span>' ?>
                        </td>
                        <td><?= date('M d, Y', strtotime($p['updated_at'])) ?></td>
                        <td>
                            <div class="d-flex gap-1">
                                <?php if ($p['category'] === 'profile'): ?>
                                <a href="<?= APP_URL ?>/pages/profiles/index.php?page_id=<?= $p['id'] ?>"
                                   class="btn btn-sm btn-outline-secondary" title="Manage Profiles" style="border-radius:7px;">
                                    <i class="fas fa-users"></i>
                                </a>
                                <?php endif; ?>
                                <a href="<?= APP_URL ?>/pages/edit.php?id=<?= $p['id'] ?>"
                                   class="btn btn-sm btn-outline-primary" title="Edit" style="border-radius:7px;">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form method="POST" action="<?= APP_URL ?>/pages/delete.php"
                                      onsubmit="return confirm('Delete page &quot;<?= h(addslashes($p['title'])) ?>&quot;? This cannot be undone.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
