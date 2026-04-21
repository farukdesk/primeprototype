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

$valid_statuses = array_keys(leads_all_statuses());
$valid_sources  = ['online', 'campus_visit', 'agent', 'f2f_marketing', 'facebook'];
$valid_sorts    = ['date_desc','date_asc','name_asc','name_desc','status_asc','followup_asc'];

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

$where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$sort_sql = match (in_array($f_sort, $valid_sorts, true) ? $f_sort : 'date_desc') {
    'date_asc'    => 'l.created_at ASC',
    'name_asc'    => 'l.first_name ASC, l.last_name ASC',
    'name_desc'   => 'l.first_name DESC, l.last_name DESC',
    'status_asc'  => 'l.status ASC',
    'followup_asc'=> 'l.next_followup_date IS NULL ASC, l.next_followup_date ASC',
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
]));

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-funnel-dollar me-2 text-primary"></i>Lead Management</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small"><li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li><li class="breadcrumb-item active">Leads</li></ol></nav>
    </div>
    <?php if (leads_can_create()): ?>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/leads/fb-settings.php" class="btn btn-outline-secondary btn-sm" title="Facebook Messenger Settings">
            <i class="fab fa-facebook-messenger me-1"></i> FB Settings
        </a>
        <a href="<?= APP_URL ?>/leads/fb-inbox.php" class="btn btn-outline-primary btn-sm">
            <i class="fab fa-facebook-messenger me-1"></i> FB Inbox
        </a>
        <a href="<?= APP_URL ?>/leads/create.php" class="btn btn-primary btn-sm">
            <i class="fas fa-plus me-1"></i> Add Lead
        </a>
    </div>
    <?php endif; ?>
</div>

<?= flash_show() ?>

<!-- ── Stats cards ── -->
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-3 p-2" style="background:#e3f2fd"><i class="fas fa-users text-primary fa-lg"></i></div>
                    <div>
                        <div class="fw-bold fs-5"><?= number_format($total_leads) ?></div>
                        <div class="text-muted small">Total Leads</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-3 p-2" style="background:#e8f5e9"><i class="fas fa-seedling text-success fa-lg"></i></div>
                    <div>
                        <div class="fw-bold fs-5"><?= number_format($status_stats['fresh'] ?? 0) ?></div>
                        <div class="text-muted small">Fresh Leads</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-3 p-2" style="background:#fce4ec"><i class="fas fa-bell text-danger fa-lg"></i></div>
                    <div>
                        <div class="fw-bold fs-5"><?= number_format($overdue_count) ?></div>
                        <div class="text-muted small">Overdue Follow-ups</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-3 p-2" style="background:#f3e5f5"><i class="fas fa-check-circle fa-lg" style="color:#6f42c1"></i></div>
                    <div>
                        <div class="fw-bold fs-5"><?= number_format($status_stats['converted'] ?? 0) ?></div>
                        <div class="text-muted small">Converted</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Follow-up quick buttons ── -->
<?php if ($today_count > 0 || $overdue_count > 0): ?>
<div class="d-flex flex-wrap gap-2 mb-3">
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
    <?php if ($f_followup): ?>
    <a href="<?= APP_URL ?>/leads/index.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-times me-1"></i> Clear</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Source quick-filter tabs ── -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body p-2">
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <span class="text-muted small fw-semibold me-1">Source:</span>
            <?php
            $sources = ['' => 'All', 'online' => 'Online', 'campus_visit' => 'Campus Visit', 'agent' => 'Promoter', 'f2f_marketing' => 'F2F Marketing', 'facebook' => 'Facebook'];
            foreach ($sources as $sv => $sl):
                $q = http_build_query(array_filter(array_merge(
                    ['search' => $search, 'status' => $f_status, 'dept' => $f_dept ?: '', 'semester' => $f_sem, 'degree' => $f_degree, 'user_id' => $f_user ?: '', 'followup' => $f_followup],
                    ['source' => $sv]
                )));
                $active = $f_source === $sv ? 'btn-secondary' : 'btn-outline-secondary';
            ?>
            <a href="?<?= $q ?>" class="btn btn-sm <?= $active ?>"><?= h($sl) ?> <span class="badge bg-light text-dark ms-1"><?= number_format($sv === '' ? $total_leads : ($source_stats[$sv] ?? 0)) ?></span></a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ── Status tabs ── -->
