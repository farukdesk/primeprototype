<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('hall-management');
require_once __DIR__ . '/helpers.php';

$page_title = 'Hall Management';

$filter_dept = (int)($_GET['dept_id'] ?? 0);
$filter_date = trim((string)($_GET['exam_date'] ?? ''));
if ($filter_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date)) {
    $filter_date = '';
}
$date_given  = $filter_date !== '';
$filter_time = trim((string)($_GET['exam_time'] ?? ''));
if ($filter_time !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $filter_time)) {
    $filter_time = '';
}
hm_ensure_schedule_columns();
if ($filter_date === '') {
    // Default to the exam date with assignments nearest to today so the
    // Filled/Available counts reflect real data instead of showing 0.
    try {
        hm_ensure_assignments_table();
        $st = db()->prepare(
            'SELECT exam_date FROM hm_hall_assignments
              GROUP BY exam_date
              ORDER BY ABS(DATEDIFF(exam_date, CURDATE())) ASC, exam_date DESC
              LIMIT 1'
        );
        $st->execute();
        $filter_date = (string)($st->fetchColumn() ?: '');
    } catch (Throwable $e) {
        $filter_date = '';
    }
}
if ($filter_date === '') $filter_date = date('Y-m-d');
$departments = hm_departments();

$scope  = get_dept_scope();
$where  = [];
$params = [];
if ($filter_dept > 0) { $where[] = 'h.dept_id = ?'; $params[] = $filter_dept; }
if ($date_given)      { $where[] = 'h.exam_date = ?'; $params[] = $filter_date; }
if ($filter_time !== '') { $where[] = 'h.exam_time = ?'; $params[] = $filter_time; }
if ($scope !== null) {
    if (empty($scope)) {
        $where[] = '1 = 0';
    } else {
        $ph = implode(',', array_fill(0, count($scope), '?'));
        $where[] = "h.dept_id IN ($ph)";
        $params  = array_merge($params, $scope);
    }
}
$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$halls = [];
try {
    $st = db()->prepare(
        "SELECT h.*, d.name AS dept_name, u.full_name AS created_by_name
           FROM hm_halls h
           JOIN dept_departments d ON d.id = h.dept_id
      LEFT JOIN users u            ON u.id = h.created_by
          $where_sql
          ORDER BY d.name ASC, h.room_number ASC, h.exam_date ASC, h.exam_time ASC"
    );
    $st->execute($params);
    $halls = $st->fetchAll();
} catch (Throwable $e) {
    flash_set('error', 'Hall Management tables are missing. Please run admin/hall-management-schema.sql.');
}

// Seats filled per hall on the selected exam date
$filled = [];
try {
    hm_ensure_assignments_table();
    $st = db()->prepare(
        'SELECT hall_id, COUNT(*) AS cnt FROM hm_hall_assignments WHERE exam_date = ? GROUP BY hall_id'
    );
    $st->execute([$filter_date]);
    foreach ($st->fetchAll() as $r) $filled[(int)$r['hall_id']] = (int)$r['cnt'];
} catch (Throwable $e) {
    $filled = [];
}

$can_create = is_super_admin() || can_access('hall-management', 'can_create');
$can_edit   = is_super_admin() || can_access('hall-management', 'can_edit');
$can_delete = is_super_admin() || can_access('hall-management', 'can_delete');

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Hall Management</li>
        </ol>
    </nav>
    <?php if ($can_create): ?>
    <a href="<?= APP_URL ?>/hall-management/create.php" class="btn btn-primary" style="border-radius:10px;">
        <i class="fas fa-plus me-1"></i> New Hall / Room
    </a>
    <?php endif; ?>
</div>

<?php flash_show(); ?>

