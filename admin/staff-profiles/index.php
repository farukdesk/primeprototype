<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/sp-helpers.php';

sp_ensure_view_module();

// Non-admin staff go to their own profile, except read-only Employee Profile
// View holders who may browse the full employee list.
if (!is_super_admin() && !sp_is_admin() && !can_access('staff-profile-view', 'can_view')) {
    if (can_access('staff-profile', 'can_view')) {
        redirect(APP_URL . '/staff-profiles/my-profile.php');
    }
    require_access('staff-profile-view', 'can_view');
}

$page_title = 'Employee Profiles';

sp_ensure_hr_columns();

// ── Filters ───────────────────────────────────────────────────────────────────
$search      = trim($_GET['search'] ?? '');
$f_type      = $_GET['dept_type'] ?? '';
$f_dept      = (int)($_GET['dept'] ?? 0);
$f_edu_dept  = (int)($_GET['edu_dept_id'] ?? 0);
$f_desig     = trim($_GET['designation'] ?? '');
$f_last_des  = trim($_GET['last_designation'] ?? '');
$f_job_type  = trim($_GET['job_type'] ?? '');
$f_status    = trim($_GET['status'] ?? '');
$f_join_from = trim($_GET['join_from'] ?? '');
$f_join_to   = trim($_GET['join_to'] ?? '');
$f_prom_from = trim($_GET['promo_from'] ?? '');
$f_prom_to   = trim($_GET['promo_to'] ?? '');
$f_sal_min   = trim($_GET['salary_min'] ?? '');
$f_sal_max   = trim($_GET['salary_max'] ?? '');
$page        = max(1, (int)($_GET['page'] ?? 1));
$per_page    = 20;

// Normalise date / number inputs (ignore garbage values).
$valid_date = static fn(string $d): string =>
    preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : '';
$f_join_from = $valid_date($f_join_from);
$f_join_to   = $valid_date($f_join_to);
$f_prom_from = $valid_date($f_prom_from);
$f_prom_to   = $valid_date($f_prom_to);
if ($f_sal_min !== '' && !is_numeric($f_sal_min)) $f_sal_min = '';
if ($f_sal_max !== '' && !is_numeric($f_sal_max)) $f_sal_max = '';

// Only show users who have a registered employee profile (staff_profiles row
// with an employee type set). This lists every registered staff / employee /
// faculty member from the database.
$where  = [
    "sp.department_type IS NOT NULL",
];
$params = [];

if ($search !== '') {
    $like     = '%' . $search . '%';
    $where[]  = '(u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR sp.employee_id LIKE ?)';
    $params   = array_merge($params, [$like, $like, $like, $like]);
}
if (in_array($f_type, ['administrative', 'educational'], true)) {
    $where[]  = 'sp.department_type = ?';
    $params[] = $f_type;
}
if ($f_dept > 0) {
    $where[]  = 'sp.staff_dept_id = ?';
    $params[] = $f_dept;
}
if ($f_edu_dept > 0) {
    $where[]  = 'sp.department_type = \'educational\' AND sd.dept_id = ?';
    $params[] = $f_edu_dept;
}
if ($f_desig !== '') {
    $where[]  = 'sp.designation = ?';
    $params[] = $f_desig;
}
if ($f_last_des !== '') {
    $where[]  = 'sp.last_designation = ?';
    $params[] = $f_last_des;
}
if ($f_job_type !== '' && in_array($f_job_type, SP_JOB_TYPES, true)) {
    $where[]  = 'sp.job_type = ?';
    $params[] = $f_job_type;
}
if ($f_status !== '' && in_array($f_status, SP_EMPLOYEE_STATUSES, true)) {
    $where[]  = 'sp.employee_status = ?';
    $params[] = $f_status;
}
if ($f_join_from !== '') { $where[] = 'sp.joining_date >= ?'; $params[] = $f_join_from; }
if ($f_join_to   !== '') { $where[] = 'sp.joining_date <= ?'; $params[] = $f_join_to; }
if ($f_prom_from !== '') { $where[] = 'sp.last_promotion_date >= ?'; $params[] = $f_prom_from; }
if ($f_prom_to   !== '') { $where[] = 'sp.last_promotion_date <= ?'; $params[] = $f_prom_to; }
if ($f_sal_min   !== '') { $where[] = 'sp.salary >= ?'; $params[] = $f_sal_min; }
if ($f_sal_max   !== '') { $where[] = 'sp.salary <= ?'; $params[] = $f_sal_max; }

$where_sql = ' WHERE ' . implode(' AND ', $where);

$base_sql = 'FROM users u
     LEFT JOIN user_groups ug ON ug.id = u.group_id
     INNER JOIN staff_profiles sp ON sp.user_id = u.id
     LEFT JOIN staff_departments sd ON sd.id = sp.staff_dept_id';

