<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('notice-signing');
require_once __DIR__ . '/helpers.php';

$page_title = 'Notice Signing';
$user       = auth_user();

// ── Filters ───────────────────────────────────────────────────────────────────
$search = trim($_GET['q']      ?? '');
$status = in_array($_GET['status'] ?? '', ['draft','active','completed'], true) ? $_GET['status'] : '';
$mine   = !empty($_GET['mine']);

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[]  = '(d.title LIKE ? OR d.description LIKE ?)';
    $like     = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}
if ($status !== '') {
    $where[]  = 'd.status = ?';
    $params[] = $status;
}
if ($mine) {
    $where[]  = 'EXISTS (SELECT 1 FROM notice_sign_positions p WHERE p.document_id = d.id AND p.user_id = ?)';
    $params[] = $user['id'];
}

$where_sql = implode(' AND ', $where);

// ── Pagination ────────────────────────────────────────────────────────────────
$per_page = 20;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

$count_stmt = db()->prepare("SELECT COUNT(*) FROM notice_documents d WHERE $where_sql");
$count_stmt->execute($params);
$total = (int)$count_stmt->fetchColumn();
$pages = max(1, (int)ceil($total / $per_page));

$stmt = db()->prepare(
    "SELECT d.*,
            u.full_name AS creator_name,
            (SELECT COUNT(*) FROM notice_sign_positions p WHERE p.document_id = d.id) AS total_signers,
            (SELECT COUNT(*) FROM notice_signatures s WHERE s.document_id = d.id) AS signed_count
     FROM notice_documents d
     LEFT JOIN users u ON u.id = d.created_by
     WHERE $where_sql
     ORDER BY d.created_at DESC
     LIMIT $per_page OFFSET $offset"
);
$stmt->execute($params);
$docs = $stmt->fetchAll();

// My pending to-sign count
$my_pending_stmt = db()->prepare(
    'SELECT COUNT(*) FROM notice_sign_positions p
     JOIN notice_documents d ON d.id = p.document_id
     LEFT JOIN notice_signatures s ON s.document_id = p.document_id AND s.user_id = p.user_id
     WHERE p.user_id = ? AND d.status = \'active\' AND s.id IS NULL'
);
$my_pending_stmt->execute([$user['id']]);
$my_pending = (int)$my_pending_stmt->fetchColumn();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Notice Signing</li>
        </ol>
    </nav>
    <?php if (ns_can_manage()): ?>
    <a href="<?= APP_URL ?>/notice-signing/create.php" class="btn btn-primary" style="border-radius:10px;">
        <i class="fas fa-plus me-1"></i> New Notice
    </a>
    <?php endif; ?>
</div>

<?php if ($my_pending > 0): ?>
<div class="alert alert-warning d-flex align-items-center gap-3 mb-4" style="border-radius:12px;">
    <i class="fas fa-pen-nib fa-lg"></i>
    <div>
        You have <strong><?= $my_pending ?> pending signature<?= $my_pending > 1 ? 's' : '' ?></strong>.
        <a href="?mine=1&status=active" class="alert-link ms-1">View notices awaiting your signature →</a>
    </div>
</div>
<?php endif; ?>

<!-- Filter bar -->
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-body p-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-sm-4">
                <input type="text" name="q" class="form-control" placeholder="Search notices…" value="<?= h($search) ?>">
            </div>
            <div class="col-sm-2">
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="draft"     <?= $status === 'draft'     ? 'selected' : '' ?>>Draft</option>
                    <option value="active"    <?= $status === 'active'    ? 'selected' : '' ?>>Active</option>
                    <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Completed</option>
                </select>
            </div>
            <div class="col-sm-auto d-flex gap-2">
                <div class="form-check align-self-center ms-1">
                    <input class="form-check-input" type="checkbox" name="mine" id="mine" value="1" <?= $mine ? 'checked' : '' ?>>
                    <label class="form-check-label" for="mine">Assigned to me</label>
                </div>
                <button type="submit" class="btn btn-primary" style="border-radius:8px;">
                    <i class="fas fa-search me-1"></i> Filter
                </button>
                <a href="<?= APP_URL ?>/notice-signing/index.php" class="btn btn-light" style="border-radius:8px;">Clear</a>
            </div>
        </form>
    </div>
