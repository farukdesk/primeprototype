<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('student-transfer');
require_once __DIR__ . '/helpers.php';

$page_title = 'Student Transfer';
$db         = db();

// ── Filters ─────────────────────────────────────────────────────────────────
$search  = trim($_GET['q'] ?? '');
$f_kind  = trim($_GET['kind'] ?? '');
$f_dept  = (int)($_GET['dept'] ?? 0);
$f_batch = (int)($_GET['batch'] ?? 0);

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[]  = '(s.full_name LIKE ? OR s.student_id LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($f_kind === 'department' || $f_kind === 'batch') {
    $where[]  = 't.kind = ?';
    $params[] = $f_kind;
}
if ($f_dept > 0) {
    $where[]  = 't.to_dept_id = ?';
    $params[] = $f_dept;
}
if ($f_batch > 0) {
    $where[]  = 't.to_batch_id = ?';
    $params[] = $f_batch;
}

$where_sql = implode(' AND ', $where);

// ── Pagination ──────────────────────────────────────────────────────────────
$per_page = 25;
$page     = max(1, (int)($_GET['page'] ?? 1));

$cnt_stmt = $db->prepare(
    "SELECT COUNT(*)
       FROM student_transfers t
       JOIN students s ON s.id = t.student_id
      WHERE $where_sql"
);
$cnt_stmt->execute($params);
$total = (int)$cnt_stmt->fetchColumn();
$pages = max(1, (int)ceil($total / $per_page));
$page  = min($page, $pages);
$off   = ($page - 1) * $per_page;

$stmt = $db->prepare(
    "SELECT t.*, s.full_name AS student_name, s.student_id AS student_sid,
            u.full_name AS created_by_name
       FROM student_transfers t
       JOIN students s ON s.id = t.student_id
  LEFT JOIN users u     ON u.id = t.created_by
      WHERE $where_sql
      ORDER BY t.created_at DESC
      LIMIT $per_page OFFSET $off"
);
$stmt->execute($params);
$transfers = $stmt->fetchAll();

$departments = stt_allowed_depts();
$batches     = stt_batch_map();

$qs = ['q' => $search, 'kind' => $f_kind, 'dept' => $f_dept ?: '', 'batch' => $f_batch ?: ''];
$has_filter = $search !== '' || $f_kind !== '' || $f_dept > 0 || $f_batch > 0;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-exchange-alt me-2 text-primary"></i>Student Transfer</h1>
        <p class="text-muted mb-0 small">Move a student to another department/program, or another batch — with a full history log.</p>
    </div>
    <?php if (stt_can_create()): ?>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= APP_URL ?>/student-transfer/department.php" class="btn btn-primary btn-sm">
            <i class="fas fa-building me-1"></i> Department Transfer
        </a>
        <a href="<?= APP_URL ?>/student-transfer/batch.php" class="btn btn-outline-primary btn-sm">
            <i class="fas fa-users me-1"></i> Batch Transfer
        </a>
    </div>
    <?php endif; ?>
</div>

<?= flash_show() ?>

<!-- ── Search & Filter bar ── -->
<div class="card mb-4">
    <div class="card-body py-3">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label fw-semibold small mb-1">Search student</label>
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Student name or ID…"
                       value="<?= h($search) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold small mb-1">Kind</label>
                <select name="kind" class="form-select form-select-sm">
                    <option value="">All Transfers</option>
                    <option value="department" <?= $f_kind === 'department' ? 'selected' : '' ?>>Department Transfer</option>
                    <option value="batch"      <?= $f_kind === 'batch'      ? 'selected' : '' ?>>Batch Transfer</option>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label fw-semibold small mb-1">To Department</label>
                <select name="dept" class="form-select form-select-sm">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $dep): ?>
                    <option value="<?= (int)$dep['id'] ?>" <?= $f_dept === (int)$dep['id'] ? 'selected' : '' ?>>
                        <?= h($dep['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label fw-semibold small mb-1">To Batch</label>
                <select name="batch" class="form-select form-select-sm">
                    <option value="">All Batches</option>
                    <?php foreach ($batches as $b): ?>
                    <option value="<?= (int)$b['id'] ?>" <?= $f_batch === (int)$b['id'] ? 'selected' : '' ?>>
                        <?= h($b['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2 d-flex gap-2">
                <button class="btn btn-primary btn-sm flex-fill" type="submit"><i class="fas fa-search me-1"></i>Filter</button>
                <?php if ($has_filter): ?>
                <a href="<?= APP_URL ?>/student-transfer/index.php" class="btn btn-outline-secondary btn-sm flex-fill">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- ── Table ── -->
<div class="card">
    <div class="card-body p-0">
        <?php if (empty($transfers)): ?>
        <div class="text-center text-muted py-5">
            <i class="fas fa-exchange-alt fa-3x mb-3 opacity-25"></i>
            <p class="mb-0">No transfers recorded yet.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Kind</th>
                        <th>From → To</th>
                        <th>Reason</th>
                        <th>Recorded by</th>
                        <th>Date</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($transfers as $t): ?>
                <tr>
                    <td>
                        <a href="<?= APP_URL ?>/students/view.php?id=<?= (int)$t['student_id'] ?>"
                           class="fw-semibold text-decoration-none">
                            <?= h($t['student_name']) ?>
                        </a><br>
                        <small class="text-muted"><?= h($t['student_sid']) ?></small>
                    </td>
                    <td><?= stt_kind_badge($t['kind']) ?> <?= !empty($t['reverted_at']) ? stt_reverted_badge() : '' ?></td>
                    <td>
                        <?php if ($t['kind'] === 'department'): ?>
                        <div><?= h($t['from_dept_name'] ?? '—') ?> → <strong><?= h($t['to_dept_name'] ?? '—') ?></strong></div>
                        <?php if (!empty($t['to_program_name']) || !empty($t['from_program_name'])): ?>
                        <small class="text-muted"><?= h($t['from_program_name'] ?? '— None —') ?> → <?= h($t['to_program_name'] ?? '— None —') ?></small>
                        <?php endif; ?>
                        <?php if ($t['old_student_id'] !== $t['new_student_id']): ?>
                        <small class="text-warning d-block"><i class="fas fa-id-card me-1"></i>ID reissued: <?= h($t['old_student_id']) ?> → <?= h($t['new_student_id']) ?></small>
                        <?php endif; ?>
                        <?php else: ?>
                        <div><?= h($t['from_batch_name'] ?? '— None —') ?> → <strong><?= h($t['to_batch_name'] ?? '—') ?></strong></div>
                        <?php endif; ?>
                    </td>
                    <td><small class="text-muted"><?= h($t['reason'] ? (mb_strlen($t['reason']) > 60 ? mb_substr($t['reason'], 0, 60) . '…' : $t['reason']) : '—') ?></small></td>
                    <td><small class="text-muted"><?= h($t['created_by_name'] ?? '—') ?></small></td>
                    <td><small class="text-muted"><?= h(date('d M Y', strtotime($t['created_at']))) ?></small></td>
                    <td class="text-end">
                        <a href="<?= APP_URL ?>/student-transfer/view.php?id=<?= (int)$t['id'] ?>"
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
                           href="?<?= http_build_query(array_merge($qs, ['page' => $p])) ?>">
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
