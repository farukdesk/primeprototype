<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/slot-helpers.php';
require_access('exam-invigilation');

$page_title = 'Exam Invigilation';

// ── Legacy report URLs: the Faculty Duty Report now lives on reports.php ────
if (isset($_GET['report_export']) || isset($_GET['report_exam_id']) || isset($_GET['report_dept_id'])
    || isset($_GET['report_faculty_id']) || isset($_GET['report_date_from']) || isset($_GET['report_date_to'])) {
    redirect(APP_URL . '/exam-invigilation/reports.php?' . http_build_query($_GET));
}

// ── Handle inline actions ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    csrf_check();
    $eid = (int)($_POST['id'] ?? 0);

    if ($_POST['_action'] === 'toggle') {
        db()->prepare('UPDATE ei_exams SET is_active = 1 - is_active WHERE id = ?')->execute([$eid]);
        flash_set('success', 'Status updated.');
    } elseif ($_POST['_action'] === 'save_settings') {
        require_access('exam-invigilation', 'can_edit');
        $auto_assign_max_slots         = max(1, min(100, (int)($_POST['auto_assign_max_slots'] ?? 12)));
        $auto_assign_max_slots_per_day = max(1, min(50,  (int)($_POST['auto_assign_max_slots_per_day'] ?? 3)));
        ei_save_setting('auto_assign_max_slots',         (string)$auto_assign_max_slots);
        ei_save_setting('auto_assign_max_slots_per_day', (string)$auto_assign_max_slots_per_day);
        flash_set('success', 'Auto-assign slot caps updated.');
    } elseif ($_POST['_action'] === 'delete') {
        require_access('exam-invigilation', 'can_delete');
        db()->prepare('DELETE FROM ei_exams WHERE id = ?')->execute([$eid]);
        flash_set('success', 'Exam deleted.');
    }
    redirect(APP_URL . '/exam-invigilation/index.php');
}

// ── Filters ──────────────────────────────────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 20;

$where  = [];
$params = [];
if ($search !== '') {
    $where[]  = '(e.exam_name LIKE ? OR e.exam_year LIKE ?)';
    $s        = '%' . $search . '%';
    $params   = [$s, $s];
}

$sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$cnt_st = db()->prepare("SELECT COUNT(*) FROM ei_exams e $sql_where");
$cnt_st->execute($params);
$total = (int)$cnt_st->fetchColumn();

$pages  = max(1, (int)ceil($total / $per));
$page   = min($page, $pages);
$offset = ($page - 1) * $per;

$st = db()->prepare(
    "SELECT e.*,
            (SELECT COUNT(*) FROM ei_slots s WHERE s.exam_id = e.id) AS slot_count,
            (SELECT COUNT(*) FROM ei_slots s WHERE s.exam_id = e.id AND s.faculty1_id IS NOT NULL AND s.faculty2_id IS NOT NULL) AS assigned_count
     FROM ei_exams e $sql_where
     ORDER BY e.exam_year DESC, e.exam_name ASC
     LIMIT $per OFFSET $offset"
);
$st->execute($params);
$rows = $st->fetchAll();

// ── Overview stats ────────────────────────────────────────────────────────────
$active_exam_count = (int)db()->query('SELECT COUNT(*) FROM ei_exams WHERE is_active = 1')->fetchColumn();

$vacant_seat_count = (int)db()->query(
    'SELECT COALESCE(SUM((s.faculty1_id IS NULL) + (s.faculty2_id IS NULL)), 0)
     FROM ei_slots s
     JOIN ei_exams e ON e.id = s.exam_id
     WHERE e.is_active = 1'
)->fetchColumn();

$total_active_faculty = (int)db()->query('SELECT COUNT(*) FROM ei_faculty WHERE is_active = 1')->fetchColumn();
$assigned_faculty_count = (int)db()->query(
    'SELECT COUNT(DISTINCT fid) FROM (
        SELECT faculty1_id AS fid FROM ei_slots s JOIN ei_exams e ON e.id = s.exam_id WHERE e.is_active = 1 AND s.faculty1_id IS NOT NULL
        UNION
        SELECT faculty2_id FROM ei_slots s JOIN ei_exams e ON e.id = s.exam_id WHERE e.is_active = 1 AND s.faculty2_id IS NOT NULL
    ) t'
)->fetchColumn();
$available_backup_faculty = max(0, $total_active_faculty - $assigned_faculty_count);