$count_stmt = db()->prepare('SELECT COUNT(*) ' . $base_sql . $where_sql);
$count_stmt->execute($params);
$total_rows  = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$rows_stmt = db()->prepare(
    'SELECT u.id, u.full_name, u.username, u.email, u.phone,
            sp.photo, sp.employee_id, sp.department_type, sp.designation,
            sp.job_type, sp.employee_status, sp.joining_date,
            sp.last_promotion_date, sp.last_designation, sp.salary,
            sd.name AS dept_name
     ' . $base_sql . $where_sql .
    ' ORDER BY u.full_name ASC LIMIT ' . $per_page . ' OFFSET ' . $offset
);
$rows_stmt->execute($params);
$rows = $rows_stmt->fetchAll();

// Department list for filter dropdown
$all_depts = db()->query(
    "SELECT id, name, type FROM staff_departments WHERE is_active = 1 ORDER BY type ASC, sort_order ASC, name ASC"
)->fetchAll();

// Dropdown option lists built from the data on record.
$all_designations      = sp_distinct_values('designation');
$all_last_designations = sp_distinct_values('last_designation');

// True when any of the advanced filters is active (keeps the panel open).
$adv_active = $f_desig !== '' || $f_last_des !== '' || $f_job_type !== '' || $f_status !== ''
    || $f_join_from !== '' || $f_join_to !== '' || $f_prom_from !== '' || $f_prom_to !== ''
    || $f_sal_min !== '' || $f_sal_max !== '';

