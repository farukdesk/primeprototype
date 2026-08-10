<?php
/**
 * View a single leave request: details, balance impact and the step-by-step
 * signed approval timeline. Current approver can approve/reject here.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';
// Module access OR self-service (Administrative / Faculty employee types).
if (!lm_can_view()) {
    $_SESSION['flash_error'] = 'You do not have permission to access this section.';
    redirect(APP_URL . '/index.php');
}

$user = auth_user();
$id   = (int)($_GET['id'] ?? 0);
if ($id < 1) { flash_set('error', 'Invalid request.'); redirect(APP_URL . '/leave-management/index.php'); }

$stmt = db()->prepare(
    'SELECT r.*, u.full_name AS requester_name, u.email AS requester_email,
            sp.employee_id, sd.name AS dept_name
       FROM leave_requests r
       JOIN users u ON u.id = r.user_id
  LEFT JOIN staff_profiles sp ON sp.user_id = u.id
  LEFT JOIN staff_departments sd ON sd.id = sp.staff_dept_id
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

// Admin may (re-)sync the approval flow while the request is pending and no
// step has been acted on — covers requests submitted before the approval
// chain for the requester's group was configured.
$flow_untouched = true;
foreach ($approvals as $a) {
    if ($a['status'] !== 'pending') { $flow_untouched = false; break; }
}
$can_sync = $is_admin && $req['status'] === 'pending' && $flow_untouched;

$page_title = 'Leave Request #' . $id;
require_once __DIR__ . '/../includes/header.php';

$fmt = fn(float $n) => rtrim(rtrim(number_format($n, 1), '0'), '.');

// ── Balance impact (Casual / Sick only) ─────────────────────────────────────────
$bal_info = null;
if (in_array($req['category'], LM_BALANCE_CATEGORIES, true)) {
    $bal_year = (int)date('Y', strtotime($req['start_date']));
    $bal      = lm_get_balance((int)$req['user_id'], $bal_year);
    $bal_rem  = $req['category'] === 'casual' ? (float)$bal['casual_remaining'] : (float)$bal['sick_remaining'];
    $bal_tot  = $req['category'] === 'casual' ? (float)$bal['casual_total']     : (float)$bal['sick_total'];
    $bal_info = [
        'year'      => $bal_year,
        'total'     => $bal_tot,
        'remaining' => $bal_rem,                        // approved requests already deducted
        'after'     => $bal_rem - (float)$req['days'],  // if this pending request gets approved
    ];
}
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
                        <tr><th class="text-muted">Department</th><td><?= !empty($req['dept_name']) ? h($req['dept_name']) : '<span class="text-muted">—</span>' ?><?php if (!empty($req['employee_id'])): ?> <span class="text-muted small">(Employee ID: <?= h($req['employee_id']) ?>)</span><?php endif; ?></td></tr>
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
                        <?php if (!empty($req['makeup_plan'])): ?>
                        <tr><th class="text-muted">Makeup Class Plan</th><td><?= nl2br(h($req['makeup_plan'])) ?></td></tr>
                        <?php endif; ?>
                        <tr><th class="text-muted">Submitted</th><td><?= h(date('d M Y, g:i A', strtotime($req['created_at']))) ?></td></tr>
                        <?php if ($bal_info !== null): ?>
                        <tr>
                            <th class="text-muted"><?= h(lm_category_label($req['category'])) ?> Balance (<?= (int)$bal_info['year'] ?>)</th>
                            <td>
                                <?php if ($req['status'] === 'pending'): ?>
                                    <strong><?= $fmt($bal_info['remaining']) ?></strong> of <?= $fmt($bal_info['total']) ?> day(s) left
                                    <i class="fas fa-arrow-right mx-1 text-muted"></i>
                                    <strong class="<?= $bal_info['after'] < 0 ? 'text-danger' : 'text-success' ?>"><?= $fmt($bal_info['after']) ?></strong> day(s) will be left after approval
                                    <?php if ($bal_info['after'] < 0): ?>
                                        <br><span class="badge bg-danger-subtle text-danger border border-danger mt-1"><i class="fas fa-exclamation-triangle me-1"></i>Exceeds available balance</span>
                                    <?php endif; ?>
                                <?php elseif ($req['status'] === 'approved'): ?>
                                    <strong class="<?= $bal_info['remaining'] < 0 ? 'text-danger' : 'text-success' ?>"><?= $fmt($bal_info['remaining']) ?></strong> of <?= $fmt($bal_info['total']) ?> day(s) left
                                    <span class="text-muted small">(this leave already deducted)</span>
                                <?php else: ?>
                                    <strong><?= $fmt($bal_info['remaining']) ?></strong> of <?= $fmt($bal_info['total']) ?> day(s) left
                                    <span class="text-muted small">(this request does not affect the balance)</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
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

                <?php if ($can_sync): ?>
                <form method="POST" action="<?= APP_URL ?>/leave-management/action.php" class="mt-3 pt-3 border-top"
                      onsubmit="return confirm('Sync the current approval flow of the requester group to this request?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="sync_flow">
                    <button type="submit" class="btn <?= empty($approvals) ? 'btn-primary' : 'btn-outline-secondary' ?> btn-sm" style="border-radius:8px;">
                        <i class="fas fa-sync-alt me-1"></i> Sync Approval Flow
                    </button>
                    <div class="form-text mt-1">
                        Re-applies the current approval chain of the requester's group.
                        Use this for requests submitted before the approval flow was configured.
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
