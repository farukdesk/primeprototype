<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';

if (!acc_can_access_voucher_delete()) {
    flash_set('error', 'You do not have permission to view voucher delete requests.');
    redirect(APP_URL . '/index.php');
}

$currency = acc_currency();

// ── Review actions ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action     = $_POST['action'] ?? '';
    $request_id = (int)($_POST['request_id'] ?? 0);
    $note       = trim($_POST['note'] ?? '');

    try {
        switch ($action) {
            case 'dd_approve':
                acc_dd_review_delete_request($request_id, true, $note);
                flash_set('success', 'Approved and forwarded to the Treasurer for final confirmation.');
                break;
            case 'dd_reject':
                acc_dd_review_delete_request($request_id, false, $note);
                flash_set('success', 'Delete request rejected.');
                break;
            case 'treasurer_confirm':
                acc_treasurer_review_delete_request($request_id, true, $note);
                flash_set('success', 'Deletion confirmed. The voucher and its calculations have been cleared.');
                break;
            case 'treasurer_reject':
                acc_treasurer_review_delete_request($request_id, false, $note);
                flash_set('success', 'Delete request rejected.');
                break;
            default:
                flash_set('error', 'Unknown action.');
        }
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
    }
    redirect(APP_URL . '/accounting/voucher-delete-requests.php?id=' . $request_id);
}

// ── Detail view ───────────────────────────────────────────────────────────────
$detail_id = (int)($_GET['id'] ?? 0);
$detail    = $detail_id ? acc_get_delete_request($detail_id) : null;

// ── List view ─────────────────────────────────────────────────────────────────
$f_status = $_GET['status'] ?? '';
$valid    = ['pending_dd', 'pending_treasurer', 'deleted', 'rejected'];

$where = [];
$params = [];
if (in_array($f_status, $valid, true)) { $where[] = 'r.status = ?'; $params[] = $f_status; }
$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = db()->prepare(
    "SELECT r.*, ru.full_name AS requested_by_name
     FROM acc_voucher_delete_requests r
     LEFT JOIN users ru ON ru.id = r.requested_by
     $where_sql
     ORDER BY r.id DESC
     LIMIT 200"
);
$stmt->execute($params);
$requests = $stmt->fetchAll();

$can_dd        = acc_can_review_voucher_delete_dd();
$can_treasurer = acc_can_review_voucher_delete_treasurer();

$page_title = 'Voucher Delete Requests';
require_once __DIR__ . '/../includes/header.php';

function vdel_attachment_url(?string $name): ?string
{
    return $name ? (UPLOAD_URL . '/voucher-deletes/' . rawurlencode($name)) : null;
}
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-trash-restore me-2 text-danger"></i>Voucher Delete Requests</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/index.php">Accounting</a></li>
            <li class="breadcrumb-item active">Delete Requests</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/accounting/vouchers.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Vouchers</a>
</div>

<?= flash_show() ?>

<?php if ($detail): ?>
<?php
    $snap       = $detail['voucher_snapshot'] ? json_decode($detail['voucher_snapshot'], true) : null;
    $snap_items = $snap['items'] ?? [];
    $att_url    = vdel_attachment_url($detail['attachment']);
