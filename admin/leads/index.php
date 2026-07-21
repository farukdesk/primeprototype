<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('leads');
require_once __DIR__ . '/helpers.php';

$page_title = 'Lead Management';
$user       = auth_user();
$is_staff   = leads_is_staff();

// ── Filters ─────────────────────────────────────────────────────────────
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
$per_page  = (int)($_GET['per_page'] ?? 20);
if (!in_array($per_page, [20, 50, 100], true)) $per_page = 20;

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

// ── Stats ───────────────────────────────────────────────────────────────
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

// ── Departments & users for filters ────────────────────────────────────
$departments = db()->query(
    'SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC'
)->fetchAll();
$staff_users = db()->query(
    "SELECT u.id, u.full_name FROM users u WHERE u.is_active = 1 ORDER BY u.full_name"
)->fetchAll();
$semesters = leads_semester_list();

$dept_names = array_column($departments, 'name', 'id');
$user_names = array_column($staff_users, 'full_name', 'id');

// ── Build filter query string (for pagination links) ─────────────────────────
$filter_params = array_filter([
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
    'per_page'   => $per_page !== 20 ? $per_page : '',
]);
$filter_qs = http_build_query($filter_params);
$print_url = APP_URL . '/leads/print.php' . ($filter_qs !== '' ? '?' . $filter_qs : '');

// URL with some filter keys removed (for the removable filter chips)
function leads_chip_url(array $params, array $remove_keys): string
{
    foreach ($remove_keys as $k) unset($params[$k]);
    $qs = http_build_query($params);
    return APP_URL . '/leads/index.php' . ($qs !== '' ? '?' . $qs : '');
}

// Active filter chips: [label, remove_url]
$chips = [];
if ($search !== '')  $chips[] = ['Search: “' . $search . '”',                       leads_chip_url($filter_params, ['search'])];
if ($f_status !== '')$chips[] = ['Status: ' . leads_status_label($f_status),         leads_chip_url($filter_params, ['status'])];
if ($f_source !== '')$chips[] = ['Source: ' . leads_source_label($f_source),         leads_chip_url($filter_params, ['source'])];
if ($f_dept > 0)     $chips[] = ['Dept: ' . ($dept_names[$f_dept] ?? '#' . $f_dept), leads_chip_url($filter_params, ['dept'])];
if ($f_degree !== '')$chips[] = ['Degree: ' . ucfirst($f_degree),                    leads_chip_url($filter_params, ['degree'])];
if ($f_sem !== '')   $chips[] = ['Semester: ' . $f_sem,                              leads_chip_url($filter_params, ['semester'])];
if ($f_user > 0)     $chips[] = ['Assignee: ' . ($user_names[$f_user] ?? '#' . $f_user), leads_chip_url($filter_params, ['user_id'])];
if ($f_followup !== '') $chips[] = [$f_followup === 'today' ? "Today's follow-ups" : 'Overdue follow-ups', leads_chip_url($filter_params, ['followup'])];
if ($f_from !== '' || $f_to !== '') {
    $range = ($f_datefield === 'updated' ? 'Updated' : 'Created') . ': ';
    if ($f_from !== '' && $f_to !== '') {
        $range .= $f_from === $f_to ? date('d M Y', strtotime($f_from)) : date('d M', strtotime($f_from)) . ' – ' . date('d M Y', strtotime($f_to));
    } elseif ($f_from !== '') {
        $range .= 'from ' . date('d M Y', strtotime($f_from));
    } else {
        $range .= 'up to ' . date('d M Y', strtotime($f_to));
    }
    $chips[] = [$range, leads_chip_url($filter_params, ['date_field', 'date_from', 'date_to'])];
}

// Advanced panel open when any of its filters are active
$adv_count  = ($f_dept > 0) + ($f_degree !== '') + ($f_sem !== '') + ($f_user > 0) + ($f_from !== '' || $f_to !== '');
$adv_open   = $adv_count > 0;

$is_created_today = $f_datefield === 'created' && $f_from === $today_str && $f_to === $today_str;
$is_updated_today = $f_datefield === 'updated' && $f_from === $today_str && $f_to === $today_str;

