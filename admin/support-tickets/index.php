<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';

$page_title = 'IT Support Tickets';
$user       = auth_user();
$is_staff   = st_is_staff();

// ── Filters ───────────────────────────────────────────────────────────────────
$search      = trim($_GET['search']   ?? '');
$f_status    = $_GET['status']        ?? '';
$f_priority  = $_GET['priority']      ?? '';
$f_category  = $_GET['category']      ?? '';
$assigned_me = $is_staff && isset($_GET['assigned_me']);

$valid_statuses   = ['Open','In Progress','Pending','Resolved','Closed','Reopened'];
$valid_priorities = ['Low','Medium','High','Critical'];
$valid_categories = ['Hardware','Software','Network','Email','Other'];

$where  = [];
$params = [];

// Non-staff see only their own tickets
if (!$is_staff) {
    $where[]  = 't.created_by = ?';
    $params[] = $user['id'];
}

if ($search !== '') {
    $like     = '%' . $search . '%';
    $where[]  = '(t.title LIKE ? OR t.ticket_number LIKE ? OR u.full_name LIKE ?)';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if (in_array($f_status, $valid_statuses, true)) {
    $where[]  = 't.status = ?';
    $params[] = $f_status;
}
if (in_array($f_priority, $valid_priorities, true)) {
    $where[]  = 't.priority = ?';
    $params[] = $f_priority;
}
if (in_array($f_category, $valid_categories, true)) {
    $where[]  = 't.category = ?';
    $params[] = $f_category;
}
if ($assigned_me) {
    $where[]  = 't.assigned_to = ?';
    $params[] = $user['id'];
}

$sql = 'SELECT t.*, COALESCE(u.full_name, t.submitter_name) AS creator_name, a.full_name AS assignee_name
        FROM support_tickets t
        LEFT JOIN users u ON u.id = t.created_by
        LEFT JOIN users a ON a.id = t.assigned_to'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY FIELD(t.priority,\'Critical\',\'High\',\'Medium\',\'Low\'), t.created_at DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll();

// ── Stats ─────────────────────────────────────────────────────────────────────
$stats_sql = 'SELECT status, COUNT(*) AS cnt FROM support_tickets'
           . (!$is_staff ? ' WHERE created_by = ?' : '')
           . ' GROUP BY status';
$stats_stmt = db()->prepare($stats_sql);
$stats_stmt->execute($is_staff ? [] : [$user['id']]);
$stats    = array_column($stats_stmt->fetchAll(), 'cnt', 'status');
$total    = array_sum($stats);
$open_cnt = ($stats['Open'] ?? 0) + ($stats['Reopened'] ?? 0);
$prog_cnt = $stats['In Progress'] ?? 0;
$done_cnt = ($stats['Resolved'] ?? 0) + ($stats['Closed'] ?? 0);

// Overdue count
$od_sql = 'SELECT COUNT(*) FROM support_tickets
           WHERE deadline IS NOT NULL AND deadline < NOW()
             AND status NOT IN (\'Resolved\',\'Closed\')'
        . (!$is_staff ? ' AND created_by = ?' : '');
$od_stmt = db()->prepare($od_sql);
$od_stmt->execute($is_staff ? [] : [$user['id']]);
$overdue_cnt = (int)$od_stmt->fetchColumn();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">IT Support</li>
        </ol>
    </nav>
    <div class="d-flex gap-2">
        <?php if ($is_staff): ?>
        <a href="<?= APP_URL ?>/support-tickets/reports.php" class="btn btn-outline-secondary" style="border-radius:10px;font-size:.875rem;">
            <i class="fas fa-chart-bar me-1"></i> Reports
        </a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/support-tickets/create.php" class="btn btn-primary" style="border-radius:10px;font-size:.875rem;">
            <i class="fas fa-plus me-1"></i> New Ticket
        </a>
    </div>
</div>

<?php $flash_s = flash_get('success'); $flash_e = flash_get('error'); ?>
<?php if ($flash_s): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert"><?= $flash_s ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($flash_e): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert"><?= h($flash_e) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<!-- Stats cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card text-center h-100" style="border-radius:12px;border-left:4px solid #4f8ef7;">
            <div class="card-body py-3">
                <div style="font-size:1.8rem;font-weight:700;color:#4f8ef7;"><?= $total ?></div>
                <div class="text-muted" style="font-size:.8rem;">Total Tickets</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center h-100" style="border-radius:12px;border-left:4px solid #0d6efd;">
            <div class="card-body py-3">
                <div style="font-size:1.8rem;font-weight:700;color:#0d6efd;"><?= $open_cnt ?></div>
                <div class="text-muted" style="font-size:.8rem;">Open / Reopened</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center h-100" style="border-radius:12px;border-left:4px solid #0dcaf0;">
            <div class="card-body py-3">
                <div style="font-size:1.8rem;font-weight:700;color:#0dcaf0;"><?= $prog_cnt ?></div>
                <div class="text-muted" style="font-size:.8rem;">In Progress</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center h-100" style="border-radius:12px;border-left:4px solid <?= $overdue_cnt ? '#dc3545' : '#198754' ?>;">
            <div class="card-body py-3">
                <div style="font-size:1.8rem;font-weight:700;color:<?= $overdue_cnt ? '#dc3545' : '#198754' ?>;"><?= $overdue_cnt ?: $done_cnt ?></div>
                <div class="text-muted" style="font-size:.8rem;"><?= $overdue_cnt ? 'Overdue' : 'Resolved/Closed' ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-body py-3 px-4">
        <form method="GET" class="d-flex gap-2 flex-wrap align-items-center">
            <input type="text" name="search" class="form-control" style="max-width:220px;border-radius:10px;"
                   placeholder="Search tickets…" value="<?= h($search) ?>">
            <select name="status" class="form-select" style="max-width:150px;border-radius:10px;">
                <option value="">All Statuses</option>
                <?php foreach ($valid_statuses as $s): ?>
                <option value="<?= h($s) ?>" <?= $f_status === $s ? 'selected' : '' ?>><?= h($s) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="priority" class="form-select" style="max-width:140px;border-radius:10px;">
                <option value="">All Priorities</option>
                <?php foreach ($valid_priorities as $p): ?>
                <option value="<?= h($p) ?>" <?= $f_priority === $p ? 'selected' : '' ?>><?= h($p) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="category" class="form-select" style="max-width:150px;border-radius:10px;">
                <option value="">All Categories</option>
                <?php foreach ($valid_categories as $c): ?>
                <option value="<?= h($c) ?>" <?= $f_category === $c ? 'selected' : '' ?>><?= h($c) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($is_staff): ?>
            <label class="form-check-label d-flex align-items-center gap-2" style="cursor:pointer;font-size:.875rem;white-space:nowrap;">
                <input type="checkbox" class="form-check-input mt-0" name="assigned_me" value="1" <?= $assigned_me ? 'checked' : '' ?>> Assigned to me
            </label>
            <?php endif; ?>
            <button class="btn btn-outline-primary" style="border-radius:10px;">
                <i class="fas fa-search me-1"></i> Filter
            </button>
            <?php if ($search || $f_status || $f_priority || $f_category || $assigned_me): ?>
            <a href="<?= APP_URL ?>/support-tickets/index.php" class="btn btn-light" style="border-radius:10px;">Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Ticket table -->
<div class="card" style="border-radius:12px;">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-3" style="width:120px;">Ticket #</th>
                        <th>Title</th>
                        <th>Category</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <?php if ($is_staff): ?><th>Submitted by</th><?php endif; ?>
                        <th>Deadline</th>
                        <th style="width:110px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($tickets)): ?>
                    <tr><td colspan="<?= $is_staff ? 8 : 7 ?>" class="text-center text-muted py-5">No tickets found.</td></tr>
                <?php else: ?>
                    <?php foreach ($tickets as $ticket): ?>
                    <?php $overdue = st_is_overdue($ticket); ?>
                    <tr <?= $overdue ? 'style="background:#fff8f8;"' : '' ?>>
                        <td class="px-3">
                            <a href="<?= APP_URL ?>/support-tickets/view.php?id=<?= $ticket['id'] ?>"
                               class="text-decoration-none fw-semibold" style="font-size:.83rem;">
                                <?= h($ticket['ticket_number']) ?>
                            </a>
                            <?php if ($overdue): ?>
                            <br><span class="badge bg-danger" style="font-size:.6rem;">OVERDUE</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?= APP_URL ?>/support-tickets/view.php?id=<?= $ticket['id'] ?>"
                               class="text-dark text-decoration-none fw-medium">
                                <?= h($ticket['title']) ?>
                            </a>
                            <?php if ($ticket['assignee_name'] && $is_staff): ?>
                            <div style="font-size:.75rem;color:#9ca3af;">
                                <i class="fas fa-user me-1"></i><?= h($ticket['assignee_name']) ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-light text-dark border" style="font-size:.75rem;"><?= h($ticket['category']) ?></span>
                        </td>
                        <td><?= st_priority_badge($ticket['priority']) ?></td>
                        <td><?= st_status_badge($ticket['status']) ?></td>
                        <?php if ($is_staff): ?>
                        <td style="font-size:.85rem;">
                            <?= h($ticket['creator_name'] ?: '—') ?>
                            <?php if ($ticket['is_public']): ?>
                            <span class="badge bg-secondary ms-1" style="font-size:.65rem;">Public</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td>
                            <?php if ($ticket['deadline']): ?>
                            <?php $dlColor = $overdue ? 'text-danger fw-semibold' : 'text-body'; ?>
                            <span class="<?= $dlColor ?>" style="font-size:.83rem;">
                                <?= date('M d, Y', strtotime($ticket['deadline'])) ?>
                            </span>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="<?= APP_URL ?>/support-tickets/view.php?id=<?= $ticket['id'] ?>"
                                   class="btn btn-sm btn-outline-primary" title="View" style="border-radius:7px;">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php if (is_super_admin() || (int)$ticket['created_by'] === (int)$user['id']): ?>
                                <a href="<?= APP_URL ?>/support-tickets/edit.php?id=<?= $ticket['id'] ?>"
                                   class="btn btn-sm btn-outline-secondary" title="Edit" style="border-radius:7px;">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form method="POST" action="<?= APP_URL ?>/support-tickets/delete.php" class="d-inline"
                                      onsubmit="return confirm('Permanently delete ticket #<?= h($ticket['ticket_number']) ?>?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $ticket['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" title="Delete" style="border-radius:7px;">
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
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
