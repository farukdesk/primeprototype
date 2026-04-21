<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('leads');
require_once __DIR__ . '/helpers.php';

$page_title = 'Call Logs';
$user       = auth_user();
$is_staff   = leads_is_staff();

// ── Filters ────────────────────────────────────────────────────────────────────
$f_user      = (int)($_GET['user_id']   ?? 0);
$f_call_type = trim($_GET['call_type']  ?? '');
$f_date_from = trim($_GET['date_from']  ?? '');
$f_date_to   = trim($_GET['date_to']    ?? '');
$f_converted = $_GET['converted']       ?? '';
$search      = trim($_GET['search']     ?? '');
$page        = max(1, (int)($_GET['page'] ?? 1));
$per_page    = 25;

$valid_call_types = ['1st_call','2nd_call','3rd_call','followup_call','visit_confirmation','final_call'];

// ── Check table exists ─────────────────────────────────────────────────────────
$table_exists = false;
try {
    db()->query('SELECT 1 FROM lead_call_logs LIMIT 1');
    $table_exists = true;
} catch (Exception $e) {}

$call_logs   = [];
$total_rows  = 0;
$total_pages = 1;
$staff_stats = [];
$total_calls = 0;
$converted_calls = 0;

if ($table_exists) {
    $where  = [];
    $params = [];

    if ($f_user > 0) {
        $where[]  = 'cl.user_id = ?';
        $params[] = $f_user;
    }
    if ($f_call_type && in_array($f_call_type, $valid_call_types, true)) {
        $where[]  = 'cl.call_type = ?';
        $params[] = $f_call_type;
    }
    if ($f_date_from) {
        $where[]  = 'DATE(cl.call_date) >= ?';
        $params[] = $f_date_from;
    }
    if ($f_date_to) {
        $where[]  = 'DATE(cl.call_date) <= ?';
        $params[] = $f_date_to;
    }
    if ($f_converted === '1') {
        $where[] = 'cl.call_converted = 1';
    }
    if ($search !== '') {
        $like     = '%' . $search . '%';
        $where[]  = '(l.first_name LIKE ? OR l.last_name LIKE ? OR l.phone LIKE ? OR l.email LIKE ? OR cl.call_notes LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like);
    }

    $where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $base_sql  = 'FROM lead_call_logs cl
                  JOIN leads l ON l.id = cl.lead_id
                  LEFT JOIN users u ON u.id = cl.user_id'
                . $where_sql;

    $count_stmt = db()->prepare('SELECT COUNT(*) ' . $base_sql);
    $count_stmt->execute($params);
    $total_rows  = (int)$count_stmt->fetchColumn();
    $total_pages = max(1, (int)ceil($total_rows / $per_page));
    $page        = min($page, $total_pages);
    $offset      = ($page - 1) * $per_page;

    $stmt = db()->prepare(
        'SELECT cl.*,
                l.first_name, l.last_name, l.phone, l.lead_number, l.status AS lead_status,
                u.full_name AS caller_name
         ' . $base_sql . '
         ORDER BY cl.call_date DESC
         LIMIT ' . $per_page . ' OFFSET ' . $offset
    );
    $stmt->execute($params);
    $call_logs = $stmt->fetchAll();

    // ── Per-staff summary ────────────────────────────────────────────────────
    $staff_stats = db()->query(
        'SELECT u.full_name, u.id AS uid, COUNT(*) AS total_calls,
                SUM(cl.call_converted) AS converted,
                SUM(cl.call_duration) AS total_minutes
         FROM lead_call_logs cl
         LEFT JOIN users u ON u.id = cl.user_id
         GROUP BY cl.user_id
         ORDER BY total_calls DESC'
    )->fetchAll();

    $total_calls    = (int)db()->query('SELECT COUNT(*) FROM lead_call_logs')->fetchColumn();
    $converted_calls= (int)db()->query('SELECT COUNT(*) FROM lead_call_logs WHERE call_converted = 1')->fetchColumn();
}

$staff_users = db()->query(
    "SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name"
)->fetchAll();

$filter_qs = http_build_query(array_filter([
    'search'    => $search,
    'user_id'   => $f_user ?: '',
    'call_type' => $f_call_type,
    'date_from' => $f_date_from,
    'date_to'   => $f_date_to,
    'converted' => $f_converted,
]));

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-phone-alt me-2 text-success"></i>Call Logs</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/leads/index.php">Leads</a></li>
            <li class="breadcrumb-item active">Call Logs</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/leads/index.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back to Leads</a>
