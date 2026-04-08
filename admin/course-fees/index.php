<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('course-fees');

$page_title = 'Course Fees Calculator';

$db = db();

// ── Filters ───────────────────────────────────────────────────────────────────
$search      = trim($_GET['q']      ?? '');
$f_dept      = (int)($_GET['dept']  ?? 0);
$f_degree    = $_GET['degree']      ?? '';
$f_status    = $_GET['status']      ?? '';

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[]  = '(d.name LIKE ? OR ap.program_name LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($f_dept > 0) {
    $where[]  = 'p.dept_id = ?';
    $params[] = $f_dept;
}
if (in_array($f_degree, ['bachelor','master','diploma','certificate'], true)) {
    $where[]  = 'p.degree_type = ?';
    $params[] = $f_degree;
}
if ($f_status === 'active') {
    $where[] = 'p.is_active = 1';
} elseif ($f_status === 'inactive') {
    $where[] = 'p.is_active = 0';
}

$where_sql = implode(' AND ', $where);

// ── Pagination ────────────────────────────────────────────────────────────────
$per_page = 20;
$page     = max(1, (int)($_GET['page'] ?? 1));

$cnt_stmt = $db->prepare(
    "SELECT COUNT(*) FROM cf_programs p
     LEFT JOIN dept_departments d      ON d.id  = p.dept_id
     LEFT JOIN dept_academic_programs ap ON ap.id = p.program_id
     WHERE $where_sql"
);
$cnt_stmt->execute($params);
$total = (int)$cnt_stmt->fetchColumn();
$pages = max(1, (int)ceil($total / $per_page));
$page  = min($page, $pages);
$off   = ($page - 1) * $per_page;

$stmt = $db->prepare(
    "SELECT p.*,
            d.name           AS dept_name,
            ap.program_name,
            (SELECT COUNT(*) FROM cf_fixed_fees f WHERE f.cf_program_id = p.id) AS fee_count
     FROM cf_programs p
     LEFT JOIN dept_departments d      ON d.id  = p.dept_id
     LEFT JOIN dept_academic_programs ap ON ap.id = p.program_id
     WHERE $where_sql
     ORDER BY p.sort_order, d.name, ap.program_name, p.id
     LIMIT $per_page OFFSET $off"
);
$stmt->execute($params);
$programs = $stmt->fetchAll();

// ── Stats ─────────────────────────────────────────────────────────────────────
$stats = $db->query(
    "SELECT COUNT(*) AS total,
            SUM(is_active=1) AS active_count,
            SUM(is_active=0) AS inactive_count
     FROM cf_programs"
)->fetch();

// ── Filter data ───────────────────────────────────────────────────────────────
$depts = $db->query('SELECT id, name FROM dept_departments ORDER BY name')->fetchAll();

$settings = cf_get_settings();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-calculator me-2 text-warning"></i>Course Fees Calculator</h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Course Fees</li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (is_super_admin()): ?>
        <a href="<?= APP_URL ?>/course-fees/settings.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-cog me-1"></i> Settings
        </a>
        <?php endif; ?>
        <?php if (cf_can_create()): ?>
        <a href="<?= APP_URL ?>/course-fees/create.php" class="btn btn-primary btn-sm">
            <i class="fas fa-plus me-1"></i> Add Fee Structure
        </a>
        <?php endif; ?>
    </div>
</div>

