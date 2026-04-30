<?php
/**
 * HOD Review Action – approve to Controller or return to reviewer/teacher.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/workflow-helpers.php';

if (!wf_can_hod()) {
    flash_set('error', 'You do not have permission to access this page.');
    redirect(APP_URL . '/results/index.php');
}

$id     = (int)($_GET['id'] ?? 0);
$sheet  = wf_get_sheet($id);
$grades = wf_get_grades($id);
$user   = auth_user();

if ($sheet['workflow_status'] !== 'under_review') {
    flash_set('error', 'This sheet is not in the "under review" state.');
    redirect(APP_URL . '/results/hod-queue.php');
}

$page_title = 'HOD Review: ' . $sheet['subject_title'];

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action  = $_POST['action']     ?? '';
    $remarks = trim($_POST['hod_remarks'] ?? '');

    if ($action === 'approve') {
        db()->prepare(
            'UPDATE result_mark_sheets SET
               workflow_status  = "hod_approved",
               hod_approved_by  = ?,
               hod_approved_at  = NOW(),
               hod_remarks      = ?
             WHERE id = ? AND workflow_status = "under_review"'
        )->execute([$user['id'], $remarks ?: null, $id]);
        flash_set('success', 'Mark sheet approved and forwarded to the Controller.');
        redirect(APP_URL . '/results/hod-queue.php');

    } elseif ($action === 'return_reviewer') {
        if ($remarks === '') {
            flash_set('error', 'Please provide remarks when returning a sheet.');
            redirect(APP_URL . '/results/hod-review.php?id=' . $id);
        }
        db()->prepare(
            'UPDATE result_mark_sheets SET
               workflow_status   = "submitted",
               returned_by       = ?,
               returned_at       = NOW(),
               return_remarks    = ?,
               returned_to_step  = "submitted"
             WHERE id = ? AND workflow_status = "under_review"'
        )->execute([$user['id'], $remarks, $id]);
        flash_set('success', 'Mark sheet returned to the reviewer.');
        redirect(APP_URL . '/results/hod-queue.php');

    } elseif ($action === 'return_teacher') {
        if ($remarks === '') {
            flash_set('error', 'Please provide remarks when returning a sheet.');
            redirect(APP_URL . '/results/hod-review.php?id=' . $id);
        }
        db()->prepare(
            'UPDATE result_mark_sheets SET
               workflow_status   = "returned",
               returned_by       = ?,
               returned_at       = NOW(),
               return_remarks    = ?,
               returned_to_step  = "draft"
             WHERE id = ? AND workflow_status = "under_review"'
        )->execute([$user['id'], $remarks, $id]);
        flash_set('success', 'Mark sheet returned to the teacher for revision.');
        redirect(APP_URL . '/results/hod-queue.php');
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/results/hod-queue.php">HOD Queue</a></li>
            <li class="breadcrumb-item active">HOD Review</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/results/sheet-print.php?id=<?= $id ?>" target="_blank"
       class="btn btn-sm btn-outline-secondary" style="border-radius:8px;">
        <i class="fas fa-print me-1"></i> Print
    </a>
</div>

<?php flash_show(); ?>

<?php require __DIR__ . '/partials/sheet-header.php'; ?>
<?php require __DIR__ . '/partials/grades-readonly.php'; ?>

<!-- HOD Action -->
<div class="card mt-4" style="border-radius:12px;">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-gavel me-2 text-muted"></i>HOD Decision</h6>
    </div>
    <div class="card-body p-4">
        <div class="mb-3">
            <label class="form-label fw-medium">HOD Remarks</label>
            <textarea id="hod_remarks_field" class="form-control" rows="3"
                      placeholder="Add remarks (required when returning)…"></textarea>
        </div>
        <div class="d-flex gap-3 flex-wrap">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="hod_remarks" id="approve_remarks">
                <button type="submit" class="btn btn-warning text-dark" style="border-radius:10px;"
                        onclick="document.getElementById('approve_remarks').value = document.getElementById('hod_remarks_field').value; return confirm('Approve and send to Controller?');">
                    <i class="fas fa-thumbs-up me-1"></i> Approve &amp; Send to Controller
                </button>
            </form>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="return_reviewer">
                <input type="hidden" name="hod_remarks" id="return_rev_remarks">
                <button type="submit" class="btn btn-outline-info" style="border-radius:10px;"
                        onclick="document.getElementById('return_rev_remarks').value = document.getElementById('hod_remarks_field').value;">
                    <i class="fas fa-undo me-1"></i> Return to Reviewer
                </button>
            </form>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="return_teacher">
                <input type="hidden" name="hod_remarks" id="return_tch_remarks">
                <button type="submit" class="btn btn-outline-danger" style="border-radius:10px;"
                        onclick="document.getElementById('return_tch_remarks').value = document.getElementById('hod_remarks_field').value;">
                    <i class="fas fa-undo me-1"></i> Return to Teacher
                </button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
