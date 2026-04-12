<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';
require_access('medical-center');

$page_title = 'Medicine Stock';
$db = db();

$search   = trim($_GET['q']        ?? '');
$f_cat    = trim($_GET['category'] ?? '');
$f_stock  = $_GET['stock']         ?? '';

$where  = ['is_active = 1'];
$params = [];

if ($search !== '') {
    $where[]  = '(name LIKE ? OR generic_name LIKE ? OR supplier LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($f_cat !== '') {
    $where[]  = 'category = ?';
    $params[] = $f_cat;
}
if ($f_stock === 'low') {
    $where[] = 'quantity_in_stock <= reorder_level';
} elseif ($f_stock === 'ok') {
    $where[] = 'quantity_in_stock > reorder_level';
}

$where_sql = implode(' AND ', $where);
$per_page  = 20;
$page      = max(1, (int)($_GET['page'] ?? 1));

$cnt_stmt = $db->prepare("SELECT COUNT(*) FROM mc_medicines WHERE $where_sql");
$cnt_stmt->execute($params);
$total  = (int)$cnt_stmt->fetchColumn();
$pages  = max(1, (int)ceil($total / $per_page));
$page   = min($page, $pages);
$offset = ($page - 1) * $per_page;

$stmt = $db->prepare("SELECT * FROM mc_medicines WHERE $where_sql ORDER BY name LIMIT $per_page OFFSET $offset");
$stmt->execute($params);
$medicines = $stmt->fetchAll();

$categories = $db->query("SELECT DISTINCT category FROM mc_medicines WHERE category IS NOT NULL AND category != '' AND is_active = 1 ORDER BY category")->fetchAll(\PDO::FETCH_COLUMN);

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-pills me-2 text-danger"></i>Medicine Stock</h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/medical-center/index.php">Medical Center</a></li>
            <li class="breadcrumb-item active">Medicine Stock</li>
        </ol></nav>
    </div>
    <?php if (mc_can_create()): ?>
    <a href="<?= APP_URL ?>/medical-center/medicine-create.php" class="btn btn-primary btn-sm">
        <i class="fas fa-plus me-1"></i> Add Medicine
    </a>
    <?php endif; ?>
</div>

<?= flash_show() ?>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-sm-4 col-md-4">
                <input type="text" name="q" class="form-control form-control-sm"
                       placeholder="Search name / generic / supplier…" value="<?= h($search) ?>">
            </div>
            <div class="col-sm-3 col-md-2">
                <select name="category" class="form-select form-select-sm">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= h($cat) ?>" <?= $f_cat === $cat ? 'selected' : '' ?>><?= h($cat) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-3 col-md-2">
                <select name="stock" class="form-select form-select-sm">
                    <option value="">All Stock Levels</option>
                    <option value="low" <?= $f_stock === 'low' ? 'selected' : '' ?>>Low Stock Only</option>
                    <option value="ok"  <?= $f_stock === 'ok'  ? 'selected' : '' ?>>Adequate Stock</option>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search me-1"></i>Filter</button>
                <a href="<?= APP_URL ?>/medical-center/medicines.php" class="btn btn-outline-secondary btn-sm ms-1">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center py-3">
        <span class="fw-semibold"><?= number_format($total) ?> medicine<?= $total !== 1 ? 's' : '' ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Medicine</th>
                    <th>Category</th>
                    <th>Unit</th>
                    <th>In Stock</th>
                    <th>Reorder At</th>
                    <th>Expiry</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($medicines): ?>
                <?php foreach ($medicines as $i => $m): ?>
                <?php $low_stock = (int)$m['quantity_in_stock'] <= (int)$m['reorder_level']; ?>
                <tr class="<?= $low_stock ? 'table-danger' : '' ?>">
                    <td class="text-muted small"><?= $offset + $i + 1 ?></td>
                    <td>
                        <div class="fw-semibold"><?= h($m['name']) ?></div>
                        <?php if ($m['generic_name']): ?>
                        <div class="small text-muted"><?= h($m['generic_name']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= h($m['category'] ?: '—') ?></td>
                    <td class="small"><?= h($m['unit']) ?></td>
                    <td>
                        <?php if ($low_stock): ?>
                        <span class="badge bg-danger"><?= (int)$m['quantity_in_stock'] ?> <i class="fas fa-exclamation-triangle ms-1"></i></span>
                        <?php else: ?>
                        <span class="badge bg-success"><?= (int)$m['quantity_in_stock'] ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted"><?= (int)$m['reorder_level'] ?></td>
                    <td class="small"><?= $m['expiry_date'] ? date('M Y', strtotime($m['expiry_date'])) : '—' ?></td>
                    <td>
                        <div class="d-flex gap-1">
                            <?php if (mc_can_edit()): ?>
                            <a href="<?= APP_URL ?>/medical-center/medicine-edit.php?id=<?= $m['id'] ?>"
                               class="btn btn-outline-warning btn-sm" title="Edit">
                                <i class="fas fa-edit"></i>
                            </a>
                            <?php endif; ?>
                            <?php if (mc_can_delete()): ?>
                            <form method="post" action="<?= APP_URL ?>/medical-center/medicine-delete.php"
                                  onsubmit="return confirm('Delete this medicine?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $m['id'] ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php else: ?>
                <tr><td colspan="8" class="text-center text-muted py-5">No medicines found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pages > 1): ?>
    <div class="card-footer d-flex justify-content-between align-items-center py-2">
        <div class="small text-muted">Page <?= $page ?> of <?= $pages ?></div>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php for ($p = 1; $p <= $pages; $p++): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
