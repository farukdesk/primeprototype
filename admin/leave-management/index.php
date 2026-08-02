<?php
/**
 * Leave Management dashboard.
 *   - Every staff member: their balance + their own requests + "Request Leave".
 *   - Approvers: a list of requests awaiting their approval.
 *   - Admins (can_edit): all requests + links to Balances and Approval Flow.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';
// Module access OR self-service (Administrative / Faculty employee types).
if (!lm_can_view()) {
    $_SESSION['flash_error'] = 'You do not have permission to access this section.';
    redirect(APP_URL . '/index.php');
}

$page_title = 'Leave Management';
$user       = auth_user();
$is_admin   = lm_is_admin();
$year       = (int)date('Y');
$balance    = lm_get_balance((int)$user['id'], $year);
$db         = db();
$fmt        = fn(float $n) => rtrim(rtrim(number_format($n, 1), '0'), '.');

$view = $_GET['view'] ?? 'mine';
if ($view === 'all' && !$is_admin) $view = 'mine';

// ── Filters (status + leave-date range), applied to the requests list ─────────
$f_status = $_GET['status'] ?? '';
if (!in_array($f_status, ['pending', 'approved', 'rejected', 'cancelled'], true)) $f_status = '';
$valid_date = fn(string $d): bool => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
$f_from = (string)($_GET['from'] ?? '');
$f_to   = (string)($_GET['to'] ?? '');
if (!$valid_date($f_from)) $f_from = '';
if (!$valid_date($f_to))   $f_to   = '';
$has_filters = ($f_status !== '' || $f_from !== '' || $f_to !== '');

$filter_sql    = '';
$filter_params = [];
if ($f_status !== '') { $filter_sql .= ' AND r.status = ?';      $filter_params[] = $f_status; }
// A request matches the date range when its leave dates overlap the range.
if ($f_from !== '')   { $filter_sql .= ' AND r.end_date >= ?';   $filter_params[] = $f_from; }
if ($f_to !== '')     { $filter_sql .= ' AND r.start_date <= ?'; $filter_params[] = $f_to; }

// ── My requests ────────────────────────────────────────────────────────────────
$mine_stmt = $db->prepare(
    "SELECT r.* FROM leave_requests r WHERE r.user_id = ?$filter_sql ORDER BY r.created_at DESC LIMIT 100"
);
$mine_stmt->execute(array_merge([$user['id']], $filter_params));
$my_requests = $mine_stmt->fetchAll();

// ── Awaiting my approval ───────────────────────────────────────────────────────
$group_ids = array_map('intval', $user['group_ids'] ?? [(int)$user['group_id']]);
$pending_approvals = [];
if (!empty($group_ids)) {
    $ph = implode(',', array_fill(0, count($group_ids), '?'));
    $pa_stmt = $db->prepare(
        "SELECT r.*, u.full_name AS requester_name
           FROM leave_requests r
           JOIN users u ON u.id = r.user_id
           JOIN leave_request_approvals a
             ON a.request_id = r.id AND a.step_order = r.current_step
          WHERE r.status = 'pending' AND a.status = 'pending'
            AND a.group_id IN ($ph)
            AND r.user_id <> ?
          ORDER BY r.created_at ASC"
    );
    $pa_stmt->execute(array_merge($group_ids, [(int)$user['id']]));
    $pending_approvals = $pa_stmt->fetchAll();
}

// ── All requests (admin) ───────────────────────────────────────────────────────
$all_requests = [];
if ($is_admin && $view === 'all') {
    $all_stmt = $db->prepare(
        "SELECT r.*, u.full_name AS requester_name
           FROM leave_requests r
           JOIN users u ON u.id = r.user_id
          WHERE 1=1$filter_sql
          ORDER BY r.created_at DESC
          LIMIT 200"
    );
    $all_stmt->execute($filter_params);
    $all_requests = $all_stmt->fetchAll();
}

require_once __DIR__ . '/../includes/header.php';

/** Render a table of requests. */
function lm_render_rows(array $rows, bool $show_user, callable $fmt): void { ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th class="px-3">#</th>
                    <?php if ($show_user): ?><th>Staff</th><?php endif; ?>
                    <th>Category</th>
                    <th>Dates</th>
                    <th>Days</th>
                    <th>Pay</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="<?= $show_user ? 8 : 7 ?>" class="text-center text-muted py-4">No requests.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td class="px-3 text-muted">#<?= (int)$r['id'] ?></td>
                    <?php if ($show_user): ?><td><?= h($r['requester_name'] ?? '') ?></td><?php endif; ?>
                    <td><?= lm_category_badge($r['category']) ?></td>
                    <td class="small">
                        <?php if ($r['category'] === 'short'): ?>
                            <?= h(date('d M Y', strtotime($r['start_date']))) ?><br>
                            <span class="text-muted"><?= h(substr((string)$r['start_time'], 0, 5)) ?>–<?= h(substr((string)$r['end_time'], 0, 5)) ?></span>
                        <?php else: ?>
                            <?= h(date('d M Y', strtotime($r['start_date']))) ?> – <?= h(date('d M Y', strtotime($r['end_date']))) ?>
                        <?php endif; ?>
                    </td>
                    <td><?= $fmt((float)$r['days']) ?></td>
                    <td><?= lm_paytype_badge($r['pay_type']) ?: '<span class="text-muted">—</span>' ?></td>
                    <td><?= lm_status_badge($r['status']) ?></td>
                    <td class="text-end pe-3">
                        <a href="<?= APP_URL ?>/leave-management/view.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary" style="border-radius:8px;">View</a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
<?php }
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-plane-departure me-2 text-primary"></i>Leave Management</h1>
        <p class="text-muted mb-0 small">Request leave and track step-by-step approvals.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (lm_can_request()): ?>
        <a href="<?= APP_URL ?>/leave-management/request.php" class="btn btn-primary btn-sm">
            <i class="fas fa-plus me-1"></i> Request Leave
        </a>
        <?php endif; ?>
        <?php if ($is_admin): ?>
        <a href="<?= APP_URL ?>/leave-management/balances.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-wallet me-1"></i> Balances
        </a>
        <a href="<?= APP_URL ?>/leave-management/flow.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-route me-1"></i> Approval Flow
        </a>
        <?php endif; ?>
    </div>
