<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_access('library');
require_once __DIR__ . '/../helpers.php';

$db = db();

// ── Filters ───────────────────────────────────────────────────────────────────
$search      = trim($_GET['q']           ?? '');
$status_f    = trim($_GET['status']      ?? '');
$type_f      = trim($_GET['fine_type']   ?? '');
$date_from   = trim($_GET['date_from']   ?? '');
$date_to     = trim($_GET['date_to']     ?? '');
$circ_id_f   = (int)($_GET['circulation_id'] ?? 0);

// ── Pagination ────────────────────────────────────────────────────────────────
$per_page = 20;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

// ── Stats ─────────────────────────────────────────────────────────────────────
$stat_unpaid_sum   = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM library_fines WHERE status='Unpaid'")->fetchColumn();
$stat_unpaid_cnt   = (int)$db->query("SELECT COUNT(*) FROM library_fines WHERE status='Unpaid'")->fetchColumn();
$stat_paid_sum     = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM library_fines WHERE status='Paid'")->fetchColumn();
$stat_paid_cnt     = (int)$db->query("SELECT COUNT(*) FROM library_fines WHERE status='Paid'")->fetchColumn();
$stat_waived_sum   = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM library_fines WHERE status='Waived'")->fetchColumn();
$stat_collected_mo = (float)$db->query(
    "SELECT COALESCE(SUM(amount),0) FROM library_fines
     WHERE status='Paid' AND MONTH(paid_at)=MONTH(NOW()) AND YEAR(paid_at)=YEAR(NOW())"
)->fetchColumn();

// ── Build WHERE ───────────────────────────────────────────────────────────────
$where  = '1=1';
$params = [];