<ul class="nav nav-tabs mb-0" style="border-bottom:none">
    <?php
    $tab_statuses = ['' => 'All'] + leads_all_statuses();
    foreach ($tab_statuses as $sv => $sl):
        $q = http_build_query(array_filter(array_merge(
            ['search' => $search, 'source' => $f_source, 'dept' => $f_dept ?: '', 'semester' => $f_sem, 'degree' => $f_degree, 'user_id' => $f_user ?: '', 'followup' => $f_followup, 'sort' => $f_sort !== 'date_desc' ? $f_sort : ''],
            ['status' => $sv]
        )));
        $cnt = $sv === '' ? $total_leads : ($status_stats[$sv] ?? 0);
    ?>
    <li class="nav-item">
        <a class="nav-link <?= $f_status === $sv ? 'active' : '' ?>" href="?<?= $q ?>">
            <?= h($sl) ?> <span class="badge bg-secondary ms-1"><?= number_format($cnt) ?></span>
        </a>
    </li>
    <?php endforeach; ?>
</ul>

<!-- ── Filter & list card ── -->
<div class="card border-0 shadow-sm" style="border-top-left-radius:0">
    <div class="card-body">

        <!-- Filter form -->
        <div class="mb-3">
            <button class="btn btn-outline-secondary btn-sm mb-2" type="button" data-bs-toggle="collapse" data-bs-target="#filterPanel" aria-expanded="<?= ($search || $f_dept || $f_sem || $f_degree || $f_user || $f_sort !== 'date_desc') ? 'true' : 'false' ?>">
                <i class="fas fa-filter me-1"></i> Filters &amp; Sort
                <?php if ($search || $f_dept || $f_sem || $f_degree || $f_user): ?>
                <span class="badge bg-primary ms-1">Active</span>
                <?php endif; ?>
            </button>
            <div class="collapse <?= ($search || $f_dept || $f_sem || $f_degree || $f_user || $f_sort !== 'date_desc') ? 'show' : '' ?>" id="filterPanel">
                <form method="get" class="row g-2 pt-2 border-top mt-1">
                    <input type="hidden" name="status" value="<?= h($f_status) ?>">
                    <input type="hidden" name="source" value="<?= h($f_source) ?>">
                    <?php if ($f_followup): ?><input type="hidden" name="followup" value="<?= h($f_followup) ?>"><?php endif; ?>
                    <div class="col-12 col-md-4">
                        <input type="text" name="search" class="form-control form-control-sm" placeholder="Search name, phone, email, city…" value="<?= h($search) ?>">
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
                        <select name="degree" class="form-select form-select-sm">
                            <option value="">All Degrees</option>
                            <option value="bachelor" <?= $f_degree === 'bachelor' ? 'selected' : '' ?>>Bachelor</option>
                            <option value="master"   <?= $f_degree === 'master'   ? 'selected' : '' ?>>Master</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <select name="semester" class="form-select form-select-sm">
                            <option value="">All Semesters</option>
                            <?php foreach ($semesters as $sem): ?>
                            <option value="<?= h($sem) ?>" <?= $f_sem === $sem ? 'selected' : '' ?>><?= h($sem) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <select name="user_id" class="form-select form-select-sm">
                            <option value="">All Assignees</option>
                            <?php foreach ($staff_users as $su): ?>
                            <option value="<?= $su['id'] ?>" <?= $f_user == $su['id'] ? 'selected' : '' ?>><?= h($su['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <select name="sort" class="form-select form-select-sm">
                            <option value="date_desc"  <?= $f_sort === 'date_desc'   ? 'selected' : '' ?>>Newest First</option>
                            <option value="date_asc"   <?= $f_sort === 'date_asc'    ? 'selected' : '' ?>>Oldest First</option>
                            <option value="name_asc"   <?= $f_sort === 'name_asc'    ? 'selected' : '' ?>>Name A–Z</option>
                            <option value="name_desc"  <?= $f_sort === 'name_desc'   ? 'selected' : '' ?>>Name Z–A</option>
                            <option value="status_asc" <?= $f_sort === 'status_asc'  ? 'selected' : '' ?>>By Status</option>
                            <option value="followup_asc" <?= $f_sort === 'followup_asc' ? 'selected' : '' ?>>Follow-up Date</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-auto d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search me-1"></i>Apply</button>
                        <a href="<?= APP_URL ?>/leads/index.php" class="btn btn-outline-secondary btn-sm">Reset</a>
                    </div>
                </form>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