?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header d-flex justify-content-between align-items-center py-2 px-4">
        <strong>Request #<?= (int)$detail['id'] ?> — Voucher <?= h($detail['voucher_number']) ?></strong>
        <?= acc_voucher_delete_status_badge($detail['status']) ?>
    </div>
    <div class="card-body p-4">
        <div class="row g-3">
            <div class="col-md-6">
                <table class="table table-borderless table-sm mb-0">
                    <tr><td class="text-muted small fw-semibold" style="width:150px">Voucher</td><td class="fw-semibold"><?= h($detail['voucher_number']) ?></td></tr>
                    <tr><td class="text-muted small fw-semibold">Amount</td><td><?= $currency ?> <?= number_format($detail['total_amount'], 2) ?></td></tr>
                    <tr><td class="text-muted small fw-semibold">Requested By</td><td><?= h($detail['requested_by_name'] ?? '–') ?></td></tr>
                    <tr><td class="text-muted small fw-semibold">Requested At</td><td class="small text-muted"><?= date('d M Y, h:i A', strtotime($detail['requested_at'])) ?></td></tr>
                    <tr><td class="text-muted small fw-semibold">Attachment</td><td>
                        <?php if ($att_url): ?><a href="<?= h($att_url) ?>" target="_blank" rel="noopener"><i class="fas fa-paperclip me-1"></i>View attachment</a><?php else: ?><span class="text-muted">–</span><?php endif; ?>
                    </td></tr>
                </table>
            </div>
            <div class="col-md-6">
                <div class="p-3 rounded-3 h-100" style="background:#f8f9fb">
                    <div class="text-muted small fw-semibold mb-1">Reason for Deletion</div>
                    <div><?= nl2br(h($detail['reason'])) ?></div>
                </div>
            </div>
        </div>

        <!-- Workflow timeline -->
        <div class="mt-4">
            <h6 class="fw-semibold small text-uppercase text-muted">Approval Trail</h6>
            <ul class="list-group list-group-flush small">
                <li class="list-group-item px-0">
                    <i class="fas fa-paper-plane me-2 text-primary"></i>
                    <strong>Requested</strong> by <?= h($detail['requested_by_name'] ?? '–') ?>
                    <span class="text-muted">on <?= date('d M Y, h:i A', strtotime($detail['requested_at'])) ?></span>
                </li>
                <?php if ($detail['dd_at']): ?>
                <li class="list-group-item px-0">
                    <i class="fas fa-user-check me-2 text-info"></i>
                    <strong>DD Accounts</strong> – <?= h($detail['dd_user_name'] ?? '–') ?>
                    <span class="text-muted">on <?= date('d M Y, h:i A', strtotime($detail['dd_at'])) ?></span>
                    <div class="text-muted ms-4">Note: <?= nl2br(h($detail['dd_note'] ?? '')) ?></div>
                </li>
                <?php endif; ?>
                <?php if ($detail['treasurer_at']): ?>
                <li class="list-group-item px-0">
                    <i class="fas fa-user-shield me-2 text-success"></i>
                    <strong>Treasurer</strong> – <?= h($detail['treasurer_user_name'] ?? '–') ?>
                    <span class="text-muted">on <?= date('d M Y, h:i A', strtotime($detail['treasurer_at'])) ?></span>
                    <div class="text-muted ms-4">Note: <?= nl2br(h($detail['treasurer_note'] ?? '')) ?></div>
                </li>
                <?php endif; ?>
                <?php if ($detail['status'] === 'rejected'): ?>
                <li class="list-group-item px-0 text-danger">
                    <i class="fas fa-times-circle me-2"></i>
                    <strong>Rejected</strong> by <?= h($detail['rejected_by_name'] ?? '–') ?>
                    <span class="text-muted">on <?= $detail['rejected_at'] ? date('d M Y, h:i A', strtotime($detail['rejected_at'])) : '–' ?></span>
                </li>
                <?php elseif ($detail['status'] === 'deleted'): ?>
                <li class="list-group-item px-0 text-danger">
                    <i class="fas fa-trash-alt me-2"></i><strong>Voucher deleted.</strong> The entry and its calculations have been cleared.
                </li>
                <?php endif; ?>
            </ul>
        </div>

        <!-- Action forms -->
        <?php if ($detail['status'] === 'pending_dd' && $can_dd): ?>
        <form method="post" class="mt-4 border-top pt-3">
            <?= csrf_field() ?>
            <input type="hidden" name="request_id" value="<?= (int)$detail['id'] ?>">
            <label class="form-label fw-semibold">DD Accounts Note <span class="text-danger">*</span></label>
            <textarea name="note" class="form-control mb-2" rows="2" required placeholder="Add your review note…"></textarea>
            <div class="d-flex gap-2">
                <button name="action" value="dd_approve" class="btn btn-success btn-sm" onclick="return confirm('Approve and forward to Treasurer?')"><i class="fas fa-check me-1"></i> Approve &amp; Forward</button>
                <button name="action" value="dd_reject" class="btn btn-outline-danger btn-sm" onclick="return confirm('Reject this delete request?')"><i class="fas fa-times me-1"></i> Reject</button>
            </div>
        </form>
        <?php elseif ($detail['status'] === 'pending_treasurer' && $can_treasurer): ?>
        <form method="post" class="mt-4 border-top pt-3">
            <?= csrf_field() ?>
            <input type="hidden" name="request_id" value="<?= (int)$detail['id'] ?>">
            <label class="form-label fw-semibold">Treasurer Note <span class="text-danger">*</span></label>
            <textarea name="note" class="form-control mb-2" rows="2" required placeholder="Add your final note…"></textarea>
            <div class="d-flex gap-2">
                <button name="action" value="treasurer_confirm" class="btn btn-danger btn-sm" onclick="return confirm('Confirm final deletion? This clears the voucher and cannot be undone.')"><i class="fas fa-trash-alt me-1"></i> Confirm Delete</button>
                <button name="action" value="treasurer_reject" class="btn btn-outline-secondary btn-sm" onclick="return confirm('Reject this delete request?')"><i class="fas fa-times me-1"></i> Reject</button>
            </div>
        </form>
        <?php elseif (in_array($detail['status'], ['pending_dd','pending_treasurer'], true)): ?>
        <div class="alert alert-info mt-4 mb-0 small"><i class="fas fa-clock me-1"></i> Awaiting review by the next approver.</div>
        <?php endif; ?>

        <?php if ($snap_items): ?>
        <div class="mt-4">
            <h6 class="fw-semibold small text-uppercase text-muted">Voucher Snapshot (at request time)</h6>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 small">
                    <thead class="table-light"><tr><th>Account</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr></thead>
                    <tbody>
                        <?php foreach ($snap_items as $it): ?>
                        <tr>
                            <td><?= h(($it['code'] ?? '') . ' – ' . ($it['account_name'] ?? '')) ?></td>
                            <td class="text-end"><?= (float)$it['debit_amount'] > 0 ? number_format($it['debit_amount'], 2) : '–' ?></td>
                            <td class="text-end"><?= (float)$it['credit_amount'] > 0 ? number_format($it['credit_amount'], 2) : '–' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Status tabs -->
