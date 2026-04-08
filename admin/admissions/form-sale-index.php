<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('admissions');
require_once __DIR__ . '/form-sale-helpers.php';

$page_title = 'Form Sale';
$user       = auth_user();

// ── Filters ───────────────────────────────────────────────────────────────────
$search   = trim($_GET['search'] ?? '');
$f_status = $_GET['status']      ?? '';
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;

$valid_statuses = ['pending', 'used', 'cancelled'];

$where  = [];
$params = [];

if ($search !== '') {
    $like = '%' . $search . '%';
    $where[]  = '(fs.form_number LIKE ? OR fs.buyer_name LIKE ? OR fs.buyer_mobile LIKE ? OR fs.buyer_email LIKE ?)';
    array_push($params, $like, $like, $like, $like);
}
if (in_array($f_status, $valid_statuses, true)) {
    $where[]  = 'fs.status = ?';
    $params[] = $f_status;
}

$where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$count_stmt = db()->prepare('SELECT COUNT(*) FROM adm_form_sales fs' . $where_sql);
$count_stmt->execute($params);
$total_rows  = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$sql = 'SELECT fs.*, u.full_name AS sold_by_name
        FROM adm_form_sales fs
        LEFT JOIN users u ON u.id = fs.sold_by'
     . $where_sql
     . ' ORDER BY fs.sold_at DESC LIMIT ' . $per_page . ' OFFSET ' . $offset;

$stmt = db()->prepare($sql);
$stmt->execute($params);
$sales = $stmt->fetchAll();

$filter_qs = http_build_query(array_filter([
    'search' => $search,
    'status' => $f_status,
]));

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-receipt me-2 text-warning"></i>Form Sale</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/admissions/index.php">Admissions</a></li>
            <li class="breadcrumb-item active">Form Sale</li>
        </ol></nav>
    </div>
    <?php if (adm_can_manage()): ?>
    <a href="<?= APP_URL ?>/admissions/form-sale-create.php" class="btn btn-warning btn-sm text-dark fw-semibold">
        <i class="fas fa-plus me-1"></i> Sell Form
    </a>
    <?php endif; ?>
</div>

<?php flash_show(); ?>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-12 col-md-5">
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="Search form no, name, mobile…" value="<?= h($search) ?>">
            </div>
            <div class="col-6 col-md-3">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <?php foreach ($valid_statuses as $st): ?>
                    <option value="<?= h($st) ?>" <?= $f_status === $st ? 'selected' : '' ?>><?= ucfirst(h($st)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search me-1"></i>Filter</button>
                <a href="<?= APP_URL ?>/admissions/form-sale-index.php" class="btn btn-outline-secondary btn-sm ms-1">Clear</a>
            </div>
        </form>
    </div>
</div>

<!-- Table -->
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-4">#</th>
                    <th>Form No</th>
                    <th>Buyer Name</th>
                    <th>Mobile</th>
                    <th>Email</th>
                    <th>Price (Tk)</th>
                    <th>Status</th>
                    <th>Sold At</th>
                    <th>Sold By</th>
                    <th class="text-end pe-4">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($sales)): ?>
                <tr><td colspan="10" class="text-center py-4 text-muted">No form sales found.</td></tr>
            <?php else: ?>
                <?php foreach ($sales as $i => $sale): ?>
                <tr>
                    <td class="ps-4 text-muted small"><?= $offset + $i + 1 ?></td>
                    <td><span class="fw-semibold"><?= h($sale['form_number']) ?></span></td>
                    <td><?= h($sale['buyer_name']) ?></td>
                    <td class="text-muted small"><?= h($sale['buyer_mobile']) ?></td>
                    <td class="text-muted small"><?= $sale['buyer_email'] ? h($sale['buyer_email']) : '—' ?></td>
                    <td class="text-muted small"><?= number_format((float)$sale['form_price'], 2) ?></td>
                    <td><?= adm_fs_status_badge($sale['status']) ?></td>
                    <td class="text-muted small"><?= h(date('d M Y H:i', strtotime($sale['sold_at']))) ?></td>
                    <td class="text-muted small"><?= h($sale['sold_by_name'] ?? '—') ?></td>
                    <td class="text-end pe-4">
                        <div class="d-flex justify-content-end gap-1">
                            <a href="<?= APP_URL ?>/admissions/form-sale-print.php?id=<?= $sale['id'] ?>"
                               class="btn btn-sm btn-outline-success" title="Print Invoice" target="_blank">
                                <i class="fas fa-print"></i>
                            </a>
                            <?php if ($sale['status'] === 'pending' && adm_can_delete()): ?>
                            <button type="button" class="btn btn-sm btn-outline-danger" title="Cancel"
                                    onclick="confirmCancel(<?= $sale['id'] ?>, '<?= h(addslashes($sale['form_number'])) ?>')">
                                <i class="fas fa-ban"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($total_pages > 1): ?>
    <div class="card-footer d-flex justify-content-between align-items-center px-4 py-3">
        <span class="text-muted small">Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total_rows) ?> of <?= $total_rows ?></span>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <a class="page-link" href="?<?= $filter_qs ?>&page=<?= $p ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<!-- Cancel Modal -->
<form id="cancelForm" method="POST" action="<?= APP_URL ?>/admissions/form-sale-delete.php">
    <?= csrf_field() ?>
    <input type="hidden" name="id" id="cancelId">
</form>
<div class="modal fade" id="cancelModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title">Cancel Form Sale</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to cancel form <strong id="cancelLabel"></strong>? This action cannot be undone.</p>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">No, Keep It</button>
                <button type="button" class="btn btn-danger" style="border-radius:8px;"
                        onclick="document.getElementById('cancelForm').submit()">
                    <i class="fas fa-ban me-1"></i> Cancel Form
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function confirmCancel(id, label) {
    document.getElementById('cancelId').value = id;
    document.getElementById('cancelLabel').textContent = label;
    new bootstrap.Modal(document.getElementById('cancelModal')).show();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
