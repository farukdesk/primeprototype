<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('semester-drop');
require_once __DIR__ . '/helpers.php';

$page_title = 'Semester Drop';
$db         = db();

// ── Filters ─────────────────────────────────────────────────────────────────
$search   = trim($_GET['q'] ?? '');
$f_status = trim($_GET['status'] ?? '');
$f_type   = trim($_GET['type'] ?? '');

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[]  = '(s.full_name LIKE ? OR s.student_id LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($f_status === 'active' || $f_status === 'cancelled') {
    $where[]  = 'd.status = ?';
    $params[] = $f_status;
}
if ($f_type === 'bi' || $f_type === 'tri') {
    $where[]  = 'd.semester_type = ?';
    $params[] = $f_type;
}

$where_sql = implode(' AND ', $where);

// ── Pagination ──────────────────────────────────────────────────────────────
$per_page = 25;
$page     = max(1, (int)($_GET['page'] ?? 1));

$cnt_stmt = $db->prepare(
    "SELECT COUNT(*)
       FROM semester_drops d
       JOIN students s ON s.id = d.student_id
      WHERE $where_sql"
);
$cnt_stmt->execute($params);
$total = (int)$cnt_stmt->fetchColumn();
$pages = max(1, (int)ceil($total / $per_page));
$page  = min($page, $pages);
$off   = ($page - 1) * $per_page;

$stmt = $db->prepare(
    "SELECT d.*, s.full_name AS student_name, s.student_id AS student_sid,
            cb.full_name AS created_by_name
       FROM semester_drops d
       JOIN students s ON s.id = d.student_id
       LEFT JOIN users cb ON cb.id = d.created_by
      WHERE $where_sql
      ORDER BY d.created_at DESC
      LIMIT $per_page OFFSET $off"
);
$stmt->execute($params);
$drops = $stmt->fetchAll();

$today = date('Y-m-d');

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-pause-circle me-2 text-warning"></i>Semester Drop</h1>
        <p class="text-muted mb-0 small">Students taking a study break – monthly dues are paused during the blocked window.</p>
    </div>
    <?php if (sd_can_create()): ?>
    <a href="<?= APP_URL ?>/semester-drop/create.php" class="btn btn-warning btn-sm">
        <i class="fas fa-plus me-1"></i> New Semester Drop
    </a>
    <?php endif; ?>
</div>

<?= flash_show() ?>

<!-- ── Search & Filter bar ── -->
<div class="card mb-4">
    <div class="card-body py-3">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label fw-semibold small mb-1">Search student</label>
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Student name or ID…"
                       value="<?= h($search) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold small mb-1">Type</label>
                <select name="type" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    <option value="bi"  <?= $f_type === 'bi'  ? 'selected' : '' ?>>Bi-semester (6 mo)</option>
                    <option value="tri" <?= $f_type === 'tri' ? 'selected' : '' ?>>Tri-semester (4 mo)</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold small mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="active"    <?= $f_status === 'active'    ? 'selected' : '' ?>>Active</option>
                    <option value="cancelled" <?= $f_status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            <div class="col-12 col-md-3 d-flex gap-2">
                <button class="btn btn-primary btn-sm flex-fill" type="submit"><i class="fas fa-search me-1"></i>Filter</button>
                <?php if ($search !== '' || $f_status !== '' || $f_type !== ''): ?>
                <a href="<?= APP_URL ?>/semester-drop/index.php" class="btn btn-outline-secondary btn-sm flex-fill">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- ── Table ── -->
<div class="card">
    <div class="card-body p-0">
        <?php if (empty($drops)): ?>
        <div class="text-center text-muted py-5">
            <i class="fas fa-pause-circle fa-3x mb-3 opacity-25"></i>
            <p class="mb-0">No semester drops found.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Type</th>
                        <th>Drop Start</th>
                        <th>Drop End</th>
                        <th>Status</th>
                        <th>Recorded by</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($drops as $d): ?>
                <?php
                    $is_current = $d['status'] === 'active'
                        && $d['drop_start'] <= $today && $today <= $d['drop_end'];
                ?>
                <tr>
                    <td>
                        <a href="<?= APP_URL ?>/students/view.php?id=<?= (int)$d['student_id'] ?>"
                           class="fw-semibold text-decoration-none">
                            <?= h($d['student_name']) ?>
                        </a><br>
                        <small class="text-muted"><?= h($d['student_sid']) ?></small>
                    </td>
                    <td>
                        <span class="badge bg-info text-dark"><?= h(sd_type_label($d['semester_type'])) ?></span>
                        <small class="text-muted d-block"><?= (int)$d['block_months'] ?> months</small>
                    </td>
                    <td><?= h(date('d M Y', strtotime($d['drop_start']))) ?></td>
                    <td><?= h(date('d M Y', strtotime($d['drop_end']))) ?></td>
                    <td>
                        <?= sd_status_badge($d['status']) ?>
                        <?php if ($is_current): ?>
                        <span class="badge bg-warning text-dark"><i class="fas fa-circle me-1" style="font-size:.5rem;"></i>On break now</span>
                        <?php endif; ?>
                    </td>
                    <td><small class="text-muted"><?= h($d['created_by_name'] ?? '—') ?></small></td>
                    <td class="text-end">
                        <a href="<?= APP_URL ?>/semester-drop/view.php?id=<?= (int)$d['id'] ?>"
                           class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-eye me-1"></i>View
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($pages > 1): ?>
        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top">
            <small class="text-muted">Page <?= $page ?> of <?= $pages ?> &middot; <?= $total ?> total</small>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php for ($p = 1; $p <= $pages; $p++): ?>
                    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                        <a class="page-link"
                           href="?<?= http_build_query(['q' => $search, 'status' => $f_status, 'type' => $f_type, 'page' => $p]) ?>">
                            <?= $p ?>
                        </a>
                    </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
