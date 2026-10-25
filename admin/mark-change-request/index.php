<?php
/**
 * Mark Change Request — home
 *  - Search a student by Student ID → published results of ALL semesters.
 *  - "Request Change" per course row.
 *  - My Requests list + (for chain approvers) a Pending Approvals queue.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

auth_check();
mcr_ensure_schema();
if (!mcr_can_view_module()) {
    flash_set('error', 'You do not have access to the Mark Change Request module.');
    redirect(APP_URL . '/index.php');
}

$page_title = 'Mark Change Request';
$user       = auth_user();
$user_id    = (int)($user['id'] ?? 0);

$q        = trim((string)($_GET['q'] ?? ''));
$student  = null;
$semesters = [];
$open_map  = [];

if ($q !== '' && mcr_can_request()) {
    $student = mcr_find_student($q);
    if ($student) {
        $semesters = mcr_student_results($student);
        $grade_ids = [];
        foreach ($semesters as $sem) {
            foreach ($sem['courses'] as $c) $grade_ids[] = (int)$c['grade_id'];
        }
        $open_map = mcr_open_requests_by_grade($grade_ids);
    }
}

$my_requests = mcr_can_request() ? mcr_my_requests($user_id) : [];
$queue       = mcr_approver_queue();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="fas fa-file-pen me-2 text-primary"></i>Mark Change Request</h4>
</div>

<?php if (mcr_can_request()): ?>
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label fw-semibold">Student ID</label>
                <input type="text" name="q" class="form-control" value="<?= h($q) ?>"
                       placeholder="e.g. 213014055" autofocus>
            </div>
            <div class="col-md-3">
                <button class="btn btn-primary"><i class="fas fa-search me-1"></i>Search Student</button>
            </div>
        </form>
        <div class="form-text mt-2">
            Search a student to see their published results of all semesters, then request a mark
            correction. Every request requires a written reason and passes through the department's
            mark approval flow before the result is changed.
        </div>
    </div>
</div>

<?php if ($q !== '' && !$student): ?>
<div class="alert alert-warning"><i class="fas fa-triangle-exclamation me-1"></i>
    No student found for "<strong><?= h($q) ?></strong>".</div>
<?php endif; ?>

<?php if ($student): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <div>
            <strong><?= h($student['full_name']) ?></strong>
            <span class="text-muted ms-2"><?= h($student['student_id']) ?></span>
        </div>
        <div class="text-muted small">
            <?= h($student['dept_name'] ?? '') ?>
            <?= !empty($student['program_name']) ? ' · ' . h($student['program_name']) : '' ?>
            <?= !empty($student['batch']) ? ' · Batch ' . h($student['batch']) : '' ?>
            · <span class="badge bg-light text-dark border"><?= h($student['status']) ?></span>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($semesters)): ?>
            <div class="text-muted"><i class="fas fa-circle-info me-1"></i>
                This student has no published results yet.</div>
        <?php else: foreach ($semesters as $sem): ?>
            <h6 class="mt-3 mb-2" style="color:#002147;">
                <i class="fas fa-calendar me-1 text-muted"></i><?= h($sem['label']) ?>
            </h6>
            <div class="table-responsive mb-3">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Course</th>
                            <th class="text-center" style="width:90px;">Credits</th>
                            <th class="text-center" style="width:90px;">Total</th>
                            <th class="text-center" style="width:90px;">Grade</th>
                            <th class="text-center" style="width:90px;">Point</th>
                            <th class="text-center" style="width:160px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($sem['courses'] as $c):
                        $gid = (int)$c['grade_id']; ?>
                        <tr>
                            <td>
                                <?= !empty($c['subject_code']) ? '<strong>' . h($c['subject_code']) . '</strong> — ' : '' ?>
                                <?= h($c['subject_title']) ?>
                            </td>
                            <td class="text-center"><?= $c['credits'] !== null ? h($c['credits']) : '—' ?></td>
                            <td class="text-center"><?= $c['total_marks'] !== null ? h($c['total_marks']) : '—' ?></td>
                            <td class="text-center">
                                <?= (int)$c['is_absent'] === 1 ? '<span class="badge bg-secondary">Incom</span>'
                                    : ($c['letter_grade'] !== null ? h($c['letter_grade']) : '—') ?>
                            </td>
                            <td class="text-center"><?= $c['grade_point'] !== null ? h($c['grade_point']) : '—' ?></td>
                            <td class="text-center">
                                <?php if (isset($open_map[$gid])): ?>
                                    <a href="view.php?id=<?= $open_map[$gid] ?>" class="badge bg-warning text-dark text-decoration-none">
                                        <i class="fas fa-hourglass-half me-1"></i>Change Pending
                                    </a>
                                <?php else: ?>
                                    <a href="create.php?grade_id=<?= $gid ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-pen me-1"></i>Request Change
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; endif; ?>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if (!empty($queue)): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white">
        <strong><i class="fas fa-tasks me-1 text-primary"></i>Pending Approvals</strong>
        <span class="badge bg-primary ms-1"><?= count($queue) ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th><th>Student</th><th>Course</th><th>Semester</th>
                    <th>Dept</th><th>Change</th><th>Requested By</th><th>Waiting At</th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($queue as $r): ?>
                <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td><?= h($r['student_sid']) ?><br><small class="text-muted"><?= h($r['student_name'] ?? '') ?></small></td>
                    <td><?= h(trim(($r['subject_code'] ? $r['subject_code'] . ' — ' : '') . $r['subject_title'])) ?></td>
                    <td><?= h($r['semester']) ?></td>
                    <td><?= h($r['dept_name'] ?? '') ?></td>
                    <td>
                        <?= h(mcr_result_summary($r['old_total'], $r['old_letter'], $r['old_point'], (int)$r['old_is_absent'])) ?>
                        <i class="fas fa-arrow-right mx-1 text-muted"></i>
                        <strong><?= h(mcr_result_summary($r['new_total'], $r['new_letter'], $r['new_point'], (int)$r['new_is_absent'])) ?></strong>
                    </td>
                    <td><?= h($r['requester_name'] ?? '') ?></td>
                    <td><span class="badge bg-info text-dark"><?= h($r['current_step_label'] ?? '') ?></span></td>
                    <td class="text-end">
                        <a href="view.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-primary">
                            <i class="fas fa-gavel me-1"></i>Review
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (mcr_can_request()): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white"><strong><i class="fas fa-list me-1 text-muted"></i>My Requests</strong></div>
    <?php if (empty($my_requests)): ?>
        <div class="card-body text-muted">You have not submitted any mark change requests yet.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th><th>Student</th><th>Course</th><th>Semester</th>
                    <th>Change</th><th>Status</th><th>Current Step</th><th>Submitted</th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($my_requests as $r): ?>
                <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td><?= h($r['student_sid']) ?></td>
                    <td><?= h(trim(($r['subject_code'] ? $r['subject_code'] . ' — ' : '') . $r['subject_title'])) ?></td>
                    <td><?= h($r['semester']) ?></td>
                    <td>
                        <?= h(mcr_result_summary($r['old_total'], $r['old_letter'], $r['old_point'], (int)$r['old_is_absent'])) ?>
                        <i class="fas fa-arrow-right mx-1 text-muted"></i>
                        <strong><?= h(mcr_result_summary($r['new_total'], $r['new_letter'], $r['new_point'], (int)$r['new_is_absent'])) ?></strong>
                    </td>
                    <td><?= mcr_status_badge((string)$r['status']) ?></td>
                    <td><?= $r['status'] === 'pending' ? '<span class="badge bg-info text-dark">' . h($r['current_step_label'] ?? '') . '</span>' : '—' ?></td>
                    <td><small><?= h(date('d M Y, g:i A', strtotime((string)$r['created_at']))) ?></small></td>
                    <td class="text-end">
                        <a href="view.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-eye"></i>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
