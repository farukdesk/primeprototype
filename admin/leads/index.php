<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('leads');
require_once __DIR__ . '/helpers.php';

$page_title = 'Lead Management';
$user       = auth_user();
$is_staff   = leads_is_staff();

// ── Filters ───────────────────────────────────────────────────────────────────
$search    = trim($_GET['search']   ?? '');
$f_status  = $_GET['status']        ?? '';
$f_source  = $_GET['source']        ?? '';
$f_dept    = (int)($_GET['dept']    ?? 0);
$f_sem     = trim($_GET['semester'] ?? '');
$f_degree  = $_GET['degree']        ?? '';
$f_user    = (int)($_GET['user_id'] ?? 0);
$f_sort    = $_GET['sort']          ?? 'date_desc';
$f_followup= $_GET['followup']      ?? '';   // 'today' | 'overdue'
$page      = max(1, (int)($_GET['page'] ?? 1));
$per_page  = 20;

// Date range filter (created vs updated)
$today_str   = date('Y-m-d');
$f_datefield = ($_GET['date_field'] ?? 'created') === 'updated' ? 'updated' : 'created';
$f_from      = trim($_GET['date_from'] ?? '');
$f_to        = trim($_GET['date_to']   ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_from)) $f_from = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_to))   $f_to   = '';
if ($f_from !== '' && $f_to !== '' && $f_from > $f_to) { [$f_from, $f_to] = [$f_to, $f_from]; }

$valid_statuses = array_keys(leads_all_statuses());
$valid_sources  = ['online', 'campus_visit', 'agent', 'f2f_marketing', 'facebook'];
$valid_sorts    = ['date_desc','date_asc','updated_desc','name_asc','name_desc','status_asc','followup_asc'];

$where  = [];
$params = [];

if ($search !== '') {
    $like = '%' . $search . '%';
    $where[] = '(l.lead_number LIKE ? OR l.first_name LIKE ? OR l.last_name LIKE ? OR l.email LIKE ? OR l.phone LIKE ? OR l.current_city LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like, $like);
}
if (in_array($f_status, $valid_statuses, true)) {
    $where[]  = 'l.status = ?';
    $params[] = $f_status;
}
if (in_array($f_source, $valid_sources, true)) {
    $where[]  = 'l.source = ?';
    $params[] = $f_source;
}
if ($f_dept > 0) {
    $where[]  = 'l.dept_id = ?';
    $params[] = $f_dept;
}
if ($f_sem !== '') {
    $where[]  = 'l.preferred_semester = ?';
    $params[] = $f_sem;
}
if (in_array($f_degree, ['bachelor', 'master'], true)) {
    $where[]  = 'l.degree_type = ?';
    $params[] = $f_degree;
}
if ($f_user > 0) {
    $where[]  = 'EXISTS (SELECT 1 FROM lead_assignments la WHERE la.lead_id = l.id AND la.user_id = ?)';
    $params[] = $f_user;
}
if ($f_followup === 'today') {
    $where[] = 'l.next_followup_date = CURDATE()';
}
if ($f_followup === 'overdue') {
    $where[] = "(l.next_followup_date < CURDATE() AND l.status NOT IN ('converted','not_interested'))";
}
$date_col = $f_datefield === 'updated' ? 'l.updated_at' : 'l.created_at';
if ($f_from !== '') {
    $where[]  = 'DATE(' . $date_col . ') >= ?';
    $params[] = $f_from;
}
if ($f_to !== '') {
    $where[]  = 'DATE(' . $date_col . ') <= ?';
    $params[] = $f_to;
}

$where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$sort_sql = match (in_array($f_sort, $valid_sorts, true) ? $f_sort : 'date_desc') {
    'date_asc'    => 'l.created_at ASC',
    'updated_desc'=> 'l.updated_at DESC',
    'name_asc'    => 'l.first_name ASC, l.last_name ASC',
    'name_desc'   => 'l.first_name DESC, l.last_name DESC',
    'status_asc'  => 'l.status ASC',
    'followup_asc'=> 'CASE WHEN l.next_followup_date IS NULL THEN 1 ELSE 0 END, l.next_followup_date ASC',
    default       => 'l.created_at DESC',
};

