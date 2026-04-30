<?php
/**
 * Reviewer Action – view mark sheet, add remarks, approve or return.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/workflow-helpers.php';

if (!wf_can_review()) {
    flash_set('error', 'You do not have permission to review mark sheets.');
    redirect(APP_URL . '/results/index.php');
}

$id     = (int)($_GET['id'] ?? 0);
$sheet  = wf_get_sheet($id);
$grades = wf_get_grades($id);
$user   = auth_user();

// Allow reviewing only submitted sheets
if ($sheet['workflow_status'] !== 'submitted') {
    flash_set('error', 'This sheet is not in the "submitted" state.');
    redirect(APP_URL . '/results/review-queue.php');
}

$page_title = 'Review: ' . $sheet['subject_title'];

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action   = $_POST['action']   ?? '';
    $remarks  = trim($_POST['reviewer_remarks'] ?? '');

    if ($action === 'approve') {
        db()->prepare(
            'UPDATE result_mark_sheets SET
               workflow_status = "under_review",
               reviewed_by     = ?,
               reviewed_at     = NOW(),
               reviewer_remarks = ?
             WHERE id = ? AND workflow_status = "submitted"'
        )->execute([$user['id'], $remarks ?: null, $id]);
        flash_set('success', 'Mark sheet approved and forwarded to the Department Head.');
        redirect(APP_URL . '/results/review-queue.php');

    } elseif ($action === 'return') {
        if ($remarks === '') {
            flash_set('error', 'You must provide remarks when returning a sheet.');
            redirect(APP_URL . '/results/review-sheet.php?id=' . $id);
        }
        db()->prepare(
            'UPDATE result_mark_sheets SET
               workflow_status   = "returned",
               returned_by       = ?,
               returned_at       = NOW(),
               return_remarks    = ?,
               returned_to_step  = "draft"
             WHERE id = ? AND workflow_status = "submitted"'
        )->execute([$user['id'], $remarks, $id]);
        flash_set('success', 'Mark sheet returned to the teacher for revision.');
        redirect(APP_URL . '/results/review-queue.php');
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/results/review-queue.php">Review Queue</a></li>
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

<!-- Grades table -->
<?php require __DIR__ . '/partials/grades-readonly.php'; ?>

<!-- Reviewer action -->
<div class="card mt-4" style="border-radius:12px;">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-comments me-2 text-muted"></i>Reviewer Decision</h6>
    </div>
    <div class="card-body p-4">
        <div class="mb-3">
            <label class="form-label fw-medium">Reviewer Remarks</label>
            <textarea id="reviewer_remarks_field" class="form-control" rows="3"
                      placeholder="Add remarks (required when returning)…"></textarea>
        </div>
        <div class="d-flex gap-3 flex-wrap">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="reviewer_remarks" id="approve_remarks">
                <button type="submit" class="btn btn-success" style="border-radius:10px;"
                        onclick="document.getElementById('approve_remarks').value = document.getElementById('reviewer_remarks_field').value; return confirm('Forward this sheet to the Department Head?');">
                    <i class="fas fa-thumbs-up me-1"></i> Approve &amp; Send to HOD
                </button>
            </form>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="return">
                <input type="hidden" name="reviewer_remarks" id="return_remarks">
                <button type="submit" class="btn btn-outline-danger" style="border-radius:10px;"
                        onclick="document.getElementById('return_remarks').value = document.getElementById('reviewer_remarks_field').value;">
                    <i class="fas fa-undo me-1"></i> Return to Teacher
                </button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
