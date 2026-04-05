<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/fp-helpers.php';

// Non-super-admin: Register Office can see index; Faculty goes to my-profile
if (!is_super_admin()) {
    if (fp_is_register_office() && can_access('faculty-profile', 'can_view')) {
        // allowed to continue
    } elseif (can_access('faculty-profile', 'can_view')) {
        redirect(APP_URL . '/faculty-profiles/my-profile.php');
    } else {
        require_access('faculty-profile', 'can_view'); // triggers redirect/error
    }
}

$page_title = 'Faculty Profiles';

// ── Filters ───────────────────────────────────────────────────────────────────
$search   = trim($_GET['search'] ?? '');
$f_dept   = (int)($_GET['dept']  ?? 0);
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;

$where  = ["ug.name = 'Faculty'"];
$params = [];

if ($search !== '') {
    $like     = '%' . $search . '%';
    $where[]  = '(u.full_name LIKE ? OR u.email LIKE ? OR fp.phone LIKE ?)';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($f_dept > 0) {
    $where[]  = 'fp.dept_id = ?';
    $params[] = $f_dept;
}

$where_sql = ' WHERE ' . implode(' AND ', $where);

$base_sql = 'FROM users u
     JOIN user_groups ug ON ug.id = u.group_id
     LEFT JOIN faculty_profiles fp ON fp.user_id = u.id
     LEFT JOIN dept_departments d ON d.id = fp.dept_id';

$count_stmt = db()->prepare('SELECT COUNT(*)' . ' ' . $base_sql . $where_sql);
$count_stmt->execute($params);
$total_rows  = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$sql = 'SELECT u.id, u.full_name, u.email,
               fp.designation, fp.phone, d.name AS dept_name
        ' . $base_sql . $where_sql . '
        ORDER BY u.full_name ASC
        LIMIT ' . $per_page . ' OFFSET ' . $offset;

$stmt = db()->prepare($sql);
$stmt->execute($params);
$faculty = $stmt->fetchAll();

// ── Departments for filter dropdown ──────────────────────────────────────────
$departments = db()->query(
    'SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC'
)->fetchAll();

// Count pending registrations for badge
$pending_count = 0;
if (fp_can_manage_pending()) {
    $pcs = db()->query("SELECT COUNT(*) FROM faculty_registrations WHERE status = 'pending'");
    $pending_count = (int)($pcs ? $pcs->fetchColumn() : 0);
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Faculty Profiles</li>
        </ol>
    </nav>
    <?php if (fp_can_manage_pending()): ?>
    <a href="<?= APP_URL ?>/faculty-profiles/pending.php" class="btn btn-outline-warning" style="border-radius:10px;font-size:.875rem;">
        <i class="fas fa-user-clock me-1"></i>Pending Registrations
        <?php if ($pending_count > 0): ?>
        <span class="badge bg-danger ms-1"><?= $pending_count ?></span>
        <?php endif; ?>
    </a>
    <?php endif; ?>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body py-3 px-4">
        <form method="GET" action="" class="row g-2 align-items-end">
            <div class="col-12 col-md-5">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Search</label>
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="Name, email, phone…"
                       value="<?= h($search) ?>">
            </div>
            <div class="col-8 col-md-4">
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
            <div class="col-4 col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-fill" style="border-radius:7px;">
                    <i class="fas fa-search me-1"></i> Filter
                </button>
                <a href="<?= APP_URL ?>/faculty-profiles/index.php" class="btn btn-outline-secondary btn-sm flex-fill" style="border-radius:7px;">
                    Reset
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Table -->
<div class="card">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-id-card me-2 text-muted"></i>Faculty Profiles</h6>
        <span class="badge bg-primary bg-opacity-10 text-primary"><?= $total_rows ?> result<?= $total_rows !== 1 ? 's' : '' ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Designation</th>
                        <th>Department</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($faculty)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No faculty users found<?= ($search || $f_dept) ? ' matching your filters' : '. Add users to the <strong>Faculty</strong> group first.' ?>.</td></tr>
                <?php else: ?>
                    <?php foreach ($faculty as $i => $row): ?>
                    <tr>
                        <td class="px-4"><?= $offset + $i + 1 ?></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div style="width:34px;height:34px;border-radius:50%;background:#4f8ef7;color:#fff;
                                    display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0;">
                                    <?= strtoupper(substr($row['full_name'] ?? 'F', 0, 1)) ?>
                                </div>
                                <span class="fw-medium"><?= h($row['full_name']) ?></span>
                            </div>
                        </td>
                        <td><?= h($row['email']) ?></td>
                        <td><?= $row['phone'] ? h($row['phone']) : '<span class="text-muted">—</span>' ?></td>
                        <td><?= h($row['designation'] ?? '—') ?></td>
                        <td><?= $row['dept_name'] ? h($row['dept_name']) : '<span class="text-muted">—</span>' ?></td>
                        <td class="text-end pe-4">
                            <div class="d-flex gap-1 justify-content-end">
                            <a href="<?= APP_URL ?>/faculty-profiles/edit.php?user_id=<?= $row['id'] ?>"
                               class="btn btn-sm btn-outline-primary" style="border-radius:7px;" title="Edit Profile">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                            <a href="<?= APP_URL ?>/faculty-profiles/files.php?user_id=<?= $row['id'] ?>"
                               class="btn btn-sm btn-outline-secondary" style="border-radius:7px;" title="Files">
                                <i class="fas fa-folder-open"></i> Files
                            </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="card-footer py-3 px-4 d-flex justify-content-between align-items-center">
        <small class="text-muted">
            Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total_rows) ?> of <?= $total_rows ?>
        </small>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php
                $qp = $_GET;
                for ($p = 1; $p <= $total_pages; $p++):
                    $qp['page'] = $p;
                    $active = $p === $page;
                ?>
                <li class="page-item <?= $active ? 'active' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query($qp) ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