$auto_assign_max_slots         = ei_get_auto_assign_max_slots();
$auto_assign_max_slots_per_day = ei_get_auto_assign_max_slots_per_day();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Exam Invigilation</li>
        </ol>
    </nav>
    <div class="d-flex gap-2">
        <?php if (is_super_admin() || can_access('exam-invigilation', 'can_edit')): ?>
        <button type="button" class="btn btn-outline-secondary btn-sm" style="border-radius:10px;"
                data-bs-toggle="modal" data-bs-target="#eiSettingsModal" title="Auto-assign slot caps">
            <i class="fas fa-sliders-h me-1"></i> Settings
        </button>
        <?php endif; ?>
        <?php if (is_super_admin() || can_access('exam-invigilation', 'can_create')): ?>
        <a href="<?= APP_URL ?>/exam-invigilation/create.php" class="btn btn-primary btn-sm" style="border-radius:10px;">
            <i class="fas fa-plus me-1"></i> New Exam
        </a>
        <?php endif; ?>
    </div>
</div>

<?php flash_show(); ?>

<?php require __DIR__ . '/ei-nav.php'; ?>

<!-- ── Overview stats ── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card text-center py-3 h-100" style="border-left:4px solid #0d6efd;">
            <div style="font-size:1.8rem;font-weight:700;color:#0d6efd;"><?= $active_exam_count ?></div>
            <div class="text-muted" style="font-size:.8rem;">Active Exams</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center py-3 h-100" style="border-left:4px solid <?= $vacant_seat_count > 0 ? '#dc3545' : '#198754' ?>;">
            <div style="font-size:1.8rem;font-weight:700;color:<?= $vacant_seat_count > 0 ? '#dc3545' : '#198754' ?>;"><?= $vacant_seat_count ?></div>
            <div class="text-muted" style="font-size:.8rem;">Vacant Invigilator Seats</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center py-3 h-100" style="border-left:4px solid #27ae60;">
            <div style="font-size:1.8rem;font-weight:700;color:#27ae60;"><?= $total_active_faculty ?></div>
            <div class="text-muted" style="font-size:.8rem;">Active Faculty</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center py-3 h-100" style="border-left:4px solid #4f8ef7;">
            <div style="font-size:1.8rem;font-weight:700;color:#4f8ef7;"><?= $available_backup_faculty ?></div>
            <div class="text-muted" style="font-size:.8rem;">Available for Backup</div>
        </div>
    </div>
</div>

<?php if ($vacant_seat_count > 0): ?>
<div class="alert alert-warning py-2 px-3 mb-4 d-flex align-items-center gap-2" style="font-size:.875rem;">
    <i class="fas fa-exclamation-triangle"></i>
    <span><strong><?= $vacant_seat_count ?></strong> invigilator seat<?= $vacant_seat_count === 1 ? ' is' : 's are' ?> still vacant in active exams.</span>
    <a href="<?= APP_URL ?>/exam-invigilation/reports.php#vacant-slots" class="ms-auto btn btn-sm btn-outline-warning" style="border-radius:8px;white-space:nowrap;">
        <i class="fas fa-search me-1"></i> Find Eligible Teachers
    </a>
</div>
<?php endif; ?>

