<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('scholarship');

$page_title = 'Scholarships';
$db         = db();

// ── Filters ───────────────────────────────────────────────────────────────────
$search    = trim($_GET['q']        ?? '');
$f_policy  = (int)($_GET['policy']  ?? 0);
$f_sem     = trim($_GET['semester'] ?? '');
$f_status  = $_GET['status']        ?? '';
$f_type    = $_GET['type']          ?? '';

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[]  = '(s.full_name LIKE ? OR s.student_id LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($f_policy > 0) {
    $where[]  = 'a.policy_id = ?';
    $params[] = $f_policy;
}
if ($f_sem !== '') {
    $where[]  = 'a.semester = ?';
    $params[] = $f_sem;
}
if (in_array($f_status, ['active', 'revoked'], true)) {
    $where[]  = 'a.status = ?';
    $params[] = $f_status;
}
if (in_array($f_type, ['gpa_based', 'merit_based'], true)) {
    $where[]  = 'p.type = ?';
    $params[] = $f_type;
}

$where_sql = implode(' AND ', $where);

// ── Pagination ────────────────────────────────────────────────────────────────
$per_page = 20;
$page     = max(1, (int)($_GET['page'] ?? 1));

$cnt_stmt = $db->prepare(
    "SELECT COUNT(*)
     FROM sc_awards a
     JOIN students s    ON s.id = a.student_id
     JOIN sc_policies p ON p.id = a.policy_id
     WHERE $where_sql"
);
$cnt_stmt->execute($params);
$total = (int)$cnt_stmt->fetchColumn();
$pages = max(1, (int)ceil($total / $per_page));
$page  = min($page, $pages);
$off   = ($page - 1) * $per_page;

$stmt = $db->prepare(
    "SELECT a.*,
            s.full_name, s.student_id AS student_sid,
            p.name AS policy_name, p.type AS policy_type,
            t.label AS tier_label
     FROM sc_awards a
     JOIN students s       ON s.id  = a.student_id
     JOIN sc_policies p    ON p.id  = a.policy_id
     LEFT JOIN sc_tiers t  ON t.id  = a.tier_id
     WHERE $where_sql
     ORDER BY a.awarded_at DESC
     LIMIT $per_page OFFSET $off"
);
$stmt->execute($params);
$awards = $stmt->fetchAll();

// ── Stats ─────────────────────────────────────────────────────────────────────
$stats = $db->query(
    "SELECT COUNT(*) AS total,
            SUM(a.status='active')                         AS active_count,
            SUM(a.status='revoked')                        AS revoked_count,
            SUM(p.type='gpa_based')                        AS gpa_count,
            SUM(p.type='merit_based')                      AS merit_count
     FROM sc_awards a
     JOIN sc_policies p ON p.id = a.policy_id"
)->fetch();

// ── Filter data ───────────────────────────────────────────────────────────────
$policies   = $db->query('SELECT id, name, type FROM sc_policies ORDER BY sort_order, name')->fetchAll();
$semesters  = $db->query('SELECT DISTINCT semester FROM sc_awards ORDER BY semester DESC')->fetchAll(PDO::FETCH_COLUMN);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-graduation-cap me-2 text-warning"></i>Scholarships</h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Scholarships</li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= APP_URL ?>/scholarship/policies.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-cog me-1"></i> Policies
        </a>
        <a href="<?= APP_URL ?>/scholarship/run-merit.php" class="btn btn-outline-primary btn-sm">
            <i class="fas fa-bolt me-1"></i> Run Merit Auto-Apply
        </a>
        <?php if (sc_can_create()): ?>
        <a href="<?= APP_URL ?>/scholarship/award.php" class="btn btn-primary btn-sm">
            <i class="fas fa-plus me-1"></i> Add Award
        </a>
        <?php endif; ?>
    </div>
</div>

