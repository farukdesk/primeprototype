<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_access('library-digital');
require_once __DIR__ . '/../helpers.php';

$db = db();

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = trim($_POST['action'] ?? '');

    if ($action === 'toggle' && lib_is_staff()) {
        $id  = (int)($_POST['id'] ?? 0);
        $row = $db->prepare('SELECT id, title, is_active FROM library_digital_resources WHERE id=?');
        $row->execute([$id]);
        $res = $row->fetch();
        if ($res) {
            $nv = $res['is_active'] ? 0 : 1;
            $db->prepare('UPDATE library_digital_resources SET is_active=?, updated_at=NOW() WHERE id=?')->execute([$nv, $id]);
            lib_audit('DIGITAL_TOGGLE', 'digital', $id, $res['title'], "Set is_active={$nv}.");
            flash_set('success', 'Resource status updated.');
        }
        redirect(APP_URL . '/library/digital/index.php');
    }

    if ($action === 'delete' && lib_can_delete()) {
        $id  = (int)($_POST['id'] ?? 0);
        $row = $db->prepare('SELECT * FROM library_digital_resources WHERE id=?');
        $row->execute([$id]);
        $res = $row->fetch();
        if ($res) {
            if ($res['file_name'])    lib_delete_file('digital', $res['file_name']);
            if ($res['cover_image'])  lib_delete_file('covers',  $res['cover_image']);
            $db->prepare('DELETE FROM library_digital_resources WHERE id=?')->execute([$id]);
            lib_audit('DIGITAL_DELETED', 'digital', $id, $res['title'], "Deleted resource.");
            log_change('library-digital', 'DELETE', $id, $res['title'], null, null, null, "Deleted digital resource \"{$res['title']}\".");
            flash_set('success', 'Resource deleted.');
        }
        redirect(APP_URL . '/library/digital/index.php');
    }
}

// ── Stats ─────────────────────────────────────────────────────────────────────
$stat_total     = (int)$db->query("SELECT COUNT(*) FROM library_digital_resources")->fetchColumn();
$stat_ebooks    = (int)$db->query("SELECT COUNT(*) FROM library_digital_resources WHERE resource_type='E-Book'")->fetchColumn();
$stat_research  = (int)$db->query("SELECT COUNT(*) FROM library_digital_resources WHERE resource_type IN ('Research Paper','Thesis','Dissertation')")->fetchColumn();
$stat_downloads = (int)$db->query("SELECT COALESCE(SUM(download_count),0) FROM library_digital_resources")->fetchColumn();

// ── Filters ───────────────────────────────────────────────────────────────────
$search    = trim($_GET['q']              ?? '');
$type_f    = trim($_GET['resource_type']  ?? '');
$cat_f     = (int)($_GET['category_id']   ?? 0);
$access_f  = trim($_GET['access_level']   ?? '');
$active_f  = $_GET['is_active'] ?? '';

$per_page = 20;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

$where  = '1=1';
$params = [];
if ($search !== '') {
    $like = '%' . $search . '%';
    $where .= ' AND (r.title LIKE ? OR r.author LIKE ?)';
    $params[] = $like; $params[] = $like;
}
if ($type_f !== '')   { $where .= ' AND r.resource_type=?';  $params[] = $type_f; }
if ($cat_f)           { $where .= ' AND r.category_id=?';    $params[] = $cat_f; }
if ($access_f !== '') { $where .= ' AND r.access_level=?';   $params[] = $access_f; }
if ($active_f !== '') { $where .= ' AND r.is_active=?';      $params[] = (int)$active_f; }

$count_stmt = $db->prepare("SELECT COUNT(*) FROM library_digital_resources r WHERE $where");
$count_stmt->execute($params);
$total_rows  = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$sql = "SELECT r.*, c.name AS cat_name, d.name AS dept_name
        FROM library_digital_resources r
        LEFT JOIN library_categories c ON c.id = r.category_id
        LEFT JOIN dept_departments   d ON d.id = r.department_id
        WHERE $where ORDER BY r.id DESC LIMIT $per_page OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$resources = $stmt->fetchAll();

$categories  = lib_all_categories();
$departments = $db->query('SELECT id, name FROM dept_departments ORDER BY name ASC')->fetchAll();

$page_title  = 'Digital Library';
$breadcrumbs = [
    ['label' => 'Library', 'url' => APP_URL . '/library/index.php'],
    ['label' => 'Digital Resources'],
];
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/library/index.php">Library</a></li>
            <li class="breadcrumb-item active">Digital Resources</li>
        </ol>
    </nav>
    <?php if (lib_can_create()): ?>
    <a href="<?= APP_URL ?>/library/digital/create.php" class="btn btn-primary btn-sm" style="border-radius:10px;">
        <i class="fas fa-upload me-1"></i> Upload Resource
    </a>
    <?php endif; ?>
</div>

<?php $flash = flash_get(); if ($flash): ?>
<div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show">
    <?= $flash['message'] ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="row g-3 mb-4">
    <?php $stats = [
        ['Total Resources', $stat_total, 'fas fa-book-open', 'linear-gradient(135deg,#4f8ef7,#2d63e8)'],
        ['E-Books', $stat_ebooks, 'fas fa-tablet-screen-button', 'linear-gradient(135deg,#11c48d,#0a9971)'],
        ['Papers & Theses', $stat_research, 'fas fa-file-alt', 'linear-gradient(135deg,#f5a623,#d4870a)'],
        ['Total Downloads', $stat_downloads, 'fas fa-download', 'linear-gradient(135deg,#6f42c1,#4e2d8c)'],
    ]; foreach ($stats as [$label, $val, $icon, $bg]): ?>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:<?= $bg ?>;">
            <div class="d-flex justify-content-between align-items-start">
                <div><div class="stat-val"><?= number_format($val) ?></div><div class="stat-label"><?= $label ?></div></div>
                <div class="stat-icon"><i class="<?= $icon ?>"></i></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Filter -->
