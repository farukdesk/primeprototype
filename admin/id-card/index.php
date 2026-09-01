<?php
/**
 * ID Card – Admin Index
 * Lists generated ID cards with a quick "Generate by Student ID" search.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('id-card');
require_once __DIR__ . '/helpers.php';

$page_title = 'ID Cards';
$db = db();

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
    redirect(APP_URL . '/id-card/index.php' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''));
}

// ── Filters ──────────────────────────────────────────────────────────────────
$f_search = trim($_GET['search'] ?? '');
$f_type   = trim($_GET['type'] ?? '');
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
    flash_set('danger', 'ID card table missing – run <code>admin/id-card-v1.sql</code> first.');
}
$total_pages = max(1, (int)ceil($total / $per_page));

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
        <form method="GET" class="d-flex gap-2">
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
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Type</th>
                    <th>ID Number</th>
                    <th>Name</th>
                    <th>Program / Designation</th>
                    <th>Blood</th>
                    <th>Validity</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No ID cards yet. Use Quick Generate above or Manual Entry.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><span class="badge bg-<?= $r['card_type'] === 'student' ? 'primary' : ($r['card_type'] === 'faculty' ? 'success' : 'secondary') ?>"><?= h(IDC_TYPES[$r['card_type']] ?? $r['card_type']) ?></span></td>
                    <td class="fw-semibold"><?= h($r['id_number']) ?></td>
                    <td><?= h($r['full_name']) ?></td>
                    <td><?= h($r['card_type'] === 'student' ? (string)$r['program_name'] : (string)$r['designation']) ?></td>
                    <td><?= h((string)$r['blood_group']) ?></td>
                    <td class="small text-muted"><?= h(idc_fmt_date($r['issue_date'])) ?> – <?= h(idc_fmt_date($r['expiry_date'])) ?></td>
                    <td>
                        <?php if (idc_can_edit()): ?>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
