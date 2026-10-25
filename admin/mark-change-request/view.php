<?php
/**
 * Mark Change Request — detail & review
 * Shows the old → new comparison, the faculty's reason note, the approval
 * chain progress and the full history. Approvers of the current step can
 * approve (forward / apply on final step) or reject with remarks.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

auth_check();
mcr_ensure_schema();

$user    = auth_user();
$user_id = (int)($user['id'] ?? 0);
$id      = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$req = mcr_get_request($id);
if (!$req) {
    flash_set('error', 'Mark change request not found.');
    redirect(APP_URL . '/mark-change-request/index.php');
}

// Requester, module members and chain approvers may view.
$is_requester = ((int)$req['requested_by'] === $user_id);
if (!$is_requester && !mcr_can_view_module()) {
    flash_set('error', 'You do not have access to this request.');
    redirect(APP_URL . '/index.php');
}

$can_review = mcr_can_review($req);

// ── POST: approve / reject ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action  = (string)($_POST['action'] ?? '');
    $remarks = trim((string)($_POST['remarks'] ?? ''));

    if (!$can_review) {
        flash_set('error', 'You are not an approver for the current step.');
    } elseif ($action === 'approve') {
        [$ok, $msg] = mcr_approve($id, $user_id, $remarks);
        flash_set($ok ? 'success' : 'error', $msg);
    } elseif ($action === 'reject') {
        [$ok, $msg] = mcr_reject($id, $user_id, $remarks);
        flash_set($ok ? 'success' : 'error', $msg);
    }
    redirect(APP_URL . '/mark-change-request/view.php?id=' . $id);
}

$old_marks = json_decode((string)$req['old_marks_json'], true) ?: [];
$new_marks = json_decode((string)$req['new_marks_json'], true) ?: [];
$dist      = mcr_distribution($req['curriculum_id'] ? (int)$req['curriculum_id'] : null,
                              max(count($old_marks), count($new_marks)));
$steps     = wf_get_chain_steps((int)$req['chain_id']);
$history   = mcr_get_history($id);

$page_title = 'Mark Change Request #' . $id;
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">
        <i class="fas fa-file-pen me-2 text-primary"></i>Mark Change Request #<?= $id ?>
        <span class="ms-2"><?= mcr_status_badge((string)$req['status']) ?></span>
    </h4>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Back</a>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white">
                <strong><?= h($req['student_sid']) ?></strong>
                <?= !empty($req['student_name']) ? ' — ' . h($req['student_name']) : '' ?>
                <span class="text-muted ms-2">
                    <?= h(trim(($req['subject_code'] ? $req['subject_code'] . ' — ' : '') . $req['subject_title'])) ?>
                    · <?= h($req['semester']) ?>
                    · <?= h($req['dept_name'] ?? '') ?><?= !empty($req['program_name']) ? ' / ' . h($req['program_name']) : '' ?>
                </span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle" style="max-width:680px;">
                        <thead class="table-light">
                            <tr>
                                <th>Component</th>
                                <th class="text-center" style="width:90px;">Max</th>
                                <th class="text-center" style="width:120px;">Current</th>
                                <th class="text-center" style="width:120px;">Proposed</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($dist as $i => $d):
                            $ov = $old_marks[$i] ?? null;
                            $nv = $new_marks[$i] ?? null;
                            $changed = ($ov !== null || $nv !== null) && ((float)($ov ?? -1) !== (float)($nv ?? -1)) && !($ov === null && $nv === null);
                        ?>
                            <tr>
                                <td><?= h($d['name']) ?></td>
                                <td class="text-center text-muted">/<?= h(rtrim(rtrim(number_format($d['max'], 2, '.', ''), '0'), '.')) ?></td>
                                <td class="text-center"><?= $ov !== null ? h($ov + 0) : '—' ?></td>
                                <td class="text-center <?= $changed ? 'fw-bold text-primary' : '' ?>">
                                    <?= $nv !== null ? h($nv + 0) : '—' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="table-light">
                            <td colspan="2" class="fw-semibold">Total · Grade (Point)</td>
                            <td class="text-center">
                                <?= h(mcr_result_summary($req['old_total'], $req['old_letter'], $req['old_point'], (int)$req['old_is_absent'])) ?>
                            </td>
                            <td class="text-center fw-bold text-primary">
                                <?= h(mcr_result_summary($req['new_total'], $req['new_letter'], $req['new_point'], (int)$req['new_is_absent'])) ?>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>

                <div class="alert alert-light border mt-2 mb-0">
                    <div class="fw-semibold mb-1"><i class="fas fa-sticky-note me-1 text-warning"></i>
                        Reason from <?= h($req['requester_name'] ?? 'faculty') ?>:</div>
                    <?= nl2br(h($req['reason'])) ?>
                </div>
            </div>
        </div>

        <?php if ($can_review): ?>
        <div class="card shadow-sm mb-4 border-primary">
            <div class="card-header bg-white">
                <strong><i class="fas fa-gavel me-1 text-primary"></i>Your Decision</strong>
                <span class="text-muted ms-2">Waiting at:
                    <span class="badge bg-info text-dark"><?= h($req['current_step_label'] ?? '') ?></span>
                    (<?= h($req['current_group_name'] ?? '') ?>)
                </span>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Remarks</label>
                        <textarea name="remarks" class="form-control" rows="2"
                                  placeholder="Optional remarks for approval; required when rejecting…"></textarea>
                    </div>
                    <button name="action" value="approve" class="btn btn-success"
                            onclick="return confirm('<?= (int)$req['current_step_is_final'] === 1
                                ? 'FINAL approval: the student result will be updated immediately. Continue?'
                                : 'Approve and forward to the next step?' ?>');">
                        <i class="fas fa-check me-1"></i>
                        <?= (int)$req['current_step_is_final'] === 1 ? 'Approve & Apply Change' : 'Approve & Forward' ?>
                    </button>
                    <button name="action" value="reject" class="btn btn-outline-danger ms-2"
                            onclick="return confirm('Reject this mark change request?');">
                        <i class="fas fa-times me-1"></i>Reject
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white"><strong><i class="fas fa-clock-rotate-left me-1 text-muted"></i>History</strong></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($history as $hrow): ?>
                <li class="list-group-item">
                    <div class="d-flex justify-content-between">
                        <div>
                            <span class="badge <?= match ($hrow['action']) {
                                'submitted' => 'bg-secondary',
                                'approved'  => 'bg-success',
                                'applied'   => 'bg-primary',
                                'rejected'  => 'bg-danger',
                                default     => 'bg-light text-dark',
                            } ?>"><?= h(ucfirst((string)$hrow['action'])) ?></span>
                            <strong class="ms-1"><?= h($hrow['actor_name'] ?? 'System') ?></strong>
                            <?php if (!empty($hrow['step_label'])): ?>
                                <span class="text-muted">at "<?= h($hrow['step_label']) ?>"</span>
                            <?php endif; ?>
                        </div>
                        <small class="text-muted"><?= h(date('d M Y, g:i A', strtotime((string)$hrow['acted_at']))) ?></small>
                    </div>
                    <?php if (!empty($hrow['remarks'])): ?>
                        <div class="text-muted small mt-1"><?= nl2br(h($hrow['remarks'])) ?></div>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white">
                <strong><i class="fas fa-sitemap me-1 text-muted"></i>Approval Flow</strong>
                <div class="text-muted small"><?= h($req['chain_name'] ?? 'Department chain') ?></div>
            </div>
            <ul class="list-group list-group-flush">
                <?php foreach ($steps as $s):
                    $is_current = ((int)$s['step_order'] === (int)$req['current_step_order'] && $req['status'] === 'pending');
                    $is_entry   = (int)$s['is_entry'] === 1;
                    $is_done    = $req['status'] === 'approved'
                        || ($req['status'] === 'pending' && (int)$s['step_order'] < (int)$req['current_step_order']);
                ?>
                <li class="list-group-item d-flex align-items-center <?= $is_current ? 'bg-light' : '' ?>">
                    <?php if ($is_done): ?>
                        <i class="fas fa-circle-check text-success me-2"></i>
                    <?php elseif ($is_current): ?>
                        <i class="fas fa-hourglass-half text-warning me-2"></i>
                    <?php else: ?>
                        <i class="far fa-circle text-muted me-2"></i>
                    <?php endif; ?>
                    <div>
                        <div class="<?= $is_current ? 'fw-bold' : '' ?>">
                            <?= h($s['step_label']) ?>
                            <?php if ($is_entry): ?><span class="badge bg-light text-dark border ms-1">Request</span><?php endif; ?>
                            <?php if ((int)$s['is_final'] === 1): ?><span class="badge bg-dark ms-1">Final</span><?php endif; ?>
                        </div>
                        <small class="text-muted"><?= h($s['group_name']) ?></small>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white"><strong><i class="fas fa-circle-info me-1 text-muted"></i>Details</strong></div>
            <div class="card-body small">
                <div class="mb-1"><span class="text-muted">Requested by:</span> <?= h($req['requester_name'] ?? '') ?></div>
                <div class="mb-1"><span class="text-muted">Submitted:</span> <?= h(date('d M Y, g:i A', strtotime((string)$req['created_at']))) ?></div>
                <?php if (!empty($req['decided_at'])): ?>
                <div class="mb-1"><span class="text-muted">Decided:</span> <?= h(date('d M Y, g:i A', strtotime((string)$req['decided_at']))) ?></div>
                <?php endif; ?>
                <div class="mb-1"><span class="text-muted">Mark sheet:</span>
                    <a href="<?= APP_URL ?>/results/view.php?id=<?= (int)$req['sheet_id'] ?>">#<?= (int)$req['sheet_id'] ?></a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
