<?php
/**
 * Workflow Review – Generic approver action page.
 * Works for ANY step in ANY chain. No hard-coded roles.
 *
 * URL: /results/workflow-review.php?id=SHEET_ID
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/workflow-helpers.php';

auth_check();

$id     = (int)($_GET['id'] ?? 0);
$sheet  = wf_get_sheet($id);
$grades = wf_get_grades($id);
$history = wf_get_sheet_history($id);
$user   = auth_user();

// Build full chain steps for the "return to" dropdown
$chain_steps = $sheet['chain_id'] ? wf_get_chain_steps((int)$sheet['chain_id']) : [];
$current_step_order = (int)$sheet['current_step_order'];

// Build list of steps the approver can return to (all steps with step_order < current)
$returnable_steps = array_filter($chain_steps, fn($s) => (int)$s['step_order'] < $current_step_order);

// Permission check: must be able to approve this sheet
if (!wf_can_approve_sheet($sheet) && !is_super_admin()) {
    flash_set('error', 'You do not have permission to act on this mark sheet at this stage.');
    redirect(APP_URL . '/results/workflow-queue.php');
}

if ($sheet['workflow_status'] !== 'pending') {
    flash_set('error', 'This sheet is not currently pending review.');
    redirect(APP_URL . '/results/index.php?tab=my_sheets');
}

$page_title = 'Review: ' . $sheet['subject_title'];

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action          = $_POST['action']          ?? '';
    $remarks         = trim($_POST['remarks']     ?? '');
    $return_to_step  = (int)($_POST['return_to_step'] ?? 0);

    if ($action === 'approve') {
        wf_advance_sheet($id, (int)$user['id'], $remarks);
        // Re-fetch to check new status
        $updated = db()->prepare('SELECT workflow_status FROM result_mark_sheets WHERE id = ?');
        $updated->execute([$id]);
        $new_status = $updated->fetchColumn();
        if ($new_status === 'published') {
            flash_set('success', 'Mark sheet <strong>published</strong> successfully.');
        } else {
            flash_set('success', 'Approved and forwarded to the next step.');
        }
        redirect(APP_URL . '/results/workflow-queue.php');

    } elseif ($action === 'return') {
        if ($remarks === '') {
            flash_set('error', 'Remarks are required when returning a sheet.');
            redirect(APP_URL . '/results/workflow-review.php?id=' . $id);
        }
        if ($return_to_step <= 0) {
            flash_set('error', 'Please select which step to return the sheet to.');
            redirect(APP_URL . '/results/workflow-review.php?id=' . $id);
        }
        wf_return_sheet($id, (int)$user['id'], $return_to_step, $remarks);
        flash_set('success', 'Sheet returned for revision.');
        redirect(APP_URL . '/results/workflow-queue.php');
    }
}

$cur_step = wf_get_step((int)$sheet['chain_id'], $current_step_order);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/results/workflow-queue.php">Workflow Queue</a></li>
            <li class="breadcrumb-item active">Review Sheet</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/results/sheet-print.php?id=<?= $id ?>" target="_blank"
       class="btn btn-sm btn-outline-secondary" style="border-radius:8px;">
        <i class="fas fa-print me-1"></i> Print
    </a>
</div>

<?php flash_show(); ?>

<!-- Sheet header -->
<?php require __DIR__ . '/partials/sheet-header.php'; ?>

<!-- Chain progress indicator -->
<?php if (!empty($chain_steps)): ?>
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-body px-4 py-3">
        <div class="d-flex align-items-center gap-0 flex-wrap" style="gap:.25rem!important;">
            <?php foreach ($chain_steps as $idx => $step): ?>
            <?php
            $sorder = (int)$step['step_order'];
            if ($sorder < $current_step_order) {
                $cls = 'bg-success text-white';
                $icon = '<i class="fas fa-check me-1"></i>';
            } elseif ($sorder === $current_step_order) {
                $cls = 'bg-primary text-white';
                $icon = '<i class="fas fa-circle-notch fa-spin me-1"></i>';
            } else {
                $cls = 'bg-light text-muted border';
                $icon = '';
            }
            ?>
            <?php if ($idx > 0): ?>
            <div style="width:18px; height:2px; background:#dee2e6; flex-shrink:0;"></div>
            <?php endif; ?>
            <span class="badge <?= $cls ?>" style="border-radius:8px; padding:.4rem .7rem; font-size:.75rem;">
                <?= $icon ?><?= h($step['step_label']) ?>
                <?php if ($step['is_final']): ?> <i class="fas fa-flag ms-1"></i><?php endif; ?>
            </span>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Grades -->
<?php require __DIR__ . '/partials/grades-readonly.php'; ?>

<!-- Approval action -->
<div class="card mt-4" style="border-radius:12px; border-left:4px solid #0d6efd;">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold">
            <i class="fas fa-gavel me-2 text-muted"></i>
            Your Decision
            <?php if ($cur_step): ?>
            <small class="text-muted fw-normal ms-2">— acting as <strong><?= h($cur_step['step_label']) ?></strong></small>
            <?php endif; ?>
        </h6>
    </div>
    <div class="card-body p-4">
        <div class="mb-3">
            <label class="form-label fw-medium">Remarks</label>
            <textarea id="remarks_field" class="form-control" rows="3"
                      placeholder="Optional remarks for approval; required when returning…"></textarea>
        </div>

        <div class="row g-3">
            <!-- Approve -->
            <div class="col-md-6">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="remarks" id="approve_remarks">
                    <button type="submit" class="btn btn-success w-100" style="border-radius:10px;"
                            onclick="document.getElementById('approve_remarks').value = document.getElementById('remarks_field').value;
                                     return confirm('<?= $cur_step && $cur_step['is_final'] ? 'This will PUBLISH the mark sheet. Confirm?' : 'Approve and forward to the next step?' ?>');">
                        <i class="fas fa-<?= $cur_step && $cur_step['is_final'] ? 'globe' : 'thumbs-up' ?> me-1"></i>
                        <?= $cur_step && $cur_step['is_final'] ? 'Publish Results' : 'Approve &amp; Forward' ?>
                    </button>
                </form>
            </div>

            <!-- Return -->
            <?php if (!empty($returnable_steps)): ?>
            <div class="col-md-6">
                <form method="POST" id="returnForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="return">
                    <input type="hidden" name="remarks" id="return_remarks">
                    <div class="input-group">
                        <select name="return_to_step" id="return_step_sel" class="form-select" style="border-radius:10px 0 0 10px;" required>
                            <option value="">— Return to step —</option>
                            <?php foreach (array_reverse(array_values($returnable_steps)) as $rs): ?>
                            <option value="<?= $rs['step_order'] ?>">
                                Step <?= $rs['step_order'] ?>: <?= h($rs['step_label']) ?>
                                <?= $rs['is_entry'] ? '(Entry / Teacher)' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-outline-danger" style="border-radius:0 10px 10px 0;"
                                onclick="document.getElementById('return_remarks').value = document.getElementById('remarks_field').value;">
                            <i class="fas fa-undo me-1"></i> Return
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- History timeline -->
<?php if (!empty($history)): ?>
<div class="card mt-4" style="border-radius:12px;">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-history me-2 text-muted"></i>Workflow History</h6>
    </div>
    <div class="card-body p-4">
        <div class="timeline" style="position:relative; padding-left:1.5rem; border-left:2px solid #dee2e6;">
            <?php
            $action_icons = [
                'created'   => ['fas fa-plus-circle',    'text-secondary'],
                'submitted' => ['fas fa-paper-plane',     'text-primary'],
                'approved'  => ['fas fa-check-circle',    'text-success'],
                'returned'  => ['fas fa-undo',            'text-danger'],
                'published' => ['fas fa-globe',           'text-success'],
            ];
            foreach ($history as $h_row):
                [$icon, $col] = $action_icons[$h_row['action']] ?? ['fas fa-circle', 'text-muted'];
            ?>
            <div class="mb-3" style="position:relative; padding-left:.75rem;">
                <span style="position:absolute; left:-1.82rem; top:2px;">
                    <i class="<?= $icon ?> <?= $col ?>"></i>
                </span>
                <div class="small">
                    <strong><?= h($h_row['step_label'] ?? 'Step ' . $h_row['step_order']) ?></strong>
                    <span class="ms-1 badge
                        <?= $h_row['action'] === 'approved' || $h_row['action'] === 'published' ? 'bg-success' :
                           ($h_row['action'] === 'returned' ? 'bg-danger' :
                           ($h_row['action'] === 'submitted' ? 'bg-primary' : 'bg-secondary')) ?>">
                        <?= ucfirst($h_row['action']) ?>
                    </span>
                    <?php if ($h_row['action'] === 'returned' && $h_row['returned_to_step']): ?>
                    <span class="ms-1 text-muted">→ returned to step <?= $h_row['returned_to_step'] ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($h_row['group_name']): ?>
                <div class="small text-muted"><i class="fas fa-users me-1"></i><?= h($h_row['group_name']) ?></div>
                <?php endif; ?>
                <div class="small text-muted">
                    <?= h($h_row['actor_name'] ?? 'System') ?>
                    &nbsp;·&nbsp;<?= date('d M Y, h:i A', strtotime($h_row['acted_at'])) ?>
                </div>
                <?php if ($h_row['remarks']): ?>
                <div class="small mt-1 p-2 bg-light rounded"><?= h($h_row['remarks']) ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
