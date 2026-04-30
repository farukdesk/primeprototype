<?php
/**
 * Controller Action – final review, publish or return to HOD.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/workflow-helpers.php';

if (!wf_can_publish()) {
    flash_set('error', 'You do not have permission to publish mark sheets.');
    redirect(APP_URL . '/results/index.php');
}

$id     = (int)($_GET['id'] ?? 0);
$sheet  = wf_get_sheet($id);
$grades = wf_get_grades($id);
$user   = auth_user();

if ($sheet['workflow_status'] !== 'hod_approved') {
    flash_set('error', 'This sheet has not received HOD approval yet.');
    redirect(APP_URL . '/results/controller-queue.php');
}

$page_title = 'Publish: ' . $sheet['subject_title'];

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action  = $_POST['action']  ?? '';
    $remarks = trim($_POST['remarks'] ?? '');

    if ($action === 'publish') {
        db()->prepare(
            'UPDATE result_mark_sheets SET
               workflow_status = "published",
               published_by    = ?,
               published_at    = NOW()
             WHERE id = ? AND workflow_status = "hod_approved"'
        )->execute([$user['id'], $id]);
        flash_set('success', 'Mark sheet published and now visible to students.');
        redirect(APP_URL . '/results/controller-queue.php');

    } elseif ($action === 'return_hod') {
        if ($remarks === '') {
            flash_set('error', 'Please provide remarks when returning a sheet.');
            redirect(APP_URL . '/results/controller-approve.php?id=' . $id);
        }
        db()->prepare(
            'UPDATE result_mark_sheets SET
               workflow_status   = "under_review",
               returned_by       = ?,
               returned_at       = NOW(),
               return_remarks    = ?,
               returned_to_step  = "under_review"
             WHERE id = ? AND workflow_status = "hod_approved"'
        )->execute([$user['id'], $remarks, $id]);
        flash_set('success', 'Mark sheet returned to the Department Head.');
        redirect(APP_URL . '/results/controller-queue.php');
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/results/controller-queue.php">Controller Queue</a></li>
            <li class="breadcrumb-item active">Publish Sheet</li>
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

<!-- Controller action -->
<div class="card mt-4" style="border-radius:12px; border-left:4px solid #198754;">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-check-double me-2 text-success"></i>Controller of Examinations – Final Decision</h6>
    </div>
    <div class="card-body p-4">
        <div class="mb-3">
            <label class="form-label fw-medium">Remarks (optional for publish; required for return)</label>
            <textarea id="remarks_field" class="form-control" rows="3"
                      placeholder="Add remarks if returning…"></textarea>
        </div>
        <div class="d-flex gap-3 flex-wrap">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="publish">
                <button type="submit" class="btn btn-success btn-lg" style="border-radius:10px;"
                        onclick="return confirm('Publish this mark sheet? It will become visible to students.');">
                    <i class="fas fa-globe me-1"></i> Publish Results
                </button>
            </form>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="return_hod">
                <input type="hidden" name="remarks" id="return_remarks_val">
                <button type="submit" class="btn btn-outline-danger" style="border-radius:10px;"
                        onclick="document.getElementById('return_remarks_val').value = document.getElementById('remarks_field').value;">
                    <i class="fas fa-undo me-1"></i> Return to HOD
                </button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
