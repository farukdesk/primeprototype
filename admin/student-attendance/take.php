<?php
/**
 * Student Attendance – take / edit attendance for one subject on one date.
 *
 * Students listed here are the Course Offer registered students of the
 * selected offered subject (co_registrations).
 */
require_once __DIR__ . '/helpers.php';
require_access('student-attendance');

$subject_id = (int)($_POST['subject_id'] ?? $_GET['subject_id'] ?? 0);
$subject    = $subject_id > 0 ? sa_subject($subject_id) : null;

if (!$subject || !can_access_dept((int)$subject['dept_id'])) {
    flash_set('error', 'Subject not found or you do not have access to it.');
    redirect(APP_URL . '/student-attendance/index.php');
}
if (!sa_can_manage_subject($subject_id)) {
    flash_set('error', 'You are not assigned to this subject.');
    redirect(APP_URL . '/student-attendance/index.php');
}

$date = trim($_POST['class_date'] ?? $_GET['date'] ?? date('Y-m-d'));
if (!sa_valid_date($date)) {
    $date = date('Y-m-d');
}

// ── Save ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $statuses = (array)($_POST['status'] ?? []);
    if (empty($statuses)) {
        flash_set('error', 'Nothing to save — no students marked.');
        redirect(APP_URL . '/student-attendance/take.php?subject_id=' . $subject_id . '&date=' . urlencode($date));
    }
    try {
        sa_save_attendance($subject_id, $date, $statuses, (int)auth_user()['id']);
        flash_set('success', 'Attendance saved for ' . date('d M Y', strtotime($date)) . '.');
        redirect(APP_URL . '/student-attendance/sheet.php?subject_id=' . $subject_id);
    } catch (Throwable $e) {
        error_log('student-attendance: save failed – ' . $e->getMessage());
        flash_set('error', 'Could not save attendance. Make sure the attendance tables are installed (admin/student-attendance.sql).');
        redirect(APP_URL . '/student-attendance/take.php?subject_id=' . $subject_id . '&date=' . urlencode($date));
    }
}

$students = sa_students($subject_id);
try {
    $existing = sa_statuses_for_date($subject_id, $date);
} catch (Throwable $e) {
    $existing = [];
}

$page_title = 'Take Attendance – ' . $subject['course_code'];
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-1">
            <i class="fas fa-user-check me-2 text-success"></i>Take Attendance
        </h4>
        <p class="text-muted mb-0" style="font-size:.85rem;">
            <strong><?= h($subject['course_code']) ?></strong> — <?= h($subject['course_name']) ?>
            &middot; <?= h($subject['batch_name']) ?>
            &middot; <?= h($subject['semester']) ?>
            &middot; <?= h($subject['academic_intake']) ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/student-attendance/sheet.php?subject_id=<?= $subject_id ?>" class="btn btn-outline-primary btn-sm">
            <i class="fas fa-table me-1"></i> Attendance Sheet
        </a>
        <a href="<?= APP_URL ?>/student-attendance/index.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
    </div>
</div>

<?php flash_show(); ?>

<!-- Date picker -->
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="subject_id" value="<?= $subject_id ?>">
            <div class="col-auto">
                <label class="form-label" style="font-size:.8rem;font-weight:600;">Class Date</label>
                <input type="date" name="date" class="form-control form-control-sm" value="<?= h($date) ?>" required>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-calendar-day me-1"></i> Load Date
                </button>
            </div>
            <?php if (!empty($existing)): ?>
            <div class="col-auto">
                <span class="badge bg-warning text-dark">Attendance already exists for this date — saving will overwrite it.</span>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php if (empty($students)): ?>
<div class="alert alert-info">
    <i class="fas fa-info-circle me-1"></i>
    No students are registered on this subject yet. Students are pulled from
    <strong>Course Offer registrations</strong> — enrol students on the offer first.
</div>
<?php else: ?>

<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="subject_id" value="<?= $subject_id ?>">
    <input type="hidden" name="class_date" value="<?= h($date) ?>">

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <strong style="font-size:.9rem;">
                Students — <?= date('d M Y', strtotime($date)) ?>
                <span class="badge bg-secondary ms-1"><?= count($students) ?></span>
            </strong>
            <div class="btn-group btn-group-sm">
                <button type="button" class="btn btn-outline-success" onclick="saMarkAll('present')">All Present</button>
                <button type="button" class="btn btn-outline-danger" onclick="saMarkAll('absent')">All Absent</button>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th style="width:60px;">#</th>
                        <th>Student ID</th>
                        <th>Name</th>
                        <th>Section</th>
                        <th class="text-center" style="width:340px;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $i => $stu):
                        $pk      = (int)$stu['student_pk'];
                        $current = $existing[$pk] ?? 'present';
                    ?>
                    <tr>
                        <td class="text-muted"><?= $i + 1 ?></td>
                        <td><strong><?= h($stu['student_id']) ?></strong></td>
                        <td><?= h($stu['full_name']) ?></td>
                        <td><?= h($stu['section'] ?: '—') ?></td>
                        <td class="text-center">
                            <div class="btn-group btn-group-sm" role="group">
                                <?php foreach (SA_STATUSES as $key => $label):
                                    $btn = ['present' => 'success', 'absent' => 'danger', 'late' => 'warning', 'excused' => 'info'][$key];
                                ?>
                                <input type="radio" class="btn-check" name="status[<?= $pk ?>]"
                                       id="st-<?= $pk ?>-<?= $key ?>" value="<?= $key ?>"
                                       <?= $current === $key ? 'checked' : '' ?>>
                                <label class="btn btn-outline-<?= $btn ?>" for="st-<?= $pk ?>-<?= $key ?>"
                                       title="<?= ucfirst($key) ?>"><?= ucfirst($key) ?></label>
                                <?php endforeach; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white text-end">
            <button type="submit" class="btn btn-success">
                <i class="fas fa-save me-1"></i> Save Attendance
            </button>
        </div>
    </div>
</form>

<script>
function saMarkAll(status) {
    document.querySelectorAll('input.btn-check[value="' + status + '"]').forEach(el => { el.checked = true; });
}
</script>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
