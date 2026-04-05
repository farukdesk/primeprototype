<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('clubs');

$page_title = 'Clubs';

// ── Filters ───────────────────────────────────────────────────────────────────
$search  = trim($_GET['q']      ?? '');
$dept_id = (int)($_GET['dept']  ?? 0);
$status  = $_GET['status']      ?? '';

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[]  = '(c.name LIKE ? OR c.goal LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($dept_id > 0) {
    $where[]  = 'c.dept_id = ?';
    $params[] = $dept_id;
}
if ($status === 'active') {
    $where[] = 'c.is_active = 1';
} elseif ($status === 'inactive') {
    $where[] = 'c.is_active = 0';
}

$where_sql = implode(' AND ', $where);

// ── Pagination ────────────────────────────────────────────────────────────────
$per_page = 20;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

$db = db();
$st = $db->prepare("SELECT COUNT(*) FROM clubs c WHERE $where_sql");
$st->execute($params);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $per_page));

$st = $db->prepare(
    "SELECT c.*,
            d.name AS dept_name,
            p.program_name AS program_name,
            (SELECT COUNT(*) FROM club_members   cm WHERE cm.club_id = c.id) AS member_count,
            (SELECT COUNT(*) FROM club_events    ce WHERE ce.club_id = c.id AND ce.is_published = 1) AS event_count
     FROM clubs c
     LEFT JOIN dept_departments     d ON d.id = c.dept_id
     LEFT JOIN dept_academic_programs p ON p.id = c.program_id
     WHERE $where_sql
     ORDER BY c.created_at DESC
     LIMIT $per_page OFFSET $offset"
);
$st->execute($params);
$clubs = $st->fetchAll();

// ── Stats ─────────────────────────────────────────────────────────────────────
$stats = $db->query("SELECT
    COUNT(*) AS total,
    SUM(is_active=1) AS active_count,
    SUM(is_active=0) AS inactive_count
FROM clubs")->fetch();

// ── Departments for filter ────────────────────────────────────────────────────
$depts = $db->query("SELECT id, name FROM dept_departments ORDER BY name")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-users me-2 text-success"></i>Clubs</h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/dashboard.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Clubs</li>
        </ol></nav>
    </div>
    <?php if (clubs_can_create()): ?>
    <a href="<?= APP_URL ?>/clubs/create.php" class="btn btn-success">
        <i class="fas fa-plus me-1"></i> New Club
    </a>
    <?php endif; ?>
</div>

<?= flash_show() ?>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="card border-0 shadow-sm text-center py-3" style="background:linear-gradient(135deg,#1abc9c,#16a085)">
            <div class="text-white fs-2 fw-bold"><?= $stats['total'] ?></div>
            <div class="text-white small opacity-75">Total Clubs</div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card border-0 shadow-sm text-center py-3" style="background:linear-gradient(135deg,#2ecc71,#27ae60)">
            <div class="text-white fs-2 fw-bold"><?= $stats['active_count'] ?></div>
            <div class="text-white small opacity-75">Active</div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card border-0 shadow-sm text-center py-3" style="background:linear-gradient(135deg,#95a5a6,#7f8c8d)">
            <div class="text-white fs-2 fw-bold"><?= $stats['inactive_count'] ?></div>
            <div class="text-white small opacity-75">Inactive</div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card shadow-sm mb-4 border-0">
    <div class="card-body py-3">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-4">
                <input type="text" name="q" class="form-control" placeholder="Search club name or goal…" value="<?= h($search) ?>">
            </div>
            <div class="col-md-3">
                <select name="dept" class="form-select">
                    <option value="">All Departments</option>
                    <?php foreach ($depts as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $dept_id == $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select name="status" class="form-select">
                    <option value="">All Status</option>
                    <option value="active"   <?= $status === 'active'   ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button class="btn btn-primary flex-grow-1"><i class="fas fa-search me-1"></i>Filter</button>
                <a href="<?= APP_URL ?>/clubs/index.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Table -->
<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <?php if (empty($clubs)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-users fa-3x mb-3 d-block opacity-25"></i>No clubs found.
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Club</th>
                        <th>Department / Program</th>
                        <th class="text-center">Members</th>
                        <th class="text-center">Events</th>
                        <th class="text-center">Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php $n = $offset + 1; foreach ($clubs as $c): ?>
                <tr>
                    <td class="text-muted small"><?= $n++ ?></td>
                    <td>
                        <?php if ($c['logo']): ?>
                        <img src="<?= CLUB_URL_LOGOS ?>/<?= h($c['logo']) ?>" alt="" class="rounded-circle me-2" style="width:36px;height:36px;object-fit:cover;">
                        <?php endif; ?>
                        <a href="<?= APP_URL ?>/clubs/view.php?id=<?= $c['id'] ?>" class="fw-semibold text-decoration-none"><?= h($c['name']) ?></a>
                    </td>
                    <td>
                        <?php if ($c['dept_name']): ?><span class="d-block small"><?= h($c['dept_name']) ?></span><?php endif; ?>
                        <?php if ($c['program_name']): ?><span class="badge bg-light text-dark border"><?= h($c['program_name']) ?></span><?php endif; ?>
                    </td>
                    <td class="text-center"><span class="badge bg-info text-dark"><?= $c['member_count'] ?></span></td>
                    <td class="text-center"><span class="badge bg-primary"><?= $c['event_count'] ?></span></td>
                    <td class="text-center"><?= clubs_status_badge($c['is_active']) ?></td>
                    <td class="text-end">
                        <a href="<?= APP_URL ?>/clubs/view.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-secondary" title="View"><i class="fas fa-eye"></i></a>
                        <?php if (clubs_is_staff()): ?>
                        <a href="<?= APP_URL ?>/clubs/edit.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fas fa-edit"></i></a>
                        <?php endif; ?>
                        <?php if (clubs_can_delete()): ?>
                        <a href="<?= APP_URL ?>/clubs/delete.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-danger" title="Delete"
                           onclick="return confirm('Delete this club and all its data?')"><i class="fas fa-trash"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($pages > 1): ?>
    <div class="card-footer bg-transparent d-flex justify-content-between align-items-center flex-wrap gap-2">
        <small class="text-muted">Showing <?= $offset+1 ?>–<?= min($offset+$per_page,$total) ?> of <?= $total ?></small>
        <nav><ul class="pagination pagination-sm mb-0">
            <?php for ($i = 1; $i <= $pages; $i++): ?>
            <li class="page-item <?= $i==$page?'active':'' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>$i])) ?>"><?= $i ?></a>
            </li>
            <?php endfor; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
