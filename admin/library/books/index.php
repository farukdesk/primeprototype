<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_access('library');
require_once __DIR__ . '/../helpers.php';

$db = db();

// ── Filters ───────────────────────────────────────────────────────────────────
$search      = trim($_GET['q']             ?? '');
$cat_id      = (int)($_GET['category_id']  ?? 0);
$dept_id     = (int)($_GET['dept_id']      ?? 0);
$lang_filter = trim($_GET['language']      ?? '');
$avail       = trim($_GET['availability']  ?? '');

// ── Pagination ────────────────────────────────────────────────────────────────
$per_page    = 20;
$page        = max(1, (int)($_GET['page'] ?? 1));
$offset      = ($page - 1) * $per_page;

// ── Build WHERE clause ────────────────────────────────────────────────────────
$where  = '1=1';
$params = [];

if ($search !== '') {
    $where   .= ' AND (b.title LIKE ? OR b.author LIKE ? OR b.isbn LIKE ?)';
    $like     = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($cat_id) {
    $where   .= ' AND b.category_id = ?';
    $params[] = $cat_id;
}
if ($dept_id) {
    $where   .= ' AND b.department_id = ?';
    $params[] = $dept_id;
}
if ($lang_filter !== '') {
    $where   .= ' AND b.language = ?';
    $params[] = $lang_filter;
}
if ($avail === 'available') {
    $where .= ' AND b.available_copies > 0';
} elseif ($avail === 'unavailable') {
    $where .= ' AND b.available_copies = 0';
}

// ── Stats ─────────────────────────────────────────────────────────────────────
$total_books     = (int)$db->query('SELECT COUNT(*) FROM library_books')->fetchColumn();
$total_available = (int)$db->query('SELECT COUNT(*) FROM library_books WHERE available_copies > 0')->fetchColumn();
$total_issued    = (int)$db->query('SELECT COUNT(*) FROM library_books WHERE available_copies = 0 AND total_copies > 0')->fetchColumn();
$total_digital   = (int)$db->query("SELECT COUNT(*) FROM library_books WHERE is_digital = 1")->fetchColumn();

// ── Total rows for pagination ─────────────────────────────────────────────────
$count_sql  = "SELECT COUNT(*) FROM library_books b WHERE $where";
$count_stmt = $db->prepare($count_sql);
$count_stmt->execute($params);
$total_rows = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page        = min($page, $total_pages);

// ── Books query ───────────────────────────────────────────────────────────────
$sql = "SELECT b.*,
               c.name  AS category_name,
               d.name  AS dept_name
        FROM library_books b
        LEFT JOIN library_categories c ON c.id = b.category_id
        LEFT JOIN dept_departments   d ON d.id = b.department_id
        WHERE $where
        ORDER BY b.id DESC
        LIMIT $per_page OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$books = $stmt->fetchAll();

// ── Filter dropdowns ─────────────────────────────────────────────────────────
$categories  = lib_all_categories();
$departments = $db->query('SELECT id, name FROM dept_departments ORDER BY name ASC')->fetchAll();
$languages   = ['English', 'Bengali', 'Arabic', 'Hindi', 'Other'];

$page_title = 'Library Books';
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Breadcrumb & Actions -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/library/index.php">Library</a></li>
            <li class="breadcrumb-item active">Books</li>
        </ol>
    </nav>
    <?php if (lib_can_create()): ?>
    <a href="<?= APP_URL ?>/library/books/create.php"
       class="btn btn-primary btn-sm" style="border-radius:10px;">
        <i class="fas fa-plus me-1"></i> Add Book
    </a>
    <?php endif; ?>
</div>

<!-- Stats Row -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#4f8ef7,#2d63e8);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= number_format($total_books) ?></div>
                    <div class="stat-label">Total Books</div>
                </div>
                <div class="stat-icon"><i class="fas fa-book"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#11c48d,#0a9971);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= number_format($total_available) ?></div>
                    <div class="stat-label">Available</div>
                </div>
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#f5a623,#d4870a);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= number_format($total_issued) ?></div>
                    <div class="stat-label">Fully Issued</div>
                </div>
                <div class="stat-icon"><i class="fas fa-arrow-right-from-bracket"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#6f42c1,#4e2d8c);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= number_format($total_digital) ?></div>
                    <div class="stat-label">Digital Books</div>
                </div>
                <div class="stat-icon"><i class="fas fa-tablet-screen-button"></i></div>
            </div>
        </div>
    </div>
</div>

<!-- Search & Filter Card -->
<div class="card mb-3">
    <div class="card-body py-3 px-4">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label fw-medium mb-1" style="font-size:.8rem;">Search</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" name="q" class="form-control" style="border-radius:0 10px 10px 0;"
                           placeholder="Title, author or ISBN…" value="<?= h($search) ?>">
                </div>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-medium mb-1" style="font-size:.8rem;">Category</label>
                <select name="category_id" class="form-select form-select-sm" style="border-radius:10px;">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= $cat_id === (int)$cat['id'] ? 'selected' : '' ?>>
                        <?= h($cat['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-medium mb-1" style="font-size:.8rem;">Department</label>
                <select name="dept_id" class="form-select form-select-sm" style="border-radius:10px;">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $dep): ?>
                    <option value="<?= $dep['id'] ?>" <?= $dept_id === (int)$dep['id'] ? 'selected' : '' ?>>
                        <?= h($dep['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-medium mb-1" style="font-size:.8rem;">Language</label>
                <select name="language" class="form-select form-select-sm" style="border-radius:10px;">
                    <option value="">All Languages</option>
                    <?php foreach ($languages as $lng): ?>
                    <option value="<?= h($lng) ?>" <?= $lang_filter === $lng ? 'selected' : '' ?>>
                        <?= h($lng) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-1">
                <label class="form-label fw-medium mb-1" style="font-size:.8rem;">Availability</label>
                <select name="availability" class="form-select form-select-sm" style="border-radius:10px;">
                    <option value="">All</option>
                    <option value="available"   <?= $avail === 'available'   ? 'selected' : '' ?>>Available</option>
                    <option value="unavailable" <?= $avail === 'unavailable' ? 'selected' : '' ?>>Unavailable</option>
                </select>
            </div>
            <div class="col-12 col-md-1 d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm w-100" style="border-radius:10px;">
                    <i class="fas fa-filter"></i>
                </button>
                <a href="<?= APP_URL ?>/library/books/index.php" class="btn btn-light btn-sm w-100" style="border-radius:10px;" title="Clear">
                    <i class="fas fa-xmark"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Books Table -->
<div class="card">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-book me-2 text-muted"></i>Books</h6>
        <span class="badge bg-primary bg-opacity-10 text-primary"><?= number_format($total_rows) ?> result<?= $total_rows !== 1 ? 's' : '' ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th style="width:60px;"></th>
                        <th>Title / Author</th>
                        <th>ISBN</th>
                        <th>Category</th>
                        <th>Publisher / Year</th>
                        <th class="text-center">Copies</th>
                        <th>Location</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($books)): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted py-5">
                            <i class="fas fa-book-open fa-2x mb-2 d-block opacity-25"></i>
                            No books found. <?php if ($search || $cat_id || $dept_id || $lang_filter || $avail): ?>
                                <a href="<?= APP_URL ?>/library/books/index.php">Clear filters</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($books as $i => $b): ?>
                    <tr>
                        <td class="px-4 text-muted" style="font-size:.8rem;"><?= $offset + $i + 1 ?></td>
                        <td>
                            <?php if ($b['cover_image']): ?>
                                <img src="<?= UPLOAD_URL ?>/library/covers/<?= h($b['cover_image']) ?>"
                                     alt="cover" width="42" height="56"
                                     style="object-fit:cover;border-radius:4px;border:1px solid #e0e0e0;">
                            <?php else: ?>
                                <div style="width:42px;height:56px;background:#f0f4ff;border-radius:4px;
                                            display:flex;align-items:center;justify-content:center;
                                            border:1px solid #e0e8ff;">
                                    <i class="fas fa-book text-primary" style="font-size:1.1rem;opacity:.5;"></i>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="fw-semibold" style="max-width:260px;">
                                <a href="<?= APP_URL ?>/library/books/view.php?id=<?= $b['id'] ?>"
                                   class="text-decoration-none text-dark">
                                    <?= h($b['title']) ?>
                                </a>
                                <?php if ($b['is_digital']): ?>
                                    <span class="badge bg-info text-dark ms-1" style="font-size:.65rem;">Digital</span>
                                <?php endif; ?>
                            </div>
                            <?php if ($b['subtitle']): ?>
                                <div class="text-muted" style="font-size:.78rem;"><?= h($b['subtitle']) ?></div>
                            <?php endif; ?>
                            <div class="text-muted" style="font-size:.8rem;"><?= h($b['author']) ?></div>
                        </td>
                        <td style="font-size:.82rem;"><?= $b['isbn'] ? h($b['isbn']) : '<span class="text-muted">—</span>' ?></td>
                        <td style="font-size:.82rem;"><?= $b['category_name'] ? h($b['category_name']) : '<span class="text-muted">—</span>' ?></td>
                        <td style="font-size:.82rem;">
                            <?= $b['publisher'] ? h($b['publisher']) : '' ?>
                            <?php if ($b['pub_year']): ?>
                                <span class="text-muted">(<?= h($b['pub_year']) ?>)</span>
                            <?php endif; ?>
                            <?php if (!$b['publisher'] && !$b['pub_year']): ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php
                            $avail_c = (int)$b['available_copies'];
                            $total_c = (int)$b['total_copies'];
                            $badge_c = $avail_c > 0 ? 'bg-success' : 'bg-danger';
                            ?>
                            <span class="badge <?= $badge_c ?>" style="font-size:.75rem;">
                                <?= $avail_c ?>/<?= $total_c ?>
                            </span>
                        </td>
                        <td style="font-size:.82rem;">
                            <?php if ($b['shelf_rack'] || $b['shelf_row']): ?>
                                <span class="text-muted">Rack:</span> <?= h($b['shelf_rack'] ?? '—') ?>
                                &nbsp;<span class="text-muted">Row:</span> <?= h($b['shelf_row'] ?? '—') ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end pe-4">
                            <div class="d-flex gap-1 justify-content-end">
                                <a href="<?= APP_URL ?>/library/books/view.php?id=<?= $b['id'] ?>"
                                   class="btn btn-sm btn-outline-secondary" style="border-radius:7px;" title="View">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php if (lib_is_staff()): ?>
                                <a href="<?= APP_URL ?>/library/books/edit.php?id=<?= $b['id'] ?>"
                                   class="btn btn-sm btn-outline-primary" style="border-radius:7px;" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php endif; ?>
                                <?php if (lib_can_delete()): ?>
                                <form method="POST" action="<?= APP_URL ?>/library/books/delete.php"
                                      onsubmit="return confirm('Delete book &quot;<?= h(addslashes($b['title'])) ?>&quot;? This cannot be undone.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $b['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" style="border-radius:7px;" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="card-footer py-3 px-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <small class="text-muted">
            Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $per_page, $total_rows)) ?>
            of <?= number_format($total_rows) ?> books
        </small>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php
                $base_url = APP_URL . '/library/books/index.php?' . http_build_query(array_filter([
                    'q'            => $search,
                    'category_id'  => $cat_id  ?: '',
                    'dept_id'      => $dept_id  ?: '',
                    'language'     => $lang_filter,
                    'availability' => $avail,
                ]));
                ?>
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= $base_url ?>&page=<?= $page - 1 ?>" style="border-radius:7px 0 0 7px;">
                        <i class="fas fa-chevron-left" style="font-size:.7rem;"></i>
                    </a>
                </li>
                <?php
                $start_p = max(1, $page - 2);
                $end_p   = min($total_pages, $page + 2);
                for ($p = $start_p; $p <= $end_p; $p++):
                ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <a class="page-link" href="<?= $base_url ?>&page=<?= $p ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= $base_url ?>&page=<?= $page + 1 ?>" style="border-radius:0 7px 7px 0;">
                        <i class="fas fa-chevron-right" style="font-size:.7rem;"></i>
                    </a>
                </li>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
