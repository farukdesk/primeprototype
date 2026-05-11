<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('admissions');
require_once __DIR__ . '/helpers.php';

$page_title = 'Admissions';
$user       = auth_user();

// ── Filters ───────────────────────────────────────────────────────────────────
$search   = trim($_GET['search'] ?? '');
$f_status = $_GET['status']      ?? '';
$f_dept   = (int)($_GET['dept']  ?? 0);
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;

$valid_statuses = ['ready_for_admission', 'draft', 'cancelled', 'admission_complete'];
$status_labels  = [
    'ready_for_admission' => 'Ready for Admission',
    'draft'               => 'Draft',
    'cancelled'           => 'Cancelled',
    'admission_complete'  => 'Admission Complete',
];

$where  = [];
$params = [];

if ($search !== '') {
    $like = '%' . $search . '%';
    $where[]  = '(a.app_number LIKE ? OR a.student_name LIKE ? OR a.father_name LIKE ? OR a.present_contact LIKE ? OR a.permanent_contact LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like);
}
if (in_array($f_status, $valid_statuses, true)) {
    $where[]  = 'a.status = ?';
    $params[] = $f_status;
}
if ($f_dept > 0) {
    $where[]  = 'a.dept_id = ?';
    $params[] = $f_dept;
}

$where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$count_stmt = db()->prepare('SELECT COUNT(*) FROM admissions_applications a' . $where_sql);
$count_stmt->execute($params);
$total_rows  = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$sql = 'SELECT a.*,
               d.name         AS dept_name,
               p.program_name
        FROM admissions_applications a
        LEFT JOIN dept_departments d        ON d.id = a.dept_id
        LEFT JOIN dept_academic_programs p  ON p.id = a.program_id'
     . $where_sql
     . ' ORDER BY a.created_at DESC LIMIT ' . $per_page . ' OFFSET ' . $offset;

$stmt = db()->prepare($sql);
$stmt->execute($params);
$applications = $stmt->fetchAll();

// ── Departments for filter ────────────────────────────────────────────────────
$departments = db()->query(
    'SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC'
)->fetchAll();

// ── Filter query string ───────────────────────────────────────────────────────
$filter_qs = http_build_query(array_filter([
    'search' => $search,
    'status' => $f_status,
    'dept'   => $f_dept ?: '',
]));

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-user-plus me-2 text-primary"></i>Admissions</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item active">Admissions</li>
        </ol></nav>
    </div>
    <?php if (adm_can_manage()): ?>
    <a href="<?= APP_URL ?>/admissions/create.php" class="btn btn-primary btn-sm">
        <i class="fas fa-plus me-1"></i> New Application
    </a>
    <?php endif; ?>
</div>

<?php flash_show(); ?>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="Search by App No, Name, Phone…" value="<?= h($search) ?>">
            </div>
            <div class="col-6 col-md-2">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <?php foreach ($valid_statuses as $st): ?>
                    <option value="<?= h($st) ?>" <?= $f_status === $st ? 'selected' : '' ?>><?= h($status_labels[$st] ?? ucfirst(str_replace('_', ' ', $st))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <select name="dept" class="form-select form-select-sm">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= h($d['id']) ?>" <?= $f_dept == $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search me-1"></i>Filter</button>
                <a href="<?= APP_URL ?>/admissions/index.php" class="btn btn-outline-secondary btn-sm ms-1">Clear</a>
            </div>
        </form>
    </div>
</div>

<!-- Table -->
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-4">#</th>
                    <th>App No</th>
                    <th>Student Name</th>
                    <th>Department</th>
                    <th>Program</th>
                    <th>Semester</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th class="text-end pe-4">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($applications)): ?>
                <tr><td colspan="9" class="text-center py-4 text-muted">No applications found.</td></tr>
            <?php else: ?>
                <?php foreach ($applications as $i => $app): ?>
                <tr>
                    <td class="ps-4 text-muted small"><?= $offset + $i + 1 ?></td>
                    <td><a href="<?= APP_URL ?>/admissions/view.php?id=<?= $app['id'] ?>" class="fw-semibold text-decoration-none"><?= h($app['app_number']) ?></a></td>
                    <td><?= h($app['student_name']) ?></td>
                    <td class="text-muted small"><?= h($app['dept_name'] ?? '—') ?></td>
                    <td class="text-muted small"><?= h($app['program_name'] ?? '—') ?></td>
                    <td class="text-muted small"><?= h($app['semester'] ?? '—') ?></td>
                    <td><?= adm_status_badge($app['status']) ?></td>
                    <td class="text-muted small"><?= h(date('d M Y', strtotime($app['created_at']))) ?></td>
                    <td class="text-end pe-4">
                        <div class="d-flex justify-content-end gap-1">
                            <a href="<?= APP_URL ?>/admissions/view.php?id=<?= $app['id'] ?>" class="btn btn-sm btn-outline-secondary" title="View"><i class="fas fa-eye"></i></a>
                            <a href="<?= APP_URL ?>/admissions/statement.php?id=<?= $app['id'] ?>" target="_blank" class="btn btn-sm btn-outline-success" title="Statement"><i class="fas fa-file-invoice-dollar"></i></a>
                            <?php if (adm_can_edit()): ?>
                            <a href="<?= APP_URL ?>/admissions/edit.php?id=<?= $app['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fas fa-edit"></i></a>
                            <?php endif; ?>
                            <?php if (adm_can_delete()): ?>
                            <button type="button" class="btn btn-sm btn-outline-danger" title="Delete"
                                    onclick="confirmDelete(<?= $app['id'] ?>, '<?= h(addslashes($app['app_number'])) ?>')">
                                <i class="fas fa-trash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($total_pages > 1): ?>
    <div class="card-footer d-flex justify-content-between align-items-center px-4 py-3">
        <span class="text-muted small">Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total_rows) ?> of <?= $total_rows ?></span>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <a class="page-link" href="?<?= $filter_qs ?>&page=<?= $p ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<!-- Delete Modal -->
<form id="deleteForm" method="POST" action="<?= APP_URL ?>/admissions/delete.php">
    <?= csrf_field() ?>
    <input type="hidden" name="id" id="deleteId">
</form>
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title">Delete Application</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete application <strong id="deleteLabel"></strong>? This action cannot be undone.</p>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" style="border-radius:8px;"
                        onclick="document.getElementById('deleteForm').submit()">
                    <i class="fas fa-trash me-1"></i> Delete
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDelete(id, label) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteLabel').textContent = label;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