</div>

<!-- Notices list -->
<div class="card" style="border-radius:12px;">
    <div class="card-header d-flex align-items-center justify-content-between py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-file-signature me-2 text-primary"></i>Notices <span class="badge bg-secondary ms-1"><?= $total ?></span></h6>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:40px">#</th>
                    <th>Title</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Signatures</th>
                    <th>Created by</th>
                    <th>Date</th>
                    <th style="width:100px">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($docs)): ?>
                <tr><td colspan="8" class="text-center text-muted py-5">No notices found.</td></tr>
            <?php else: ?>
                <?php foreach ($docs as $i => $d): ?>
                <?php
                    $signed  = (int)$d['signed_count'];
                    $total_s = (int)$d['total_signers'];
                    $pct     = $total_s > 0 ? round($signed / $total_s * 100) : 0;
                ?>
                <tr>
                    <td class="text-muted" style="font-size:.8rem"><?= $offset + $i + 1 ?></td>
                    <td>
                        <a href="<?= APP_URL ?>/notice-signing/view.php?id=<?= $d['id'] ?>" class="fw-medium text-decoration-none">
                            <?= h($d['title']) ?>
                        </a>
                        <?php if ($d['description']): ?>
                        <div class="text-muted" style="font-size:.78rem"><?= h(mb_substr($d['description'], 0, 70)) ?><?= mb_strlen($d['description']) > 70 ? '…' : '' ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($d['document_type'] === 'pdf'): ?>
                        <span class="badge bg-danger bg-opacity-10 text-danger"><i class="fas fa-file-pdf me-1"></i>PDF</span>
                        <?php else: ?>
                        <span class="badge bg-info bg-opacity-10 text-info"><i class="fas fa-image me-1"></i>Image</span>
                        <?php endif; ?>
                    </td>
                    <td><?= ns_status_badge($d['status']) ?></td>
                    <td style="min-width:130px;">
                        <?php if ($total_s > 0): ?>
                        <div class="d-flex align-items-center gap-2">
                            <div class="progress flex-grow-1" style="height:6px;border-radius:3px;">
                                <div class="progress-bar <?= $pct === 100 ? 'bg-success' : 'bg-primary' ?>"
                                     style="width:<?= $pct ?>%"></div>
                            </div>
                            <span style="font-size:.8rem;white-space:nowrap;"><?= $signed ?>/<?= $total_s ?></span>
                        </div>
                        <?php else: ?>
                        <span class="text-muted" style="font-size:.85rem">No signers set</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.85rem"><?= h($d['creator_name'] ?? '—') ?></td>
                    <td style="font-size:.82rem;white-space:nowrap;"><?= date('d M Y', strtotime($d['created_at'])) ?></td>
                    <td>
                        <div class="d-flex gap-1">
                            <a href="<?= APP_URL ?>/notice-signing/view.php?id=<?= $d['id'] ?>" class="btn btn-sm btn-outline-primary" style="border-radius:6px;" title="View"><i class="fas fa-eye"></i></a>
                            <?php if (ns_can_delete()): ?>
                            <button type="button" class="btn btn-sm btn-outline-danger" style="border-radius:6px;" title="Delete"
                                    onclick="confirmDelete(<?= $d['id'] ?>, '<?= h(addslashes($d['title'])) ?>')">
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
                    <a class="page-link" href="?q=<?= urlencode($search) ?>&status=<?= urlencode($status) ?><?= $mine ? '&mine=1' : '' ?>&page=<?= $p ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<!-- Delete modal -->
<form id="deleteForm" method="POST" action="<?= APP_URL ?>/notice-signing/delete.php">
    <?= csrf_field() ?>
    <input type="hidden" name="id" id="deleteId">
</form>
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title">Delete Notice</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete <strong id="deleteLabel"></strong>? All signatures will be removed.</p>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" style="border-radius:8px;"
                        onclick="document.getElementById('deleteForm').submit()">
                    <i class="fas fa-trash me-1"></i> Delete
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDelete(id, label) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteLabel').textContent = label;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