<ul class="nav nav-tabs mb-0" style="border-bottom:none">
    <?php
    $tabs = ['' => 'All', 'pending_dd' => 'Pending DD', 'pending_treasurer' => 'Pending Treasurer', 'deleted' => 'Deleted', 'rejected' => 'Rejected'];
    foreach ($tabs as $tv => $tl):
        $q = http_build_query(array_filter(['status' => $tv]));
    ?>
    <li class="nav-item"><a class="nav-link <?= $f_status === $tv ? 'active' : '' ?>" href="?<?= $q ?>"><?= h($tl) ?></a></li>
    <?php endforeach; ?>
</ul>

<div class="card border-0 shadow-sm" style="border-top-left-radius:0">
    <div class="card-body">
        <?php if (empty($requests)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-trash-alt fa-3x mb-3 opacity-25"></i>
            <p class="mb-0">No delete requests found.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Voucher</th>
                        <th class="text-end">Amount</th>
                        <th>Requested By</th>
                        <th>Requested</th>
                        <th>Status</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $r): ?>
                    <tr>
                        <td class="text-muted small"><?= (int)$r['id'] ?></td>
                        <td class="fw-semibold"><?= h($r['voucher_number']) ?></td>
                        <td class="text-end"><?= $currency ?> <?= number_format($r['total_amount'], 2) ?></td>
                        <td class="small"><?= h($r['requested_by_name'] ?? '–') ?></td>
                        <td class="text-muted small"><?= date('d M Y', strtotime($r['requested_at'])) ?></td>
                        <td><?= acc_voucher_delete_status_badge($r['status']) ?></td>
                        <td class="text-end">
                            <a href="?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-eye"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
