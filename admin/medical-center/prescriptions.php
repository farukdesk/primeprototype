<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';
require_access('medical-center');

$page_title = 'Prescriptions';
$db = db();

$search = trim($_GET['q'] ?? '');
$f_type = $_GET['type']   ?? '';
$f_date = $_GET['date']   ?? '';

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[]  = '(patient_name LIKE ? OR patient_id_no LIKE ? OR diagnosis LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if (in_array($f_type, ['student','faculty','staff','officer'], true)) {
    $where[]  = 'patient_type = ?';
    $params[] = $f_type;
}
if ($f_date !== '') {
    $where[]  = 'prescription_date = ?';
    $params[] = $f_date;
}

$where_sql = implode(' AND ', $where);
$per_page  = 20;
$page      = max(1, (int)($_GET['page'] ?? 1));

$cnt_stmt = $db->prepare("SELECT COUNT(*) FROM mc_prescriptions WHERE $where_sql");
$cnt_stmt->execute($params);
$total  = (int)$cnt_stmt->fetchColumn();
$pages  = max(1, (int)ceil($total / $per_page));
$page   = min($page, $pages);
$offset = ($page - 1) * $per_page;

$stmt = $db->prepare("SELECT * FROM mc_prescriptions WHERE $where_sql ORDER BY created_at DESC LIMIT $per_page OFFSET $offset");
$stmt->execute($params);
$prescriptions = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-file-medical me-2 text-success"></i>Prescriptions</h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/medical-center/index.php">Medical Center</a></li>
            <li class="breadcrumb-item active">Prescriptions</li>
        </ol></nav>
    </div>
    <?php if (mc_can_create()): ?>
    <a href="<?= APP_URL ?>/medical-center/prescription-create.php" class="btn btn-primary btn-sm">
        <i class="fas fa-plus me-1"></i> New Prescription
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
                       placeholder="Search name / ID / diagnosis…" value="<?= h($search) ?>">
            </div>
            <div class="col-sm-3 col-md-2">
                <select name="type" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    <?php foreach (['student','faculty','staff','officer'] as $t): ?>
                    <option value="<?= $t ?>" <?= $f_type === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-3 col-md-2">
                <input type="date" name="date" class="form-control form-control-sm" value="<?= h($f_date) ?>">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search me-1"></i>Filter</button>
                <a href="<?= APP_URL ?>/medical-center/prescriptions.php" class="btn btn-outline-secondary btn-sm ms-1">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center py-3">
        <span class="fw-semibold"><?= number_format($total) ?> prescription<?= $total !== 1 ? 's' : '' ?> found</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Patient</th>
                    <th>Type</th>
                    <th>Diagnosis</th>
                    <th>Rx Date</th>
                    <th>Follow-up</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($prescriptions): ?>
                <?php foreach ($prescriptions as $i => $rx): ?>
                <tr>
                    <td class="text-muted small"><?= $offset + $i + 1 ?></td>
                    <td>
                        <div class="fw-semibold"><?= h($rx['patient_name']) ?></div>
                        <div class="small text-muted"><?= h($rx['patient_id_no'] ?: '—') ?></div>
                    </td>
                    <td><?= mc_patient_badge($rx['patient_type']) ?></td>
                    <td class="small text-muted" style="max-width:200px">
                        <?= h(mb_strimwidth($rx['diagnosis'] ?? '', 0, 70, '…')) ?>
                    </td>
                    <td class="small"><?= date('d M Y', strtotime($rx['prescription_date'])) ?></td>
                    <td class="small"><?= $rx['follow_up_date'] ? date('d M Y', strtotime($rx['follow_up_date'])) : '—' ?></td>
                    <td>
                        <div class="d-flex gap-1">
                            <a href="<?= APP_URL ?>/medical-center/prescription-view.php?id=<?= $rx['id'] ?>"
                               class="btn btn-outline-primary btn-sm" title="View / Print">
                                <i class="fas fa-print"></i>
                            </a>
                            <?php if (mc_can_delete()): ?>
                            <form method="post" action="<?= APP_URL ?>/medical-center/prescription-delete.php"
                                  onsubmit="return confirm('Delete this prescription?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $rx['id'] ?>">
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
                <tr><td colspan="7" class="text-center text-muted py-5">No prescriptions found.</td></tr>
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
