<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('exam-invigilation');

$page_title = 'Faculty Availability Pool';
$weekday_labels = [
    0 => 'Sun',
    1 => 'Mon',
    2 => 'Tue',
    3 => 'Wed',
    4 => 'Thu',
    5 => 'Fri',
    6 => 'Sat',
];

// ── Inline actions ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    csrf_check();
    $fid = (int)($_POST['id'] ?? 0);

    if ($_POST['_action'] === 'toggle') {
        db()->prepare('UPDATE ei_faculty SET is_active = 1 - is_active WHERE id = ?')->execute([$fid]);
        flash_set('success', 'Status updated.');
    } elseif ($_POST['_action'] === 'delete') {
        require_access('exam-invigilation', 'can_delete');
        db()->prepare('DELETE FROM ei_faculty WHERE id = ?')->execute([$fid]);
        flash_set('success', 'Faculty removed.');
    }
    redirect(APP_URL . '/exam-invigilation/faculty.php?' . http_build_query(array_intersect_key($_GET, array_flip(['dept','q','active','designation','page']))));
}

// ── Filters ───────────────────────────────────────────────────────────────────
$f_dept = (int)($_GET['dept'] ?? 0);
$search = trim($_GET['q'] ?? '');
$f_active = isset($_GET['active']) ? (string)$_GET['active'] : '';
$f_designation = trim((string)($_GET['designation'] ?? ''));
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 25;

$where  = [];
$params = [];
if ($f_dept) {
    $where[]  = 'f.dept_id = ?';
    $params[] = $f_dept;
}
if ($search !== '') {
    $where[]  = '(f.name LIKE ? OR f.designation LIKE ? OR f.contact_number LIKE ?)';
    $s = '%' . $search . '%';
    $params = array_merge($params, [$s, $s, $s]);
}
if ($f_active === '1' || $f_active === '0') {
    $where[] = 'f.is_active = ?';
    $params[] = (int)$f_active;
}
if ($f_designation !== '') {
    $where[] = 'f.designation = ?';
    $params[] = $f_designation;
}

$sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$cnt_st = db()->prepare("SELECT COUNT(*) FROM ei_faculty f $sql_where");
$cnt_st->execute($params);
$total  = (int)$cnt_st->fetchColumn();

$pages  = max(1, (int)ceil($total / $per));
$page   = min($page, $pages);
$offset = ($page - 1) * $per;

$data_st = db()->prepare(
    "SELECT f.*, d.name AS dept_name
     FROM ei_faculty f
     JOIN dept_departments d ON d.id = f.dept_id
     $sql_where
     ORDER BY d.name ASC, f.name ASC
     LIMIT $per OFFSET $offset"
);
$data_st->execute($params);
$rows = $data_st->fetchAll();

$departments = db()->query('SELECT id, name FROM dept_departments WHERE is_active=1 ORDER BY name ASC')->fetchAll();
$designation_rows = db()->query("SELECT DISTINCT designation FROM ei_faculty WHERE designation IS NOT NULL AND designation <> '' ORDER BY designation ASC")->fetchAll();
$designations = array_map(static fn($r) => (string)$r['designation'], $designation_rows);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/exam-invigilation/index.php">Exam Invigilation</a></li>
            <li class="breadcrumb-item active">Faculty Pool</li>
        </ol>
    </nav>
    <?php if (is_super_admin() || can_access('exam-invigilation', 'can_create')): ?>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/exam-invigilation/faculty-import.php" class="btn btn-outline-primary btn-sm" style="border-radius:10px;">
            <i class="fas fa-file-csv me-1"></i> Bulk Import CSV
        </a>
        <a href="<?= APP_URL ?>/exam-invigilation/faculty-create.php" class="btn btn-primary btn-sm" style="border-radius:10px;">
            <i class="fas fa-plus me-1"></i> Add Faculty
        </a>
    </div>
    <?php endif; ?>
</div>

<?php flash_show(); ?>

