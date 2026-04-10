<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('course-fees');

$page_title = 'Course Fees Calculator';
$db = db();

// ── Filters ───────────────────────────────────────────────────────────────────
$search     = trim($_GET['q']      ?? '');
$f_type     = trim($_GET['type']   ?? '');
$f_status   = trim($_GET['status'] ?? '');

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[]  = 'p.program_name LIKE ?';
    $params[] = "%$search%";
}
if ($f_type !== '') {
    $where[]  = 'dt.slug = ?';
    $params[] = $f_type;
}
if ($f_status === 'active') {
    $where[] = 'p.is_active = 1';
} elseif ($f_status === 'inactive') {
    $where[] = 'p.is_active = 0';
}

$where_sql = implode(' AND ', $where);

// ── Pagination ────────────────────────────────────────────────────────────────
$per_page = 25;
$page     = max(1, (int)($_GET['page'] ?? 1));

$cnt_stmt = $db->prepare(
    "SELECT COUNT(*) FROM cf_programs p
     JOIN cf_degree_types dt ON dt.id = p.degree_type_id
     WHERE $where_sql"
);
$cnt_stmt->execute($params);
$total = (int)$cnt_stmt->fetchColumn();
$pages = max(1, (int)ceil($total / $per_page));
$page  = min($page, $pages);
$off   = ($page - 1) * $per_page;

$stmt = $db->prepare(
    "SELECT p.*, dt.slug AS degree_type_slug, dt.name AS degree_type_name
     FROM cf_programs p
     JOIN cf_degree_types dt ON dt.id = p.degree_type_id
     WHERE $where_sql
     ORDER BY dt.sort_order, p.sort_order, p.id
     LIMIT $per_page OFFSET $off"
);
$stmt->execute($params);
$programs = $stmt->fetchAll();

// ── Stats ─────────────────────────────────────────────────────────────────────
$stats = $db->query(
    "SELECT COUNT(*) AS total,
            SUM(p.is_active=1) AS active_count,
            SUM(p.is_active=0) AS inactive_count
     FROM cf_programs p"
)->fetch();

$degree_types = cf_get_degree_types();
$settings     = cf_get_settings();

// ── Build pagination URL ──────────────────────────────────────────────────────
function cf_paginate_url(int $p): string {
    $q = $_GET;
    $q['page'] = $p;
    return '?' . http_build_query($q);
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-calculator me-2 text-warning"></i>Course Fees Calculator</h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Course Fees</li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (cf_can_create()): ?>
        <a href="<?= APP_URL ?>/course-fees/create.php" class="btn btn-success btn-sm">
            <i class="fas fa-plus me-1"></i> Add Program
        </a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/course-fees/settings.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-cog me-1"></i> Settings
        </a>
        <a href="<?= SITE_URL ?>/course-fees-calculator.php" target="_blank" class="btn btn-outline-primary btn-sm">
            <i class="fas fa-external-link-alt me-1"></i> Public Page
        </a>
    </div>
</div>

<?= flash_show() ?>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-2 fw-bold text-primary"><?= (int)$stats['total'] ?></div>
            <div class="small text-muted">Total Programs</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-2 fw-bold text-success"><?= (int)$stats['active_count'] ?></div>
            <div class="small text-muted">Active</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-2 fw-bold text-secondary"><?= (int)$stats['inactive_count'] ?></div>
            <div class="small text-muted">Inactive</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-2 fw-bold text-info"><?= h($settings['session_label'] ?? '—') ?></div>
            <div class="small text-muted">Current Session</div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label small fw-semibold mb-1">Search</label>
                <input type="text" name="q" value="<?= h($search) ?>" placeholder="Program name…"
                       class="form-control form-control-sm">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold mb-1">Degree Type</label>
                <select name="type" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    <?php foreach ($degree_types as $dt): ?>
                    <option value="<?= h($dt['slug']) ?>" <?= $f_type === $dt['slug'] ? 'selected' : '' ?>>
                        <?= h($dt['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="active"   <?= $f_status === 'active'   ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $f_status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div class="col-12 col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-fill">
                    <i class="fas fa-search me-1"></i> Filter
                </button>
                <a href="<?= APP_URL ?>/course-fees/index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-times"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Programs Table -->
<div class="card border-0 shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center py-3">
        <span class="fw-semibold">
            Programs
            <?php if ($total > 0): ?>
            <span class="badge bg-secondary ms-1"><?= $total ?></span>
            <?php endif; ?>
        </span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-3" style="width:40px">#</th>
                    <th>Program Name</th>
                    <th>Type</th>
                    <th>Slug</th>
                    <th class="text-center">Credits</th>
                    <th class="text-center">Semesters</th>
                    <th class="text-center">Status</th>
                    <th class="text-end pe-3">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($programs)): ?>
                <tr><td colspan="8" class="text-center py-4 text-muted">No programs found.</td></tr>
            <?php else: ?>
                <?php foreach ($programs as $prog): ?>
                <tr>
                    <td class="ps-3 text-muted small"><?= (int)$prog['id'] ?></td>
                    <td>
                        <div class="fw-semibold"><?= h($prog['program_name']) ?></div>
                        <div class="small text-muted"><?= h($prog['program_slug']) ?></div>
                    </td>
                    <td><?= cf_type_badge($prog) ?></td>
                    <td><code class="small"><?= h($prog['program_slug']) ?></code></td>
                    <td class="text-center"><?= $prog['total_credits'] ?? '—' ?></td>
                    <td class="text-center"><?= $prog['total_semesters'] ?? '—' ?></td>
                    <td class="text-center">
                        <?php if ($prog['is_active']): ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                        <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end pe-3">
                        <div class="d-flex gap-1 justify-content-end">
                            <a href="<?= APP_URL ?>/course-fees/view.php?id=<?= $prog['id'] ?>"
                               class="btn btn-sm btn-outline-info" title="View">
                                <i class="fas fa-eye"></i>
                            </a>
                            <?php if (cf_can_edit()): ?>
                            <a href="<?= APP_URL ?>/course-fees/edit.php?id=<?= $prog['id'] ?>"
                               class="btn btn-sm btn-outline-primary" title="Edit">
                                <i class="fas fa-pencil"></i>
                            </a>
                            <?php endif; ?>
                            <?php if (cf_can_delete()): ?>
                            <a href="<?= APP_URL ?>/course-fees/delete.php?id=<?= $prog['id'] ?>"
                               class="btn btn-sm btn-outline-danger" title="Delete"
                               onclick="return confirm('Delete &quot;<?= h(addslashes($prog['program_name'])) ?>&quot;? This cannot be undone.')">
                                <i class="fas fa-trash"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
    <div class="card-footer d-flex justify-content-between align-items-center py-2">
        <small class="text-muted">Showing <?= $off + 1 ?>–<?= min($off + $per_page, $total) ?> of <?= $total ?></small>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php if ($page > 1): ?>
                <li class="page-item"><a class="page-link" href="<?= cf_paginate_url($page - 1) ?>">‹</a></li>
                <?php endif; ?>
                <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                    <a class="page-link" href="<?= cf_paginate_url($i) ?>"><?= $i ?></a>
                </li>
                <?php endfor; ?>
                <?php if ($page < $pages): ?>
                <li class="page-item"><a class="page-link" href="<?= cf_paginate_url($page + 1) ?>">›</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
