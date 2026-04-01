<?php
require_once __DIR__ . '/../../../includes/auth.php';
auth_check();
require_access('library-circulation');
require_once __DIR__ . '/../helpers.php';

$db = db();

// ── Filters ───────────────────────────────────────────────────────────────────
$search     = trim($_GET['q']          ?? '');
$status_f   = trim($_GET['status']     ?? '');
$date_from  = trim($_GET['date_from']  ?? '');
$date_to    = trim($_GET['date_to']    ?? '');

// ── Pagination ────────────────────────────────────────────────────────────────
$per_page = 20;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

// ── Stats ─────────────────────────────────────────────────────────────────────
$stat_active   = (int)$db->query("SELECT COUNT(*) FROM library_circulation WHERE status='Issued'")->fetchColumn();
$stat_overdue  = (int)$db->query("SELECT COUNT(*) FROM library_circulation WHERE status='Overdue'")->fetchColumn();
$stat_returned = (int)$db->query("SELECT COUNT(*) FROM library_circulation WHERE status='Returned' AND DATE(return_date)=CURDATE()")->fetchColumn();
$stat_members  = (int)$db->query("SELECT COUNT(*) FROM library_members WHERE is_active=1")->fetchColumn();

// ── Mark overdue automatically ────────────────────────────────────────────────
$db->exec("UPDATE library_circulation SET status='Overdue'
           WHERE status='Issued' AND due_date < NOW()");

// ── Build WHERE ───────────────────────────────────────────────────────────────
$where  = '1=1';
$params = [];

if ($search !== '') {
    $like     = '%' . $search . '%';
    $where   .= " AND (b.title LIKE ? OR b.isbn LIKE ?
                       OR COALESCE(s.full_name, u.full_name) LIKE ?
                       OR m.member_code LIKE ?)";
    $params[] = $like; $params[] = $like;
    $params[] = $like; $params[] = $like;
}
if ($status_f !== '') {
    $where   .= ' AND c.status = ?';
    $params[] = $status_f;
}
if ($date_from !== '') {
    $where   .= ' AND DATE(c.issue_date) >= ?';
    $params[] = $date_from;
}
if ($date_to !== '') {
    $where   .= ' AND DATE(c.issue_date) <= ?';
    $params[] = $date_to;
}

// ── Count ─────────────────────────────────────────────────────────────────────
$count_sql = "SELECT COUNT(*)
              FROM library_circulation c
              JOIN library_book_copies cp ON cp.id = c.copy_id
              JOIN library_books b ON b.id = c.book_id
              JOIN library_members m ON m.id = c.member_id
              LEFT JOIN students s ON s.id = m.student_id
              LEFT JOIN users u ON u.id = m.user_id
              WHERE $where";
$cs = $db->prepare($count_sql);
$cs->execute($params);
$total_rows  = (int)$cs->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

// ── Main query ────────────────────────────────────────────────────────────────
$sql = "SELECT c.*,
               b.title AS book_title, b.isbn,
               cp.barcode, cp.copy_number,
               m.member_code, m.member_type,
               COALESCE(s.full_name, u.full_name) AS member_name,
               issued_u.full_name AS issued_by_name
        FROM library_circulation c
        JOIN library_book_copies cp ON cp.id = c.copy_id
        JOIN library_books b ON b.id = c.book_id
        JOIN library_members m ON m.id = c.member_id
        LEFT JOIN students s ON s.id = m.student_id
        LEFT JOIN users u ON u.id = m.user_id
        LEFT JOIN users issued_u ON issued_u.id = c.issued_by
        WHERE $where
        ORDER BY c.id DESC
        LIMIT $per_page OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

$flash_success = flash_get('success');
$flash_error   = flash_get('error');

$page_title = 'Circulation Management';
require_once __DIR__ . '/../../../includes/header.php';
?>

<!-- Breadcrumb & Actions -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/library/index.php">Library</a></li>
            <li class="breadcrumb-item active">Circulation</li>
        </ol>
    </nav>
    <?php if (lib_is_circulation_staff()): ?>
    <a href="<?= APP_URL ?>/library/circulation/issue.php"
       class="btn btn-primary btn-sm" style="border-radius:10px;">
        <i class="fas fa-plus me-1"></i> Issue Book
    </a>
    <?php endif; ?>
