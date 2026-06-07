<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('alumni');

$page_title = 'Alumni';

// ── Handle inline actions (approve / reject / toggle) ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    csrf_check();
    $aid = (int)($_POST['id'] ?? 0);

    if ($_POST['_action'] === 'approve') {
        db()->prepare("UPDATE alumni SET status='approved', is_active=1 WHERE id=?")
           ->execute([$aid]);
        flash_set('success', 'Alumni approved.');
    } elseif ($_POST['_action'] === 'reject') {
        $notes = trim($_POST['admin_notes'] ?? '');
        db()->prepare("UPDATE alumni SET status='rejected', admin_notes=? WHERE id=?")
           ->execute([$notes ?: null, $aid]);
        flash_set('success', 'Alumni rejected.');
    } elseif ($_POST['_action'] === 'toggle') {
        db()->prepare('UPDATE alumni SET is_active = 1 - is_active WHERE id = ?')->execute([$aid]);
        flash_set('success', 'Status updated.');
    } elseif ($_POST['_action'] === 'delete') {
        require_access('alumni', 'can_delete');
        $row = db()->prepare('SELECT photo FROM alumni WHERE id=?');
        $row->execute([$aid]);
        $row = $row->fetch();
        if ($row && $row['photo']) {
            $path = UPLOAD_DIR . '/alumni/' . $row['photo'];
            if (file_exists($path)) @unlink($path);
        }
        db()->prepare('DELETE FROM alumni WHERE id=?')->execute([$aid]);
        flash_set('success', 'Alumni deleted.');
    }

    redirect(APP_URL . '/alumni/index.php?' . http_build_query(array_intersect_key($_GET, array_flip(['tab','dept','q','page']))));
}

// ── Filters ──────────────────────────────────────────────────────────────────
$tab    = in_array($_GET['tab'] ?? '', ['pending','approved','rejected','all']) ? ($_GET['tab'] ?? 'pending') : 'pending';
$f_dept = (int)($_GET['dept'] ?? 0);
$search = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 20;

$where  = [];
$params = [];

if ($tab !== 'all') {
    $where[]  = 'a.status = ?';
    $params[] = $tab;
}
if ($f_dept) {
    $where[]  = 'a.dept_id = ?';
    $params[] = $f_dept;
}
if ($search !== '') {
    $where[]  = '(a.name LIKE ? OR a.student_id LIKE ? OR a.company LIKE ? OR a.position LIKE ? OR a.batch LIKE ?)';
    $s = '%' . $search . '%';
    $params = array_merge($params, [$s, $s, $s, $s, $s]);
}

$sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$count_st = db()->prepare("SELECT COUNT(*) FROM alumni a $sql_where");
$count_st->execute($params);
$total = (int)$count_st->fetchColumn();

$pages = max(1, (int)ceil($total / $per));
$page  = min($page, $pages);
$offset = ($page - 1) * $per;

$data_st = db()->prepare(
    "SELECT a.*, d.name AS dept_name
     FROM alumni a
     LEFT JOIN dept_departments d ON d.id = a.dept_id
     $sql_where
     ORDER BY a.created_at DESC
     LIMIT $per OFFSET $offset"
);
$data_st->execute($params);
$rows = $data_st->fetchAll();

// Counts per tab
$counts = ['pending'=>0,'approved'=>0,'rejected'=>0,'all'=>0];
$cnt_st = db()->query("SELECT status, COUNT(*) AS n FROM alumni GROUP BY status");
foreach ($cnt_st->fetchAll() as $c) {
    $counts[$c['status']] = (int)$c['n'];
    $counts['all'] += (int)$c['n'];
}

$departments = db()->query('SELECT id, name FROM dept_departments WHERE is_active=1 ORDER BY name ASC')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Alumni</li>
        </ol>
    </nav>
    <?php if (is_super_admin() || can_access('alumni', 'can_create')): ?>
    <a href="<?= APP_URL ?>/alumni/create.php" class="btn btn-primary" style="border-radius:10px;font-size:.875rem;">
        <i class="fas fa-plus me-1"></i> Add Alumni
    </a>
    <?php endif; ?>
</div>

<!-- Status tabs -->
<ul class="nav nav-tabs mb-3">
    <?php foreach (['pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected','all'=>'All'] as $key => $label): ?>
    <li class="nav-item">
        <a class="nav-link <?= $tab === $key ? 'active' : '' ?>"
           href="?tab=<?= $key ?>&dept=<?= $f_dept ?>&q=<?= urlencode($search) ?>">
            <?= $label ?>
            <span class="badge ms-1 <?= $tab === $key ? 'bg-primary' : 'bg-secondary bg-opacity-50 text-dark' ?>">
                <?= $counts[$key] ?>
            </span>
        </a>
    </li>
    <?php endforeach; ?>
