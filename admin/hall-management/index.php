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
          ORDER BY d.name ASC, h.room_number ASC"
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
            <div class="col-md-5">
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
                        <th class="text-center">Columns</th>
                        <th class="text-center">Rows</th>
                        <th class="text-center">Total Seat Capacity</th>
                        <th class="text-center">Filled</th>
                        <th class="text-center">Available</th>
                        <th class="text-center">Status</th>
                        <th>Created By</th>
                        <th class="text-end" style="width:160px;">Actions</th>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