</div>

<?= flash_show() ?>

<!-- ── Balance cards ── -->
<div class="row g-3 mb-4">
    <div class="col-md-3 col-6">
        <div class="card h-100" style="border-radius:12px;">
            <div class="card-body">
                <div class="text-muted small">Casual Leave (<?= $year ?>)</div>
                <div class="h4 mb-0"><?= $fmt($balance['casual_remaining']) ?><span class="text-muted fs-6"> / <?= $fmt($balance['casual_total']) ?></span></div>
                <div class="small text-muted">days remaining</div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="card h-100" style="border-radius:12px;">
            <div class="card-body">
                <div class="text-muted small">Sick Leave (<?= $year ?>)</div>
                <div class="h4 mb-0"><?= $fmt($balance['sick_remaining']) ?><span class="text-muted fs-6"> / <?= $fmt($balance['sick_total']) ?></span></div>
                <div class="small text-muted">days remaining</div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="card h-100" style="border-radius:12px;">
            <div class="card-body">
                <div class="text-muted small">Awaiting My Approval</div>
                <div class="h4 mb-0"><?= count($pending_approvals) ?></div>
                <div class="small text-muted">request(s)</div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="card h-100" style="border-radius:12px;">
            <div class="card-body">
                <div class="text-muted small">My Requests</div>
                <div class="h4 mb-0"><?= count($my_requests) ?></div>
                <div class="small text-muted">total</div>
            </div>
        </div>
    </div>
</div>

<!-- ── Awaiting my approval ── -->
<?php if (!empty($pending_approvals)): ?>
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-header py-3 px-4"><h6 class="mb-0 fw-semibold"><i class="fas fa-inbox me-2 text-warning"></i>Awaiting My Approval</h6></div>
    <div class="card-body p-0"><?php lm_render_rows($pending_approvals, true, $fmt); ?></div>
</div>
<?php endif; ?>

<!-- ── Requests list ── -->
<div class="card" style="border-radius:12px;">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <ul class="nav nav-pills card-header-pills">
            <li class="nav-item"><a class="nav-link <?= $view === 'mine' ? 'active' : '' ?>" href="<?= APP_URL ?>/leave-management/index.php?view=mine">My Requests</a></li>
            <?php if ($is_admin): ?>
            <li class="nav-item"><a class="nav-link <?= $view === 'all' ? 'active' : '' ?>" href="<?= APP_URL ?>/leave-management/index.php?view=all">All Requests</a></li>
            <?php endif; ?>
        </ul>
        <form method="get" class="d-flex gap-2 align-items-center flex-wrap">
            <input type="hidden" name="view" value="<?= h($view) ?>">
            <select name="status" class="form-select form-select-sm" style="width:auto;" title="Filter by status" onchange="this.form.submit()">
                <option value="">All statuses</option>
                <?php foreach (['pending', 'approved', 'rejected', 'cancelled'] as $s): ?>
                <option value="<?= $s ?>" <?= $f_status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="d-flex align-items-center gap-1">
                <span class="text-muted small">From</span>
                <input type="date" name="from" class="form-control form-control-sm" style="width:auto;" value="<?= h($f_from) ?>" title="Leave date from" onchange="this.form.submit()">
            </div>
            <div class="d-flex align-items-center gap-1">
                <span class="text-muted small">To</span>
                <input type="date" name="to" class="form-control form-control-sm" style="width:auto;" value="<?= h($f_to) ?>" title="Leave date to" onchange="this.form.submit()">
            </div>
            <?php if ($has_filters): ?>
            <a href="<?= APP_URL ?>/leave-management/index.php?view=<?= h($view) ?>" class="btn btn-sm btn-outline-secondary" title="Clear filters" style="border-radius:7px;"><i class="fas fa-times me-1"></i>Clear</a>
            <?php endif; ?>
        </form>
    </div>
    <div class="card-body p-0">
        <?php lm_render_rows($view === 'all' ? $all_requests : $my_requests, $view === 'all', $fmt); ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
