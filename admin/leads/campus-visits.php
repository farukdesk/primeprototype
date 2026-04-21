<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('leads');
require_once __DIR__ . '/helpers.php';

$page_title = 'Campus Visits';
$user       = auth_user();
$is_staff   = leads_is_staff();

// ── Filters ────────────────────────────────────────────────────────────────────
$f_status   = $_GET['status']    ?? '';    // 'scheduled','completed','cancelled','no_show'
$f_date_from= trim($_GET['date_from'] ?? '');
$f_date_to  = trim($_GET['date_to']   ?? '');
$f_dept     = (int)($_GET['dept'] ?? 0);
$search     = trim($_GET['search'] ?? '');
$page       = max(1, (int)($_GET['page'] ?? 1));
$per_page   = 25;

$valid_appt_statuses = ['scheduled','completed','cancelled','no_show'];

$where  = [];
$params = [];

if ($f_status && in_array($f_status, $valid_appt_statuses, true)) {
    $where[]  = 'a.status = ?';
    $params[] = $f_status;
}
if ($f_date_from) {
    $where[]  = 'a.appointment_date >= ?';
    $params[] = $f_date_from;
}
if ($f_date_to) {
    $where[]  = 'a.appointment_date <= ?';
    $params[] = $f_date_to;
}
if ($f_dept > 0) {
    $where[]  = 'l.dept_id = ?';
    $params[] = $f_dept;
}
if ($search !== '') {
    $like     = '%' . $search . '%';
    $where[]  = '(l.first_name LIKE ? OR l.last_name LIKE ? OR l.phone LIKE ? OR l.email LIKE ?)';
    array_push($params, $like, $like, $like, $like);
}

$where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$base_sql = 'FROM lead_appointments a
             JOIN leads l ON l.id = a.lead_id
             LEFT JOIN dept_departments d ON d.id = l.dept_id
             LEFT JOIN users cb ON cb.id = a.created_by'
           . $where_sql;

$count_stmt = db()->prepare('SELECT COUNT(*) ' . $base_sql);
$count_stmt->execute($params);
$total_rows  = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$stmt = db()->prepare(
    'SELECT a.*,
            l.first_name, l.last_name, l.phone, l.email, l.lead_number, l.status AS lead_status, l.source,
            d.name AS dept_name,
            cb.full_name AS created_by_name
     ' . $base_sql . '
     ORDER BY a.appointment_date ASC, a.appointment_time ASC
     LIMIT ' . $per_page . ' OFFSET ' . $offset
);
$stmt->execute($params);
$visits = $stmt->fetchAll();

// ── Stats ─────────────────────────────────────────────────────────────────────
$stats_stmt = db()->query(
    "SELECT a.status, COUNT(*) AS cnt FROM lead_appointments a GROUP BY a.status"
);
$appt_stats = $stats_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$total_visits = array_sum($appt_stats);

$upcoming_count = (int)db()->query(
    "SELECT COUNT(*) FROM lead_appointments WHERE status='scheduled' AND appointment_date >= CURDATE()"
)->fetchColumn();
$today_count = (int)db()->query(
    "SELECT COUNT(*) FROM lead_appointments WHERE status='scheduled' AND appointment_date = CURDATE()"
)->fetchColumn();

// ── Departments for filter ────────────────────────────────────────────────────
$departments = db()->query(
    'SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC'
)->fetchAll();

// ── Build filter query string ─────────────────────────────────────────────────
$filter_qs = http_build_query(array_filter([
    'search'    => $search,
    'status'    => $f_status,
    'dept'      => $f_dept ?: '',
    'date_from' => $f_date_from,
    'date_to'   => $f_date_to,
]));

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-university me-2 text-info"></i>Campus Visits</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/leads/index.php">Leads</a></li>
            <li class="breadcrumb-item active">Campus Visits</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/leads/index.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back to Leads</a>
</div>

<?= flash_show() ?>

<!-- ── Stats cards ── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-3 p-2" style="background:#e3f2fd"><i class="fas fa-calendar-alt text-primary fa-lg"></i></div>
                    <div>
                        <div class="fw-bold fs-5"><?= number_format($total_visits) ?></div>
                        <div class="text-muted small">Total Visits</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-3 p-2" style="background:#fff3cd"><i class="fas fa-clock text-warning fa-lg"></i></div>
                    <div>
                        <div class="fw-bold fs-5"><?= number_format($upcoming_count) ?></div>
                        <div class="text-muted small">Upcoming</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-3 p-2" style="background:#e8f5e9"><i class="fas fa-check-circle text-success fa-lg"></i></div>
                    <div>
                        <div class="fw-bold fs-5"><?= number_format($appt_stats['completed'] ?? 0) ?></div>
                        <div class="text-muted small">Completed</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-3 p-2" style="background:#fce4ec"><i class="fas fa-calendar-day text-danger fa-lg"></i></div>
                    <div>
                        <div class="fw-bold fs-5"><?= number_format($today_count) ?></div>
                        <div class="text-muted small">Today</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Quick filter pills ── -->