if ($search !== '') {
    $like     = '%' . $search . '%';
    $where   .= ' AND (COALESCE(s.full_name, u.full_name) LIKE ? OR m.member_code LIKE ? OR b.title LIKE ?)';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($status_f !== '') {
    $where   .= ' AND f.status = ?';
    $params[] = $status_f;
}
if ($type_f !== '') {
    $where   .= ' AND f.fine_type = ?';
    $params[] = $type_f;
}
if ($date_from !== '') {
    $where   .= ' AND DATE(f.created_at) >= ?';
    $params[] = $date_from;
}
if ($date_to !== '') {
    $where   .= ' AND DATE(f.created_at) <= ?';
    $params[] = $date_to;
}
if ($circ_id_f) {
    $where   .= ' AND f.circulation_id = ?';
    $params[] = $circ_id_f;
}

// ── Count ─────────────────────────────────────────────────────────────────────
$count_sql = "SELECT COUNT(*)
              FROM library_fines f
              JOIN library_members m ON m.id = f.member_id
              JOIN library_circulation c ON c.id = f.circulation_id
              JOIN library_books b ON b.id = c.book_id
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
$sql = "SELECT f.*,
               m.member_code, m.member_type,
               COALESCE(s.full_name, u.full_name) AS member_name,
               b.title AS book_title,
               col_u.full_name AS collector_name
        FROM library_fines f
        JOIN library_members m ON m.id = f.member_id
        JOIN library_circulation c ON c.id = f.circulation_id
        JOIN library_books b ON b.id = c.book_id
        LEFT JOIN students s ON s.id = m.student_id
        LEFT JOIN users u ON u.id = m.user_id
        LEFT JOIN users col_u ON col_u.id = f.collected_by
        WHERE $where
        ORDER BY f.id DESC
        LIMIT $per_page OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$fines = $stmt->fetchAll();

// Next receipt number for pay forms
$next_receipt = lib_generate_receipt();

$flash_success = flash_get('success');
$flash_error   = flash_get('error');

$page_title = 'Fine Management';
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Breadcrumb -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/library/index.php">Library</a></li>
            <li class="breadcrumb-item active">Fines</li>
        </ol>
    </nav>
    <?php if ($circ_id_f): ?>
    <a href="<?= APP_URL ?>/library/circulation/index.php"
       class="btn btn-outline-secondary btn-sm" style="border-radius:10px;">
        <i class="fas fa-arrow-left me-1"></i> Back to Circulation
    </a>
    <?php endif; ?>
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

<?php if ($circ_id_f): ?>
<div class="alert alert-info py-2 px-3 mb-3" style="font-size:.85rem;">
    <i class="fas fa-filter me-1"></i> Showing fines for Circulation #<?= $circ_id_f ?>
    <a href="<?= APP_URL ?>/library/fines/index.php" class="ms-2">Clear filter</a>
</div>
<?php endif; ?>

<!-- Outstanding Amount Banner -->
<?php if ($stat_unpaid_sum > 0): ?>
<div class="alert alert-danger d-flex align-items-center gap-3 mb-4">
    <i class="fas fa-exclamation-triangle fa-2x"></i>
    <div>
        <strong>Total Outstanding Fines:</strong>
        <span class="fs-5 fw-bold ms-2">৳<?= number_format($stat_unpaid_sum, 2) ?></span>
        <span class="text-muted ms-2">(<?= number_format($stat_unpaid_cnt) ?> unpaid)</span>
    </div>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#e74c3c,#c0392b);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val">৳<?= number_format($stat_unpaid_sum, 2) ?></div>
                    <div class="stat-label">Unpaid (<?= $stat_unpaid_cnt ?>)</div>
                </div>
                <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#11c48d,#0a9971);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val">৳<?= number_format($stat_paid_sum, 2) ?></div>
                    <div class="stat-label">Paid (<?= $stat_paid_cnt ?>)</div>
                </div>
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#6c757d,#495057);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val">৳<?= number_format($stat_waived_sum, 2) ?></div>
                    <div class="stat-label">Waived</div>
                </div>
                <div class="stat-icon"><i class="fas fa-ban"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#4f8ef7,#2d63e8);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val">৳<?= number_format($stat_collected_mo, 2) ?></div>
                    <div class="stat-label">Collected This Month</div>
                </div>
                <div class="stat-icon"><i class="fas fa-coins"></i></div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-3 px-4">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label fw-medium mb-1" style="font-size:.8rem;">Search Member / Book</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" name="q" class="form-control" style="border-radius:0 10px 10px 0;"
                           placeholder="Name, code, book title…" value="<?= h($search) ?>">
                </div>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-medium mb-1" style="font-size:.8rem;">Status</label>
                <select name="status" class="form-select form-select-sm" style="border-radius:10px;">
                    <option value="">All Statuses</option>
                    <?php foreach (['Unpaid','Paid','Waived'] as $sv): ?>
                    <option value="<?= $sv ?>" <?= $status_f === $sv ? 'selected' : '' ?>><?= $sv ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-medium mb-1" style="font-size:.8rem;">Fine Type</label>
                <select name="fine_type" class="form-select form-select-sm" style="border-radius:10px;">
                    <option value="">All Types</option>
                    <?php foreach (['Late','Lost','Damaged','Other'] as $tv): ?>
                    <option value="<?= $tv ?>" <?= $type_f === $tv ? 'selected' : '' ?>><?= $tv ?></option>
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
            <div class="col-12 col-md-1 d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm flex-fill" style="border-radius:10px;">
                    <i class="fas fa-filter"></i>
                </button>
                <?php if ($search || $status_f || $type_f || $date_from || $date_to || $circ_id_f): ?>
                <a href="<?= APP_URL ?>/library/fines/index.php"
                   class="btn btn-outline-secondary btn-sm" style="border-radius:10px;">
                    <i class="fas fa-times"></i>
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Table -->
<div class="card">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
        <h6 class="mb-0">Fine Records</h6>
        <span class="badge bg-secondary"><?= number_format($total_rows) ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:.875rem;">
                <thead class="table-light">
                    <tr>
                        <th class="px-4">#</th>
                        <th>Member</th>
                        <th>Book</th>
                        <th>Fine Type</th>
                        <th>Amount</th>
                        <th>Days Overdue</th>
                        <th>Status</th>
                        <th>Receipt #</th>
                        <th>Created</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($fines)): ?>
                    <tr>
                        <td colspan="10" class="text-center text-muted py-5">
                            <i class="fas fa-file-invoice-dollar fa-2x mb-2 d-block opacity-25"></i>
                            No fines found.
                            <?php if ($search || $status_f || $type_f || $date_from || $date_to): ?>
                                <a href="<?= APP_URL ?>/library/fines/index.php">Clear filters</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($fines as $i => $f):
                        $type_badge_map = ['Late'=>'bg-danger','Lost'=>'bg-dark','Damaged'=>'bg-warning text-dark','Other'=>'bg-secondary'];
                        $type_cls       = $type_badge_map[$f['fine_type']] ?? 'bg-secondary';
                    ?>
                    <tr class="<?= $f['status'] === 'Unpaid' ? 'table-warning' : '' ?>">
                        <td class="px-4 text-muted" style="font-size:.8rem;"><?= $offset + $i + 1 ?></td>
                        <td>
                            <div class="fw-medium"><?= h($f['member_name']) ?></div>
                            <div class="text-muted" style="font-size:.78rem;"><?= h($f['member_code']) ?> · <?= h($f['member_type']) ?></div>
                        </td>
                        <td style="max-width:160px;">
                            <div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= h($f['book_title']) ?>">
                                <?= h($f['book_title']) ?>
                            </div>
                            <a href="<?= APP_URL ?>/library/circulation/index.php?q=<?= $f['circulation_id'] ?>"
                               class="text-muted" style="font-size:.78rem;">Circ #<?= $f['circulation_id'] ?></a>
                        </td>
                        <td><span class="badge <?= $type_cls ?>"><?= h($f['fine_type']) ?></span></td>
                        <td class="fw-semibold <?= $f['status'] === 'Unpaid' ? 'text-danger' : 'text-success' ?>">
                            ৳<?= number_format($f['amount'], 2) ?>
                        </td>
                        <td style="font-size:.82rem;">
                            <?= $f['days_overdue'] ? h($f['days_overdue']) . 'd' : '<span class="text-muted">—</span>' ?>
                        </td>
                        <td><?= lib_fine_status_badge($f['status']) ?></td>
                        <td style="font-size:.82rem;">
                            <?= $f['receipt_number'] ? h($f['receipt_number']) : '<span class="text-muted">—</span>' ?>
                        </td>
                        <td style="font-size:.82rem;"><?= $f['created_at'] ? date('d M Y', strtotime($f['created_at'])) : '—' ?></td>
                        <td class="text-end pe-4">
                            <div class="d-flex gap-1 justify-content-end">
                                <?php if ($f['status'] === 'Unpaid' && lib_is_circulation_staff()): ?>
                                <!-- Pay Button -->
                                <button type="button" class="btn btn-sm btn-success" style="border-radius:7px;"
                                        onclick="openPayModal(<?= $f['id'] ?>, '<?= h(addslashes($f['member_name'])) ?>', <?= $f['amount'] ?>, '<?= h($next_receipt) ?>')">
                                    <i class="fas fa-money-bill me-1"></i> Pay
                                </button>
                                <?php endif; ?>
                                <?php if ($f['status'] === 'Unpaid' && lib_is_staff()): ?>
                                <!-- Waive Button -->
                                <button type="button" class="btn btn-sm btn-outline-secondary" style="border-radius:7px;"
                                        onclick="openWaiveModal(<?= $f['id'] ?>, '<?= h(addslashes($f['member_name'])) ?>', <?= $f['amount'] ?>)">
                                    <i class="fas fa-ban"></i>
                                </button>
                                <?php endif; ?>
                                <!-- View Details -->
                                <a href="<?= APP_URL ?>/library/fines/index.php?circulation_id=<?= $f['circulation_id'] ?>"
                                   class="btn btn-sm btn-outline-secondary" style="border-radius:7px;" title="View all fines for this circulation">
                                    <i class="fas fa-eye"></i>
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
                <?php $base_url = APP_URL . '/library/fines/index.php?' . http_build_query(array_filter([
                    'q' => $search, 'status' => $status_f, 'fine_type' => $type_f,
                    'date_from' => $date_from, 'date_to' => $date_to,
                    'circulation_id' => $circ_id_f ?: '',
                ])); ?>
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

