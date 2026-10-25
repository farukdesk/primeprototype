<?php
/**
 * Mark Change Request — create
 * Propose corrected marks for ONE published result row. A written reason
 * note is mandatory; the request then enters the department approval flow.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

auth_check();
mcr_ensure_schema();
if (!mcr_can_request()) {
    flash_set('error', 'You do not have access to create mark change requests.');
    redirect(APP_URL . '/index.php');
}

$user     = auth_user();
$user_id  = (int)($user['id'] ?? 0);
$grade_id = (int)($_GET['grade_id'] ?? $_POST['grade_id'] ?? 0);

$st = db()->prepare(
    'SELECT g.*, ms.dept_id AS ms_dept_id, ms.program_id AS ms_program_id,
            ms.semester, ms.subject_code, ms.subject_title, ms.credits,
            ms.curriculum_id, ms.workflow_status,
            d.name AS dept_name, p.program_name
       FROM result_sheet_grades g
       JOIN result_mark_sheets ms          ON ms.id = g.sheet_id
       LEFT JOIN dept_departments d        ON d.id  = ms.dept_id
       LEFT JOIN dept_academic_programs p  ON p.id  = ms.program_id
      WHERE g.id = ?'
);
$st->execute([$grade_id]);
$grade = $st->fetch();

if (!$grade) {
    flash_set('error', 'Result row not found.');
    redirect(APP_URL . '/mark-change-request/index.php');
}
if ($grade['workflow_status'] !== 'published') {
    flash_set('error', 'Only published results can be corrected through a change request.');
    redirect(APP_URL . '/mark-change-request/index.php');
}
if ($open = mcr_open_request_for_grade($grade_id)) {
    flash_set('error', 'A change request for this result is already pending.');
    redirect(APP_URL . '/mark-change-request/view.php?id=' . (int)$open['id']);
}

$cur_marks = mcr_grade_marks($grade);
$dist      = mcr_distribution($grade['curriculum_id'] ? (int)$grade['curriculum_id'] : null, count($cur_marks));

// ── POST: submit the request ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $reason    = trim((string)($_POST['reason'] ?? ''));
    $is_absent = !empty($_POST['is_absent']) ? 1 : 0;

    $new_marks = [];
    foreach ($dist as $i => $d) {
        $raw = trim((string)($_POST['marks'][$i] ?? ''));
        if ($raw === '') { $new_marks[$i] = null; continue; }
        if (!is_numeric($raw)) {
            flash_set('error', 'Marks must be numeric (component: ' . $d['name'] . ').');
            redirect(APP_URL . '/mark-change-request/create.php?grade_id=' . $grade_id);
        }
        $new_marks[$i] = (float)$raw;
    }

    if ($reason === '') {
        flash_set('error', 'A note explaining the reason for the change is required.');
        redirect(APP_URL . '/mark-change-request/create.php?grade_id=' . $grade_id);
    }

    [$ok, $msg, $request_id] = mcr_submit($grade_id, $new_marks, $is_absent, $reason, $user_id);
    flash_set($ok ? 'success' : 'error', $msg);
    redirect($ok
        ? APP_URL . '/mark-change-request/view.php?id=' . $request_id
        : APP_URL . '/mark-change-request/create.php?grade_id=' . $grade_id);
}

$page_title = 'New Mark Change Request';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="fas fa-file-pen me-2 text-primary"></i>New Mark Change Request</h4>
    <a href="index.php?q=<?= urlencode((string)$grade['student_sid']) ?>" class="btn btn-sm btn-outline-secondary">
        <i class="fas fa-arrow-left me-1"></i>Back
    </a>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white">
        <strong><?= h($grade['student_sid']) ?></strong>
        <?= !empty($grade['student_name']) ? ' — ' . h($grade['student_name']) : '' ?>
        <span class="text-muted ms-2">
            <?= h(trim(($grade['subject_code'] ? $grade['subject_code'] . ' — ' : '') . $grade['subject_title'])) ?>
            · <?= h($grade['semester']) ?>
            · <?= h($grade['dept_name'] ?? '') ?><?= !empty($grade['program_name']) ? ' / ' . h($grade['program_name']) : '' ?>
        </span>
    </div>
    <div class="card-body">
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="grade_id" value="<?= $grade_id ?>">

            <div class="table-responsive mb-3">
                <table class="table table-bordered align-middle" style="max-width:720px;">
                    <thead class="table-light">
                        <tr>
                            <th>Component</th>
                            <th class="text-center" style="width:110px;">Max</th>
                            <th class="text-center" style="width:130px;">Current</th>
                            <th class="text-center" style="width:160px;">New Mark</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($dist as $i => $d): ?>
                        <tr>
                            <td><?= h($d['name']) ?></td>
                            <td class="text-center text-muted">/<?= h(rtrim(rtrim(number_format($d['max'], 2, '.', ''), '0'), '.')) ?></td>
                            <td class="text-center"><?= $cur_marks[$i] !== null ? h($cur_marks[$i] + 0) : '—' ?></td>
                            <td>
                                <input type="number" step="0.01" min="0" max="<?= h($d['max']) ?>"
                                       class="form-control form-control-sm text-center mcr-mark"
                                       name="marks[<?= $i ?>]"
                                       value="<?= $cur_marks[$i] !== null ? h($cur_marks[$i] + 0) : '' ?>">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="table-light">
                        <td colspan="2" class="fw-semibold">Current total / grade</td>
                        <td class="text-center fw-semibold" colspan="2">
                            <?= h(mcr_result_summary($grade['total_marks'], $grade['letter_grade'], $grade['grade_point'], (int)$grade['is_absent'])) ?>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="mcr-absent" name="is_absent" value="1"
                       <?= (int)$grade['is_absent'] === 1 ? 'checked' : '' ?>>
                <label class="form-check-label" for="mcr-absent">
                    Student was absent (result stays <strong>Incom</strong>)
                </label>
            </div>

            <div class="mb-3" style="max-width:720px;">
                <label class="form-label fw-semibold">
                    Reason for change <span class="text-danger">*</span>
                </label>
                <textarea name="reason" class="form-control" rows="4" required
                          placeholder="Write the reason for this mark change (e.g. totalling mistake, re-scrutiny outcome, data-entry error…). This note is shown to every approver in the chain."></textarea>
                <div class="form-text">
                    A written note from the faculty is mandatory. The request will go through the
                    department's original mark approval flow; the result only changes after the
                    final step approves it.
                </div>
            </div>

            <button class="btn btn-primary">
                <i class="fas fa-paper-plane me-1"></i>Submit for Approval
            </button>
            <a href="index.php?q=<?= urlencode((string)$grade['student_sid']) ?>" class="btn btn-outline-secondary">Cancel</a>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