<div class="d-flex flex-wrap gap-2 mb-3">
    <?php
    $qf_base = array_filter(['search' => $search, 'dept' => $f_dept ?: '', 'date_from' => $f_date_from, 'date_to' => $f_date_to]);
    $pill_appts = ['' => 'All', 'scheduled' => 'Scheduled', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'no_show' => 'No Show'];
    $pill_colors = ['' => 'secondary', 'scheduled' => 'primary', 'completed' => 'success', 'cancelled' => 'danger', 'no_show' => 'warning'];
    foreach ($pill_appts as $sv => $sl):
        $cnt = $sv === '' ? $total_visits : ($appt_stats[$sv] ?? 0);
        $is_act = $f_status === $sv;
        $col = $pill_colors[$sv] ?? 'secondary';
        $url = '?' . http_build_query(array_filter(array_merge($qf_base, ['status' => $sv])));
    ?>
    <a href="<?= $url ?>" class="btn btn-sm <?= $is_act ? 'btn-' . $col : 'btn-outline-' . $col ?>" <?= $is_act ? 'style="font-weight:600"' : '' ?>>
        <?= h($sl) ?> <span class="badge ms-1 <?= $is_act ? 'bg-white text-dark' : 'bg-secondary' ?>"><?= number_format($cnt) ?></span>
    </a>
    <?php endforeach; ?>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">

        <!-- Filter form -->
        <form method="get" class="row g-2 mb-3">
            <input type="hidden" name="status" value="<?= h($f_status) ?>">
            <div class="col-12 col-md-4">
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" name="search" class="form-control" placeholder="Search lead name, phone…" value="<?= h($search) ?>">
                </div>
            </div>
            <div class="col-6 col-md-2">
                <select name="dept" class="form-select form-select-sm">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $f_dept == $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= h($f_date_from) ?>" placeholder="From date" title="Visit date from">
            </div>
            <div class="col-6 col-md-2">
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= h($f_date_to) ?>" placeholder="To date" title="Visit date to">
            </div>
            <div class="col-auto d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search me-1"></i>Apply</button>
                <a href="<?= APP_URL ?>/leads/campus-visits.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>

        <?php if (empty($visits)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-university fa-3x mb-3 opacity-25"></i>
            <p class="mb-0">No campus visits found matching your criteria.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Lead</th>
                        <th>Contact</th>
                        <th>Department</th>
                        <th>Visit Date</th>
                        <th>Time</th>
                        <th>Purpose</th>
                        <th>Status</th>
                        <th>Notes</th>
                        <?php if ($is_staff): ?><th class="text-end">Update</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($visits as $v):
                        $visit_date = strtotime($v['appointment_date']);
                        $is_today   = date('Y-m-d', $visit_date) === date('Y-m-d');
                        $is_past    = $visit_date < strtotime('today') && $v['status'] === 'scheduled';
                    ?>
                    <tr class="<?= $is_today ? 'table-warning' : ($is_past ? 'table-danger' : '') ?>">
                        <td>
                            <a href="<?= APP_URL ?>/leads/view.php?id=<?= $v['lead_id'] ?>#appointments" class="fw-semibold text-decoration-none d-block">
                                <?= h($v['first_name'] . ' ' . $v['last_name']) ?>
                            </a>
                            <span class="text-muted small"><?= h($v['lead_number']) ?></span>
                            <span class="ms-1"><?= leads_status_badge($v['lead_status']) ?></span>
                        </td>
                        <td>
                            <div><?= h($v['phone']) ?></div>
                            <?php if ($v['email']): ?><div class="text-muted small"><?= h($v['email']) ?></div><?php endif; ?>
                        </td>
                        <td class="small"><?= h($v['dept_name'] ?? '–') ?></td>
                        <td>
                            <span class="fw-semibold <?= $is_today ? 'text-warning' : '' ?>">
                                <?= date('d M Y', $visit_date) ?>
                            </span>
                            <?php if ($is_today): ?><br><span class="badge bg-warning text-dark">Today</span><?php endif; ?>
                            <?php if ($is_past): ?><br><span class="badge bg-danger">Overdue</span><?php endif; ?>
                        </td>
                        <td class="small"><?= $v['appointment_time'] ? date('h:i A', strtotime($v['appointment_time'])) : '–' ?></td>
                        <td class="small"><?= h($v['purpose'] ?? '–') ?></td>
                        <td><?= leads_appt_status_badge($v['status']) ?></td>
                        <td class="small text-muted" style="max-width:120px">
                            <?= $v['notes'] ? h(mb_substr($v['notes'], 0, 60)) : '–' ?>
                        </td>
                        <?php if ($is_staff): ?>
                        <td class="text-end text-nowrap">
                            <a href="<?= APP_URL ?>/leads/view.php?id=<?= $v['lead_id'] ?>#appointments" class="btn btn-sm btn-outline-primary" title="View Lead">
                                <i class="fas fa-external-link-alt"></i>
                            </a>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
            <p class="text-muted small mb-0">Showing <?= number_format(($page-1)*$per_page+1) ?>–<?= number_format(min($page*$per_page,$total_rows)) ?> of <?= number_format($total_rows) ?> visit(s)</p>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