<div class="card mb-3">
    <div class="card-body py-3 px-4">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3"><input type="text" name="q" class="form-control form-control-sm" placeholder="Search title, author…" value="<?= h($search) ?>" style="border-radius:8px;"></div>
            <div class="col-md-2">
                <select name="resource_type" class="form-select form-select-sm" style="border-radius:8px;">
                    <option value="">All Types</option>
                    <?php foreach (['E-Book','Journal','Research Paper','Thesis','Dissertation','Other'] as $t): ?>
                    <option value="<?= $t ?>" <?= $type_f===$t?'selected':'' ?>><?= $t ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select name="category_id" class="form-select form-select-sm" style="border-radius:8px;">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>" <?= $cat_f==$c['id']?'selected':'' ?>><?= h($c['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select name="access_level" class="form-select form-select-sm" style="border-radius:8px;">
                    <option value="">All Levels</option>
                    <?php foreach (['Public','Students','Faculty','Staff','Admin'] as $al): ?><option value="<?= $al ?>" <?= $access_f===$al?'selected':'' ?>><?= $al ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1">
                <select name="is_active" class="form-select form-select-sm" style="border-radius:8px;">
                    <option value="">All</option>
                    <option value="1" <?= $active_f==='1'?'selected':'' ?>>Active</option>
                    <option value="0" <?= $active_f==='0'?'selected':'' ?>>Inactive</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-fill" style="border-radius:8px;"><i class="fas fa-search"></i></button>
                <a href="?" class="btn btn-outline-secondary btn-sm flex-fill" style="border-radius:8px;"><i class="fas fa-times"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- Table -->
<div class="card">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-list me-2 text-muted"></i>Digital Resources
            <span class="badge bg-secondary ms-2"><?= number_format($total_rows) ?></span>
        </h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">#</th>
                        <th>Cover</th>
                        <th>Title / Author</th>
                        <th>Type</th>
                        <th>Category</th>
                        <th>Department</th>
                        <th>Access</th>
                        <th>Size</th>
                        <th class="text-center">Downloads</th>
                        <th>Status</th>
                        <th>Uploaded</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($resources)): ?>
                    <tr><td colspan="12" class="text-center py-4 text-muted">No resources found.</td></tr>
                <?php else: ?>
                <?php foreach ($resources as $i => $r): ?>
                <tr>
                    <td class="ps-4"><?= $offset + $i + 1 ?></td>
                    <td>
                        <?php if ($r['cover_image']): ?>
                        <img src="<?= UPLOAD_URL ?>/library/covers/<?= h($r['cover_image']) ?>"
                             alt="" style="width:36px;height:48px;object-fit:cover;border-radius:4px;">
                        <?php else: ?>
                        <div class="d-flex align-items-center justify-content-center" style="width:36px;height:48px;background:#f0f0f0;border-radius:4px;">
                            <i class="<?= lib_mime_icon($r['mime_type'] ?? $r['file_name'] ?? '') ?> fa-lg"></i>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="fw-medium"><?= h($r['title']) ?></div>
                        <?php if ($r['author']): ?><div class="small text-muted"><?= h($r['author']) ?></div><?php endif; ?>
                    </td>
                    <td><?= lib_resource_type_badge($r['resource_type']) ?></td>
                    <td class="small"><?= $r['cat_name'] ? h($r['cat_name']) : '<span class="text-muted">—</span>' ?></td>
                    <td class="small"><?= $r['dept_name'] ? h($r['dept_name']) : '<span class="text-muted">—</span>' ?></td>
                    <td><?= lib_access_level_badge($r['access_level']) ?></td>
                    <td class="small text-muted"><?= $r['file_size'] ? lib_format_size((int)$r['file_size']) : '—' ?></td>
                    <td class="text-center"><span class="badge bg-light text-dark"><?= number_format((int)$r['download_count']) ?></span></td>
                    <td><?= $r['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td>
                    <td class="small text-muted"><?= $r['created_at'] ? date('d M Y', strtotime($r['created_at'])) : '—' ?></td>
                    <td class="text-end pe-4">
                        <div class="btn-group btn-group-sm">
                            <?php if (lib_can_access_digital() && $r['file_name']): ?>
                            <a href="<?= APP_URL ?>/library/digital/download.php?id=<?= $r['id'] ?>"
                               class="btn btn-outline-success btn-sm" title="Download"><i class="fas fa-download"></i></a>
                            <?php endif; ?>
                            <?php if (lib_is_staff()): ?>
                            <a href="<?= APP_URL ?>/library/digital/edit.php?id=<?= $r['id'] ?>"
                               class="btn btn-outline-primary btn-sm" title="Edit"><i class="fas fa-pencil"></i></a>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Toggle status?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                <button type="submit" class="btn btn-outline-<?= $r['is_active'] ? 'warning' : 'success' ?> btn-sm" title="Toggle">
                                    <i class="fas fa-<?= $r['is_active'] ? 'toggle-on' : 'toggle-off' ?>"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                            <?php if (lib_can_delete()): ?>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this resource permanently?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete"><i class="fas fa-trash"></i></button>
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
    <?php if ($total_pages > 1): ?>
    <div class="card-footer py-3 px-4">
        <nav><ul class="pagination pagination-sm mb-0 justify-content-center">
            <?php for ($p = 1; $p <= $total_pages; $p++): $qp = array_merge($_GET, ['page' => $p]); ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                <a class="page-link" href="?<?= http_build_query($qp) ?>"><?= $p ?></a>
            </li>
            <?php endfor; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
