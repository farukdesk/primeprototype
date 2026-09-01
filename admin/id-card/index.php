<?php
/**
 * ID Card – Admin Index
 * Lists generated ID cards with a quick "Generate by Student ID" search,
 * per-card print-status quick change and bulk status update.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('id-card');
require_once __DIR__ . '/helpers.php';

$page_title = 'ID Cards';
$db = db();

$self_qs = ($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '';

// ── Toggle active status ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_active') {
    csrf_check();
    if (!idc_can_edit()) {
        flash_set('danger', 'You do not have permission to change ID card status.');
        redirect(APP_URL . '/id-card/index.php');
    }
    $tid  = (int)($_POST['id'] ?? 0);
    $card = idc_get_card($tid);
    if ($card) {
        $db->prepare('UPDATE idc_cards SET is_active = ? WHERE id = ?')
           ->execute([$card['is_active'] ? 0 : 1, $tid]);
        flash_set('success', 'ID card status updated.');
    }
    redirect(APP_URL . '/id-card/index.php' . $self_qs);
}

// ── Print-status update (single quick change / bulk) ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['set_status', 'bulk_status'], true)) {
    csrf_check();
    if (!idc_can_edit()) {
        flash_set('danger', 'You do not have permission to change ID card status.');
        redirect(APP_URL . '/id-card/index.php');
    }
    $new_status = trim((string)($_POST['print_status'] ?? ''));
    if (!isset(IDC_PRINT_STATUSES[$new_status])) {
        flash_set('danger', 'Invalid status selected.');
        redirect(APP_URL . '/id-card/index.php' . $self_qs);
    }

    $ids = ($_POST['action'] === 'bulk_status')
        ? array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), static fn(int $i): bool => $i > 0))
        : array_values(array_filter([(int)($_POST['id'] ?? 0)], static fn(int $i): bool => $i > 0));

    if (!$ids) {
        flash_set('warning', 'No ID cards selected.');
        redirect(APP_URL . '/id-card/index.php' . $self_qs);
    }

    try {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $st = $db->prepare(
            "UPDATE idc_cards
             SET print_status = ?, print_status_updated_at = NOW(), print_status_updated_by = ?
             WHERE id IN ($placeholders)"
        );
        $st->execute(array_merge([$new_status, auth_user()['id']], $ids));
        flash_set('success', $st->rowCount() . ' ID card(s) marked as “' . h(idc_print_status_label($new_status)) . '”.');
    } catch (Throwable $e) {
        flash_set('danger', 'Could not update status – run <code>admin/id-card-status-v1.sql</code> first. (' . h($e->getMessage()) . ')');
    }
    redirect(APP_URL . '/id-card/index.php' . $self_qs);
}

// ── Filters ──────────────────────────────────────────────────────────────
$f_search  = trim($_GET['search'] ?? '');
$f_type    = trim($_GET['type'] ?? '');
$f_pstatus = trim($_GET['pstatus'] ?? '');
$per_page = 25;
$cur_page = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($cur_page - 1) * $per_page;

$where  = '1=1';
$params = [];
if ($f_search !== '') {
    $where .= ' AND (c.id_number LIKE ? OR c.full_name LIKE ?)';
    $params[] = "%$f_search%";
    $params[] = "%$f_search%";
}
if ($f_type !== '' && isset(IDC_TYPES[$f_type])) {
    $where .= ' AND c.card_type = ?';
    $params[] = $f_type;
}
if ($f_pstatus !== '' && isset(IDC_PRINT_STATUSES[$f_pstatus])) {
    $where .= ' AND c.print_status = ?';
    $params[] = $f_pstatus;
}

$total = 0;
$rows  = [];
try {
    $st = $db->prepare("SELECT COUNT(*) FROM idc_cards c WHERE $where");
    $st->execute($params);
    $total = (int)$st->fetchColumn();

    $st = $db->prepare(
        "SELECT c.* FROM idc_cards c WHERE $where
         ORDER BY c.created_at DESC, c.id DESC
         LIMIT $per_page OFFSET $offset"
    );
    $st->execute($params);
    $rows = $st->fetchAll();
} catch (Throwable $e) {
    flash_set('danger', 'ID card table missing or outdated – run <code>admin/id-card-v1.sql</code> and <code>admin/id-card-status-v1.sql</code>.');
}
$total_pages = max(1, (int)ceil($total / $per_page));
$can_edit = idc_can_edit();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">ID Cards</li>
        </ol>
    </nav>
    <?php if (idc_can_create()): ?>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/id-card/bulk-create.php" class="btn btn-outline-success btn-sm">
            <i class="fas fa-layer-group me-1"></i> Bulk Create
        </a>
        <a href="<?= APP_URL ?>/id-card/create.php" class="btn btn-outline-primary btn-sm">
            <i class="fas fa-pen me-1"></i> Manual Entry
        </a>
    </div>
    <?php endif; ?>
</div>

<?php if (idc_can_create()): ?>
<div class="card mb-4">
    <div class="card-body p-3">
        <form method="GET" action="<?= APP_URL ?>/id-card/create.php" class="row g-2 align-items-center">
            <div class="col-auto"><span class="fw-semibold"><i class="fas fa-bolt text-warning me-1"></i>Quick Generate:</span></div>
            <div class="col-md-4 col-sm-6">
                <input type="text" name="student_id" class="form-control form-control-sm"
                       placeholder="Enter Student ID (e.g. 20250101001)" required>
            </div>
            <div class="col-auto">
                <button class="btn btn-primary btn-sm"><i class="fas fa-id-card me-1"></i>Generate Student ID Card</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-id-card me-2 text-muted"></i>Generated ID Cards (<?= $total ?>)</h6>
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <?php if ($can_edit): ?>
            <!-- Bulk status update: applies the chosen status to all selected cards -->
            <form method="POST" id="bulkStatusForm" class="d-flex gap-2 align-items-center"
                  onsubmit="return confirm('Apply the selected status to ' + document.querySelectorAll('.js-idc-check:checked').length + ' ID card(s)?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="bulk_status">
                <select name="print_status" class="form-select form-select-sm" style="width:auto" required>
                    <option value="">Bulk: set status…</option>
                    <?php foreach (IDC_PRINT_STATUSES as $k => $v): ?>
                        <option value="<?= $k ?>"><?= h($v) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-warning js-bulk-status-btn" disabled>
                    <i class="fas fa-tags me-1"></i>Apply (<span class="js-idc-count">0</span>)
                </button>
            </form>
            <?php endif; ?>
            <form method="GET" class="d-flex gap-2">
                <select name="pstatus" class="form-select form-select-sm" style="width:auto">
                    <option value="">All Print Statuses</option>
                    <?php foreach (IDC_PRINT_STATUSES as $k => $v): ?>
                        <option value="<?= $k ?>" <?= $f_pstatus === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="type" class="form-select form-select-sm" style="width:auto">
                    <option value="">All Types</option>
                    <?php foreach (IDC_TYPES as $k => $v): ?>
                        <option value="<?= $k ?>" <?= $f_type === $k ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="search" class="form-control form-control-sm" style="width:220px"
                       placeholder="Search ID / name…" value="<?= h($f_search) ?>">
                <button class="btn btn-sm btn-outline-secondary"><i class="fas fa-search"></i></button>
            </form>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <?php if ($can_edit): ?>
                    <th style="width:1%"><input type="checkbox" class="form-check-input" id="idcSelectAll" title="Select all"></th>
                    <?php endif; ?>
                    <th>Type</th>
                    <th>ID Number</th>
                    <th>Name</th>
                    <th>Program / Designation</th>
                    <th>Blood</th>
                    <th>Validity</th>
                    <th>Print Status</th>
                    <th>Active</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="<?= $can_edit ? 10 : 9 ?>" class="text-center text-muted py-4">No ID cards yet. Use Quick Generate above or Manual Entry.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <?php $pstatus = trim((string)($r['print_status'] ?? '')) ?: 'in_printing_queue'; ?>
                <tr>
                    <?php if ($can_edit): ?>
                    <td><input type="checkbox" class="form-check-input js-idc-check" form="bulkStatusForm" name="ids[]" value="<?= (int)$r['id'] ?>"></td>
                    <?php endif; ?>
                    <td><span class="badge bg-<?= $r['card_type'] === 'student' ? 'primary' : ($r['card_type'] === 'faculty' ? 'success' : 'secondary') ?>"><?= h(IDC_TYPES[$r['card_type']] ?? $r['card_type']) ?></span></td>
                    <td class="fw-semibold"><?= h($r['id_number']) ?></td>
                    <td><?= h($r['full_name']) ?></td>
                    <td><?= h($r['card_type'] === 'student' ? (string)$r['program_name'] : (string)$r['designation']) ?></td>
                    <td><?= h((string)$r['blood_group']) ?></td>
                    <td class="small text-muted"><?= h(idc_fmt_date($r['issue_date'])) ?> – <?= h(idc_fmt_date($r['expiry_date'])) ?></td>
                    <td>
                        <?php if ($can_edit): ?>
                        <form method="POST" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="set_status">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <select name="print_status" class="form-select form-select-sm" style="width:auto;min-width:150px"
                                    onchange="this.form.submit()" title="Change print status">
                                <?php foreach (IDC_PRINT_STATUSES as $k => $v): ?>
                                    <option value="<?= $k ?>" <?= $pstatus === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <?php else: ?>
                            <?= idc_print_status_badge($pstatus) ?>
                        <?php endif; ?>
                        <?php if (!empty($r['print_status_updated_at'])): ?>
                        <div class="text-muted" style="font-size:.68rem"><?= h(date('d/m/Y H:i', strtotime($r['print_status_updated_at']))) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($can_edit): ?>
                        <form method="POST" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle_active">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-sm badge border-0 bg-<?= $r['is_active'] ? 'success' : 'secondary' ?>" title="Click to toggle">
                                <?= $r['is_active'] ? 'Active' : 'Inactive' ?>
                            </button>
                        </form>
                        <?php else: ?>
                            <span class="badge bg-<?= $r['is_active'] ? 'success' : 'secondary' ?>"><?= $r['is_active'] ? 'Active' : 'Inactive' ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <a href="<?= APP_URL ?>/id-card/print.php?id=<?= (int)$r['id'] ?>" target="_blank"
                           class="btn btn-sm btn-outline-primary" title="Preview & Print"><i class="fas fa-print"></i></a>
                        <?php if ($can_edit): ?>
                        <a href="<?= APP_URL ?>/id-card/edit.php?id=<?= (int)$r['id'] ?>"
                           class="btn btn-sm btn-outline-secondary" title="Edit"><i class="fas fa-pen"></i></a>
                        <?php endif; ?>
                        <?php if (idc_can_delete()): ?>
                        <form method="POST" action="<?= APP_URL ?>/id-card/delete.php" class="d-inline"
                              onsubmit="return confirm('Delete this ID card record?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($total_pages > 1): ?>
    <div class="card-footer">
        <nav><ul class="pagination pagination-sm mb-0">
            <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                <li class="page-item <?= $p === $cur_page ? 'active' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= $p ?></a>
                </li>
            <?php endfor; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<?php if ($can_edit): ?>
<script>
(function () {
    var selectAll = document.getElementById('idcSelectAll');
    var checks    = Array.prototype.slice.call(document.querySelectorAll('.js-idc-check'));
    var bulkBtn   = document.querySelector('.js-bulk-status-btn');
    var countEls  = Array.prototype.slice.call(document.querySelectorAll('.js-idc-count'));

    function refresh() {
        var n = checks.filter(function (c) { return c.checked; }).length;
        countEls.forEach(function (el) { el.textContent = n; });
        if (bulkBtn) { bulkBtn.disabled = n === 0; }
        if (selectAll) { selectAll.checked = checks.length > 0 && n === checks.length; }
    }
    if (selectAll) {
        selectAll.addEventListener('change', function () {
            checks.forEach(function (c) { c.checked = selectAll.checked; });
            refresh();
        });
    }
    checks.forEach(function (c) { c.addEventListener('change', refresh); });
    refresh();
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
