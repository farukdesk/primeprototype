<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/sp-helpers.php';

// Non-admin staff go to their own profile
if (!is_super_admin() && !sp_is_admin()) {
    if (can_access('staff-profile', 'can_view')) {
        redirect(APP_URL . '/staff-profiles/my-profile.php');
    }
    require_access('staff-profile', 'can_view');
}

$page_title = 'Staff Profiles';

// ── Filters ───────────────────────────────────────────────────────────────────
$search   = trim($_GET['search'] ?? '');
$f_type   = $_GET['dept_type'] ?? '';
$f_dept   = (int)($_GET['dept'] ?? 0);
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;

// Only show users whose primary group has staff-profile module access
$where  = [
    "u.is_active = 1",
    "EXISTS (SELECT 1 FROM group_module_access gma JOIN modules mm ON mm.id = gma.module_id AND mm.slug = 'staff-profile' WHERE gma.group_id = u.group_id AND gma.can_view = 1)",
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

$where_sql = ' WHERE ' . implode(' AND ', $where);

$base_sql = 'FROM users u
     JOIN user_groups ug ON ug.id = u.group_id
     LEFT JOIN staff_profiles sp ON sp.user_id = u.id
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

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Staff Profiles</li>
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
                    <option value="">All Department Types</option>
                    <option value="administrative" <?= $f_type === 'administrative' ? 'selected' : '' ?>>Administrative</option>
                    <option value="educational"    <?= $f_type === 'educational'    ? 'selected' : '' ?>>Educational</option>
                </select>
            </div>
            <div class="col-md-3">
                <select name="dept" class="form-select" style="border-radius:10px;">
                    <option value="0">All Departments</option>
                    <?php foreach ($all_depts as $d): ?>
                    <option value="<?= (int)$d['id'] ?>" <?= $f_dept === (int)$d['id'] ? 'selected' : '' ?>>
                        <?= h($d['name']) ?> (<?= ucfirst($d['type']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary w-100" style="border-radius:10px;">
                    <i class="fas fa-search"></i>
                </button>
                <a href="<?= APP_URL ?>/staff-profiles/index.php" class="btn btn-secondary" style="border-radius:10px;">
                    <i class="fas fa-times"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-id-badge me-2 text-muted"></i>Staff Profiles
            <span class="badge bg-secondary ms-1"><?= $total_rows ?></span>
        </h6>
    </div>
    <div class="card-body p-0">
        <?php if (empty($rows)): ?>
        <p class="text-muted p-4 mb-0">No staff profiles found.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Photo</th>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Employee ID</th>
                        <th>Department</th>
                        <th>Designation</th>
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
                    <td><?= h($row['full_name']) ?></td>
                    <td><code><?= h($row['username']) ?></code></td>
                    <td><?= h($row['email']) ?></td>
                    <td><?= h($row['employee_id'] ?? '—') ?></td>
                    <td>
                        <?php if ($row['dept_name']): ?>
                            <?= sp_dept_type_badge($row['department_type'] ?? '') ?>
                            <?= h($row['dept_name']) ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= h($row['designation'] ?? '—') ?></td>
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
                       href="?page=<?= $p ?>&search=<?= urlencode($search) ?>&dept_type=<?= urlencode($f_type) ?>&dept=<?= $f_dept ?>">
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
