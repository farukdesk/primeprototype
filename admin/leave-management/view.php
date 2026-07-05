<?php
/**
 * View a single leave request: details, balance impact and the step-by-step
 * signed approval timeline. Current approver can approve/reject here.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('leave-management');
require_once __DIR__ . '/helpers.php';

$user = auth_user();
$id   = (int)($_GET['id'] ?? 0);
if ($id < 1) { flash_set('error', 'Invalid request.'); redirect(APP_URL . '/leave-management/index.php'); }

$stmt = db()->prepare(
    'SELECT r.*, u.full_name AS requester_name, u.email AS requester_email
       FROM leave_requests r
       JOIN users u ON u.id = r.user_id
      WHERE r.id = ?'
);
$stmt->execute([$id]);
$req = $stmt->fetch();
if (!$req) { flash_set('error', 'Leave request not found.'); redirect(APP_URL . '/leave-management/index.php'); }

$approvals = lm_request_approvals($id);

// ── Visibility: requester, admin, or a member of any approval-step group ───────
$is_owner    = (int)$req['user_id'] === (int)$user['id'];
$is_admin    = lm_is_admin();
$group_ids   = array_map('intval', $user['group_ids'] ?? [(int)$user['group_id']]);
$in_flow     = false;
foreach ($approvals as $a) {
    if (in_array((int)$a['group_id'], $group_ids, true)) { $in_flow = true; break; }
}
if (!$is_owner && !$is_admin && !$in_flow) {
    flash_set('error', 'You do not have permission to view this request.');
    redirect(APP_URL . '/leave-management/index.php');
}

$can_act    = lm_user_can_act($req, $user) || ($is_admin && $req['status'] === 'pending' && !$is_owner);
$can_cancel = $is_owner && $req['status'] === 'pending';

$page_title = 'Leave Request #' . $id;
require_once __DIR__ . '/../includes/header.php';

$fmt = fn(float $n) => rtrim(rtrim(number_format($n, 1), '0'), '.');
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/leave-management/index.php">Leave Management</a></li>
            <li class="breadcrumb-item active">Request #<?= $id ?></li>
        </ol>
    </nav>
    <?php if ($req['status'] === 'approved'): ?>
    <a href="<?= APP_URL ?>/leave-management/download.php?id=<?= $id ?>" class="btn btn-primary" style="border-radius:10px;">
        <i class="fas fa-file-pdf me-1"></i> Download PDF
    </a>
    <?php endif; ?>
</div>

<?= flash_show() ?>

<div class="row">
    <div class="col-lg-7">
        <div class="card mb-4" style="border-radius:12px;">
            <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-file-alt me-2 text-primary"></i>Request Details</h6>
                <?= lm_status_badge($req['status']) ?>
            </div>
            <div class="card-body p-4">
                <table class="table table-borderless mb-0">
                    <tbody>
                        <tr><th class="text-muted" style="width:180px;">Requested By</th><td><?= h($req['requester_name']) ?><br><span class="text-muted small"><?= h($req['requester_email']) ?></span></td></tr>
                        <tr><th class="text-muted">Category</th><td><?= lm_category_badge($req['category']) ?></td></tr>
                        <?php if ($req['pay_type']): ?>
                        <tr><th class="text-muted">Pay Type</th><td><?= lm_paytype_badge($req['pay_type']) ?></td></tr>
                        <?php endif; ?>
                        <?php if ($req['category'] === 'short'): ?>
                        <tr><th class="text-muted">Date</th><td><?= h(date('d M Y', strtotime($req['start_date']))) ?></td></tr>
                        <tr><th class="text-muted">Time</th><td><?= h(substr((string)$req['start_time'], 0, 5)) ?> – <?= h(substr((string)$req['end_time'], 0, 5)) ?></td></tr>
                        <?php else: ?>
                        <tr><th class="text-muted">From</th><td><?= h(date('d M Y', strtotime($req['start_date']))) ?></td></tr>
                        <tr><th class="text-muted">To</th><td><?= h(date('d M Y', strtotime($req['end_date']))) ?></td></tr>
                        <tr><th class="text-muted">Duration</th><td><strong><?= $fmt((float)$req['days']) ?></strong> day(s)</td></tr>
                        <?php endif; ?>
                        <tr><th class="text-muted">Reason</th><td><?= nl2br(h($req['reason'])) ?></td></tr>
                        <tr><th class="text-muted">Submitted</th><td><?= h(date('d M Y, g:i A', strtotime($req['created_at']))) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($can_act || $can_cancel): ?>
        <div class="card mb-4" style="border-radius:12px;">
            <div class="card-header py-3 px-4"><h6 class="mb-0 fw-semibold"><i class="fas fa-gavel me-2 text-muted"></i>Actions</h6></div>
            <div class="card-body p-4">
                <?php if ($can_act): ?>
                <form method="POST" action="<?= APP_URL ?>/leave-management/action.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <div class="mb-3">
                        <label class="form-label fw-medium">Note (optional)</label>
                        <textarea name="note" class="form-control" rows="2" placeholder="Add a note with your decision"></textarea>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" name="action" value="approve" class="btn btn-success" style="border-radius:10px;"
                                onclick="return confirm('Approve this leave request and apply your signature?')">
                            <i class="fas fa-check me-1"></i> Approve &amp; Sign
                        </button>
                        <button type="submit" name="action" value="reject" class="btn btn-outline-danger" style="border-radius:10px;"
                                onclick="return confirm('Reject this leave request?')">
                            <i class="fas fa-times me-1"></i> Reject
                        </button>
                    </div>
                    <p class="text-muted small mb-0 mt-2"><i class="fas fa-signature me-1"></i> Approving applies your uploaded signature image to this step.</p>
                </form>
                <?php endif; ?>

                <?php if ($can_cancel): ?>
                <?php if ($can_act): ?><hr><?php endif; ?>
                <form method="POST" action="<?= APP_URL ?>/leave-management/action.php"
                      onsubmit="return confirm('Cancel your leave request?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="cancel">
                    <button type="submit" class="btn btn-outline-secondary" style="border-radius:10px;">
                        <i class="fas fa-ban me-1"></i> Cancel Request
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-5">
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4"><h6 class="mb-0 fw-semibold"><i class="fas fa-route me-2 text-muted"></i>Approval Workflow</h6></div>
            <div class="card-body p-4">
                <?php if (empty($approvals)): ?>
                    <p class="text-muted mb-0">No approval steps configured. Awaiting administrator setup.</p>
                <?php else: ?>
                    <div class="lm-timeline">
                        <?php foreach ($approvals as $a):
                            $is_current = $req['status'] === 'pending' && (int)$a['step_order'] === (int)$req['current_step'];
                            $icon = match ($a['status']) {
                                'approved' => 'fas fa-check-circle text-success',
                                'rejected' => 'fas fa-times-circle text-danger',
                                default    => $is_current ? 'fas fa-hourglass-half text-warning' : 'far fa-circle text-muted',
                            };
                        ?>
                        <div class="d-flex mb-3">
                            <div class="me-3 text-center" style="width:24px;">
                                <i class="<?= $icon ?>"></i>
                            </div>
                            <div class="flex-grow-1">
                                <div class="fw-semibold">
                                    Step <?= (int)$a['step_order'] ?>: <?= h($a['label'] ?: $a['group_name']) ?>
                                    <?php if ($is_current): ?><span class="badge bg-warning text-dark ms-1">Awaiting</span><?php endif; ?>
                                </div>
                                <div class="small text-muted">Group: <?= h($a['group_name']) ?></div>
                                <?php if ($a['status'] !== 'pending'): ?>
                                    <div class="small mt-1">
                                        <?= ucfirst($a['status']) ?> by <strong><?= h($a['approver_name'] ?? '—') ?></strong>
                                        <?php if ($a['acted_at']): ?> on <?= h(date('d M Y, g:i A', strtotime($a['acted_at']))) ?><?php endif; ?>
                                    </div>
                                    <?php if (!empty($a['note'])): ?>
                                        <div class="small text-muted fst-italic">“<?= h($a['note']) ?>”</div>
                                    <?php endif; ?>
                                    <?php if ($a['status'] === 'approved' && !empty($a['signature_file'])): ?>
                                        <div class="mt-1 p-2 bg-light rounded d-inline-block">
                                            <img src="<?= h(lm_signature_url($a['signature_file'])) ?>" alt="Signature"
                                                 style="max-height:48px;max-width:160px;object-fit:contain;">
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
