<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../helpers.php';

if (!lib_is_circulation_staff()) {
    flash_set('error', 'You do not have permission to manage reservations.');
    redirect(APP_URL . '/library/circulation/index.php');
}

$db   = db();
$user = auth_user();

// ── POST Actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = trim($_POST['action'] ?? '');

    if ($action === 'reserve') {
        $member_id = (int)($_POST['member_id'] ?? 0);
        $book_id   = (int)($_POST['book_id']   ?? 0);
        $notes     = trim($_POST['notes']       ?? '');
        $errors    = [];

        if (!$member_id) $errors[] = 'Please select a member.';
        if (!$book_id)   $errors[] = 'Please select a book.';

        if (!$errors) {
            // Check member exists and is active
            $mem = $db->prepare('SELECT * FROM library_members WHERE id=? AND is_active=1');
            $mem->execute([$member_id]);
            if (!$mem->fetch()) $errors[] = 'Member not found or inactive.';

            // Check book exists
            $bk = $db->prepare('SELECT * FROM library_books WHERE id=?');
            $bk->execute([$book_id]);
            if (!$bk->fetch()) $errors[] = 'Book not found.';

            // Check max reservations per member
            $max_res = (int)lib_setting('max_reservations', 3);
            $cur_res = $db->prepare(
                "SELECT COUNT(*) FROM library_reservations
                 WHERE member_id=? AND status IN ('Pending','Available')"
            );
            $cur_res->execute([$member_id]);
            if ((int)$cur_res->fetchColumn() >= $max_res) {
                $errors[] = "Member has reached the reservation limit ($max_res).";
            }

            // Check duplicate reservation
            $dup = $db->prepare(
                "SELECT id FROM library_reservations
                 WHERE book_id=? AND member_id=? AND status IN ('Pending','Available') LIMIT 1"
            );
            $dup->execute([$book_id, $member_id]);
            if ($dup->fetch()) $errors[] = 'Member already has a pending reservation for this book.';
        }

        if (!$errors) {
            $expiry_hours = (int)lib_setting('reservation_expiry_hours', 48);
            $ins = $db->prepare(
                "INSERT INTO library_reservations
                     (book_id, member_id, reserved_by, status, reserved_at, expires_at, notes)
                 VALUES (?, ?, ?, 'Pending', NOW(),
                         DATE_ADD(NOW(), INTERVAL ? HOUR), ?)"
            );
            $ins->execute([$book_id, $member_id, $user['id'], $expiry_hours, $notes]);
            $res_id = (int)$db->lastInsertId();

            lib_audit('RESERVATION_CREATED', 'reservations', $res_id,
                "Book #$book_id for Member #$member_id",
                "Reserved by " . ($user['full_name'] ?? 'Staff')
            );
            flash_set('success', 'Reservation created successfully.');
            redirect(APP_URL . '/library/circulation/reserve.php');
        }
        $page_errors = $errors;

    } elseif ($action === 'cancel') {
        $res_id = (int)($_POST['reservation_id'] ?? 0);
        if ($res_id) {
            $db->prepare("UPDATE library_reservations SET status='Cancelled' WHERE id=?")
               ->execute([$res_id]);
            lib_audit('RESERVATION_CANCELLED', 'reservations', $res_id, "Reservation #$res_id", '');
            flash_set('success', 'Reservation cancelled.');
        }
        redirect(APP_URL . '/library/circulation/reserve.php');

    } elseif ($action === 'notify') {
        $res_id = (int)($_POST['reservation_id'] ?? 0);
        if ($res_id) {
            $db->prepare(
                "UPDATE library_reservations SET status='Available', notified_at=NOW() WHERE id=?"
            )->execute([$res_id]);
            lib_audit('RESERVATION_NOTIFIED', 'reservations', $res_id, "Reservation #$res_id", 'Marked as Available');
            flash_set('success', 'Member notified and reservation marked as Available.');
        }
        redirect(APP_URL . '/library/circulation/reserve.php');
    }
}

