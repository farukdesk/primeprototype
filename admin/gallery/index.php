<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('gallery');

$page_title = 'Gallery';

// ── Filters ───────────────────────────────────────────────────────────────────
$search  = trim($_GET['q']       ?? '');
$dept_id = (int)($_GET['dept']   ?? 0);
$status  = $_GET['status']       ?? '';

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[]  = '(a.title LIKE ? OR a.description LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($dept_id > 0) {
    $where[]  = 'a.dept_id = ?';
    $params[] = $dept_id;
}
if ($status === 'active') {
    $where[] = 'a.is_active = 1';
} elseif ($status === 'inactive') {
    $where[] = 'a.is_active = 0';
}

$where_sql = implode(' AND ', $where);

// ── Pagination ────────────────────────────────────────────────────────────────
$per_page = 18;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

$db = db();
$st = $db->prepare("SELECT COUNT(*) FROM gallery_albums a WHERE $where_sql");
$st->execute($params);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $per_page));

$st = $db->prepare(
    "SELECT a.*,
            d.name AS dept_name,
            p.program_name,
            (SELECT COUNT(*) FROM gallery_photos gp WHERE gp.album_id = a.id AND gp.status = 'approved') AS approved_count,
            (SELECT COUNT(*) FROM gallery_photos gp WHERE gp.album_id = a.id AND gp.status = 'pending')  AS pending_count
     FROM gallery_albums a
     LEFT JOIN dept_departments      d ON d.id = a.dept_id
     LEFT JOIN dept_academic_programs p ON p.id = a.program_id
     WHERE $where_sql
     ORDER BY a.sort_order ASC, a.event_date DESC, a.created_at DESC
     LIMIT $per_page OFFSET $offset"
);
$st->execute($params);
$albums = $st->fetchAll();

// ── Stats ─────────────────────────────────────────────────────────────────────
$stats = $db->query(
    "SELECT
        COUNT(DISTINCT a.id) AS total_albums,
        SUM(a.is_active = 1)           AS active_albums,
        COUNT(gp.id)                   AS total_photos,
        SUM(gp.status = 'pending')     AS pending_photos
     FROM gallery_albums a
     LEFT JOIN gallery_photos gp ON gp.album_id = a.id"
)->fetch();

// ── Departments for filter ────────────────────────────────────────────────────
$depts = $db->query("SELECT id, name FROM dept_departments ORDER BY name")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-images me-2 text-primary"></i>Gallery</h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/dashboard.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Gallery</li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($stats['pending_photos'] > 0): ?>
        <a href="<?= APP_URL ?>/gallery/photo-approve.php" class="btn btn-warning">
            <i class="fas fa-clock me-1"></i> Pending Approvals
            <span class="badge bg-dark ms-1"><?= $stats['pending_photos'] ?></span>
        </a>
        <?php endif; ?>
        <?php if (gallery_can_create()): ?>
        <a href="<?= APP_URL ?>/gallery/create.php" class="btn btn-primary">
            <i class="fas fa-plus me-1"></i> New Album
        </a>
        <?php endif; ?>
    </div>
</div>

<?= flash_show() ?>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-sm-3">
        <div class="card border-0 shadow-sm text-center py-3" style="background:linear-gradient(135deg,#4f8ef7,#2563eb)">
            <div class="text-white fs-2 fw-bold"><?= (int)$stats['total_albums'] ?></div>
            <div class="text-white small opacity-75">Total Albums</div>
        </div>
    </div>
    <div class="col-6 col-sm-3">
        <div class="card border-0 shadow-sm text-center py-3" style="background:linear-gradient(135deg,#1abc9c,#16a085)">
            <div class="text-white fs-2 fw-bold"><?= (int)$stats['active_albums'] ?></div>
            <div class="text-white small opacity-75">Active Albums</div>
        </div>
    </div>
    <div class="col-6 col-sm-3">
        <div class="card border-0 shadow-sm text-center py-3" style="background:linear-gradient(135deg,#8b5cf6,#7c3aed)">
            <div class="text-white fs-2 fw-bold"><?= (int)$stats['total_photos'] ?></div>
            <div class="text-white small opacity-75">Total Photos</div>
        </div>
    </div>
    <div class="col-6 col-sm-3">
        <div class="card border-0 shadow-sm text-center py-3" style="background:linear-gradient(135deg,#f59e0b,#d97706)">
            <div class="text-white fs-2 fw-bold"><?= (int)$stats['pending_photos'] ?></div>
            <div class="text-white small opacity-75">Pending Photos</div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card shadow-sm mb-4 border-0">
    <div class="card-body py-3">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-4">
                <input type="text" name="q" class="form-control" placeholder="Search album title…" value="<?= h($search) ?>">
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
                <a href="<?= APP_URL ?>/gallery/index.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Albums grid -->
