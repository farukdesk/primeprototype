<?php
/**
 * Accounting – Online Payments review queue
 *
 * Students submit online payment claims (bank deposit / transfer or mobile
 * banking) from the Student Accounts Portal. Accounts staff review them here:
 *   • Approve — confirms the money was received; the payment is then recorded
 *     in the books through Collect Payment (which allocates it to the right
 *     fee heads and enforces the unique transaction-number rule).
 *   • Reject  — requires a note, which the student sees on their portal so
 *     they can fix and resubmit.
 *   • Reopen  — a Super Administrator can move a reviewed claim back to
 *     Pending if it was approved / rejected by mistake.
 * Every decision is written to the change log.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('accounting', 'can_create');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/payment-methods-helpers.php';
require_once __DIR__ . '/../change-log/helpers.php';

$page_title = 'Online Payments';
opm_ensure_tables();

$status = strtolower(trim((string)($_GET['status'] ?? 'pending')));
if (!in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) {
    $status = 'pending';
}
$q        = trim((string)($_GET['q'] ?? ''));
$self_url = APP_URL . '/accounting/online-payments.php?status=' . urlencode($status) . ($q !== '' ? '&q=' . urlencode($q) : '');

// ── POST: approve / reject / reopen ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);
    $note   = trim((string)($_POST['note'] ?? ''));

    $row = null;
    if ($id > 0) {
        $st = db()->prepare(
            'SELECT p.*, s.student_id AS sid, s.full_name, m.operator_name AS m_operator
             FROM acc_online_payments p
             JOIN students s ON s.id = p.student_id
             LEFT JOIN acc_payment_methods m ON m.id = p.method_id
             WHERE p.id = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch() ?: null;
    }
    if (!$row) {
        flash_set('danger', 'Online payment submission not found.');
        redirect($self_url, 303);
    }
    $user = auth_user();

    if ($action === 'approve' || $action === 'reject') {
        if ((string)$row['status'] !== 'pending') {
            flash_set('warning', 'Submission #' . $id . ' has already been reviewed.');
            redirect($self_url, 303);
        }
        if ($action === 'reject' && $note === '') {
            flash_set('danger', 'A note explaining the rejection is required — the student will see it on their portal.');
            redirect($self_url, 303);
        }
        $posted = null;
        if ($action === 'approve') {
            // Auto-post to the books: allocate the amount against the student's
            // outstanding dues (oldest first), create the receipt voucher and
            // email the auto-created invoice. If posting fails the submission
            // must stay pending so nothing is silently lost.
            try {
                $posted = opm_post_approved_payment($row);
            } catch (Throwable $e) {
                flash_set('danger', 'Submission #' . $id . ' was NOT approved — posting to the books failed: '
                    . $e->getMessage() . ' The submission remains pending.');
                redirect($self_url, 303);
            }
        }
        $new_status = $action === 'approve' ? 'approved' : 'rejected';
        db()->prepare(
            "UPDATE acc_online_payments
             SET status = ?, admin_note = ?, reviewed_by = ?, reviewed_at = NOW()
             WHERE id = ? AND status = 'pending'"
        )->execute([$new_status, $note !== '' ? $note : null, (int)($user['id'] ?? 0), $id]);
        log_change('accounting', 'UPDATE', $id,
            'Online payment #' . $id . ' — ' . (string)$row['sid'] . ' (' . acc_fmt((float)$row['amount']) . ', txn ' . (string)$row['transaction_number'] . ')',
            'status', 'pending', $new_status,
            $action === 'approve'
                ? 'Online payment approved and auto-posted — voucher ' . (string)($posted['voucher_number'] ?? '—')
                  . '; dues adjusted and the invoice was emailed to the student.'
                : 'Online payment rejected: ' . $note);
        flash_set('success', $action === 'approve'
            ? 'Submission #' . $id . ' approved — ' . acc_fmt((float)$row['amount'])
              . ' was posted to the books (voucher ' . (string)($posted['voucher_number'] ?? '—') . '), the dues were adjusted'
              . (!empty($posted['advance']) ? ' (' . acc_fmt((float)$posted['advance']) . ' recorded as advance above current dues)' : '')
              . ' and the invoice was emailed to the student.'
            : 'Submission #' . $id . ' rejected. The student can see your note and submit a corrected payment.');
    } elseif ($action === 'reopen') {
        if (!is_super_admin()) {
            flash_set('danger', 'Only a Super Administrator can reopen a reviewed submission.');
        } elseif ((string)$row['status'] === 'pending') {
            flash_set('warning', 'Submission #' . $id . ' is already pending.');
        } else {
            db()->prepare("UPDATE acc_online_payments SET status = 'pending', reviewed_by = NULL, reviewed_at = NULL WHERE id = ?")->execute([$id]);
            log_change('accounting', 'UPDATE', $id,
                'Online payment #' . $id . ' — ' . (string)$row['sid'],
                'status', (string)$row['status'], 'pending', 'Online payment review reopened.');
            flash_set('success', 'Submission #' . $id . ' moved back to Pending.');
        }
    } else {
        flash_set('danger', 'Unknown action.');
    }
    redirect($self_url, 303);
}

// ── Counts for the tabs ────────────────────────────────────────────────────
$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
foreach (db()->query('SELECT status, COUNT(*) AS c FROM acc_online_payments GROUP BY status')->fetchAll() as $c) {
    $counts[(string)$c['status']] = (int)$c['c'];
}
$counts['all'] = array_sum($counts);

// ── Listing ────────────────────────────────────────────────────────────────
$where  = [];
$params = [];
if ($status !== 'all') {
    $where[]  = 'p.status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $where[] = '(s.student_id LIKE ? OR s.full_name LIKE ? OR p.transaction_number LIKE ?)';
    $like    = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
$sql = 'SELECT p.*, s.student_id AS sid, s.full_name,
               m.method_type AS m_type, m.bank_name, m.branch_name, m.account_name, m.account_number,
               m.operator_name, m.wallet_number, m.charge_note,
               u.full_name AS reviewed_by_name
        FROM acc_online_payments p
        JOIN students s ON s.id = p.student_id
        LEFT JOIN acc_payment_methods m ON m.id = p.method_id
        LEFT JOIN users u ON u.id = p.reviewed_by'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY p.id DESC LIMIT 300';
$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-clipboard-check me-2 text-warning"></i>Online Payments</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/index.php">Accounting</a></li>
            <li class="breadcrumb-item active">Online Payments</li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/accounting/payment-methods.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-university me-1"></i> Payment Methods</a>
        <a href="<?= APP_URL ?>/accounting/collect-payment.php" class="btn btn-success btn-sm"><i class="fas fa-hand-holding-usd me-1"></i> Collect Payment</a>
    </div>
</div>

<?= flash_show() ?>

<div class="alert alert-info small">
    <i class="fas fa-info-circle me-1"></i>
    <strong>Workflow:</strong> verify the money actually arrived in the selected account/wallet (amount, date and transaction
    number against the bank statement / wallet history and the uploaded receipt), then <strong>Approve</strong> — the payment is posted to the
    books automatically: it is allocated to the student's outstanding dues (oldest first), a receipt voucher is created, the dues are
    adjusted immediately and the invoice is emailed to the student. Any amount above the current dues is kept as an advance.
    If anything is wrong, <strong>Reject</strong> with a note; the student sees it and can resubmit.
    Students are told verification normally takes up to 24 hours (occasionally 48).
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <ul class="nav nav-pills nav-sm gap-1">
            <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $key => $label): ?>
            <li class="nav-item">
                <a class="nav-link py-1 px-3 <?= $status === $key ? 'active' : '' ?>"
                   href="<?= APP_URL ?>/accounting/online-payments.php?status=<?= $key ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>">
                    <?= $label ?> <span class="badge <?= $status === $key ? 'bg-light text-dark' : 'bg-secondary' ?> ms-1"><?= (int)($counts[$key] ?? 0) ?></span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
        <form method="get" class="d-flex gap-2">
            <input type="hidden" name="status" value="<?= h($status) ?>">
            <input type="search" name="q" value="<?= h($q) ?>" class="form-control form-control-sm" style="max-width: 240px;" placeholder="Student ID, name or txn no…">
            <button class="btn btn-outline-secondary btn-sm" type="submit"><i class="fas fa-search"></i></button>
        </form>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">#</th>
                        <th>Submitted</th>
                        <th>Student</th>
                        <th>Method</th>
                        <th>Paid From</th>
                        <th>Paid On</th>
                        <th>Txn No.</th>
                        <th class="text-end">Amount</th>
                        <th>Receipt</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $method_title = $r['m_type'] !== null
                        ? opm_method_title([
                            'method_type'   => (string)$r['m_type'],
                            'bank_name'     => $r['bank_name'],
                            'branch_name'   => $r['branch_name'],
                            'operator_name' => $r['operator_name'],
                            'wallet_number' => $r['wallet_number'],
                        ])
                        : opm_type_label((string)$r['method_type']);
                ?>
                    <tr>
                        <td class="ps-4 fw-semibold"><?= (int)$r['id'] ?></td>
                        <td class="small"><?= h((string)$r['created_at']) ?></td>
                        <td class="small">
                            <span class="fw-semibold"><?= h((string)$r['sid']) ?></span><br>
                            <span class="text-muted"><?= h((string)$r['full_name']) ?></span>
                        </td>
                        <td class="small">
                            <span class="badge bg-<?= (string)$r['method_type'] === 'bank' ? 'primary' : 'success' ?> mb-1"><?= h(opm_type_label((string)$r['method_type'])) ?></span><br>
                            <?= h($method_title) ?>
                            <?php if ((string)$r['method_type'] === 'bank' && !empty($r['account_number'])): ?>
                            <br><span class="text-muted font-monospace"><?= h((string)$r['account_number']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= h((string)$r['paid_from']) ?></td>
                        <td class="small"><?= h((string)$r['paid_date']) ?><?= !empty($r['paid_time']) ? '<br>' . h((string)$r['paid_time']) : '' ?></td>
                        <td class="small font-monospace"><?= h((string)$r['transaction_number']) ?></td>
                        <td class="text-end fw-semibold"><?= h(number_format((float)$r['amount'], 2)) ?></td>
                        <td>
                            <?php if (!empty($r['receipt_file'])): ?>
                            <a href="<?= h(opm_receipt_url((string)$r['receipt_file'])) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-info" title="View receipt"><i class="fas fa-file-image"></i></a>
                            <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
                        </td>
                        <td>
                            <?= opm_status_badge((string)$r['status']) ?>
                            <?php if ((string)$r['status'] !== 'pending'): ?>
                            <div class="small text-muted mt-1">
                                by <?= h((string)($r['reviewed_by_name'] ?? '—')) ?><br><?= h((string)($r['reviewed_at'] ?? '')) ?>
                                <?php if (!empty($r['admin_note'])): ?><br><em><?= h((string)$r['admin_note']) ?></em><?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end pe-4 text-nowrap">
                            <?php if ((string)$r['status'] === 'pending'): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Approve submission #<?= (int)$r['id'] ?>? Confirm the money actually arrived before approving — the payment will be posted to the books, the dues adjusted and the invoice emailed automatically.');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn-sm btn-success" title="Approve"><i class="fas fa-check"></i></button>
                            </form>
                            <form method="post" class="d-inline" onsubmit="var n = prompt('Reason for rejecting #<?= (int)$r['id'] ?> (the student will see this):'); if (n === null || n.trim() === '') { return false; } this.note.value = n.trim(); return true;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <input type="hidden" name="note" value="">
                                <button class="btn btn-sm btn-outline-danger" title="Reject"><i class="fas fa-times"></i></button>
                            </form>
                            <?php else: ?>
                                <?php if ((string)$r['status'] === 'approved'): ?>
                                    <?php if (!empty($r['voucher_id'])): ?>
                                    <a href="<?= APP_URL ?>/accounting/voucher-view.php?id=<?= (int)$r['voucher_id'] ?>" class="btn btn-sm btn-outline-success" title="View posted voucher"><i class="fas fa-file-invoice"></i></a>
                                    <?php else: ?>
                                    <a href="<?= APP_URL ?>/accounting/collect-payment.php" class="btn btn-sm btn-outline-success" title="Approved before auto-posting — record it through Collect Payment"><i class="fas fa-hand-holding-usd"></i></a>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if (is_super_admin()): ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Move submission #<?= (int)$r['id'] ?> back to Pending?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="reopen">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <button class="btn btn-sm btn-outline-secondary" title="Reopen"><i class="fas fa-rotate-left"></i></button>
                                </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="11" class="text-center text-muted py-4">No <?= $status === 'all' ? '' : h($status) . ' ' ?>online payment submissions found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="px-4 py-2 small text-muted">
            Approving a submission posts it to the books automatically — dues are adjusted, a receipt voucher is created and the
            invoice is emailed to the student. Older submissions approved before auto-posting (no voucher link) still need to be
            recorded through Collect Payment with the same method and transaction number.
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