</div>

<?= flash_show() ?>

<?php if (!$table_exists): ?>
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle me-2"></i>
    <strong>Table not found.</strong> Please run <code>admin/leads/call-logs.sql</code> to create the <code>lead_call_logs</code> table.
</div>
<?php else: ?>

<!-- ── Stats cards ── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-3 p-2" style="background:#e8f5e9"><i class="fas fa-phone-alt text-success fa-lg"></i></div>
                    <div>
                        <div class="fw-bold fs-5"><?= number_format($total_calls) ?></div>
                        <div class="text-muted small">Total Calls</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-3 p-2" style="background:#f3e5f5"><i class="fas fa-check-double fa-lg" style="color:#6f42c1"></i></div>
                    <div>
                        <div class="fw-bold fs-5"><?= number_format($converted_calls) ?></div>
                        <div class="text-muted small">Converted</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-3 p-2" style="background:#e3f2fd"><i class="fas fa-users text-primary fa-lg"></i></div>
                    <div>
                        <div class="fw-bold fs-5"><?= number_format(count($staff_stats)) ?></div>
                        <div class="text-muted small">Active Staff</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-3 p-2" style="background:#fff3cd"><i class="fas fa-percentage text-warning fa-lg"></i></div>
                    <div>
                        <div class="fw-bold fs-5"><?= $total_calls > 0 ? round($converted_calls / $total_calls * 100, 1) . '%' : '0%' ?></div>
                        <div class="text-muted small">Conversion Rate</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($staff_stats): ?>