// ── GET: Load data ────────────────────────────────────────────────────────────
$status_f = trim($_GET['status'] ?? '');
$search_q = trim($_GET['q']      ?? '');

$where  = "r.status != 'Fulfilled'";
$params = [];

if ($status_f !== '') {
    $where   .= ' AND r.status = ?';
    $params[] = $status_f;
} else {
    $where .= " AND r.status IN ('Pending','Available')";
}

if ($search_q !== '') {
    $like     = '%' . $search_q . '%';
    $where   .= ' AND (b.title LIKE ? OR COALESCE(s.full_name, u.full_name) LIKE ? OR m.member_code LIKE ?)';
    $params[] = $like; $params[] = $like; $params[] = $like;
}

$per_page    = 20;
$page        = max(1, (int)($_GET['page'] ?? 1));
$offset      = ($page - 1) * $per_page;

$count_s = $db->prepare(
    "SELECT COUNT(*) FROM library_reservations r
     JOIN library_books b ON b.id = r.book_id
     JOIN library_members m ON m.id = r.member_id
     LEFT JOIN students s ON s.id = m.student_id
     LEFT JOIN users u ON u.id = m.user_id
     WHERE $where"
);
$count_s->execute($params);
$total_rows  = (int)$count_s->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$stmt = $db->prepare(
    "SELECT r.*,
            b.title AS book_title, b.isbn, b.available_copies,
            m.member_code, m.member_type,
            COALESCE(s.full_name, u.full_name) AS member_name
     FROM library_reservations r
     JOIN library_books b ON b.id = r.book_id
     JOIN library_members m ON m.id = r.member_id
     LEFT JOIN students s ON s.id = m.student_id
     LEFT JOIN users u ON u.id = m.user_id
     WHERE $where
     ORDER BY r.id DESC
     LIMIT $per_page OFFSET $offset"
);
$stmt->execute($params);
$reservations = $stmt->fetchAll();

// Form data
$active_members = $db->query(
    "SELECT m.id, m.member_code, m.member_type,
            COALESCE(s.full_name, u.full_name, m.name) AS display_name
     FROM library_members m
     LEFT JOIN students s ON s.id = m.student_id
     LEFT JOIN users u ON u.id = m.user_id
     WHERE m.is_active = 1
     ORDER BY display_name ASC"
)->fetchAll();

$all_books = $db->query(
    'SELECT id, title, isbn, available_copies
     FROM library_books
     ORDER BY available_copies ASC, title ASC'
)->fetchAll();

// Stats
$stat_pending   = (int)$db->query("SELECT COUNT(*) FROM library_reservations WHERE status='Pending'")->fetchColumn();
$stat_available = (int)$db->query("SELECT COUNT(*) FROM library_reservations WHERE status='Available'")->fetchColumn();
$stat_fulfilled = (int)$db->query("SELECT COUNT(*) FROM library_reservations WHERE status='Fulfilled'")->fetchColumn();
$stat_expired   = (int)$db->query("SELECT COUNT(*) FROM library_reservations WHERE status='Expired'")->fetchColumn();

$page_errors = $page_errors ?? [];
$flash_success = flash_get('success');
$flash_error   = flash_get('error');

$page_title = 'Book Reservations';
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Breadcrumb -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/library/index.php">Library</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/library/circulation/index.php">Circulation</a></li>
            <li class="breadcrumb-item active">Reservations</li>
        </ol>
    </nav>
    <button class="btn btn-primary btn-sm" style="border-radius:10px;"
            data-bs-toggle="modal" data-bs-target="#addReservationModal">
        <i class="fas fa-plus me-1"></i> Add Reservation
    </button>
</div>

