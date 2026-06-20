<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_super_admin();
require_once __DIR__ . '/helpers.php';

$page_title = 'Change Log';

// ── Filters & Pagination ──────────────────────────────────────────────────────
$search     = trim($_GET['search']     ?? '');
$f_module   = trim($_GET['module']     ?? '');
$f_action   = trim($_GET['action']     ?? '');
$f_date_from = trim($_GET['date_from'] ?? '');
$f_date_to   = trim($_GET['date_to']   ?? '');
$page        = max(1, (int)($_GET['page'] ?? 1));
$per_page    = 10;

$valid_actions = ['CREATE', 'UPDATE', 'DELETE'];

$where  = [];
$params = [];

if ($search !== '') {
    $like     = '%' . $search . '%';
    $where[]  = '(u.full_name LIKE ? OR u.email LIKE ? OR cl.module LIKE ? OR cl.field_name LIKE ? OR cl.description LIKE ? OR cl.record_label LIKE ?)';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($f_module !== '') {
    $where[]  = 'cl.module = ?';
    $params[] = $f_module;
}
if (in_array($f_action, $valid_actions, true)) {
    $where[]  = 'cl.action = ?';
    $params[] = $f_action;
}
if ($f_date_from !== '') {
    $where[]  = 'DATE(cl.created_at) >= ?';
    $params[] = $f_date_from;
}
if ($f_date_to !== '') {
    $where[]  = 'DATE(cl.created_at) <= ?';
    $params[] = $f_date_to;
}

$base_sql = 'FROM change_log cl
             JOIN users u ON u.id = cl.user_id'
          . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

// Total count for pagination
$count_stmt = db()->prepare('SELECT COUNT(*) ' . $base_sql);
$count_stmt->execute($params);
$total_rows  = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$data_params   = $params;
$data_params[] = $per_page;
$data_params[] = $offset;

$stmt = db()->prepare(
    'SELECT cl.*, u.full_name, u.email ' . $base_sql
    . ' ORDER BY cl.created_at DESC LIMIT ? OFFSET ?'
);
$stmt->execute($data_params);
$logs = $stmt->fetchAll();

// Module list for dropdown
$modules = cl_distinct_modules();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Change Log</li>
        </ol>
    </nav>
    <div class="text-muted" style="font-size:.85rem;">
        <i class="fas fa-history me-1"></i>
        <?= number_format($total_rows) ?> total entr<?= $total_rows === 1 ? 'y' : 'ies' ?>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-body py-3 px-4">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-12 col-sm-6 col-md-3">
                <label class="form-label" style="font-size:.78rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;">Search</label>
                <input type="text" name="search" class="form-control" style="border-radius:10px;"
                       placeholder="Name, email, module…" value="<?= h($search) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label" style="font-size:.78rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;">Module</label>
                <select name="module" class="form-select" style="border-radius:10px;">
                    <option value="">All Modules</option>
                    <?php foreach ($modules as $m): ?>
                    <option value="<?= h($m) ?>" <?= $f_module === $m ? 'selected' : '' ?>><?= h($m) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label" style="font-size:.78rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;">Action</label>
                <select name="action" class="form-select" style="border-radius:10px;">
                    <option value="">All Actions</option>
                    <?php foreach ($valid_actions as $a): ?>
                    <option value="<?= h($a) ?>" <?= $f_action === $a ? 'selected' : '' ?>><?= h($a) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label" style="font-size:.78rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;">From Date</label>
                <input type="date" name="date_from" class="form-control" style="border-radius:10px;"
                       value="<?= h($f_date_from) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label" style="font-size:.78rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;">To Date</label>
                <input type="date" name="date_to" class="form-control" style="border-radius:10px;"
                       value="<?= h($f_date_to) ?>">
            </div>
            <div class="col-12 col-md-1 d-flex gap-1">
                <button class="btn btn-outline-primary w-100" style="border-radius:10px;" title="Apply filters">
                    <i class="fas fa-search"></i>
                </button>
                <?php if ($search || $f_module || $f_action || $f_date_from || $f_date_to): ?>
                <a href="<?= APP_URL ?>/change-log/index.php" class="btn btn-light" style="border-radius:10px;" title="Clear filters">
                    <i class="fas fa-times"></i>
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Log table -->
<div class="card" style="border-radius:12px;">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-3" style="width:50px;">#</th>
                        <th>User</th>
                        <th>Module</th>
                        <th>Action</th>
                        <th>Record</th>
                        <th>Field</th>
                        <th>Previous Value</th>
                        <th>New Value</th>
                        <th>IP Address</th>
                        <th>Date &amp; Time</th>
                        <th style="width:70px;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="11" class="text-center text-muted py-5">
                            <i class="fas fa-history fa-2x mb-3 d-block opacity-25"></i>
                            No change log entries found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="px-3 text-muted" style="font-size:.78rem;"><?= $log['id'] ?></td>
                        <td>
                            <div class="fw-semibold" style="font-size:.875rem;"><?= h($log['full_name']) ?></div>
                            <div class="text-muted" style="font-size:.75rem;"><?= h($log['email']) ?></div>
                        </td>
                        <td>
                            <span class="badge bg-light text-dark border" style="font-size:.75rem;"><?= h($log['module']) ?></span>
                        </td>
                        <td><?= cl_action_badge($log['action']) ?></td>
                        <td style="font-size:.83rem;">
                            <?php if ($log['record_label']): ?>
                                <span title="Record ID: <?= (int)$log['record_id'] ?>"><?= h($log['record_label']) ?></span>
                            <?php elseif ($log['record_id']): ?>
                                <span class="text-muted">#<?= (int)$log['record_id'] ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:.83rem;"><?php if ($log['field_name']): ?><code><?= h($log['field_name']) ?></code><?php else: ?>—<?php endif; ?></td>
                        <td style="font-size:.83rem;max-width:160px;">
                            <?php if ($log['old_value'] !== null): ?>
                            <span class="d-inline-block text-truncate" style="max-width:140px;" title="<?= h($log['old_value']) ?>">
                                <?= h($log['old_value']) ?>
                            </span>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:.83rem;max-width:160px;">
                            <?php if ($log['new_value'] !== null): ?>
                            <span class="d-inline-block text-truncate" style="max-width:140px;" title="<?= h($log['new_value']) ?>">
                                <?= h($log['new_value']) ?>
                            </span>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:.78rem;font-family:monospace;"><?= $log['ip_address'] ? h($log['ip_address']) : '—' ?></td>
                        <td style="font-size:.78rem;white-space:nowrap;">
                            <div><?= date('M d, Y', strtotime($log['created_at'])) ?></div>
                            <div class="text-muted"><?= date('H:i:s', strtotime($log['created_at'])) ?></div>
                        </td>
                        <td>
                            <a href="<?= APP_URL ?>/change-log/view.php?id=<?= $log['id'] ?>"
                               class="btn btn-sm btn-outline-primary" title="View details" style="border-radius:7px;">
                                <i class="fas fa-eye"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Pagination -->
<?php if ($total_pages > 1): ?>
<?php
$qs_base = http_build_query(array_filter([
    'search'    => $search,
    'module'    => $f_module,
    'action'    => $f_action,
    'date_from' => $f_date_from,
    'date_to'   => $f_date_to,
]));
$qs_base = $qs_base ? '&' . $qs_base : '';
?>
<nav class="mt-4" aria-label="Change log pagination">
    <ul class="pagination justify-content-center">
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="?page=<?= $page - 1 . $qs_base ?>">
                <i class="fas fa-chevron-left"></i>
            </a>
        </li>
        <?php
        // Smart page range: show first, last, current ±2, with ellipsis
        $range = [];
        for ($p = 1; $p <= $total_pages; $p++) {
            if ($p === 1 || $p === $total_pages || abs($p - $page) <= 2) {
                $range[] = $p;
            }
        }
        $prev = null;
        foreach ($range as $p):
            if ($prev !== null && $p - $prev > 1): ?>
            <li class="page-item disabled"><span class="page-link">…</span></li>
        <?php endif; ?>
        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
            <a class="page-link" href="?page=<?= $p . $qs_base ?>"><?= $p ?></a>
        </li>
        <?php $prev = $p; endforeach; ?>
        <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
            <a class="page-link" href="?page=<?= $page + 1 . $qs_base ?>">
                <i class="fas fa-chevron-right"></i>
            </a>
        </li>
    </ul>
    <p class="text-center text-muted mb-0" style="font-size:.8rem;">
        Page <?= $page ?> of <?= $total_pages ?> &nbsp;·&nbsp; <?= number_format($total_rows) ?> total entries
    </p>
</nav>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