</div>

<?php if ($flash_success): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fas fa-check-circle me-1"></i> <?= h($flash_success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($flash_error): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="fas fa-exclamation-circle me-1"></i> <?= h($flash_error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#4f8ef7,#2d63e8);">
            <div class="d-flex justify-content-between align-items-start">
                <div><div class="stat-val"><?= number_format($stat_active) ?></div><div class="stat-label">Active Issues</div></div>
                <div class="stat-icon"><i class="fas fa-book-open"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#e74c3c,#c0392b);">
            <div class="d-flex justify-content-between align-items-start">
                <div><div class="stat-val"><?= number_format($stat_overdue) ?></div><div class="stat-label">Overdue</div></div>
                <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#11c48d,#0a9971);">
            <div class="d-flex justify-content-between align-items-start">
                <div><div class="stat-val"><?= number_format($stat_returned) ?></div><div class="stat-label">Returned Today</div></div>
                <div class="stat-icon"><i class="fas fa-undo"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#f5a623,#d4870a);">
            <div class="d-flex justify-content-between align-items-start">
                <div><div class="stat-val"><?= number_format($stat_members) ?></div><div class="stat-label">Active Members</div></div>
                <div class="stat-icon"><i class="fas fa-users"></i></div>
            </div>
        </div>
    </div>
</div>

<!-- Filter Card -->
<div class="card mb-3">
    <div class="card-body py-3 px-4">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label fw-medium mb-1" style="font-size:.8rem;">Search</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" name="q" class="form-control" style="border-radius:0 10px 10px 0;"
                           placeholder="Member name/code, book title/ISBN…" value="<?= h($search) ?>">
                </div>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-medium mb-1" style="font-size:.8rem;">Status</label>
                <select name="status" class="form-select form-select-sm" style="border-radius:10px;">
                    <option value="">All</option>
                    <?php foreach (['Issued','Overdue','Returned','Lost'] as $s): ?>
                    <option value="<?= $s ?>" <?= $status_f === $s ? 'selected' : '' ?>><?= $s ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-medium mb-1" style="font-size:.8rem;">From</label>
                <input type="date" name="date_from" class="form-control form-control-sm" style="border-radius:10px;"
                       value="<?= h($date_from) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-medium mb-1" style="font-size:.8rem;">To</label>
                <input type="date" name="date_to" class="form-control form-control-sm" style="border-radius:10px;"
                       value="<?= h($date_to) ?>">
            </div>
            <div class="col-6 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm w-100" style="border-radius:10px;">
                    <i class="fas fa-filter me-1"></i> Filter
                </button>
                <?php if ($search || $status_f || $date_from || $date_to): ?>
                <a href="<?= APP_URL ?>/library/circulation/index.php"
                   class="btn btn-outline-secondary btn-sm" style="border-radius:10px;" title="Clear">
                    <i class="fas fa-times"></i>
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Status Tab Pills -->
<div class="mb-3 d-flex gap-2 flex-wrap">
    <?php
    $tab_counts = [];
    foreach (['','Issued','Overdue','Returned','Lost'] as $ts) {
        $tc = $db->prepare("SELECT COUNT(*) FROM library_circulation WHERE " . ($ts ? "status=?" : "1=1"));
        $tc->execute($ts ? [$ts] : []);
        $tab_counts[$ts] = (int)$tc->fetchColumn();
    }
    $tab_labels = ['' => 'All', 'Issued' => 'Issued', 'Overdue' => 'Overdue', 'Returned' => 'Returned', 'Lost' => 'Lost'];
    $tab_colors = ['' => 'secondary', 'Issued' => 'primary', 'Overdue' => 'danger', 'Returned' => 'success', 'Lost' => 'dark'];
    foreach ($tab_labels as $tv => $tl):
        $active = ($status_f === $tv) ? 'active' : 'outline-' . $tab_colors[$tv];
        $btn_c  = ($status_f === $tv) ? 'btn-' . $tab_colors[$tv] : 'btn-outline-' . $tab_colors[$tv];
        $href   = APP_URL . '/library/circulation/index.php?' . http_build_query(array_filter(['q' => $search, 'status' => $tv, 'date_from' => $date_from, 'date_to' => $date_to]));
    ?>
    <a href="<?= $href ?>" class="btn btn-sm <?= $btn_c ?>" style="border-radius:20px;">
        <?= $tl ?> <span class="badge bg-white text-dark ms-1" style="font-size:.65rem;"><?= number_format($tab_counts[$tv]) ?></span>
    </a>
    <?php endforeach; ?>