<!-- Search -->
<div class="card mb-3">
    <div class="card-body py-2 px-3">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-12 col-md-6">
                <input type="text" name="q" class="form-control form-control-sm" style="border-radius:8px;"
                       placeholder="Search exam name or year…" value="<?= h($search) ?>">
            </div>
            <div class="col-auto d-flex gap-2">
                <button class="btn btn-sm btn-primary" style="border-radius:8px;">Filter</button>
                <a href="?" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-user-check me-2 text-muted"></i>Exams</h6>
        <span class="badge bg-primary bg-opacity-10 text-primary"><?= $total ?> total</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Exam Name</th>
                        <th>Year</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Slots</th>
                        <th>Assigned</th>
                        <th>Active</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No exams found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $i => $e): ?>
                    <tr>
                        <td class="px-4"><?= $offset + $i + 1 ?></td>
                        <td>
                            <a href="<?= APP_URL ?>/exam-invigilation/view.php?id=<?= $e['id'] ?>" class="fw-medium text-decoration-none">
                                <?= h($e['exam_name']) ?>
                            </a>
                        </td>
                        <td><?= h($e['exam_year']) ?></td>
                        <td><?= $e['start_date'] ? date('d M Y', strtotime($e['start_date'])) : '<span class="text-muted">—</span>' ?></td>
                        <td><?= $e['end_date']   ? date('d M Y', strtotime($e['end_date']))   : '<span class="text-muted">—</span>' ?></td>
                        <td>
                            <span class="badge bg-secondary bg-opacity-15 text-secondary"><?= $e['slot_count'] ?> slots</span>
                        </td>
                        <td>
                            <?php if ($e['slot_count'] > 0): ?>
                            <?php $pct = round($e['assigned_count'] / $e['slot_count'] * 100); ?>
                            <div class="d-flex align-items-center gap-2">
                                <div class="progress flex-grow-1" style="height:6px;min-width:60px;">
                                    <div class="progress-bar <?= $pct === 100 ? 'bg-success' : 'bg-warning' ?>"
                                         style="width:<?= $pct ?>%"></div>
                                </div>
                                <small class="text-muted"><?= $e['assigned_count'] ?>/<?= $e['slot_count'] ?></small>
                            </div>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="_action" value="toggle">
                                <input type="hidden" name="id" value="<?= $e['id'] ?>">
                                <button class="btn btn-sm <?= $e['is_active'] ? 'btn-success' : 'btn-secondary' ?>"
                                        style="border-radius:6px;font-size:.75rem;padding:2px 8px;">
                                    <?= $e['is_active'] ? 'On' : 'Off' ?>
                                </button>
                            </form>
                        </td>
                        <td class="text-end pe-4">
                            <div class="d-flex gap-1 justify-content-end">
                                <a href="<?= APP_URL ?>/exam-invigilation/view.php?id=<?= $e['id'] ?>"
                                   class="btn btn-sm btn-outline-secondary" style="border-radius:7px;" title="View">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="<?= APP_URL ?>/exam-invigilation/attendance.php?id=<?= $e['id'] ?>"
                                   class="btn btn-sm btn-outline-info" style="border-radius:7px;" title="Attendance">
                                    <i class="fas fa-calendar-check"></i>
                                </a>
                                <a href="<?= APP_URL ?>/exam-invigilation/remuneration-bill.php?id=<?= $e['id'] ?>"
                                   class="btn btn-sm btn-outline-success" style="border-radius:7px;" title="Remuneration Bill">
                                    <i class="fas fa-file-invoice-dollar"></i>
                                </a>
                                <?php if (is_super_admin() || can_access('exam-invigilation', 'can_edit')): ?>
                                <a href="<?= APP_URL ?>/exam-invigilation/edit.php?id=<?= $e['id'] ?>"
                                   class="btn btn-sm btn-outline-primary" style="border-radius:7px;" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php endif; ?>
                                <?php if (is_super_admin() || can_access('exam-invigilation', 'can_delete')): ?>
                                <form method="POST" style="display:inline;"
                                      onsubmit="return confirm('Delete exam &quot;<?= h(addslashes($e['exam_name'])) ?>&quot;? All slots and assignments will be removed.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="delete">
                                    <input type="hidden" name="id" value="<?= $e['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" style="border-radius:7px;" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
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
    <?php if ($pages > 1): ?>
    <div class="card-footer d-flex justify-content-between align-items-center py-2 px-4">
        <small class="text-muted">Showing <?= $offset+1 ?>–<?= min($offset+$per,$total) ?> of <?= $total ?></small>
        <nav><ul class="pagination pagination-sm mb-0">
            <?php for ($p = 1; $p <= $pages; $p++): ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                <a class="page-link" href="?q=<?= urlencode($search) ?>&page=<?= $p ?>"><?= $p ?></a>
            </li>
            <?php endfor; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<?php if (is_super_admin() || can_access('exam-invigilation', 'can_edit')): ?>
<!-- ── Auto-assign settings modal ── -->
<div class="modal fade" id="eiSettingsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="save_settings">
            <div class="modal-header">
                <h6 class="modal-title fw-semibold"><i class="fas fa-sliders-h me-2 text-primary"></i>Auto-Assign Slot Caps</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted" style="font-size:.85rem;">
                    Each teacher can receive up to <strong><?= $auto_assign_max_slots ?></strong> slots total
                    and up to <strong><?= $auto_assign_max_slots_per_day ?></strong> slot<?= $auto_assign_max_slots_per_day === 1 ? '' : 's' ?> per day during auto-assign.
                </p>
                <div class="mb-3">
                    <label for="autoAssignMaxSlots" class="form-label small text-muted mb-1">Max slots (total)</label>
                    <input type="number" min="1" max="100" name="auto_assign_max_slots" id="autoAssignMaxSlots"
                           value="<?= $auto_assign_max_slots ?>" class="form-control form-control-sm">
                </div>
                <div>
                    <label for="autoAssignMaxSlotsPerDay" class="form-label small text-muted mb-1">Max slots per day</label>
                    <input type="number" min="1" max="50" name="auto_assign_max_slots_per_day" id="autoAssignMaxSlotsPerDay"
                           value="<?= $auto_assign_max_slots_per_day ?>" class="form-control form-control-sm">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-sm btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