<?= flash_show() ?>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-2">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="h2 fw-bold text-primary mb-0"><?= (int)$stats['total'] ?></div>
            <div class="small text-muted">Total Awards</div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="h2 fw-bold text-success mb-0"><?= (int)$stats['active_count'] ?></div>
            <div class="small text-muted">Active</div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="h2 fw-bold text-danger mb-0"><?= (int)$stats['revoked_count'] ?></div>
            <div class="small text-muted">Revoked</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="h2 fw-bold text-info mb-0"><?= (int)$stats['gpa_count'] ?></div>
            <div class="small text-muted">GPA-Based</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="h2 fw-bold text-primary mb-0"><?= (int)$stats['merit_count'] ?></div>
            <div class="small text-muted">Merit-Based</div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-semibold mb-1">Student Search</label>
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Name or Student ID…" value="<?= h($search) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Policy</label>
                <select name="policy" class="form-select form-select-sm">
                    <option value="">All Policies</option>
                    <?php foreach ($policies as $pol): ?>
                    <option value="<?= $pol['id'] ?>" <?= $f_policy == $pol['id'] ? 'selected' : '' ?>><?= h($pol['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Semester</label>
                <select name="semester" class="form-select form-select-sm">
                    <option value="">All Semesters</option>
                    <?php foreach ($semesters as $sem): ?>
                    <option value="<?= h($sem) ?>" <?= $f_sem === $sem ? 'selected' : '' ?>><?= h($sem) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Type</label>
                <select name="type" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    <option value="gpa_based"   <?= $f_type === 'gpa_based'   ? 'selected' : '' ?>>GPA-Based</option>
                    <option value="merit_based" <?= $f_type === 'merit_based' ? 'selected' : '' ?>>Merit-Based</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="active"  <?= $f_status === 'active'  ? 'selected' : '' ?>>Active</option>
                    <option value="revoked" <?= $f_status === 'revoked' ? 'selected' : '' ?>>Revoked</option>
                </select>
            </div>
            <div class="col-md-1 d-flex gap-1">
                <button class="btn btn-primary btn-sm w-100"><i class="fas fa-search"></i></button>
                <a href="<?= APP_URL ?>/scholarship/index.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-times"></i></a>
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
                        <th>Student</th>
                        <th>Policy</th>
                        <th>Semester</th>
                        <th>GPA Used</th>
                        <th>Discount</th>
                        <th>Status</th>
                        <th>Awarded</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($awards)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-5">
                        <i class="fas fa-graduation-cap fa-2x mb-2 d-block opacity-25"></i>
                        No awards found.
                        <?php if (sc_can_create()): ?><a href="<?= APP_URL ?>/scholarship/award.php">Add one now</a>.<?php endif; ?>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($awards as $i => $a): ?>
                    <tr>
                        <td class="px-4"><?= $off + $i + 1 ?></td>
                        <td>
                            <div class="fw-semibold"><?= h($a['full_name']) ?></div>
                            <small class="text-muted"><?= h($a['student_sid']) ?></small>
                        </td>
                        <td>
                            <div><?= h($a['policy_name']) ?></div>
                            <div><?= sc_type_badge($a['policy_type']) ?><?php if ($a['tier_label']): ?> <span class="badge bg-light text-dark border"><?= h($a['tier_label']) ?></span><?php endif; ?></div>
                        </td>
                        <td><?= h($a['semester']) ?></td>
                        <td><?= $a['gpa_used'] !== null ? number_format((float)$a['gpa_used'], 2) : '—' ?></td>
                        <td class="fw-semibold"><?= number_format((float)$a['discount_percent'], 2) ?>%</td>
                        <td><?= sc_status_badge($a['status']) ?></td>
                        <td><small><?= date('d M Y', strtotime($a['awarded_at'])) ?></small></td>
                        <td>
                            <?php if (sc_can_edit()): ?>
                            <a href="<?= APP_URL ?>/scholarship/award-edit.php?id=<?= $a['id'] ?>"
                               class="btn btn-sm btn-outline-primary" title="Edit"><i class="fas fa-pencil"></i></a>
                            <?php endif; ?>
                            <?php if (sc_can_delete()): ?>
                            <form method="post" action="<?= APP_URL ?>/scholarship/award-delete.php" class="d-inline"
                                  onsubmit="return confirm('Delete this award?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button>
                            </form>
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