<!-- Pay Fine Modal -->
<div class="modal fade" id="payModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="<?= APP_URL ?>/library/fines/pay.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="pay">
                <input type="hidden" name="fine_id" id="payFineId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-money-bill me-2 text-success"></i> Collect Fine Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Collecting payment from <strong id="payMemberName"></strong>.</p>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Amount</label>
                        <div class="input-group">
                            <span class="input-group-text">৳</span>
                            <input type="text" class="form-control" id="payAmount" readonly style="border-radius:0 10px 10px 0;">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Receipt Number</label>
                        <input type="text" name="receipt_number" id="payReceiptNumber" class="form-control"
                               style="border-radius:10px;" required>
                        <div class="form-text">Auto-generated. You may edit if needed.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success" style="border-radius:10px;">
                        <i class="fas fa-check me-1"></i> Confirm Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Waive Fine Modal -->
<?php if (lib_is_staff()): ?>
<div class="modal fade" id="waiveModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="<?= APP_URL ?>/library/fines/pay.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="waive">
                <input type="hidden" name="fine_id" id="waiveFineId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-ban me-2 text-secondary"></i> Waive Fine</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Waiving fine of <strong id="waiveAmount"></strong> for <strong id="waiveMemberName"></strong>.</p>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Reason for Waiving <span class="text-danger">*</span></label>
                        <textarea name="waive_notes" class="form-control" rows="2" required
                                  style="border-radius:10px;"
                                  placeholder="Enter reason…"></textarea>
                    </div>
                    <div class="alert alert-warning py-2" style="font-size:.85rem;">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        This action requires staff-level authorization and will be audited.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning" style="border-radius:10px;">
                        <i class="fas fa-ban me-1"></i> Waive Fine
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const payModal   = new bootstrap.Modal(document.getElementById('payModal'));
<?php if (lib_is_staff()): ?>
const waiveModal = new bootstrap.Modal(document.getElementById('waiveModal'));
<?php endif; ?>

function openPayModal(fineId, memberName, amount, receipt) {
    document.getElementById('payFineId').value         = fineId;
    document.getElementById('payMemberName').textContent = memberName;
    document.getElementById('payAmount').value         = parseFloat(amount).toFixed(2);
    document.getElementById('payReceiptNumber').value  = receipt;
    payModal.show();
}

function openWaiveModal(fineId, memberName, amount) {
    document.getElementById('waiveFineId').value          = fineId;
    document.getElementById('waiveMemberName').textContent = memberName;
    document.getElementById('waiveAmount').textContent    = '৳' + parseFloat(amount).toFixed(2);
    waiveModal.show();
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
