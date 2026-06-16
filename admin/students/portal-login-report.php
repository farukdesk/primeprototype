<?php
/**
 * Student Portal – Login Report
 *
 * Shows which students logged in on a given date, grouped by department.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('students');
require_once __DIR__ . '/helpers.php';

$page_title = 'Student Portal Login Report';

// Date filter – default today
$f_date      = trim($_GET['date']   ?? date('Y-m-d'));
$f_dept      = (int)($_GET['dept']  ?? 0);
$dept_scope  = get_dept_scope();

// Validate date
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_date)) {
    $f_date = date('Y-m-d');
}

// ── Build query ──────────────────────────────────────────────────────────────
$where  = ["DATE(u.last_login) = ?"];
$params = [$f_date];

if ($f_dept > 0) {
    $where[]  = 's.dept_id = ?';
    $params[] = $f_dept;
}
if ($dept_scope !== null) {
    if (empty($dept_scope)) {
        $where[] = '0 = 1';
    } else {
        $phs     = implode(',', array_fill(0, count($dept_scope), '?'));
        $where[] = "s.dept_id IN ($phs)";
        array_push($params, ...$dept_scope);
    }
}

$where_sql = ' WHERE ' . implode(' AND ', $where);

$stmt = db()->prepare(
    'SELECT s.student_id, s.full_name, s.phone, s.email,
            d.name AS dept_name,
            p.program_name,
            s.status,
            u.last_login
     FROM students s
     JOIN users u       ON u.id = s.portal_user_id
     JOIN dept_departments d ON d.id = s.dept_id
     LEFT JOIN dept_academic_programs p ON p.id = s.program_id'
    . $where_sql
    . ' ORDER BY d.name ASC, u.last_login DESC'
);
$stmt->execute($params);
$logins = $stmt->fetchAll();

// Summary counts
$total = count($logins);

// Group by department
$by_dept = [];
foreach ($logins as $row) {
    $by_dept[$row['dept_name']][] = $row;
}

// ── Departments for filter dropdown ──────────────────────────────────────────
$departments = db()->query(
    'SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC'
)->fetchAll();
if ($dept_scope !== null) {
    $departments = array_values(array_filter(
        $departments,
        fn($d) => in_array((int)$d['id'], $dept_scope, true)
    ));
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/students/index.php">Students</a></li>
            <li class="breadcrumb-item active">Login Report</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/students/portal-bulk-create.php" class="btn btn-outline-primary btn-sm" style="border-radius:10px;">
        <i class="fas fa-user-plus me-1"></i> Bulk Create Accounts
    </a>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body py-3 px-4">
        <form method="GET" action="" class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Date</label>
                <input type="date" name="date" class="form-control form-control-sm"
                       value="<?= h($f_date) ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Department</label>
                <select name="dept" class="form-select form-select-sm">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $f_dept == $d['id'] ? 'selected' : '' ?>>
                        <?= h($d['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-fill" style="border-radius:7px;">
                    <i class="fas fa-search me-1"></i> Filter
                </button>
                <a href="<?= APP_URL ?>/students/portal-login-report.php" class="btn btn-outline-secondary btn-sm flex-fill" style="border-radius:7px;">
                    Reset
                </a>
            </div>
            <div class="col-auto ms-auto">
                <button type="button" class="btn btn-outline-success btn-sm" style="border-radius:7px;"
                        onclick="window.print()">
                    <i class="fas fa-print me-1"></i> Print
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Summary -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#4f8ef7,#3a6fd8);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= $total ?></div>
                    <div class="stat-label">Total Logins</div>
                </div>
                <div class="stat-icon"><i class="fas fa-sign-in-alt"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#28a745,#1d7a34);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= count($by_dept) ?></div>
                    <div class="stat-label">Departments</div>
                </div>
                <div class="stat-icon"><i class="fas fa-building"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#17a2b8,#117a8b);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= h($f_date === date('Y-m-d') ? 'Today' : $f_date) ?></div>
                    <div class="stat-label">Report Date</div>
                </div>
                <div class="stat-icon"><i class="fas fa-calendar-day"></i></div>
            </div>
        </div>
    </div>
</div>

<?php if ($total === 0): ?>
<div class="card">
    <div class="card-body text-center py-5 text-muted">
        <i class="fas fa-user-slash fa-2x mb-2"></i>
        <p class="mb-0">No student logins found for <?= h($f_date) ?>.</p>
    </div>
</div>

<?php else: ?>

<!-- Department summary table -->
<div class="card mb-4">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-chart-bar me-2 text-muted"></i>Summary by Department</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4">#</th>
                        <th>Department</th>
                        <th class="text-end pe-4">Logins</th>
                    </tr>
                </thead>
                <tbody>
                <?php $n = 1; foreach ($by_dept as $dept_name => $rows): ?>
                <tr>
                    <td class="px-4"><?= $n++ ?></td>
                    <td><?= h($dept_name) ?></td>
                    <td class="text-end pe-4">
                        <span class="badge bg-primary"><?= count($rows) ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <td colspan="2" class="px-4 fw-semibold">Total</td>
                        <td class="text-end pe-4 fw-semibold"><?= $total ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- Detailed table per department -->
<?php foreach ($by_dept as $dept_name => $rows): ?>
<div class="card mb-4">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold">
            <i class="fas fa-building me-2 text-muted"></i><?= h($dept_name) ?>
        </h6>
        <span class="badge bg-primary bg-opacity-10 text-primary"><?= count($rows) ?> login<?= count($rows) !== 1 ? 's' : '' ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Student ID</th>
                        <th>Name</th>
                        <th>Program</th>
                        <th>Contact</th>
                        <th>Status</th>
                        <th>Last Login</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $i => $row): ?>
                <tr>
                    <td class="px-4"><?= $i + 1 ?></td>
                    <td><code class="text-primary"><?= h($row['student_id']) ?></code></td>
                    <td class="fw-medium"><?= h($row['full_name']) ?></td>
                    <td><?= $row['program_name'] ? h($row['program_name']) : '<span class="text-muted">—</span>' ?></td>
                    <td>
                        <?php if ($row['phone']): ?>
                        <div><i class="fas fa-phone fa-xs text-muted me-1"></i><?= h($row['phone']) ?></div>
                        <?php endif; ?>
                        <?php if ($row['email']): ?>
                        <div><i class="fas fa-envelope fa-xs text-muted me-1"></i><small><?= h($row['email']) ?></small></div>
                        <?php endif; ?>
                    </td>
                    <td><?= sm_status_badge($row['status']) ?></td>
                    <td><small><?= date('M d, Y H:i', strtotime($row['last_login'])) ?></small></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