</div>

<!-- Table -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:.875rem;">
                <thead class="table-light">
                    <tr>
                        <th class="px-4">#</th>
                        <th>Book</th>
                        <th>Copy / Barcode</th>
                        <th>Member</th>
                        <th>Issue Date</th>
                        <th>Due Date</th>
                        <th>Return Date</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($records)): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted py-5">
                            <i class="fas fa-book-open fa-2x mb-2 d-block opacity-25"></i>
                            No circulation records found.
                            <?php if ($search || $status_f || $date_from || $date_to): ?>
                                <a href="<?= APP_URL ?>/library/circulation/index.php">Clear filters</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($records as $i => $r):
                        $is_overdue = ($r['status'] === 'Overdue');
                        $row_class  = $is_overdue ? 'table-danger' : '';
                    ?>
                    <tr class="<?= $row_class ?>">
                        <td class="px-4 text-muted" style="font-size:.8rem;"><?= $offset + $i + 1 ?></td>
                        <td>
                            <div class="fw-semibold" style="max-width:200px;"><?= h($r['book_title']) ?></div>
                            <?php if ($r['isbn']): ?>
                            <div class="text-muted" style="font-size:.78rem;">ISBN: <?= h($r['isbn']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:.82rem;">
                            <div>Copy #<?= h($r['copy_number']) ?></div>
                            <?php if ($r['barcode']): ?>
                            <div class="text-muted"><?= h($r['barcode']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="fw-medium"><?= h($r['member_name']) ?></div>
                            <div class="text-muted" style="font-size:.78rem;">
                                <?= h($r['member_code']) ?>
                                <span class="badge bg-light text-dark border ms-1"><?= h($r['member_type']) ?></span>
                            </div>
                        </td>
                        <td style="font-size:.82rem;"><?= $r['issue_date'] ? date('d M Y', strtotime($r['issue_date'])) : '—' ?></td>
                        <td style="font-size:.82rem;">
                            <?php if ($r['due_date']): ?>
                                <?= date('d M Y', strtotime($r['due_date'])) ?>
                                <?php if ($is_overdue): ?>
                                    <div class="text-danger fw-semibold" style="font-size:.75rem;">
                                        <?= lib_overdue_days($r['due_date']) ?>d overdue
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td style="font-size:.82rem;"><?= $r['return_date'] ? date('d M Y', strtotime($r['return_date'])) : '<span class="text-muted">—</span>' ?></td>
                        <td><?= lib_circulation_status_badge($r['status']) ?></td>
                        <td class="text-end pe-4">
                            <div class="d-flex gap-1 justify-content-end flex-wrap">
                                <?php if (lib_is_circulation_staff() && in_array($r['status'], ['Issued','Overdue'])): ?>
                                <a href="<?= APP_URL ?>/library/circulation/return.php?id=<?= $r['id'] ?>"
                                   class="btn btn-sm btn-outline-success" style="border-radius:7px;" title="Return / Renew">
                                    <i class="fas fa-undo"></i>
                                </a>
                                <?php endif; ?>
                                <a href="<?= APP_URL ?>/library/fines/index.php?circulation_id=<?= $r['id'] ?>"
                                   class="btn btn-sm btn-outline-warning" style="border-radius:7px;" title="Fine Details">
                                    <i class="fas fa-file-invoice-dollar"></i>
                                </a>
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
            of <?= number_format($total_rows) ?> records
        </small>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php
                $base_url = APP_URL . '/library/circulation/index.php?' . http_build_query(array_filter([
                    'q' => $search, 'status' => $status_f, 'date_from' => $date_from, 'date_to' => $date_to,
                ]));
                ?>
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= $base_url ?>&page=<?= $page - 1 ?>" style="border-radius:7px 0 0 7px;">
                        <i class="fas fa-chevron-left" style="font-size:.7rem;"></i>
                    </a>
                </li>
                <?php for ($p = max(1,$page-2); $p <= min($total_pages,$page+2); $p++): ?>
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

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