$source_labels = ['online' => 'Online', 'campus_visit' => 'Campus Visit', 'agent' => 'Promoter', 'f2f_marketing' => 'F2F Marketing', 'facebook' => 'Facebook'];

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.lead-tile{transition:transform .12s ease,box-shadow .12s ease;text-decoration:none}
.lead-tile:hover{transform:translateY(-2px);box-shadow:0 .45rem .9rem rgba(15,23,42,.12)!important}
.lead-tile .tile-num{font-size:1.15rem;font-weight:700;line-height:1.1}
.lead-tile .tile-label{font-size:.72rem}
.filter-chip{display:inline-flex;align-items:center;gap:.35rem;background:#eef2ff;color:#3730a3;border:1px solid #c7d2fe;border-radius:999px;padding:.15rem .6rem;font-size:.75rem}
.filter-chip a{color:#6366f1;text-decoration:none;font-weight:700;line-height:1}
.filter-chip a:hover{color:#dc3545}
.quick-chip{font-size:.75rem;border-radius:999px}
.leads-table thead th{font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;font-weight:600;border-bottom-width:2px;white-space:nowrap}
.leads-table tbody tr{cursor:pointer}
.leads-table tbody td{font-size:.85rem}
.more-menu summary{list-style:none}
.more-menu summary::-webkit-details-marker{display:none}
</style>

<!-- ── Page header ── -->
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-funnel-dollar me-2 text-primary"></i>Lead Management</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small"><li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li><li class="breadcrumb-item active">Leads</li></ol></nav>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <details class="more-menu position-relative">
            <summary class="btn btn-outline-secondary btn-sm">More <i class="fas fa-chevron-down fa-xs ms-1"></i></summary>
            <div class="card shadow border position-absolute end-0 mt-1" style="z-index:100;min-width:210px">
                <div class="list-group list-group-flush small">
                    <a class="list-group-item list-group-item-action" href="<?= $print_url ?>" target="_blank" rel="noopener"><i class="fas fa-print me-2 text-muted"></i>Print current view</a>
                    <a class="list-group-item list-group-item-action" href="<?= APP_URL ?>/leads/campus-visits.php"><i class="fas fa-university me-2 text-muted"></i>Campus Visits</a>
                    <a class="list-group-item list-group-item-action" href="<?= APP_URL ?>/leads/call-logs.php"><i class="fas fa-phone-alt me-2 text-muted"></i>Call Logs</a>
                    <a class="list-group-item list-group-item-action" href="<?= APP_URL ?>/leads/fb-inbox.php"><i class="fab fa-facebook-messenger me-2" style="color:#1877F2"></i>FB Inbox</a>
                    <a class="list-group-item list-group-item-action" href="<?= APP_URL ?>/leads/fb-analytics.php"><i class="fas fa-chart-line me-2 text-muted"></i>FB Analytics</a>
                    <?php if (is_super_admin()): ?>
                    <a class="list-group-item list-group-item-action" href="<?= APP_URL ?>/leads/fb-settings.php"><i class="fas fa-cog me-2 text-muted"></i>FB Settings</a>
                    <?php endif; ?>
                </div>
            </div>
        </details>
        <?php if (leads_can_create()): ?>
        <a href="<?= APP_URL ?>/leads/create.php" class="btn btn-primary btn-sm"><i class="fas fa-plus me-1"></i> Add Lead</a>
        <?php endif; ?>
    </div>
</div>

<?= flash_show() ?>

<!-- ── Stat tiles (clickable quick filters) ── -->
<div class="row g-2 mb-3">
    <?php
    $tiles = [
        ['url' => APP_URL . '/leads/index.php', 'bg' => '#e3f2fd', 'icon' => 'fas fa-users text-primary',       'num' => $total_leads,                       'label' => 'Total Leads'],
        ['url' => '?status=fresh',              'bg' => '#e8f5e9', 'icon' => 'fas fa-seedling text-success',    'num' => $status_stats['fresh'] ?? 0,        'label' => 'Fresh'],
        ['url' => '?date_field=created&date_from=' . $today_str . '&date_to=' . $today_str, 'bg' => '#fff8e1', 'icon' => 'fas fa-calendar-plus text-warning', 'num' => $created_today, 'label' => 'New Today'],
        ['url' => '?date_field=updated&date_from=' . $today_str . '&date_to=' . $today_str, 'bg' => '#e0f7fa', 'icon' => 'fas fa-pen text-info',              'num' => $updated_today, 'label' => 'Updated Today'],
        ['url' => '?followup=overdue',          'bg' => '#fce4ec', 'icon' => 'fas fa-bell text-danger',         'num' => $overdue_count,                     'label' => 'Overdue'],
        ['url' => '?status=converted',          'bg' => '#f3e5f5', 'icon' => 'fas fa-check-circle',             'num' => $status_stats['converted'] ?? 0,    'label' => 'Converted', 'icolor' => '#6f42c1'],
    ];
    foreach ($tiles as $t): ?>
    <div class="col-6 col-md-4 col-xl-2">
        <a href="<?= $t['url'] ?>" class="card border-0 shadow-sm h-100 lead-tile">
            <div class="card-body py-2 px-3 d-flex align-items-center gap-2">
                <div class="rounded-3 p-2 flex-shrink-0" style="background:<?= $t['bg'] ?>"><i class="<?= $t['icon'] ?>" <?= isset($t['icolor']) ? 'style="color:' . $t['icolor'] . '"' : '' ?>></i></div>
                <div class="overflow-hidden">
                    <div class="tile-num text-body"><?= number_format($t['num']) ?></div>
                    <div class="tile-label text-muted text-truncate"><?= h($t['label']) ?></div>
                </div>
            </div>
        </a>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── Filter toolbar + list ── -->
<div class="card border-0 shadow-sm">
    <div class="card-body pb-2">

        <form method="get" id="leads-filter-form">
            <?php if ($f_followup): ?><input type="hidden" name="followup" value="<?= h($f_followup) ?>"><?php endif; ?>
            <?php if ($per_page !== 20): ?><input type="hidden" name="per_page" value="<?= $per_page ?>"><?php endif; ?>

            <!-- Row 1: primary controls -->
            <div class="row g-2 align-items-center">
                <div class="col-12 col-lg-4">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" name="search" class="form-control border-start-0" placeholder="Search name, phone, email, city, lead no…" value="<?= h($search) ?>">
                    </div>
                </div>
                <div class="col-6 col-lg-2">
                    <select name="status" class="form-select form-select-sm" title="Status">
                        <option value="">Status: All (<?= number_format($total_leads) ?>)</option>
                        <?php foreach (leads_all_statuses() as $sv => $sl): ?>
                        <option value="<?= h($sv) ?>" <?= $f_status === $sv ? 'selected' : '' ?>><?= h($sl) ?> (<?= number_format($status_stats[$sv] ?? 0) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-lg-2">
                    <select name="source" class="form-select form-select-sm" title="Source">
                        <option value="">Source: All</option>
                        <?php foreach ($source_labels as $sv => $sl): ?>
                        <option value="<?= h($sv) ?>" <?= $f_source === $sv ? 'selected' : '' ?>><?= h($sl) ?> (<?= number_format($source_stats[$sv] ?? 0) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-lg-2">
                    <select name="sort" class="form-select form-select-sm" title="Sort by">
                        <option value="date_desc"    <?= $f_sort === 'date_desc'    ? 'selected' : '' ?>>Newest first</option>
                        <option value="date_asc"     <?= $f_sort === 'date_asc'     ? 'selected' : '' ?>>Oldest first</option>
                        <option value="updated_desc" <?= $f_sort === 'updated_desc' ? 'selected' : '' ?>>Recently updated</option>
                        <option value="name_asc"     <?= $f_sort === 'name_asc'     ? 'selected' : '' ?>>Name A–Z</option>
                        <option value="name_desc"    <?= $f_sort === 'name_desc'    ? 'selected' : '' ?>>Name Z–A</option>
                        <option value="status_asc"   <?= $f_sort === 'status_asc'   ? 'selected' : '' ?>>By status</option>
                        <option value="followup_asc" <?= $f_sort === 'followup_asc' ? 'selected' : '' ?>>Follow-up date</option>
                    </select>
                </div>
                <div class="col-6 col-lg-2 d-flex gap-1">
                    <button type="button" id="adv-toggle" class="btn btn-sm <?= $adv_open ? 'btn-secondary' : 'btn-outline-secondary' ?> flex-grow-1">
                        <i class="fas fa-sliders-h me-1"></i>Filters<?= $adv_count ? ' <span class="badge bg-danger ms-1">' . $adv_count . '</span>' : '' ?>
                    </button>
                    <button type="submit" class="btn btn-primary btn-sm" title="Apply"><i class="fas fa-check"></i></button>
                    <a href="<?= APP_URL ?>/leads/index.php" class="btn btn-outline-secondary btn-sm" title="Reset all filters"><i class="fas fa-undo"></i></a>
                </div>
            </div>

            <!-- Row 2: advanced filters (collapsible) -->
            <div id="adv-filters" class="row g-2 align-items-center mt-1 pt-2 border-top" style="<?= $adv_open ? '' : 'display:none' ?>">
                <div class="col-6 col-lg-2">
                    <select name="dept" class="form-select form-select-sm" title="Department">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= $f_dept == $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-lg-1">
                    <select name="degree" class="form-select form-select-sm" title="Degree">
                        <option value="">Degree</option>
                        <option value="bachelor" <?= $f_degree === 'bachelor' ? 'selected' : '' ?>>Bachelor</option>
                        <option value="master"   <?= $f_degree === 'master'   ? 'selected' : '' ?>>Master</option>
                    </select>
                </div>
                <div class="col-6 col-lg-2">
                    <select name="semester" class="form-select form-select-sm" title="Semester">
                        <option value="">All Semesters</option>
                        <?php foreach ($semesters as $sem): ?>
                        <option value="<?= h($sem) ?>" <?= $f_sem === $sem ? 'selected' : '' ?>><?= h($sem) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-lg-2">
                    <select name="user_id" class="form-select form-select-sm" title="Assignee">
                        <option value="">All Assignees</option>
                        <?php foreach ($staff_users as $su): ?>
                        <option value="<?= $su['id'] ?>" <?= $f_user == $su['id'] ? 'selected' : '' ?>><?= h($su['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-lg-1">
                    <select name="date_field" class="form-select form-select-sm" title="Filter dates by">
                        <option value="created" <?= $f_datefield === 'created' ? 'selected' : '' ?>>Created</option>
                        <option value="updated" <?= $f_datefield === 'updated' ? 'selected' : '' ?>>Updated</option>
                    </select>
                </div>
                <div class="col-6 col-lg-2">
                    <input type="date" name="date_from" class="form-control form-control-sm" value="<?= h($f_from) ?>" title="From date">
                </div>
                <div class="col-6 col-lg-2">
                    <input type="date" name="date_to" class="form-control form-control-sm" value="<?= h($f_to) ?>" title="To date">
                </div>
                <div class="col-6 col-lg-2">
                    <select id="date-preset" class="form-select form-select-sm" title="Quick date range">
                        <option value="">Quick range…</option>
                        <option value="today">Today</option>
                        <option value="yesterday">Yesterday</option>
                        <option value="7">Last 7 days</option>
                        <option value="30">Last 30 days</option>
                        <option value="month">This month</option>
                    </select>
                </div>
            </div>
        </form>

        <!-- Quick chips + active filters -->
        <div class="d-flex flex-wrap align-items-center gap-1 mt-2">
            <a href="?date_field=created&date_from=<?= $today_str ?>&date_to=<?= $today_str ?>" class="btn btn-sm quick-chip <?= $is_created_today ? 'btn-success' : 'btn-outline-success' ?>">Created Today <span class="badge <?= $is_created_today ? 'bg-white text-success' : 'bg-success' ?>"><?= $created_today ?></span></a>
            <a href="?date_field=updated&date_from=<?= $today_str ?>&date_to=<?= $today_str ?>" class="btn btn-sm quick-chip <?= $is_updated_today ? 'btn-info' : 'btn-outline-info' ?>">Updated Today <span class="badge <?= $is_updated_today ? 'bg-white text-info' : 'bg-info' ?>"><?= $updated_today ?></span></a>
            <?php if ($today_count > 0): ?>
            <a href="?followup=today" class="btn btn-sm quick-chip <?= $f_followup === 'today' ? 'btn-primary' : 'btn-outline-primary' ?>">Follow-ups Today <span class="badge <?= $f_followup === 'today' ? 'bg-white text-primary' : 'bg-primary' ?>"><?= $today_count ?></span></a>
            <?php endif; ?>
            <?php if ($overdue_count > 0): ?>
            <a href="?followup=overdue" class="btn btn-sm quick-chip <?= $f_followup === 'overdue' ? 'btn-danger' : 'btn-outline-danger' ?>">Overdue <span class="badge <?= $f_followup === 'overdue' ? 'bg-white text-danger' : 'bg-danger' ?>"><?= $overdue_count ?></span></a>
            <?php endif; ?>

            <?php if ($chips): ?>
            <span class="vr mx-1 d-none d-md-inline-block"></span>
            <?php foreach ($chips as [$chip_label, $chip_url]): ?>
            <span class="filter-chip"><?= h($chip_label) ?> <a href="<?= h($chip_url) ?>" title="Remove filter">&times;</a></span>
            <?php endforeach; ?>
            <a href="<?= APP_URL ?>/leads/index.php" class="small text-danger text-decoration-none ms-1">Clear all</a>
            <?php endif; ?>

            <span class="ms-auto text-muted small"><strong><?= number_format($total_rows) ?></strong> lead(s)</span>
        </div>
    </div>

    <?php if (empty($leads)): ?>
    <div class="card-body text-center py-5 text-muted border-top">
        <i class="fas fa-search fa-3x mb-3 opacity-25"></i>
        <p class="mb-1 fw-semibold">No leads found<?= $chips || $search ? ' for the current filters' : '' ?>.</p>
        <?php if ($chips || $search): ?>
        <a href="<?= APP_URL ?>/leads/index.php" class="btn btn-outline-secondary btn-sm mt-2"><i class="fas fa-undo me-1"></i> Clear filters</a>
        <?php elseif (leads_can_create()): ?>
        <a href="<?= APP_URL ?>/leads/create.php" class="btn btn-primary btn-sm mt-2"><i class="fas fa-plus me-1"></i> Add your first lead</a>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="table-responsive border-top">
        <table class="table table-hover align-middle mb-0 leads-table">
            <thead class="table-light">
                <tr>
                    <th>Lead</th>
                    <th>Contact</th>
                    <th>Program</th>
                    <th>Status / Source</th>
                    <th>Follow-up</th>
                    <th>Assigned</th>
                    <th>Created</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($leads as $lead):
                    $initials   = strtoupper(substr($lead['first_name'], 0, 1) . substr($lead['last_name'], 0, 1));
                    $isTerminal = in_array($lead['status'], ['converted', 'not_interested'], true);
                    $view_url   = APP_URL . '/leads/view.php?id=' . $lead['id'];
                ?>
                <tr data-href="<?= $view_url ?>">
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 fw-semibold text-white"
                                 style="width:34px;height:34px;font-size:.72rem;background:linear-gradient(135deg,#0d6efd,#6f42c1)">
                                <?= h($initials) ?>
                            </div>
                            <div class="overflow-hidden">
                                <a href="<?= $view_url ?>" class="fw-semibold text-decoration-none d-block text-truncate" style="max-width:160px"><?= h($lead['first_name'] . ' ' . $lead['last_name']) ?></a>
                                <span class="text-muted small"><?= h($lead['lead_number']) ?></span>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="text-nowrap"><?= h($lead['phone']) ?></div>
                        <?php if ($lead['email']): ?><div class="text-muted small text-truncate" style="max-width:170px"><?= h($lead['email']) ?></div><?php endif; ?>
                        <?php if ($lead['current_city']): ?><div class="text-muted small"><i class="fas fa-map-marker-alt me-1"></i><?= h($lead['current_city']) ?></div><?php endif; ?>
                    </td>
                    <td>
                        <?= leads_degree_badge($lead['degree_type']) ?>
                        <?php if ($lead['dept_name'] || $lead['program_name']): ?>
                        <div class="text-muted small mt-1 text-truncate" style="max-width:180px"><?= h($lead['dept_name'] ?? '') ?><?= $lead['program_name'] ? ' › ' . h($lead['program_name']) : '' ?></div>
                        <?php endif; ?>
                        <?php if ($lead['preferred_semester']): ?><div class="text-muted small"><?= h($lead['preferred_semester']) ?></div><?php endif; ?>
                    </td>
                    <td>
                        <div><?= leads_status_badge($lead['status']) ?></div>
                        <div class="mt-1"><?= leads_source_badge($lead['source']) ?></div>
                    </td>
                    <td>
                        <?= leads_followup_badge($lead['next_followup_date'] ?? null, $isTerminal) ?>
                        <?php if (!empty($lead['followup_notes'])): ?>
                        <div class="text-muted small text-truncate" style="max-width:130px" title="<?= h($lead['followup_notes']) ?>"><?= h($lead['followup_notes']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= h($lead['assigned_to_name'] ?? '–') ?></td>
                    <td class="text-nowrap">
                        <div class="small" title="Created <?= h($lead['created_at']) ?>"><?= date('d M Y', strtotime($lead['created_at'])) ?></div>
                        <?php if (!empty($lead['updated_at']) && date('Y-m-d', strtotime($lead['updated_at'])) !== date('Y-m-d', strtotime($lead['created_at']))): ?>
                        <div class="text-muted small" title="Last updated <?= h($lead['updated_at']) ?>"><i class="fas fa-pen me-1"></i><?= date('d M Y', strtotime($lead['updated_at'])) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <div class="btn-group btn-group-sm" role="group">
                            <a href="<?= $view_url ?>" class="btn btn-outline-primary" title="View"><i class="fas fa-eye"></i></a>
                            <?php if ($is_staff): ?>
                            <a href="<?= APP_URL ?>/leads/edit.php?id=<?= $lead['id'] ?>" class="btn btn-outline-secondary" title="Edit"><i class="fas fa-edit"></i></a>
                            <?php endif; ?>
                            <?php if (leads_can_delete()): ?>
                            <a href="<?= APP_URL ?>/leads/delete.php?id=<?= $lead['id'] ?>" class="btn btn-outline-danger" title="Delete" onclick="return confirm('Delete this lead?')"><i class="fas fa-trash"></i></a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Footer: count, per-page, pagination -->
    <div class="card-body py-2 d-flex justify-content-between align-items-center flex-wrap gap-2 border-top">
        <div class="d-flex align-items-center gap-2">
            <p class="text-muted small mb-0">Showing <?= number_format(($page-1)*$per_page+1) ?>–<?= number_format(min($page*$per_page, $total_rows)) ?> of <?= number_format($total_rows) ?></p>
            <select id="per-page" class="form-select form-select-sm" style="width:auto" title="Rows per page">
                <option value="20"  <?= $per_page === 20  ? 'selected' : '' ?>>20 / page</option>
                <option value="50"  <?= $per_page === 50  ? 'selected' : '' ?>>50 / page</option>
                <option value="100" <?= $per_page === 100 ? 'selected' : '' ?>>100 / page</option>
            </select>
        </div>
        <?php if ($total_pages > 1): ?>
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
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script>
(function () {
    var form = document.getElementById('leads-filter-form');
    if (!form) return;

    // Auto-apply primary dropdowns (status, source, sort)
    ['status', 'source', 'sort'].forEach(function (name) {
        var el = form.querySelector('select[name="' + name + '"]');
        if (el) el.addEventListener('change', function () { form.submit(); });
    });

    // Advanced filters toggle
    var advToggle = document.getElementById('adv-toggle');
    var advPanel  = document.getElementById('adv-filters');
    if (advToggle && advPanel) {
        advToggle.addEventListener('click', function () {
            var hidden = advPanel.style.display === 'none';
            advPanel.style.display = hidden ? '' : 'none';
            advToggle.classList.toggle('btn-secondary', hidden);
            advToggle.classList.toggle('btn-outline-secondary', !hidden);
        });
    }

    // Quick date-range presets (auto-apply)
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
            var now = new Date(), from = new Date(), to = new Date();
            if (v === 'yesterday')  { from.setDate(now.getDate() - 1); to.setDate(now.getDate() - 1); }
            else if (v === '7')     { from.setDate(now.getDate() - 6); }
            else if (v === '30')    { from.setDate(now.getDate() - 29); }
            else if (v === 'month') { from = new Date(now.getFullYear(), now.getMonth(), 1); }
            fromEl.value = fmt(from);
            toEl.value   = fmt(to);
            form.submit();
        });
    }

    // Clickable table rows (ignore clicks on links/buttons)
    document.querySelectorAll('tr[data-href]').forEach(function (tr) {
        tr.addEventListener('click', function (e) {
            if (e.target.closest('a, button, form, input, select')) return;
            window.location = tr.dataset.href;
        });
    });

    // Per-page selector
    var perPage = document.getElementById('per-page');
    if (perPage) {
        perPage.addEventListener('change', function () {
            var url = new URL(window.location.href);
            url.searchParams.set('per_page', perPage.value);
            url.searchParams.delete('page');
            window.location.href = url.toString();
        });
    }

    // Close the More menu when clicking elsewhere
    document.addEventListener('click', function (e) {
        document.querySelectorAll('details.more-menu[open]').forEach(function (d) {
            if (!d.contains(e.target)) d.removeAttribute('open');
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