<div class="card mb-4" style="border-radius:12px;">
    <div class="card-body p-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label fw-medium mb-1">Department</label>
                <select name="dept_id" class="form-select form-select-sm">
                    <option value="">All departments</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $filter_dept === (int)$d['id'] ? 'selected' : '' ?>>
                        <?= h($d['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-medium mb-1">Exam Date <span class="text-muted fw-normal">(for seat counts)</span></label>
                <input type="date" name="exam_date" class="form-control form-control-sm" value="<?= h($filter_date) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label fw-medium mb-1">Exam Time</label>
                <input type="time" name="exam_time" class="form-control form-control-sm" value="<?= h($filter_time !== '' ? substr($filter_time, 0, 5) : '') ?>">
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button class="btn btn-sm btn-primary" style="border-radius:8px;"><i class="fas fa-filter me-1"></i> Filter</button>
                <a href="<?= APP_URL ?>/hall-management/index.php" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card" style="border-radius:12px;">
    <div class="card-body p-0">
        <?php if (empty($halls)): ?>
        <div class="text-center text-muted py-5">
            <i class="fas fa-door-open fa-2x mb-3 d-block"></i>
            No halls found.
            <?php if ($can_create): ?>
            <div class="mt-2"><a href="<?= APP_URL ?>/hall-management/create.php">Create the first hall / room</a></div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:50px;">#</th>
                        <th>Room Number</th>
                        <th>Department</th>
                        <th>Exam Date &amp; Time</th>
                        <th class="text-center">Columns</th>
                        <th class="text-center">Rows</th>
                        <th class="text-center">Total Seat Capacity</th>
                        <th class="text-center">Filled</th>
                        <th class="text-center">Available</th>
                        <th class="text-center">Status</th>
                        <th>Created By</th>
                        <th class="text-end" style="width:200px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($halls as $i => $hl): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td>
                            <a href="<?= APP_URL ?>/hall-management/view.php?id=<?= $hl['id'] ?>" class="fw-semibold text-decoration-none">
                                <i class="fas fa-door-open me-1 text-muted"></i><?= h($hl['room_number']) ?>
                            </a>
                        </td>
                        <td><?= h($hl['dept_name']) ?></td>
                        <td style="font-size:.85rem;">
                            <?php if (!empty($hl['exam_date'])): ?>
                            <i class="far fa-calendar-alt me-1 text-muted"></i><?= h(hm_slot_label($hl['exam_date'], $hl['exam_time'] ?? null)) ?>
                            <?php else: ?>
                            <span class="text-muted fst-italic">Auto — set after seat assignment</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><?= (int)$hl['num_columns'] ?></td>
                        <td class="text-center"><?= (int)$hl['num_rows'] ?></td>
                        <?php
                            $cap   = (int)$hl['total_capacity'];
                            $fill  = $filled[(int)$hl['id']] ?? 0;
                            $avail = max(0, $cap - $fill);
                        ?>
                        <td class="text-center"><span class="badge bg-primary"><?= $cap ?> seats</span></td>
                        <td class="text-center">
                            <span class="badge <?= $fill > 0 ? 'bg-warning text-dark' : 'bg-light text-muted border' ?>"><?= $fill ?> filled</span>
                        </td>
                        <td class="text-center">
                            <span class="badge <?= $avail > 0 ? 'bg-success' : 'bg-danger' ?>"><?= $avail ?> available</span>
                        </td>
                        <td class="text-center">
                            <?php if ((int)$hl['is_active'] === 1): ?>
                            <span class="badge bg-success">Active</span>
                            <?php else: ?>
                            <span class="badge bg-secondary">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted" style="font-size:.85rem;"><?= h($hl['created_by_name'] ?? '—') ?></td>
                        <td class="text-end">
                            <a href="<?= APP_URL ?>/hall-management/view.php?id=<?= $hl['id'] ?>"
                               class="btn btn-sm btn-outline-secondary" title="View seat layout"><i class="fas fa-eye"></i></a>
                            <?php if ($can_create): ?>
                            <button type="button" class="btn btn-sm btn-outline-success hm-copy-btn"
                                    title="Copy room with seated students to another exam date"
                                    data-bs-toggle="modal" data-bs-target="#copyHallModal"
                                    data-hall-id="<?= $hl['id'] ?>"
                                    data-room="<?= h($hl['room_number']) ?>"
                                    data-filled="<?= $fill ?>"
                                    data-exam-date="<?= h($hl['exam_date'] ?? '') ?>">
                                <i class="fas fa-copy"></i>
                            </button>
                            <?php endif; ?>
                            <?php if ($can_edit): ?>
                            <a href="<?= APP_URL ?>/hall-management/edit.php?id=<?= $hl['id'] ?>"
                               class="btn btn-sm btn-outline-primary" title="Edit"><i class="fas fa-edit"></i></a>
                            <?php endif; ?>
                            <?php if ($can_delete): ?>
                            <form method="POST" action="<?= APP_URL ?>/hall-management/delete.php" class="d-inline"
                                  onsubmit="return confirm('Delete hall <?= h($hl['room_number']) ?>? This cannot be undone.');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $hl['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($can_create): ?>
<!-- Copy room (with its seated students) to another exam date -->
<div class="modal fade" id="copyHallModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="<?= APP_URL ?>/hall-management/copy.php" class="modal-content" style="border-radius:12px;">
            <?= csrf_field() ?>
            <input type="hidden" name="id" id="copyHallId" value="">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-copy me-2 text-success"></i>Copy Room <span id="copyHallRoom"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted mb-3" style="font-size:.9rem;">
                    Creates a duplicate booking of this room — same layout and the
                    <strong><span id="copyHallFilled">0</span> seated student(s)</strong> on the same seats —
                    for a new exam date, so the same students can sit a different exam on another day.
                    Students already seated in another room on the new date are skipped automatically.
                </p>
                <div class="row g-2">
                    <div class="col-7">
                        <label class="form-label fw-medium">New Exam Date <span class="text-danger">*</span></label>
                        <input type="date" name="exam_date" id="copyHallDate" class="form-control" required>
                    </div>
                    <div class="col-5">
                        <label class="form-label fw-medium">Exam Time <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="time" name="exam_time" class="form-control">
                    </div>
                    <div class="form-text">Leave the time blank to derive it automatically from the students' admit-card schedule for the new date.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" style="border-radius:8px;" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success" style="border-radius:8px;"><i class="fas fa-copy me-1"></i> Copy Room</button>
            </div>
        </form>
    </div>
</div>

<script>
document.querySelectorAll('.hm-copy-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        document.getElementById('copyHallId').value = this.dataset.hallId;
        document.getElementById('copyHallRoom').textContent = this.dataset.room;
        document.getElementById('copyHallFilled').textContent = this.dataset.filled;
        var src = this.dataset.examDate;
        var d = src ? new Date(src + 'T00:00:00') : new Date();
        d.setDate(d.getDate() + 1);
        document.getElementById('copyHallDate').value = d.toISOString().slice(0, 10);
    });
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