<?php if (empty($albums)): ?>
<div class="card border-0 shadow-sm">
    <div class="card-body text-center py-5 text-muted">
        <i class="fas fa-images fa-4x mb-3 d-block opacity-25"></i>
        No albums found. <a href="<?= APP_URL ?>/gallery/create.php">Create the first album</a>.
    </div>
</div>
<?php else: ?>
<div class="row g-4">
    <?php foreach ($albums as $a): ?>
    <div class="col-sm-6 col-lg-4 col-xl-3">
        <div class="card border-0 shadow-sm h-100" style="border-radius:14px;overflow:hidden;">
            <!-- Cover -->
            <div style="height:160px;overflow:hidden;background:linear-gradient(135deg,#1a1f36,#2563eb);">
                <?php if ($a['cover_photo']): ?>
                <img src="<?= GAL_URL_COVERS ?>/<?= h($a['cover_photo']) ?>" alt=""
                     style="width:100%;height:160px;object-fit:cover;opacity:.9;">
                <?php else: ?>
                <div class="d-flex align-items-center justify-content-center h-100">
                    <i class="fas fa-images fa-3x text-white opacity-50"></i>
                </div>
                <?php endif; ?>
                <!-- Active badge -->
                <div style="position:absolute;top:10px;right:10px;">
                    <?= gallery_active_badge($a['is_active']) ?>
                </div>
            </div>
            <div class="card-body">
                <h6 class="fw-bold mb-1 text-truncate"><?= h($a['title']) ?></h6>
                <?php if ($a['dept_name']): ?>
                <div class="small text-muted mb-1"><i class="fas fa-building me-1"></i><?= h($a['dept_name']) ?></div>
                <?php endif; ?>
                <?php if ($a['event_date']): ?>
                <div class="small text-muted mb-2"><i class="fas fa-calendar me-1"></i><?= date('d M Y', strtotime($a['event_date'])) ?></div>
                <?php endif; ?>
                <div class="d-flex gap-2 flex-wrap">
                    <span class="badge bg-success"><?= $a['approved_count'] ?> approved</span>
                    <?php if ($a['pending_count'] > 0): ?>
                    <span class="badge bg-warning text-dark"><?= $a['pending_count'] ?> pending</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-footer bg-transparent border-top-0 pt-0 pb-3 px-3 d-flex gap-1">
                <a href="<?= APP_URL ?>/gallery/view.php?id=<?= $a['id'] ?>" class="btn btn-sm btn-outline-secondary flex-grow-1" title="View"><i class="fas fa-eye me-1"></i>View</a>
                <?php if (gallery_can_edit()): ?>
                <a href="<?= APP_URL ?>/gallery/edit.php?id=<?= $a['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fas fa-edit"></i></a>
                <?php endif; ?>
                <?php if (gallery_can_delete()): ?>
                <a href="<?= APP_URL ?>/gallery/delete.php?id=<?= $a['id'] ?>" class="btn btn-sm btn-outline-danger" title="Delete"
                   onclick="return confirm('Delete this album and ALL its photos?')"><i class="fas fa-trash"></i></a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Pagination -->
<?php if ($pages > 1): ?>
<nav class="mt-4"><ul class="pagination justify-content-center">
    <?php for ($i = 1; $i <= $pages; $i++): ?>
    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
    </li>
    <?php endfor; ?>
</ul></nav>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