<?php if ($flash_success): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="fas fa-check-circle me-1"></i> <?= h($flash_success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($flash_error): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="fas fa-exclamation-circle me-1"></i> <?= h($flash_error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#f5a623,#d4870a);">
            <div class="d-flex justify-content-between align-items-start">
                <div><div class="stat-val"><?= $stat_pending ?></div><div class="stat-label">Pending</div></div>
                <div class="stat-icon"><i class="fas fa-clock"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#11c48d,#0a9971);">
            <div class="d-flex justify-content-between align-items-start">
                <div><div class="stat-val"><?= $stat_available ?></div><div class="stat-label">Available</div></div>
                <div class="stat-icon"><i class="fas fa-check"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#4f8ef7,#2d63e8);">
            <div class="d-flex justify-content-between align-items-start">
                <div><div class="stat-val"><?= $stat_fulfilled ?></div><div class="stat-label">Fulfilled</div></div>
                <div class="stat-icon"><i class="fas fa-bookmark"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#6c757d,#495057);">
            <div class="d-flex justify-content-between align-items-start">
                <div><div class="stat-val"><?= $stat_expired ?></div><div class="stat-label">Expired</div></div>
                <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
            </div>
        </div>
    </div>
</div>

<!-- Filter -->
<div class="card mb-3">
    <div class="card-body py-3 px-4">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-12 col-md-5">
                <label class="form-label fw-medium mb-1" style="font-size:.8rem;">Search</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" name="q" class="form-control" style="border-radius:0 10px 10px 0;"
                           placeholder="Book title, member name/code…" value="<?= h($search_q) ?>">
                </div>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label fw-medium mb-1" style="font-size:.8rem;">Status</label>
                <select name="status" class="form-select form-select-sm" style="border-radius:10px;">
                    <option value="">Active (Pending + Available)</option>
                    <?php foreach (['Pending','Available','Fulfilled','Cancelled','Expired'] as $sv): ?>
                    <option value="<?= $sv ?>" <?= $status_f === $sv ? 'selected' : '' ?>><?= $sv ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm w-100" style="border-radius:10px;">
                    <i class="fas fa-filter me-1"></i> Filter
                </button>
                <?php if ($search_q || $status_f): ?>
                <a href="<?= APP_URL ?>/library/circulation/reserve.php"
                   class="btn btn-outline-secondary btn-sm" style="border-radius:10px;">
                    <i class="fas fa-times"></i>
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Reservations Table -->
<div class="card">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
        <h6 class="mb-0">Reservations</h6>
        <span class="badge bg-secondary"><?= number_format($total_rows) ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:.875rem;">
                <thead class="table-light">
                    <tr>
                        <th class="px-4">#</th>
                        <th>Book</th>
                        <th>Member</th>
                        <th>Reserved At</th>
                        <th>Expires At</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($reservations)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            <i class="fas fa-bookmark fa-2x mb-2 d-block opacity-25"></i>
                            No reservations found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($reservations as $i => $r): ?>
                    <tr>
                        <td class="px-4 text-muted" style="font-size:.8rem;"><?= $offset + $i + 1 ?></td>
                        <td>
                            <div class="fw-semibold"><?= h($r['book_title']) ?></div>
                            <?php if ($r['isbn']): ?><div class="text-muted" style="font-size:.78rem;">ISBN: <?= h($r['isbn']) ?></div><?php endif; ?>
                            <?php if ($r['available_copies'] > 0): ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size:.7rem;"><?= $r['available_copies'] ?> available</span>
                            <?php else: ?>
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle" style="font-size:.7rem;">None available</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="fw-medium"><?= h($r['member_name']) ?></div>
                            <div class="text-muted" style="font-size:.78rem;"><?= h($r['member_code']) ?> · <?= h($r['member_type']) ?></div>
                        </td>
                        <td style="font-size:.82rem;"><?= $r['reserved_at'] ? date('d M Y H:i', strtotime($r['reserved_at'])) : '—' ?></td>
                        <td style="font-size:.82rem;">
                            <?php if ($r['expires_at']): ?>
                                <?= date('d M Y H:i', strtotime($r['expires_at'])) ?>
                                <?php if (strtotime($r['expires_at']) < time() && $r['status'] === 'Pending'): ?>
                                    <div class="text-danger" style="font-size:.75rem;">Expired</div>
                                <?php endif; ?>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td><?= lib_reservation_status_badge($r['status']) ?></td>
                        <td class="text-end pe-4">
                            <div class="d-flex gap-1 justify-content-end">
                                <?php if ($r['status'] === 'Pending'): ?>
                                <form method="POST" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="notify">
                                    <input type="hidden" name="reservation_id" value="<?= $r['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-success" style="border-radius:7px;" title="Mark Available / Notify">
                                        <i class="fas fa-bell"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                                <?php if (in_array($r['status'], ['Pending','Available'])): ?>
                                <form method="POST" style="display:inline;"
                                      onsubmit="return confirm('Cancel this reservation?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="cancel">
                                    <input type="hidden" name="reservation_id" value="<?= $r['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" style="border-radius:7px;" title="Cancel">
                                        <i class="fas fa-times"></i>
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
            of <?= number_format($total_rows) ?> reservations
        </small>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php $base_url = APP_URL . '/library/circulation/reserve.php?' . http_build_query(array_filter(['q' => $search_q, 'status' => $status_f])); ?>
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= $base_url ?>&page=<?= $page-1 ?>" style="border-radius:7px 0 0 7px;">
                        <i class="fas fa-chevron-left" style="font-size:.7rem;"></i>
                    </a>
                </li>
                <?php for ($p = max(1,$page-2); $p <= min($total_pages,$page+2); $p++): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <a class="page-link" href="<?= $base_url ?>&page=<?= $p ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= $base_url ?>&page=<?= $page+1 ?>" style="border-radius:0 7px 7px 0;">
                        <i class="fas fa-chevron-right" style="font-size:.7rem;"></i>
                    </a>
                </li>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<!-- Add Reservation Modal -->
<div class="modal fade" id="addReservationModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reserve">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-bookmark me-2 text-primary"></i> Add Reservation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if ($page_errors): ?>
                    <div class="alert alert-danger py-2">
                        <ul class="mb-0 ps-3">
                            <?php foreach ($page_errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Member <span class="text-danger">*</span></label>
                        <select name="member_id" class="form-select" required style="border-radius:10px;">
                            <option value="">— Select Member —</option>
                            <?php foreach ($active_members as $mem): ?>
                            <option value="<?= $mem['id'] ?>">
                                <?= h($mem['display_name']) ?> (<?= h($mem['member_code']) ?> · <?= h($mem['member_type']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Book <span class="text-danger">*</span></label>
                        <select name="book_id" class="form-select" required style="border-radius:10px;">
                            <option value="">— Select Book —</option>
                            <?php foreach ($all_books as $bk): ?>
                            <option value="<?= $bk['id'] ?>">
                                <?= h($bk['title']) ?>
                                <?= $bk['isbn'] ? '(ISBN: ' . h($bk['isbn']) . ')' : '' ?>
                                — <?= $bk['available_copies'] ?> available
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Books with 0 available copies are shown first for reservation priority.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Notes</label>
                        <textarea name="notes" class="form-control" rows="2" style="border-radius:10px;"
                                  placeholder="Optional notes…"></textarea>
                    </div>
                    <div class="text-muted" style="font-size:.82rem;">
                        <i class="fas fa-info-circle me-1"></i>
                        Reservation expires in <?= lib_setting('reservation_expiry_hours', 48) ?> hours.
                        Max <?= lib_setting('max_reservations', 3) ?> active reservations per member.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                        <i class="fas fa-bookmark me-1"></i> Reserve
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($page_errors): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    new bootstrap.Modal(document.getElementById('addReservationModal')).show();
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