<!-- ── Staff summary table ── -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold"><i class="fas fa-chart-bar me-2 text-primary"></i>Staff Performance</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Staff Member</th>
                        <th class="text-center">Total Calls</th>
                        <th class="text-center">Converted</th>
                        <th class="text-center">Conv. Rate</th>
                        <th class="text-center">Total Time</th>
                        <th class="text-end">Filter</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($staff_stats as $ss): ?>
                    <tr>
                        <td class="fw-semibold"><?= h($ss['full_name'] ?? 'Unknown') ?></td>
                        <td class="text-center"><span class="badge bg-primary"><?= number_format($ss['total_calls']) ?></span></td>
                        <td class="text-center"><span class="badge bg-success"><?= number_format($ss['converted'] ?? 0) ?></span></td>
                        <td class="text-center">
                            <?php $cr = $ss['total_calls'] > 0 ? round($ss['converted'] / $ss['total_calls'] * 100, 1) : 0; ?>
                            <span class="badge <?= $cr >= 30 ? 'bg-success' : ($cr >= 10 ? 'bg-warning text-dark' : 'bg-secondary') ?>"><?= $cr ?>%</span>
                        </td>
                        <td class="text-center small text-muted"><?= $ss['total_minutes'] ? number_format($ss['total_minutes']) . ' min' : '–' ?></td>
                        <td class="text-end">
                            <a href="?user_id=<?= $ss['uid'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2 <?= $f_user == $ss['uid'] ? 'active' : '' ?>">
                                <i class="fas fa-filter"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body">

        <!-- Filter form -->
        <form method="get" class="row g-2 mb-3">
            <div class="col-12 col-md-3">
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" name="search" class="form-control" placeholder="Search lead name, phone, notes…" value="<?= h($search) ?>">
                </div>
            </div>
            <div class="col-6 col-md-2">
                <select name="user_id" class="form-select form-select-sm">
                    <option value="">All Staff</option>
                    <?php foreach ($staff_users as $su): ?>
                    <option value="<?= $su['id'] ?>" <?= $f_user == $su['id'] ? 'selected' : '' ?>><?= h($su['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="call_type" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    <option value="1st_call"          <?= $f_call_type === '1st_call'          ? 'selected' : '' ?>>1st Call</option>
                    <option value="2nd_call"          <?= $f_call_type === '2nd_call'          ? 'selected' : '' ?>>2nd Call</option>
                    <option value="3rd_call"          <?= $f_call_type === '3rd_call'          ? 'selected' : '' ?>>3rd Call</option>
                    <option value="followup_call"     <?= $f_call_type === 'followup_call'     ? 'selected' : '' ?>>Follow-up</option>
                    <option value="visit_confirmation"<?= $f_call_type === 'visit_confirmation'? 'selected' : '' ?>>Visit Confirm</option>
                    <option value="final_call"        <?= $f_call_type === 'final_call'        ? 'selected' : '' ?>>Final Call</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= h($f_date_from) ?>" title="From date">
            </div>
            <div class="col-6 col-md-2">
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= h($f_date_to) ?>" title="To date">
            </div>
            <div class="col-auto d-flex gap-1 align-items-center">
                <div class="form-check form-check-inline mb-0">
                    <input class="form-check-input" type="checkbox" name="converted" value="1" id="fc_converted" <?= $f_converted === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label small" for="fc_converted">Converted only</label>
                </div>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search me-1"></i>Apply</button>
                <a href="<?= APP_URL ?>/leads/call-logs.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>

        <?php if (empty($call_logs)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-phone-slash fa-3x mb-3 opacity-25"></i>
            <p class="mb-0">No call logs found matching your criteria.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Lead</th>
                        <th>Call Date/Time</th>
                        <th>Type</th>
                        <th>By</th>
                        <th>Status Change</th>
                        <th>Duration</th>
                        <th>Outcome</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $ct_labels = [
                        '1st_call' => '1st Call', '2nd_call' => '2nd Call', '3rd_call' => '3rd Call',
                        'followup_call' => 'Follow-up', 'visit_confirmation' => 'Visit Confirm', 'final_call' => 'Final Call',
                    ];
                    foreach ($call_logs as $cl):
                    ?>
                    <tr>
                        <td>
                            <a href="<?= APP_URL ?>/leads/view.php?id=<?= $cl['lead_id'] ?>#call-logs" class="fw-semibold text-decoration-none d-block">
                                <?= h($cl['first_name'] . ' ' . $cl['last_name']) ?>
                            </a>
                            <span class="text-muted small"><?= h($cl['lead_number']) ?></span>
                            <span class="ms-1"><?= leads_status_badge($cl['lead_status']) ?></span>
                        </td>
                        <td class="small"><?= date('d M Y, h:i A', strtotime($cl['call_date'])) ?></td>
                        <td><span class="badge bg-info text-dark"><?= h($ct_labels[$cl['call_type']] ?? $cl['call_type']) ?></span></td>
                        <td class="small"><?= h($cl['caller_name'] ?? '–') ?></td>
                        <td class="small">
                            <?php if ($cl['previous_status'] && $cl['previous_status'] !== $cl['new_status']): ?>
                            <?= leads_status_badge($cl['previous_status']) ?> → <?= leads_status_badge($cl['new_status']) ?>
                            <?php else: ?>
                            <span class="text-muted">–</span>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= $cl['call_duration'] ? $cl['call_duration'] . ' min' : '–' ?></td>
                        <td class="small"><?= h($cl['call_outcome'] ?? '–') ?></td>
                        <td class="small text-muted" style="max-width:150px">
                            <?= $cl['call_notes'] ? h(mb_substr($cl['call_notes'], 0, 80)) : '–' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
            <p class="text-muted small mb-0">Showing <?= number_format(($page-1)*$per_page+1) ?>–<?= number_format(min($page*$per_page,$total_rows)) ?> of <?= number_format($total_rows) ?> log(s)</p>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php if ($page > 1): ?>
                    <li class="page-item"><a class="page-link" href="?<?= $filter_qs ?>&page=<?= $page-1 ?>"><i class="fas fa-chevron-left"></i></a></li>
                    <?php endif; ?>
                    <?php for ($p = 1; $p <= $total_pages; $p++):
                        if ($p === 1 || $p === $total_pages || abs($p - $page) <= 2): ?>
                    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                        <a class="page-link" href="?<?= $filter_qs ?>&page=<?= $p ?>"><?= $p ?></a>
                    </li>
                    <?php elseif (abs($p - $page) === 3): ?>
                    <li class="page-item disabled"><span class="page-link">…</span></li>
                    <?php endif; endfor; ?>
                    <?php if ($page < $total_pages): ?>
                    <li class="page-item"><a class="page-link" href="?<?= $filter_qs ?>&page=<?= $page+1 ?>"><i class="fas fa-chevron-right"></i></a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php endif; // table_exists ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
