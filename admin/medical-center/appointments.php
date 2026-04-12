<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';
require_access('medical-center');

$page_title = 'Appointments';
$db = db();

$search   = trim($_GET['q']      ?? '');
$f_status = $_GET['status']      ?? '';
$f_type   = $_GET['type']        ?? '';
$f_date   = $_GET['date']        ?? '';

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[]  = '(patient_name LIKE ? OR contact_number LIKE ? OR token_number LIKE ? OR patient_id_no LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if (in_array($f_status, ['pending','confirmed','completed','cancelled'], true)) {
    $where[]  = 'status = ?';
    $params[] = $f_status;
}
if (in_array($f_type, ['student','faculty','staff','officer'], true)) {
    $where[]  = 'patient_type = ?';
    $params[] = $f_type;
}
if ($f_date !== '') {
    $where[]  = 'appointment_date = ?';
    $params[] = $f_date;
}

$where_sql = implode(' AND ', $where);
$per_page  = 20;
$page      = max(1, (int)($_GET['page'] ?? 1));

$cnt_stmt = $db->prepare("SELECT COUNT(*) FROM mc_appointments WHERE $where_sql");
$cnt_stmt->execute($params);
$total = (int)$cnt_stmt->fetchColumn();
$pages = max(1, (int)ceil($total / $per_page));
$page  = min($page, $pages);
$offset = ($page - 1) * $per_page;

$stmt = $db->prepare("SELECT * FROM mc_appointments WHERE $where_sql ORDER BY created_at DESC LIMIT ? OFFSET ?");
$stmt->execute(array_merge($params, [$per_page, $offset]));
$appointments = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-calendar-check me-2 text-primary"></i>Appointments</h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/medical-center/index.php">Medical Center</a></li>
            <li class="breadcrumb-item active">Appointments</li>
        </ol></nav>
    </div>
</div>

<?= flash_show() ?>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-sm-4 col-md-3">
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Search name / phone / token…"
                       value="<?= h($search) ?>">
            </div>
            <div class="col-sm-3 col-md-2">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Status</option>
                    <?php foreach (['pending','confirmed','completed','cancelled'] as $s): ?>
                    <option value="<?= $s ?>" <?= $f_status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select>
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
                <a href="<?= APP_URL ?>/medical-center/appointments.php" class="btn btn-outline-secondary btn-sm ms-1">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center py-3">
        <span class="fw-semibold">
            <?= number_format($total) ?> appointment<?= $total !== 1 ? 's' : '' ?> found
        </span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Token</th>
                    <th>Patient</th>
                    <th>Type</th>
                    <th>Date &amp; Time</th>
                    <th>Chief Complaint</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($appointments): ?>
                <?php foreach ($appointments as $i => $a): ?>
                <tr>
                    <td class="text-muted small"><?= $offset + $i + 1 ?></td>
                    <td><code class="small"><?= h($a['token_number'] ?: '—') ?></code></td>
                    <td>
                        <div class="fw-semibold"><?= h($a['patient_name']) ?></div>
                        <div class="small text-muted"><?= h($a['contact_number']) ?><?= $a['patient_id_no'] ? ' · ' . h($a['patient_id_no']) : '' ?></div>
                    </td>
                    <td><?= mc_patient_badge($a['patient_type']) ?></td>
                    <td>
                        <div class="small"><?= date('d M Y', strtotime($a['appointment_date'])) ?></div>
                        <div class="small text-muted"><?= date('h:i A', strtotime($a['appointment_time'])) ?></div>
                    </td>
                    <td class="small text-muted" style="max-width:180px">
                        <?= h(mb_strimwidth($a['chief_complaint'] ?? '', 0, 60, '…')) ?>
                    </td>
                    <td><?= mc_status_badge($a['status']) ?></td>
                    <td>
                        <div class="d-flex gap-1">
                            <a href="<?= APP_URL ?>/medical-center/appointment-view.php?id=<?= $a['id'] ?>"
                               class="btn btn-outline-primary btn-sm" title="View">
                                <i class="fas fa-eye"></i>
                            </a>
                            <?php if (mc_can_delete()): ?>
                            <form method="post" action="<?= APP_URL ?>/medical-center/appointment-delete.php"
                                  onsubmit="return confirm('Delete this appointment?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $a['id'] ?>">
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
                <tr><td colspan="8" class="text-center text-muted py-5">No appointments found.</td></tr>
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
