<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('file-manager');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../includes/db.php';

$page_title = 'File Manager';

// ── Filters ───────────────────────────────────────────────────────────────────
$search   = trim($_GET['q']        ?? '');
$category = trim($_GET['category'] ?? '');
$status   = in_array($_GET['status'] ?? '', ['active','archived'], true) ? $_GET['status'] : '';

[$vis_sql, $vis_params] = fm_visibility_where();

$where  = [$vis_sql];
$params = $vis_params;

if ($search !== '') {
    $where[]  = '(f.file_name LIKE ? OR f.file_location LIKE ? OR f.notes LIKE ? OR f.proposal LIKE ? OR f.page_number LIKE ?)';
    $like     = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($category !== '') {
    $where[]  = 'f.category = ?';
    $params[] = $category;
}
if ($status !== '') {
    $where[]  = 'f.status = ?';
    $params[] = $status;
}

$where_sql = implode(' AND ', $where);

// ── Pagination ────────────────────────────────────────────────────────────────
$per_page = 20;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

$count_stmt = db()->prepare("SELECT COUNT(*) FROM file_manager_files f WHERE $where_sql");
$count_stmt->execute($params);
$total = (int)$count_stmt->fetchColumn();
$pages = max(1, (int)ceil($total / $per_page));

$stmt = db()->prepare(
    "SELECT f.*, u.full_name AS creator_name,
            h.full_name AS holder_name
     FROM file_manager_files f
     LEFT JOIN users u ON u.id = f.creator_id
     LEFT JOIN users h ON h.id = f.current_holder_id
     WHERE $where_sql
     ORDER BY f.created_at DESC
     LIMIT $per_page OFFSET $offset"
);
$stmt->execute($params);
$files = $stmt->fetchAll();

// ── Categories for filter ─────────────────────────────────────────────────────
$cats = db()->query("SELECT DISTINCT category FROM file_manager_files WHERE category IS NOT NULL AND category <> '' ORDER BY category")
             ->fetchAll(PDO::FETCH_COLUMN);

// ── Pending transfers for current user ───────────────────────────────────────
$pending_transfers = fm_pending_transfers_count();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">File Manager</li>
        </ol>
    </nav>
    <div class="d-flex gap-2">
        <?php if ($pending_transfers > 0): ?>
        <a href="<?= APP_URL ?>/file-manager/transfer.php" class="btn btn-warning" style="border-radius:10px;">
            <i class="fas fa-exchange-alt me-1"></i> Pending Transfers
            <span class="badge bg-dark ms-1"><?= $pending_transfers ?></span>
        </a>
        <?php endif; ?>
        <?php if (fm_can_manage()): ?>
        <a href="<?= APP_URL ?>/file-manager/create.php" class="btn btn-primary" style="border-radius:10px;">
            <i class="fas fa-plus me-1"></i> New File
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Filter bar -->
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-body p-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-sm-4">
                <input type="text" name="q" class="form-control" placeholder="Search files…" value="<?= h($search) ?>">
            </div>
            <div class="col-sm-3">
                <select name="category" class="form-select">
                    <option value="">All Categories</option>
                    <?php foreach ($cats as $c): ?>
                    <option value="<?= h($c) ?>" <?= $category === $c ? 'selected' : '' ?>><?= h($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-2">
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="active"   <?= $status === 'active'   ? 'selected' : '' ?>>Active</option>
                    <option value="archived" <?= $status === 'archived' ? 'selected' : '' ?>>Archived</option>
                </select>
            </div>
            <div class="col-sm-auto d-flex gap-2">
                <button type="submit" class="btn btn-primary" style="border-radius:8px;">
                    <i class="fas fa-search me-1"></i> Filter
                </button>
                <a href="<?= APP_URL ?>/file-manager/index.php" class="btn btn-light" style="border-radius:8px;">Clear</a>
            </div>
        </form>
    </div>
</div>

<!-- Stats -->
<?php
$stat_params = $vis_params;
$stats_stmt = db()->prepare(
    "SELECT COUNT(*) AS total,
            SUM(f.status='active') AS active_count,
            SUM(f.status='archived') AS archived_count,
            SUM(f.uploaded_file IS NOT NULL) AS with_digital
     FROM file_manager_files f
     WHERE $vis_sql"
);
$stats_stmt->execute($stat_params);
$stats = $stats_stmt->fetch();
?>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card text-center h-100" style="border-radius:12px;">
            <div class="card-body py-3">
                <div style="font-size:1.6rem;font-weight:700;color:#4f8ef7"><?= $stats['total'] ?></div>
                <div class="text-muted" style="font-size:.8rem">Total Files</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center h-100" style="border-radius:12px;">
            <div class="card-body py-3">
                <div style="font-size:1.6rem;font-weight:700;color:#27ae60"><?= $stats['active_count'] ?></div>
                <div class="text-muted" style="font-size:.8rem">Active</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center h-100" style="border-radius:12px;">
            <div class="card-body py-3">
                <div style="font-size:1.6rem;font-weight:700;color:#e67e22"><?= $stats['archived_count'] ?></div>
                <div class="text-muted" style="font-size:.8rem">Archived</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center h-100" style="border-radius:12px;">
            <div class="card-body py-3">
                <div style="font-size:1.6rem;font-weight:700;color:#8e44ad"><?= $stats['with_digital'] ?></div>
                <div class="text-muted" style="font-size:.8rem">With Digital Copy</div>
            </div>
        </div>
    </div>
</div>

<!-- Files table -->
<div class="card" style="border-radius:12px;">
    <div class="card-header d-flex align-items-center justify-content-between py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-folder-open me-2 text-warning"></i>Files <span class="badge bg-secondary ms-1"><?= $total ?></span></h6>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:40px">#</th>
                    <th>File Name</th>
                    <th>Category</th>
                    <th>Location</th>
                    <th>Initiator</th>
                    <th>Current Holder</th>
                    <th>Status</th>
                    <th style="width:120px">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($files)): ?>
                <tr><td colspan="8" class="text-center text-muted py-5">No files found.</td></tr>
            <?php else: ?>
                <?php foreach ($files as $i => $f): ?>
                <tr>
                    <td class="text-muted" style="font-size:.8rem"><?= $offset + $i + 1 ?></td>
                    <td>
                        <a href="<?= APP_URL ?>/file-manager/view.php?id=<?= $f['id'] ?>" class="fw-medium text-decoration-none">
                            <?= h($f['file_name']) ?>
                        </a>
                        <?php if ($f['description']): ?>
                        <div class="text-muted" style="font-size:.78rem"><?= h(mb_substr($f['description'], 0, 80)) ?><?= mb_strlen($f['description']) > 80 ? '…' : '' ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= $f['category'] ? '<span class="badge bg-light text-dark border">' . h($f['category']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
                    <td style="max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= $f['file_location'] ? h($f['file_location']) : '<span class="text-muted">—</span>' ?></td>
                    <td style="font-size:.85rem">
                        <?= h($f['initiator_name'] ?? $f['creator_name'] ?? '—') ?>
                        <?php if (!empty($f['initiator_designation'])): ?>
                        <div class="text-muted" style="font-size:.75rem"><?= h($f['initiator_designation']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.85rem"><?= h($f['holder_name'] ?? '—') ?></td>
                    <td>
                        <?php if ($f['status'] === 'active'): ?>
                        <span class="badge bg-success">Active</span>
                        <?php else: ?>
                        <span class="badge bg-secondary">Archived</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="d-flex gap-1">
                            <a href="<?= APP_URL ?>/file-manager/view.php?id=<?= $f['id'] ?>" class="btn btn-sm btn-outline-primary" style="border-radius:6px;" title="View"><i class="fas fa-eye"></i></a>
                            <?php if (fm_can_edit()): ?>
                            <a href="<?= APP_URL ?>/file-manager/edit.php?id=<?= $f['id'] ?>" class="btn btn-sm btn-outline-secondary" style="border-radius:6px;" title="Edit"><i class="fas fa-edit"></i></a>
                            <?php endif; ?>
                            <?php if (fm_can_delete()): ?>
                            <button type="button" class="btn btn-sm btn-outline-danger" style="border-radius:6px;" title="Delete"
                                    onclick="confirmDelete(<?= $f['id'] ?>, '<?= h(addslashes($f['file_name'])) ?>')">
                                <i class="fas fa-trash"></i>
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

    <?php if ($pages > 1): ?>
    <div class="card-footer d-flex justify-content-between align-items-center px-4 py-3">
        <span class="text-muted" style="font-size:.85rem">
            Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total) ?> of <?= $total ?>
        </span>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php for ($p = 1; $p <= $pages; $p++): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <a class="page-link" href="?q=<?= urlencode($search) ?>&category=<?= urlencode($category) ?>&status=<?= urlencode($status) ?>&page=<?= $p ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<!-- Delete modal -->
<form id="deleteForm" method="POST" action="<?= APP_URL ?>/file-manager/delete.php">
    <?= csrf_field() ?>
    <input type="hidden" name="id" id="deleteId">
</form>
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title">Delete File</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete <strong id="deleteLabel"></strong>? This action cannot be undone.</p>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" style="border-radius:8px;" onclick="document.getElementById('deleteForm').submit()">
                    <i class="fas fa-trash me-1"></i> Delete
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDelete(id, label) {
    document.getElementById('deleteId').value    = id;
    document.getElementById('deleteLabel').textContent = label;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