</ul>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2 px-3">
        <form method="GET" class="row g-2 align-items-center">
            <input type="hidden" name="tab" value="<?= h($tab) ?>">
            <div class="col-12 col-md-5">
                <input type="text" name="q" class="form-control form-control-sm" style="border-radius:8px;"
                       placeholder="Search name, company, position, batch…" value="<?= h($search) ?>">
            </div>
            <div class="col-12 col-md-4">
                <select name="dept" class="form-select form-select-sm" style="border-radius:8px;">
                    <option value="0">All Departments</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $f_dept == $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto d-flex gap-2">
                <button class="btn btn-sm btn-primary" style="border-radius:8px;">Filter</button>
                <a href="?tab=<?= h($tab) ?>" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-user-graduate me-2 text-muted"></i>Alumni Profiles</h6>
        <span class="badge bg-primary bg-opacity-10 text-primary"><?= $total ?> total</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Name / Dept</th>
                        <th>Batch</th>
                        <th>Company & Role</th>
                        <th>Links</th>
                        <th>Status</th>
                        <th>Active</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No alumni found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $i => $a): ?>
                    <tr>
                        <td class="px-4"><?= $offset + $i + 1 ?></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <?php if ($a['photo']): ?>
                                <img src="<?= UPLOAD_URL ?>/alumni/<?= h($a['photo']) ?>"
                                     alt="" style="width:36px;height:36px;border-radius:50%;object-fit:cover;flex-shrink:0;">
                                <?php else: ?>
                                <div style="width:36px;height:36px;border-radius:50%;background:#002147;color:#fff;
                                    display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0;">
                                    <?= strtoupper(substr($a['name'], 0, 1)) ?>
                                </div>
                                <?php endif; ?>
                                <div>
                                    <div class="fw-medium"><?= h($a['name']) ?></div>
                                    <?php if ($a['student_id']): ?>
                                    <small class="text-muted">ID: <?= h($a['student_id']) ?></small>
                                    <?php endif; ?>
                                    <?php if ($a['dept_name']): ?>
                                    <small class="text-muted d-block"><?= h($a['dept_name']) ?></small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td><?= h($a['batch'] ?? '—') ?></td>
                        <td>
                            <?php if ($a['company']): ?>
                            <div class="fw-medium"><?= h($a['company']) ?></div>
                            <?php endif; ?>
                            <?php if ($a['position']): ?>
                            <small class="text-muted"><?= h($a['position']) ?></small>
                            <?php endif; ?>
                            <?php if (!$a['company'] && !$a['position']): ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($a['linkedin_url']): ?>
                            <a href="<?= h($a['linkedin_url']) ?>" target="_blank" rel="noopener noreferrer"
                               class="text-primary me-2" title="LinkedIn"><i class="fab fa-linkedin fa-lg"></i></a>
                            <?php endif; ?>
                            <?php if ($a['fb_url']): ?>
                            <a href="<?= h($a['fb_url']) ?>" target="_blank" rel="noopener noreferrer"
                               class="text-primary" title="Facebook"><i class="fab fa-facebook fa-lg"></i></a>
                            <?php endif; ?>
                            <?php if (!$a['linkedin_url'] && !$a['fb_url']): ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $sc = ['pending'=>'warning','approved'=>'success','rejected'=>'danger'];
                            echo '<span class="badge bg-' . ($sc[$a['status']] ?? 'secondary') . '">' . ucfirst(h($a['status'])) . '</span>';
                            ?>
                        </td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="_action" value="toggle">
                                <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                <button class="btn btn-sm <?= $a['is_active'] ? 'btn-success' : 'btn-secondary' ?>"
                                        style="border-radius:6px;font-size:.75rem;padding:2px 8px;" title="Toggle active">
                                    <?= $a['is_active'] ? 'On' : 'Off' ?>
                                </button>
                            </form>
                        </td>
                        <td class="text-end pe-4">
                            <div class="d-flex gap-1 justify-content-end flex-wrap">
                                <a href="<?= APP_URL ?>/alumni/view.php?id=<?= $a['id'] ?>"
                                   class="btn btn-sm btn-outline-secondary" style="border-radius:7px;" title="View">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php if (is_super_admin() || can_access('alumni', 'can_edit')): ?>
                                <a href="<?= APP_URL ?>/alumni/edit.php?id=<?= $a['id'] ?>"
                                   class="btn btn-sm btn-outline-primary" style="border-radius:7px;" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php endif; ?>
                                <?php if ($a['status'] === 'pending' && (is_super_admin() || can_access('alumni', 'can_edit'))): ?>
                                <form method="POST" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="approve">
                                    <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                    <button class="btn btn-sm btn-outline-success" style="border-radius:7px;" title="Approve">
                                        <i class="fas fa-check"></i>
                                    </button>
                                </form>
                                <button class="btn btn-sm btn-outline-danger" style="border-radius:7px;" title="Reject"
                                        data-bs-toggle="modal" data-bs-target="#rejectModal"
                                        data-id="<?= $a['id'] ?>" data-name="<?= h(addslashes($a['name'])) ?>">
                                    <i class="fas fa-times"></i>
                                </button>
                                <?php endif; ?>
                                <?php if (is_super_admin() || can_access('alumni', 'can_delete')): ?>
                                <form method="POST" style="display:inline;"
                                      onsubmit="return confirm('Delete alumni &quot;<?= h(addslashes($a['name'])) ?>&quot;? This cannot be undone.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="delete">
                                    <input type="hidden" name="id" value="<?= $a['id'] ?>">
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
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php for ($p = 1; $p <= $pages; $p++): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <a class="page-link" href="?tab=<?= h($tab) ?>&dept=<?= $f_dept ?>&q=<?= urlencode($search) ?>&page=<?= $p ?>">
                        <?= $p ?>
                    </a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="reject">
                <input type="hidden" name="id" id="rejectId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-times-circle text-danger me-2"></i>Reject Alumni</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Rejecting <strong id="rejectName"></strong>.</p>
                    <label class="form-label fw-medium">Reason / Notes (optional)</label>
                    <textarea name="admin_notes" class="form-control" rows="3"
                              placeholder="Reason for rejection…" style="border-radius:8px;"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reject</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
var rejectModal = document.getElementById('rejectModal');
rejectModal.addEventListener('show.bs.modal', function (e) {
    var btn = e.relatedTarget;
    document.getElementById('rejectId').value   = btn.dataset.id;
    document.getElementById('rejectName').textContent = btn.dataset.name;
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
