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
$page      = max(1, (int)($_GET['page'] ?? 1));
$per_page  = 20;

$valid_statuses = ['fresh', 'unable_to_reach', 'converted'];
$valid_sources  = ['online', 'campus_visit', 'agent', 'f2f_marketing'];

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

$where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

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
        LEFT JOIN dept_departments d     ON d.id = l.dept_id
        LEFT JOIN dept_academic_programs p ON p.id = l.program_id
        LEFT JOIN users u                ON u.id = l.assigned_to'
     . $where_sql
     . ' ORDER BY l.created_at DESC LIMIT ' . $per_page . ' OFFSET ' . $offset;

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
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-3 p-2" style="background:#e8f5e9"><i class="fas fa-bolt text-success fa-lg"></i></div>
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
                    <div class="rounded-3 p-2" style="background:#fff8e1"><i class="fas fa-phone-slash text-warning fa-lg"></i></div>
                    <div>
                        <div class="fw-bold fs-5"><?= number_format($status_stats['unable_to_reach'] ?? 0) ?></div>
                        <div class="text-muted small">Unable to Reach</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-3 p-2" style="background:#e3f2fd"><i class="fas fa-check-circle text-primary fa-lg"></i></div>
                    <div>
                        <div class="fw-bold fs-5"><?= number_format($status_stats['converted'] ?? 0) ?></div>
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
                    <div class="rounded-3 p-2" style="background:#f3e5f5"><i class="fas fa-users text-purple fa-lg" style="color:#6f42c1"></i></div>
                    <div>
                        <div class="fw-bold fs-5"><?= number_format($total_leads) ?></div>
                        <div class="text-muted small">Total Leads</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Source quick-filter tabs ── -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body p-2">
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <span class="text-muted small fw-semibold me-1">Source:</span>
            <?php
            $sources = ['' => 'All', 'online' => 'Online', 'campus_visit' => 'Campus Visit', 'agent' => 'Agent', 'f2f_marketing' => 'F2F Marketing', 'facebook' => 'Facebook'];
            foreach ($sources as $sv => $sl):
                $q = http_build_query(array_filter(array_merge(
                    ['search' => $search, 'status' => $f_status, 'dept' => $f_dept ?: '', 'semester' => $f_sem, 'degree' => $f_degree, 'user_id' => $f_user ?: ''],
                    ['source' => $sv]
                )));
                $active = $f_source === $sv ? 'btn-secondary' : 'btn-outline-secondary';
            ?>
            <a href="?<?= $q ?>" class="btn btn-sm <?= $active ?>"><?= h($sl) ?> (<?= number_format($sv === '' ? $total_leads : ($source_stats[$sv] ?? 0)) ?>)</a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ── Status tabs ── -->
<ul class="nav nav-tabs mb-0" style="border-bottom:none">
    <?php
    $tab_statuses = ['' => 'All', 'fresh' => 'Fresh', 'unable_to_reach' => 'Unable to Reach', 'converted' => 'Converted'];
    foreach ($tab_statuses as $sv => $sl):
        $q = http_build_query(array_filter(array_merge(
            ['search' => $search, 'source' => $f_source, 'dept' => $f_dept ?: '', 'semester' => $f_sem, 'degree' => $f_degree, 'user_id' => $f_user ?: ''],
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
        <form method="get" class="row g-2 mb-3">
            <input type="hidden" name="status" value="<?= h($f_status) ?>">
            <input type="hidden" name="source" value="<?= h($f_source) ?>">
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
            <div class="col-12 col-md-auto d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search me-1"></i>Filter</button>
                <a href="<?= APP_URL ?>/leads/index.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>

        <?php if (empty($leads)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-funnel-dollar fa-3x mb-3 opacity-25"></i>
            <p class="mb-0">No leads found matching your criteria.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Lead #</th>
                        <th>Name</th>
                        <th>Contact</th>
                        <th>Degree</th>
                        <th>Department / Program</th>
                        <th>Semester</th>
                        <th>Status</th>
                        <th>Source</th>
                        <th>Assigned To</th>
                        <th>Date</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($leads as $lead): ?>
                    <tr>
                        <td><a href="<?= APP_URL ?>/leads/view.php?id=<?= $lead['id'] ?>" class="fw-semibold text-decoration-none"><?= h($lead['lead_number']) ?></a></td>
                        <td><?= h($lead['first_name'] . ' ' . $lead['last_name']) ?></td>
                        <td>
                            <div><?= h($lead['phone']) ?></div>
                            <?php if ($lead['email']): ?><div class="text-muted small"><?= h($lead['email']) ?></div><?php endif; ?>
                        </td>
                        <td><?= leads_degree_badge($lead['degree_type']) ?></td>
                        <td>
                            <?= h($lead['dept_name'] ?? '–') ?>
                            <?php if ($lead['program_name']): ?><div class="text-muted small"><?= h($lead['program_name']) ?></div><?php endif; ?>
                        </td>
                        <td><?= h($lead['preferred_semester'] ?? '–') ?></td>
                        <td><?= leads_status_badge($lead['status']) ?></td>
                        <td><?= leads_source_badge($lead['source']) ?></td>
                        <td><?= h($lead['assigned_to_name'] ?? '–') ?></td>
                        <td class="text-muted small"><?= date('d M Y', strtotime($lead['created_at'])) ?></td>
                        <td class="text-end">
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
        <nav class="mt-3">
            <ul class="pagination pagination-sm justify-content-center mb-0">
                <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <a class="page-link" href="?<?= $filter_qs ?>&page=<?= $p ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
        <p class="text-center text-muted small mt-2">Showing <?= count($leads) ?> of <?= number_format($total_rows) ?> lead(s)</p>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