// Query string (minus page) reused by the pagination links.
$qs_params = $_GET;
unset($qs_params['page']);
$qs_base = http_build_query($qs_params);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Employee Profiles</li>
        </ol>
    </nav>
    <?php if (sp_can_manage_depts()): ?>
    <a href="<?= APP_URL ?>/staff-profiles/departments.php" class="btn btn-outline-secondary btn-sm" style="border-radius:8px;">
        <i class="fas fa-sitemap me-1"></i> Manage Departments
    </a>
    <?php endif; ?>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body py-3 px-4">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <input type="text" name="search" class="form-control" style="border-radius:10px;"
                       placeholder="Search name, username, email, employee ID…"
                       value="<?= h($search) ?>">
            </div>
            <div class="col-md-3">
                <select name="dept_type" class="form-select" style="border-radius:10px;">
                    <option value="">All Employee Types</option>
                    <option value="educational"    <?= $f_type === 'educational'    ? 'selected' : '' ?>>Academic (Faculty)</option>
                    <option value="administrative" <?= $f_type === 'administrative' ? 'selected' : '' ?>>Non-Academic (Administrative)</option>
                </select>
            </div>
            <div class="col-md-3">
                <select name="dept" class="form-select" style="border-radius:10px;">
                    <option value="0">All Departments</option>
                    <?php foreach ($all_depts as $d): ?>
                    <option value="<?= (int)$d['id'] ?>" <?= $f_dept === (int)$d['id'] ? 'selected' : '' ?>>
                        <?= h($d['name']) ?> (<?= h(sp_employee_type_label($d['type'])) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary w-100" style="border-radius:10px;">
                    <i class="fas fa-search"></i>
                </button>
                <a href="<?= APP_URL ?>/staff-profiles/index.php" class="btn btn-secondary" style="border-radius:10px;" title="Reset filters">
                    <i class="fas fa-times"></i>
                </a>
                <button type="button" class="btn btn-outline-secondary" style="border-radius:10px;"
                        title="More filters" data-bs-toggle="collapse" data-bs-target="#sp-adv-filters"
                        aria-expanded="<?= $adv_active ? 'true' : 'false' ?>">
                    <i class="fas fa-sliders-h"></i>
                </button>
            </div>

            <!-- Advanced filters -->
            <div class="collapse <?= $adv_active ? 'show' : '' ?> col-12" id="sp-adv-filters">
                <div class="row g-2 pt-2 mt-1 border-top">
                    <div class="col-md-3">
                        <label class="form-label small text-muted mb-1">Designation</label>
                        <select name="designation" class="form-select" style="border-radius:10px;">
                            <option value="">All Designations</option>
                            <?php foreach ($all_designations as $des): ?>
                            <option value="<?= h($des) ?>" <?= $f_desig === $des ? 'selected' : '' ?>><?= h($des) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted mb-1">Last Designation</label>
                        <select name="last_designation" class="form-select" style="border-radius:10px;">
                            <option value="">All</option>
                            <?php foreach ($all_last_designations as $des): ?>
                            <option value="<?= h($des) ?>" <?= $f_last_des === $des ? 'selected' : '' ?>><?= h($des) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted mb-1">Job Type</label>
                        <select name="job_type" class="form-select" style="border-radius:10px;">
                            <option value="">All Job Types</option>
                            <?php foreach (SP_JOB_TYPES as $jt): ?>
                            <option value="<?= h($jt) ?>" <?= $f_job_type === $jt ? 'selected' : '' ?>><?= h($jt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted mb-1">Current Status</label>
                        <select name="status" class="form-select" style="border-radius:10px;">
                            <option value="">All Statuses</option>
                            <?php foreach (SP_EMPLOYEE_STATUSES as $st): ?>
                            <option value="<?= h($st) ?>" <?= $f_status === $st ? 'selected' : '' ?>><?= h($st) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted mb-1">Joining Date (from – to)</label>
                        <div class="input-group">
                            <input type="date" name="join_from" class="form-control" value="<?= h($f_join_from) ?>">
                            <input type="date" name="join_to"   class="form-control" value="<?= h($f_join_to) ?>">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted mb-1">Last Promotion (from – to)</label>
                        <div class="input-group">
                            <input type="date" name="promo_from" class="form-control" value="<?= h($f_prom_from) ?>">
                            <input type="date" name="promo_to"   class="form-control" value="<?= h($f_prom_to) ?>">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted mb-1">Salary (min – max)</label>
                        <div class="input-group">
                            <input type="number" name="salary_min" class="form-control" min="0" step="any"
                                   placeholder="Min" value="<?= h($f_sal_min) ?>">
                            <input type="number" name="salary_max" class="form-control" min="0" step="any"
                                   placeholder="Max" value="<?= h($f_sal_max) ?>">
                        </div>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100" style="border-radius:10px;">
                            <i class="fas fa-filter me-1"></i> Apply Filters
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-id-badge me-2 text-muted"></i>Employee Profiles
            <span class="badge bg-secondary ms-1"><?= $total_rows ?></span>
        </h6>
    </div>
    <div class="card-body p-0">
        <?php if (empty($rows)): ?>
        <p class="text-muted p-4 mb-0">No employee profiles found.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Photo</th>
                        <th>Name</th>
                        <th>Employee ID</th>
                        <th>Employee Type</th>
                        <th>Department</th>
                        <th>Designation</th>
                        <th>Job Type</th>
                        <th>Joining Date</th>
                        <th>Last Promotion</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                <tr>
                    <td>
                        <?php if (!empty($row['photo'])): ?>
                        <img src="<?= UPLOAD_URL ?>/staff-profiles/<?= h($row['photo']) ?>"
                             alt="" style="height:38px;width:38px;border-radius:50%;object-fit:cover;">
                        <?php else: ?>
                        <div style="height:38px;width:38px;border-radius:50%;background:#e9ecef;display:inline-flex;align-items:center;justify-content:center;color:#adb5bd;">
                            <i class="fas fa-user fa-sm"></i>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= h($row['full_name']) ?>
                        <div class="text-muted small"><code><?= h($row['username']) ?></code></div>
                    </td>
                    <td><?= h($row['employee_id'] ?? '—') ?></td>
                    <td>
                        <?php if (!empty($row['department_type'])): ?>
                            <?= sp_dept_type_badge($row['department_type']) ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($row['dept_name']): ?>
                            <?= h($row['dept_name']) ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= h($row['designation'] ?? '—') ?></td>
                    <td><?= h($row['job_type'] ?? '—') ?></td>
                    <td class="text-nowrap"><?= sp_fmt_date($row['joining_date'] ?? null) ?></td>
                    <td class="text-nowrap">
                        <?= sp_fmt_date($row['last_promotion_date'] ?? null) ?>
                        <?php if (!empty($row['last_designation'])): ?>
                        <div class="text-muted small">from <?= h($row['last_designation']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= sp_status_badge($row['employee_status'] ?? null) ?></td>
                    <td class="text-end">
                        <a href="<?= APP_URL ?>/staff-profiles/view.php?id=<?= (int)$row['id'] ?>"
                           class="btn btn-sm btn-outline-primary" style="border-radius:8px;" title="View profile">
                            <i class="fas fa-eye"></i>
                        </a>
                        <a href="<?= APP_URL ?>/staff-profiles/cv-pdf.php?id=<?= (int)$row['id'] ?>"
                           class="btn btn-sm btn-outline-danger" style="border-radius:8px;" title="Download CV (PDF)">
                            <i class="fas fa-file-pdf"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($total_pages > 1): ?>
    <div class="card-footer py-3 px-4">
        <nav>
            <ul class="pagination pagination-sm mb-0 justify-content-end">
                <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <a class="page-link" style="border-radius:6px;"
                       href="?page=<?= $p ?><?= $qs_base !== '' ? '&' . h($qs_base) : '' ?>">
                        <?= $p ?>
                    </a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