$count_stmt = db()->prepare('SELECT COUNT(*) FROM leads l' . $where_sql);
$count_stmt->execute($params);
$total_rows  = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$sql = 'SELECT l.*,
               d.name         AS dept_name,
               p.program_name,
               u.full_name    AS assigned_to_name
        FROM leads l
        LEFT JOIN dept_departments d       ON d.id = l.dept_id
        LEFT JOIN dept_academic_programs p ON p.id = l.program_id
        LEFT JOIN users u                  ON u.id = l.assigned_to'
     . $where_sql
     . ' ORDER BY ' . $sort_sql
     . ' LIMIT ' . $per_page . ' OFFSET ' . $offset;

$stmt = db()->prepare($sql);
$stmt->execute($params);
$leads = $stmt->fetchAll();

// ── Stats ─────────────────────────────────────────────────────────────────────
$status_stats = db()->query(
    "SELECT status, COUNT(*) AS cnt FROM leads GROUP BY status"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$source_stats = db()->query(
    "SELECT source, COUNT(*) AS cnt FROM leads GROUP BY source"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$total_leads  = array_sum($status_stats);

// Follow-up counts
$today_count   = (int)db()->query("SELECT COUNT(*) FROM leads WHERE next_followup_date = CURDATE()")->fetchColumn();
$overdue_count = (int)db()->query("SELECT COUNT(*) FROM leads WHERE next_followup_date < CURDATE() AND status NOT IN ('converted','not_interested')")->fetchColumn();

// Today's activity counts
$created_today = (int)db()->query("SELECT COUNT(*) FROM leads WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$updated_today = (int)db()->query("SELECT COUNT(*) FROM leads WHERE DATE(updated_at) = CURDATE()")->fetchColumn();

// ── Departments & users for filters ──────────────────────────────────────────
$departments = db()->query(
    'SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC'
)->fetchAll();
$staff_users = db()->query(
    "SELECT u.id, u.full_name FROM users u WHERE u.is_active = 1 ORDER BY u.full_name"
)->fetchAll();
$semesters = leads_semester_list();

// ── Build filter query string (for pagination links) ─────────────────────────
$filter_qs = http_build_query(array_filter([
    'search'   => $search,
    'status'   => $f_status,
    'source'   => $f_source,
    'dept'     => $f_dept ?: '',
    'semester' => $f_sem,
    'degree'   => $f_degree,
    'user_id'  => $f_user ?: '',
    'sort'     => $f_sort !== 'date_desc' ? $f_sort : '',
    'followup' => $f_followup,
    'date_field' => $f_datefield === 'updated' ? 'updated' : '',
    'date_from'  => $f_from,
    'date_to'    => $f_to,
]));
$print_url = APP_URL . '/leads/print.php' . ($filter_qs !== '' ? '?' . $filter_qs : '');

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-funnel-dollar me-2 text-primary"></i>Lead Management</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small"><li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li><li class="breadcrumb-item active">Leads</li></ol></nav>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= $print_url ?>" class="btn btn-outline-dark btn-sm" target="_blank" rel="noopener">
            <i class="fas fa-print me-1"></i> Print
        </a>
        <?php if (leads_can_create()): ?>
        <a href="<?= APP_URL ?>/leads/fb-settings.php" class="btn btn-outline-secondary btn-sm" title="Facebook Messenger Settings">
            <i class="fab fa-facebook-messenger me-1"></i> FB Settings
        </a>
        <a href="<?= APP_URL ?>/leads/fb-inbox.php" class="btn btn-outline-primary btn-sm">
            <i class="fab fa-facebook-messenger me-1"></i> FB Inbox
        </a>
        <a href="<?= APP_URL ?>/leads/create.php" class="btn btn-primary btn-sm">
            <i class="fas fa-plus me-1"></i> Add Lead
        </a>
        <?php endif; ?>
    </div>
</div>

<?= flash_show() ?>

<!-- ── Stats cards (clickable quick filters) ── -->
<style>
.lead-stat-card{transition:transform .12s ease,box-shadow .12s ease}
.lead-stat-card:hover{transform:translateY(-2px);box-shadow:0 .5rem 1rem rgba(0,0,0,.12)!important}
</style>
<div class="row g-3 mb-3">
    <div class="col-6 col-md-2">
        <a href="<?= APP_URL ?>/leads/index.php" class="card border-0 shadow-sm h-100 text-decoration-none lead-stat-card">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-3 p-2" style="background:#e3f2fd"><i class="fas fa-users text-primary fa-lg"></i></div>
                    <div>
                        <div class="fw-bold fs-5 text-body"><?= number_format($total_leads) ?></div>
                        <div class="text-muted small">Total Leads</div>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-2">
        <a href="?status=fresh" class="card border-0 shadow-sm h-100 text-decoration-none lead-stat-card">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-3 p-2" style="background:#e8f5e9"><i class="fas fa-seedling text-success fa-lg"></i></div>
                    <div>
                        <div class="fw-bold fs-5 text-body"><?= number_format($status_stats['fresh'] ?? 0) ?></div>
                        <div class="text-muted small">Fresh Leads</div>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-2">
        <a href="?date_field=created&date_from=<?= $today_str ?>&date_to=<?= $today_str ?>" class="card border-0 shadow-sm h-100 text-decoration-none lead-stat-card">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-3 p-2" style="background:#fff8e1"><i class="fas fa-calendar-plus text-warning fa-lg"></i></div>
                    <div>
                        <div class="fw-bold fs-5 text-body"><?= number_format($created_today) ?></div>
                        <div class="text-muted small">New Today</div>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-2">
        <a href="?date_field=updated&date_from=<?= $today_str ?>&date_to=<?= $today_str ?>" class="card border-0 shadow-sm h-100 text-decoration-none lead-stat-card">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-3 p-2" style="background:#e0f7fa"><i class="fas fa-pen text-info fa-lg"></i></div>
                    <div>
                        <div class="fw-bold fs-5 text-body"><?= number_format($updated_today) ?></div>
                        <div class="text-muted small">Updated Today</div>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-2">
        <a href="?followup=overdue" class="card border-0 shadow-sm h-100 text-decoration-none lead-stat-card">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-3 p-2" style="background:#fce4ec"><i class="fas fa-bell text-danger fa-lg"></i></div>
                    <div>
                        <div class="fw-bold fs-5 text-body"><?= number_format($overdue_count) ?></div>
                        <div class="text-muted small">Overdue Follow-ups</div>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-2">
        <a href="?status=converted" class="card border-0 shadow-sm h-100 text-decoration-none lead-stat-card">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-3 p-2" style="background:#f3e5f5"><i class="fas fa-check-circle fa-lg" style="color:#6f42c1"></i></div>
                    <div>
                        <div class="fw-bold fs-5 text-body"><?= number_format($status_stats['converted'] ?? 0) ?></div>
                        <div class="text-muted small">Converted</div>
                    </div>
                </div>
            </div>
        </a>
    </div>
</div>

<!-- ── Quick date & follow-up filter buttons ── -->
<?php
$is_created_today = $f_datefield === 'created' && $f_from === $today_str && $f_to === $today_str;
$is_updated_today = $f_datefield === 'updated' && $f_from === $today_str && $f_to === $today_str;
?>
<div class="d-flex flex-wrap gap-2 mb-3">
    <a href="?date_field=created&date_from=<?= $today_str ?>&date_to=<?= $today_str ?>" class="btn btn-sm <?= $is_created_today ? 'btn-success' : 'btn-outline-success' ?>">
        <i class="fas fa-calendar-plus me-1"></i> Created Today
        <span class="badge <?= $is_created_today ? 'bg-white text-success' : 'bg-success' ?> ms-1"><?= $created_today ?></span>
    </a>
    <a href="?date_field=updated&date_from=<?= $today_str ?>&date_to=<?= $today_str ?>" class="btn btn-sm <?= $is_updated_today ? 'btn-info' : 'btn-outline-info' ?>">
        <i class="fas fa-pen me-1"></i> Updated Today
        <span class="badge <?= $is_updated_today ? 'bg-white text-info' : 'bg-info' ?> ms-1"><?= $updated_today ?></span>
    </a>
    <?php if ($today_count > 0): ?>
    <a href="?followup=today" class="btn btn-sm <?= $f_followup === 'today' ? 'btn-primary' : 'btn-outline-primary' ?>">
        <i class="fas fa-calendar-check me-1"></i> Today's Follow-ups
        <span class="badge <?= $f_followup === 'today' ? 'bg-white text-primary' : 'bg-primary' ?> ms-1"><?= $today_count ?></span>
    </a>
    <?php endif; ?>
    <?php if ($overdue_count > 0): ?>
    <a href="?followup=overdue" class="btn btn-sm <?= $f_followup === 'overdue' ? 'btn-danger' : 'btn-outline-danger' ?>">
        <i class="fas fa-bell me-1"></i> Overdue Follow-ups
        <span class="badge <?= $f_followup === 'overdue' ? 'bg-white text-danger' : 'bg-danger' ?> ms-1"><?= $overdue_count ?></span>
    </a>
    <?php endif; ?>
    <?php if ($f_followup || $f_from !== '' || $f_to !== ''): ?>
    <a href="<?= APP_URL ?>/leads/index.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-times me-1"></i> Clear</a>
    <?php endif; ?>
</div>

<!-- ── Quick navigation links ── -->
<div class="d-flex flex-wrap gap-2 mb-3">
    <a href="<?= APP_URL ?>/leads/campus-visits.php" class="btn btn-sm btn-outline-info">
        <i class="fas fa-university me-1"></i> Campus Visits
    </a>
    <a href="<?= APP_URL ?>/leads/call-logs.php" class="btn btn-sm btn-outline-secondary">
        <i class="fas fa-phone-alt me-1"></i> Call Logs
    </a>
</div>

<!-- ── Filter & list card ── -->
<div class="card border-0 shadow-sm">
    <div class="card-body">

        <!-- ── Filter panel ── -->
        <div class="mb-3">

            <!-- Row 1: Search + dept/degree/semester/assignee · Row 2: date range + sort -->
            <form method="get" class="mb-2" id="leads-filter-form">
                <input type="hidden" name="status" value="<?= h($f_status) ?>">
                <input type="hidden" name="source" value="<?= h($f_source) ?>">
                <?php if ($f_followup): ?><input type="hidden" name="followup" value="<?= h($f_followup) ?>"><?php endif; ?>
                <div class="row g-2 mb-2">
                    <div class="col-12 col-md-4">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                            <input type="text" name="search" class="form-control" placeholder="Search name, phone, email, city…" value="<?= h($search) ?>">
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <select name="dept" class="form-select form-select-sm" title="Department">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $d): ?>
                            <option value="<?= $d['id'] ?>" <?= $f_dept == $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <select name="degree" class="form-select form-select-sm" title="Degree">
                            <option value="">All Degrees</option>
                            <option value="bachelor" <?= $f_degree === 'bachelor' ? 'selected' : '' ?>>Bachelor</option>
                            <option value="master"   <?= $f_degree === 'master'   ? 'selected' : '' ?>>Master</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <select name="semester" class="form-select form-select-sm" title="Semester">
                            <option value="">All Semesters</option>
                            <?php foreach ($semesters as $sem): ?>
                            <option value="<?= h($sem) ?>" <?= $f_sem === $sem ? 'selected' : '' ?>><?= h($sem) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <select name="user_id" class="form-select form-select-sm" title="Assignee">
                            <option value="">All Assignees</option>
                            <?php foreach ($staff_users as $su): ?>
                            <option value="<?= $su['id'] ?>" <?= $f_user == $su['id'] ? 'selected' : '' ?>><?= h($su['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row g-2 align-items-center">
                    <div class="col-6 col-md-2">
                        <select name="date_field" class="form-select form-select-sm" data-no-autosubmit="1" title="Filter dates by">
                            <option value="created" <?= $f_datefield === 'created' ? 'selected' : '' ?>>Created Date</option>
                            <option value="updated" <?= $f_datefield === 'updated' ? 'selected' : '' ?>>Updated Date</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <input type="date" name="date_from" class="form-control form-control-sm" value="<?= h($f_from) ?>" title="From date">
                    </div>
                    <div class="col-6 col-md-2">
                        <input type="date" name="date_to" class="form-control form-control-sm" value="<?= h($f_to) ?>" title="To date">
                    </div>
                    <div class="col-6 col-md-2">
                        <select id="date-preset" class="form-select form-select-sm" data-no-autosubmit="1" title="Quick date range">
                            <option value="">Quick Range…</option>
                            <option value="today">Today</option>
                            <option value="yesterday">Yesterday</option>
                            <option value="7">Last 7 Days</option>
                            <option value="30">Last 30 Days</option>
                            <option value="month">This Month</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <select name="sort" class="form-select form-select-sm" title="Sort by">
                            <option value="date_desc"    <?= $f_sort === 'date_desc'    ? 'selected' : '' ?>>Date: Newest</option>
                            <option value="date_asc"     <?= $f_sort === 'date_asc'     ? 'selected' : '' ?>>Date: Oldest</option>
                            <option value="updated_desc" <?= $f_sort === 'updated_desc' ? 'selected' : '' ?>>Recently Updated</option>
                            <option value="name_asc"     <?= $f_sort === 'name_asc'     ? 'selected' : '' ?>>Name: A–Z</option>
                            <option value="name_desc"    <?= $f_sort === 'name_desc'    ? 'selected' : '' ?>>Name: Z–A</option>
                            <option value="status_asc"   <?= $f_sort === 'status_asc'   ? 'selected' : '' ?>>By Status</option>
                            <option value="followup_asc" <?= $f_sort === 'followup_asc' ? 'selected' : '' ?>>Follow-up Date</option>
                        </select>
                    </div>
                    <div class="col-auto d-flex gap-1">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search me-1"></i>Apply</button>
                        <a href="<?= APP_URL ?>/leads/index.php" class="btn btn-outline-secondary btn-sm">Reset</a>
                    </div>
                </div>
            </form>
            <?php if ($f_from !== '' || $f_to !== ''): ?>
            <div class="small text-muted mb-2">
                <i class="fas fa-filter me-1"></i>Showing leads <?= $f_datefield === 'updated' ? 'updated' : 'created' ?>
                <?php if ($f_from !== '' && $f_to !== ''): ?>
                    <?= $f_from === $f_to ? 'on <strong>' . h(date('d M Y', strtotime($f_from))) . '</strong>' : 'between <strong>' . h(date('d M Y', strtotime($f_from))) . '</strong> and <strong>' . h(date('d M Y', strtotime($f_to))) . '</strong>' ?>
                <?php elseif ($f_from !== ''): ?>
                    from <strong><?= h(date('d M Y', strtotime($f_from))) ?></strong>
                <?php else: ?>
                    up to <strong><?= h(date('d M Y', strtotime($f_to))) ?></strong>
                <?php endif; ?>
                · <strong><?= number_format($total_rows) ?></strong> result(s)
                <a href="<?= APP_URL ?>/leads/index.php" class="ms-1">Clear</a>
            </div>
            <?php endif; ?>

            <!-- Row 2: Status filter pills (link-based, preserve other filters) -->
            <div class="d-flex flex-wrap gap-1 align-items-center mb-2">
                <span class="text-muted small fw-semibold me-1">Status:</span>
                <?php
                $base_qs_status = array_filter([
                    'search'   => $search,
                    'source'   => $f_source,
                    'dept'     => $f_dept ?: '',
                    'semester' => $f_sem,
                    'degree'   => $f_degree,
                    'user_id'  => $f_user ?: '',
                    'followup' => $f_followup,
                    'sort'     => $f_sort !== 'date_desc' ? $f_sort : '',
                    'date_field' => $f_datefield === 'updated' ? 'updated' : '',
                    'date_from'  => $f_from,
                    'date_to'    => $f_to,
                ]);
                $pill_statuses = ['' => 'All'] + leads_all_statuses();
                $status_colors = [
                    ''               => ['secondary',  ''],
                    'fresh'          => ['success',    ''],
                    '1st_call'       => ['info',       ''],
                    '2nd_call'       => ['info',       ''],
                    '3rd_call'       => ['primary',    ''],
                    'unable_to_reach'=> ['warning',    ''],
                    'interested'     => ['secondary',  '#20c997'],
                    'not_interested' => ['danger',     ''],
                    'will_visit'     => ['secondary',  '#6f42c1'],
                    'converted'      => ['dark',       ''],
                ];
                foreach ($pill_statuses as $sv => $sl):
                    $cnt      = $sv === '' ? $total_leads : ($status_stats[$sv] ?? 0);
                    $is_act   = $f_status === $sv;
                    [$col, $custom] = $status_colors[$sv] ?? ['secondary', ''];
                    $pill_url = '?' . http_build_query(array_filter(array_merge($base_qs_status, ['status' => $sv])));
                    if ($custom) {
                        $ps = $is_act
                            ? 'background:' . $custom . ';border-color:' . $custom . ';color:#fff;font-weight:600'
                            : 'border-color:' . $custom . ';color:' . $custom;
                        $pc = '';
                    } else {
                        $ps = $is_act ? 'font-weight:600' : '';
                        $pc = $is_act ? 'btn-' . $col : 'btn-outline-' . $col;
                    }
                ?>
                <a href="<?= $pill_url ?>" class="btn btn-sm <?= $pc ?>" style="<?= $ps ?>">
                    <?= h($sl) ?> <span class="badge ms-1 <?= $is_act ? 'bg-white text-dark' : 'bg-secondary' ?>"><?= number_format($cnt) ?></span>
                </a>
                <?php endforeach; ?>
            </div>

            <!-- Row 3: Source filter pills (link-based, preserve other filters) -->
            <div class="d-flex flex-wrap gap-1 align-items-center">
                <span class="text-muted small fw-semibold me-1">Source:</span>
                <?php
                $base_qs_source = array_filter([
                    'search'   => $search,
                    'status'   => $f_status,
                    'dept'     => $f_dept ?: '',
                    'semester' => $f_sem,
                    'degree'   => $f_degree,
                    'user_id'  => $f_user ?: '',
                    'followup' => $f_followup,
                    'sort'     => $f_sort !== 'date_desc' ? $f_sort : '',
                    'date_field' => $f_datefield === 'updated' ? 'updated' : '',
                    'date_from'  => $f_from,
                    'date_to'    => $f_to,
                ]);
                $pill_sources = ['' => 'All', 'online' => 'Online', 'campus_visit' => 'Campus Visit', 'agent' => 'Promoter', 'f2f_marketing' => 'F2F Marketing', 'facebook' => 'Facebook'];
                $source_colors = ['' => ['secondary',''], 'online' => ['info',''], 'campus_visit' => ['secondary',''], 'agent' => ['dark',''], 'f2f_marketing' => ['warning',''], 'facebook' => ['secondary','#1877F2']];
                foreach ($pill_sources as $sv => $sl):
                    $cnt    = $sv === '' ? $total_leads : ($source_stats[$sv] ?? 0);
                    $is_act = $f_source === $sv;
                    [$sc, $custom_sc] = $source_colors[$sv] ?? ['secondary',''];
                    $src_url = '?' . http_build_query(array_filter(array_merge($base_qs_source, ['source' => $sv])));
                    if ($custom_sc) {
                        $ss = $is_act ? 'background:' . $custom_sc . ';border-color:' . $custom_sc . ';color:#fff;font-weight:600' : 'border-color:' . $custom_sc . ';color:' . $custom_sc;
                        $sc_cls = '';
                    } else {
                        $ss = $is_act ? 'font-weight:600' : '';
                        $sc_cls = $is_act ? 'btn-' . $sc : 'btn-outline-' . $sc;
                    }
                ?>
                <a href="<?= $src_url ?>" class="btn btn-sm <?= $sc_cls ?>" style="<?= $ss ?>">
                    <?php if ($sv === 'facebook'): ?><i class="fab fa-facebook-messenger me-1"></i><?php endif; ?>
                    <?= h($sl) ?> <span class="badge ms-1 <?= $is_act ? 'bg-white text-dark' : 'bg-secondary' ?>"><?= number_format($cnt) ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if (empty($leads)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-funnel-dollar fa-3x mb-3 opacity-25"></i>
            <p class="mb-0">No leads found matching your criteria.</p>
            <?php if (leads_can_create()): ?>
            <a href="<?= APP_URL ?>/leads/create.php" class="btn btn-primary btn-sm mt-3"><i class="fas fa-plus me-1"></i> Add Lead</a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Lead</th>
                        <th>Contact</th>
                        <th>Degree / Program</th>
                        <th>Status</th>
                        <th>Source</th>
                        <th>Follow-up</th>
                        <th>Assigned To</th>
                        <th>Created</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($leads as $lead):
                        $initials = strtoupper(substr($lead['first_name'], 0, 1) . substr($lead['last_name'], 0, 1));
                        $isTerminal = in_array($lead['status'], ['converted', 'not_interested'], true);
                    ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 fw-semibold text-white"
                                     style="width:36px;height:36px;font-size:.75rem;background:#0d6efd;background:linear-gradient(135deg,#0d6efd,#6f42c1)">
                                    <?= h($initials) ?>
                                </div>
                                <div>
                                    <a href="<?= APP_URL ?>/leads/view.php?id=<?= $lead['id'] ?>" class="fw-semibold text-decoration-none d-block"><?= h($lead['first_name'] . ' ' . $lead['last_name']) ?></a>
                                    <span class="text-muted small"><?= h($lead['lead_number']) ?></span>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div><?= h($lead['phone']) ?></div>
                            <?php if ($lead['email']): ?><div class="text-muted small"><?= h($lead['email']) ?></div><?php endif; ?>
                            <?php if ($lead['current_city']): ?><div class="text-muted small"><i class="fas fa-map-marker-alt me-1"></i><?= h($lead['current_city']) ?></div><?php endif; ?>
                        </td>
                        <td>
                            <?= leads_degree_badge($lead['degree_type']) ?>
                            <?php if ($lead['dept_name'] || $lead['program_name']): ?>
                            <div class="text-muted small mt-1"><?= h($lead['dept_name'] ?? '') ?><?= $lead['program_name'] ? ' › ' . h($lead['program_name']) : '' ?></div>
                            <?php endif; ?>
                            <?php if ($lead['preferred_semester']): ?><div class="text-muted small"><?= h($lead['preferred_semester']) ?></div><?php endif; ?>
                        </td>
                        <td><?= leads_status_badge($lead['status']) ?></td>
                        <td><?= leads_source_badge($lead['source']) ?></td>
                        <td><?= leads_followup_badge($lead['next_followup_date'] ?? null, $isTerminal) ?>
                            <?php if (!empty($lead['followup_notes'])): ?>
                            <div class="text-muted small text-truncate" style="max-width:120px" title="<?= h($lead['followup_notes']) ?>"><?= h($lead['followup_notes']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= h($lead['assigned_to_name'] ?? '–') ?></td>
                        <td class="text-nowrap">
                            <div class="small" title="Created <?= h($lead['created_at']) ?>"><?= date('d M Y', strtotime($lead['created_at'])) ?></div>
                            <?php if (!empty($lead['updated_at']) && date('Y-m-d', strtotime($lead['updated_at'])) !== date('Y-m-d', strtotime($lead['created_at']))): ?>
                            <div class="text-muted small" title="Last updated <?= h($lead['updated_at']) ?>"><i class="fas fa-pen me-1"></i><?= date('d M Y', strtotime($lead['updated_at'])) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <a href="<?= APP_URL ?>/leads/view.php?id=<?= $lead['id'] ?>" class="btn btn-sm btn-outline-primary" title="View"><i class="fas fa-eye"></i></a>
                            <?php if ($is_staff): ?>
                            <a href="<?= APP_URL ?>/leads/edit.php?id=<?= $lead['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="fas fa-edit"></i></a>
                            <?php endif; ?>
                            <?php if (leads_can_delete()): ?>
                            <a href="<?= APP_URL ?>/leads/delete.php?id=<?= $lead['id'] ?>" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return confirm('Delete this lead?')"><i class="fas fa-trash"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
            <p class="text-muted small mb-0">Showing <?= number_format(($page-1)*$per_page+1) ?>–<?= number_format(min($page*$per_page,$total_rows)) ?> of <?= number_format($total_rows) ?> lead(s)</p>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php if ($page > 1): ?>
                    <li class="page-item"><a class="page-link" href="?<?= $filter_qs ?>&page=<?= $page-1 ?>"><i class="fas fa-chevron-left"></i></a></li>
                    <?php endif; ?>
                    <?php
                    $range = 2;
                    for ($p = 1; $p <= $total_pages; $p++):
                        if ($p === 1 || $p === $total_pages || abs($p - $page) <= $range):
                    ?>
                    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                        <a class="page-link" href="?<?= $filter_qs ?>&page=<?= $p ?>"><?= $p ?></a>
                    </li>
                    <?php elseif (abs($p - $page) === $range + 1): ?>
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

<script>
(function () {
    var form = document.getElementById('leads-filter-form');
    if (!form) return;

    // Auto-apply filters when a dropdown changes (except date field / preset selectors)
    form.querySelectorAll('select[name]:not([data-no-autosubmit])').forEach(function (el) {
        el.addEventListener('change', function () { form.submit(); });
    });

    // Quick date-range presets
    var preset = document.getElementById('date-preset');
    var fromEl = form.querySelector('input[name="date_from"]');
    var toEl   = form.querySelector('input[name="date_to"]');

    function fmt(d) {
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    if (preset && fromEl && toEl) {
        preset.addEventListener('change', function () {
            var v = preset.value;
            if (!v) return;
            var now  = new Date();
            var from = new Date();
            var to   = new Date();
            if (v === 'yesterday')  { from.setDate(now.getDate() - 1); to.setDate(now.getDate() - 1); }
            else if (v === '7')     { from.setDate(now.getDate() - 6); }
            else if (v === '30')    { from.setDate(now.getDate() - 29); }
            else if (v === 'month') { from = new Date(now.getFullYear(), now.getMonth(), 1); }
            // v === 'today' keeps from = to = today
            fromEl.value = fmt(from);
            toEl.value   = fmt(to);
            form.submit();
        });
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