<?= flash_show() ?>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="h2 fw-bold text-primary mb-0"><?= (int)$stats['total'] ?></div>
            <div class="small text-muted">Total Programmes</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="h2 fw-bold text-success mb-0"><?= (int)$stats['active_count'] ?></div>
            <div class="small text-muted">Active</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="h2 fw-bold text-secondary mb-0"><?= (int)$stats['inactive_count'] ?></div>
            <div class="small text-muted">Inactive</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="h2 fw-bold mb-0" style="color:<?= ($settings['is_published'] ?? 1) ? '#22c55e' : '#94a3b8' ?>">
                <?= ($settings['is_published'] ?? 1) ? 'Live' : 'Hidden' ?>
            </div>
            <div class="small text-muted">Public Page</div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small fw-semibold mb-1">Search</label>
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Department or programme…" value="<?= h($search) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold mb-1">Department</label>
                <select name="dept" class="form-select form-select-sm">
                    <option value="">All Departments</option>
                    <?php foreach ($depts as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $f_dept == $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Degree</label>
                <select name="degree" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="bachelor"    <?= $f_degree === 'bachelor'    ? 'selected' : '' ?>>Bachelor</option>
                    <option value="master"      <?= $f_degree === 'master'      ? 'selected' : '' ?>>Master</option>
                    <option value="diploma"     <?= $f_degree === 'diploma'     ? 'selected' : '' ?>>Diploma</option>
                    <option value="certificate" <?= $f_degree === 'certificate' ? 'selected' : '' ?>>Certificate</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="active"   <?= $f_status === 'active'   ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $f_status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div class="col-md-1 d-flex gap-1">
                <button class="btn btn-primary btn-sm w-100"><i class="fas fa-search"></i></button>
                <a href="<?= APP_URL ?>/course-fees/index.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-times"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- Table -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Programme</th>
                        <th>Department</th>
                        <th>Degree</th>
                        <th>Credit Fee</th>
                        <th>Credits</th>
                        <th>Extra Fees</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($programs)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-5">
                        <i class="fas fa-calculator fa-2x mb-2 d-block opacity-25"></i>
                        No fee structures found. <a href="<?= APP_URL ?>/course-fees/create.php">Add one now</a>.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($programs as $i => $p): ?>
                    <tr>
                        <td class="px-4"><?= $off + $i + 1 ?></td>
                        <td>
                            <strong><?= h($p['program_name'] ?: ($p['dept_name'] ?: '—')) ?></strong>
                        </td>
                        <td><?= h($p['dept_name'] ?: '—') ?></td>
                        <td><?= cf_degree_badge($p['degree_type']) ?></td>
                        <td class="fw-semibold"><?= cf_money((int)$p['credit_fee'], $settings['currency'] ?? 'BDT') ?></td>
                        <td><?= $p['total_credits'] ? (int)$p['total_credits'] . ' cr' : '—' ?></td>
                        <td>
                            <?php if ((int)$p['fee_count'] > 0): ?>
                            <span class="badge bg-info text-dark"><?= (int)$p['fee_count'] ?> fees</span>
                            <?php else: ?>
                            <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($p['is_active']): ?>
                                <span class="badge bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?= APP_URL ?>/course-fees/view.php?id=<?= $p['id'] ?>"
                               class="btn btn-sm btn-outline-secondary" title="View"><i class="fas fa-eye"></i></a>
                            <?php if (cf_can_edit()): ?>
                            <a href="<?= APP_URL ?>/course-fees/edit.php?id=<?= $p['id'] ?>"
                               class="btn btn-sm btn-outline-primary" title="Edit"><i class="fas fa-pencil"></i></a>
                            <?php endif; ?>
                            <?php if (cf_can_delete()): ?>
                            <a href="<?= APP_URL ?>/course-fees/delete.php?id=<?= $p['id'] ?>"
                               class="btn btn-sm btn-outline-danger" title="Delete"
                               onclick="return confirm('Delete this fee structure?')"><i class="fas fa-trash"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($pages > 1): ?>
    <div class="card-footer bg-transparent d-flex justify-content-between align-items-center flex-wrap gap-2">
        <small class="text-muted">Showing <?= $off + 1 ?>–<?= min($off + $per_page, $total) ?> of <?= $total ?></small>
        <nav><ul class="pagination pagination-sm mb-0">
            <?php for ($pg = 1; $pg <= $pages; $pg++): ?>
            <li class="page-item <?= $pg === $page ? 'active' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $pg])) ?>"><?= $pg ?></a>
            </li>
            <?php endfor; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