<div class="card mb-3">
    <div class="card-body py-2 px-3">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-12 col-md-4">
                <input type="text" name="q" class="form-control form-control-sm" style="border-radius:8px;"
                       placeholder="Search name, designation, contact…" value="<?= h($search) ?>">
            </div>
            <div class="col-12 col-md-4">
                <select name="dept" class="form-select form-select-sm" style="border-radius:8px;">
                    <option value="0">All Departments</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $f_dept == $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <select name="active" class="form-select form-select-sm" style="border-radius:8px;">
                    <option value="">Active: All</option>
                    <option value="1" <?= $f_active === '1' ? 'selected' : '' ?>>On</option>
                    <option value="0" <?= $f_active === '0' ? 'selected' : '' ?>>Off</option>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <select name="designation" class="form-select form-select-sm" style="border-radius:8px;">
                    <option value="">All Designations</option>
                    <?php foreach ($designations as $designation): ?>
                    <option value="<?= h($designation) ?>" <?= $f_designation === $designation ? 'selected' : '' ?>><?= h($designation) ?></option>
                    <?php endforeach; ?>
                </select>
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
        <h6 class="mb-0 fw-semibold"><i class="fas fa-users me-2 text-muted"></i>Faculty Availability Pool</h6>
        <span class="badge bg-primary bg-opacity-10 text-primary"><?= $total ?> total</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Name</th>
                        <th>Department</th>
                        <th>Designation</th>
                        <th>Gender</th>
                        <th>Signature</th>
                        <th>Weekend</th>
                        <th>Contact</th>
                        <th>Active</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="10" class="text-center text-muted py-4">No faculty found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $i => $f): ?>
                    <?php
                    if (!empty($f['weekend_days'])) {
                        $weekend_days = array_values(array_filter(array_map('intval', explode(',', (string)$f['weekend_days'])), static fn ($d) => $d >= 0 && $d <= 6));
                    } else {
                        $weekend_days = ((int)$f['weekend_available'] === 1) ? [] : [0, 6];
                    }
                    ?>
                    <tr>
                        <td class="px-4"><?= $offset + $i + 1 ?></td>
                        <td class="fw-medium"><?= h($f['name']) ?></td>
                        <td><span class="badge bg-primary bg-opacity-10 text-primary"><?= h($f['dept_name']) ?></span></td>
                        <td><?= $f['designation'] ? h($f['designation']) : '<span class="text-muted">—</span>' ?></td>
                        <td>
                            <?php if (!empty($f['gender'])): ?>
                            <span class="badge" style="background:<?= $f['gender'] === 'Female' ? '#e83e8c' : '#0dcaf0' ?>;color:#fff;">
                                <?= h($f['gender']) ?>
                            </span>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($f['signature'])): ?>
                            <img src="<?= UPLOAD_URL ?>/exam-invigilation/signatures/<?= h($f['signature']) ?>"
                                 alt="Signature" style="max-height:36px;max-width:90px;border:1px solid #dee2e6;border-radius:4px;padding:2px;">
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($weekend_days)): ?>
                                <?php foreach ($weekend_days as $d): ?>
                                <span class="badge bg-secondary"><?= h($weekday_labels[$d] ?? (string)$d) ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                            <span class="text-muted">None</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $f['contact_number'] ? h($f['contact_number']) : '<span class="text-muted">—</span>' ?></td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="_action" value="toggle">
                                <input type="hidden" name="id" value="<?= $f['id'] ?>">
                                <button class="btn btn-sm <?= $f['is_active'] ? 'btn-success' : 'btn-secondary' ?>"
                                        style="border-radius:6px;font-size:.75rem;padding:2px 8px;">
                                    <?= $f['is_active'] ? 'On' : 'Off' ?>
                                </button>
                            </form>
                        </td>
                        <td class="text-end pe-4">
                            <div class="d-flex gap-1 justify-content-end">
                                <?php if (is_super_admin() || can_access('exam-invigilation', 'can_edit')): ?>
                                <a href="<?= APP_URL ?>/exam-invigilation/faculty-edit.php?id=<?= $f['id'] ?>&page=<?= $page ?>&dept=<?= $f_dept ?>&q=<?= urlencode($search) ?>&active=<?= urlencode($f_active) ?>&designation=<?= urlencode($f_designation) ?>"
                                   class="btn btn-sm btn-outline-primary" style="border-radius:7px;" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php endif; ?>
                                <?php if (is_super_admin() || can_access('exam-invigilation', 'can_delete')): ?>
                                <form method="POST" style="display:inline;"
                                      onsubmit="return confirm('Remove &quot;<?= h(addslashes($f['name'])) ?>&quot; from the pool?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="delete">
                                    <input type="hidden" name="id" value="<?= $f['id'] ?>">
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
                <a class="page-link" href="?dept=<?= $f_dept ?>&q=<?= urlencode($search) ?>&active=<?= urlencode($f_active) ?>&designation=<?= urlencode($f_designation) ?>&page=<?= $p ?>"><?= $p ?></a>
            </li>
            <?php endfor; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
