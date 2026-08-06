<?php
/**
 * Student Attendance – date-wise attendance sheet for one offered subject.
 *
 * Rows: Course Offer registered students. Columns: class dates.
 * Per-student totals: Present / Absent / Late / Excused counts + attendance %.
 */
require_once __DIR__ . '/helpers.php';
require_access('student-attendance');

$subject_id = (int)($_POST['subject_id'] ?? $_GET['subject_id'] ?? 0);
$subject    = $subject_id > 0 ? sa_subject($subject_id) : null;

if (!$subject || !can_access_dept((int)$subject['dept_id'])) {
    flash_set('error', 'Subject not found or you do not have access to it.');
    redirect(APP_URL . '/student-attendance/index.php');
}
if (!sa_is_staff() && !sa_is_assigned_subject($subject_id)) {
    flash_set('error', 'You are not assigned to this subject.');
    redirect(APP_URL . '/student-attendance/index.php');
}

// ── Delete a session (one date column) ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_session') {
    csrf_check();
    $del_date = trim($_POST['class_date'] ?? '');
    if (!sa_can_delete() && !sa_is_assigned_subject($subject_id)) {
        flash_set('error', 'You do not have permission to delete attendance sessions.');
    } elseif (sa_valid_date($del_date) && sa_delete_session($subject_id, $del_date)) {
        flash_set('success', 'Attendance for ' . date('d M Y', strtotime($del_date)) . ' deleted.');
    } else {
        flash_set('error', 'Could not delete that attendance session.');
    }
    redirect(APP_URL . '/student-attendance/sheet.php?subject_id=' . $subject_id);
}

$students = sa_students($subject_id);
try {
    $sessions = sa_sessions($subject_id);
    $matrix   = sa_matrix($subject_id);
} catch (Throwable $e) {
    error_log('student-attendance: sheet load failed – ' . $e->getMessage());
    $sessions = [];
    $matrix   = [];
    flash_set('error', 'Attendance tables not found. Run admin/student-attendance.sql first.');
}

$total_sessions = count($sessions);
$can_take       = sa_can_manage_subject($subject_id);

$badge = [
    'present' => ['P', 'success'],
    'absent'  => ['A', 'danger'],
    'late'    => ['L', 'warning text-dark'],
    'excused' => ['E', 'info text-dark'],
];

$page_title = 'Attendance Sheet – ' . $subject['course_code'];
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-1"><i class="fas fa-table me-2 text-primary"></i>Attendance Sheet</h4>
        <p class="text-muted mb-0" style="font-size:.85rem;">
            <strong><?= h($subject['course_code']) ?></strong> — <?= h($subject['course_name']) ?>
            &middot; <?= h($subject['dept_name']) ?>
            &middot; <?= h($subject['program_name']) ?>
            &middot; <?= h($subject['batch_name']) ?>
            &middot; <?= h($subject['semester']) ?>
            &middot; <?= h($subject['academic_intake']) ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($can_take): ?>
        <a href="<?= APP_URL ?>/student-attendance/take.php?subject_id=<?= $subject_id ?>" class="btn btn-success btn-sm">
            <i class="fas fa-user-check me-1"></i> Take Attendance
        </a>
        <?php endif; ?>
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">
            <i class="fas fa-print me-1"></i> Print
        </button>
        <a href="<?= APP_URL ?>/student-attendance/index.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
    </div>
</div>

<?php flash_show(); ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card text-center py-3">
            <div class="stat-val text-primary" style="font-size:1.5rem;font-weight:700;"><?= count($students) ?></div>
            <div class="text-muted" style="font-size:.8rem;">Registered Students</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center py-3">
            <div class="stat-val text-success" style="font-size:1.5rem;font-weight:700;"><?= $total_sessions ?></div>
            <div class="text-muted" style="font-size:.8rem;">Classes Taken</div>
        </div>
    </div>
</div>

<?php if (empty($students)): ?>
<div class="alert alert-info">
    <i class="fas fa-info-circle me-1"></i>
    No students are registered on this subject yet. Students are pulled from
    <strong>Course Offer registrations</strong>.
</div>
<?php else: ?>

<div class="card">
    <div class="card-header">
        <strong style="font-size:.9rem;">Date-wise Attendance</strong>
        <span class="text-muted ms-2" style="font-size:.75rem;">
            P = Present &middot; A = Absent &middot; L = Late &middot; E = Excused &middot; — = not recorded
        </span>
    </div>
    <div class="table-responsive">
        <table class="table table-bordered table-sm mb-0" style="font-size:.8rem;">
            <thead>
                <tr>
                    <th style="min-width:110px;">Student ID</th>
                    <th style="min-width:170px;">Name</th>
                    <?php foreach ($sessions as $sess): ?>
                    <th class="text-center" style="min-width:74px;">
                        <?php if ($can_take): ?>
                        <a href="<?= APP_URL ?>/student-attendance/take.php?subject_id=<?= $subject_id ?>&amp;date=<?= h($sess['class_date']) ?>"
                           title="Edit this date" class="text-decoration-none">
                            <?= date('d M', strtotime($sess['class_date'])) ?><br>
                            <span class="text-muted" style="font-weight:400;"><?= date('Y', strtotime($sess['class_date'])) ?></span>
                        </a>
                        <?php else: ?>
                        <?= date('d M', strtotime($sess['class_date'])) ?><br>
                        <span class="text-muted" style="font-weight:400;"><?= date('Y', strtotime($sess['class_date'])) ?></span>
                        <?php endif; ?>
                        <?php if (sa_can_delete() || sa_is_assigned_subject($subject_id)): ?>
                        <form method="post" class="d-inline"
                              onsubmit="return confirm('Delete attendance for <?= h($sess['class_date']) ?>?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_session">
                            <input type="hidden" name="subject_id" value="<?= $subject_id ?>">
                            <input type="hidden" name="class_date" value="<?= h($sess['class_date']) ?>">
                            <button type="submit" class="btn btn-link btn-sm text-danger p-0" style="font-size:.65rem;" title="Delete this date">
                                <i class="fas fa-times"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </th>
                    <?php endforeach; ?>
                    <th class="text-center" style="min-width:64px;">P</th>
                    <th class="text-center" style="min-width:64px;">A</th>
                    <th class="text-center" style="min-width:64px;">L</th>
                    <th class="text-center" style="min-width:64px;">E</th>
                    <th class="text-center" style="min-width:80px;">%</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($students as $stu):
                    $pk     = (int)$stu['student_pk'];
                    $counts = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0];
                ?>
                <tr>
                    <td><strong><?= h($stu['student_id']) ?></strong></td>
                    <td><?= h($stu['full_name']) ?></td>
                    <?php foreach ($sessions as $sess):
                        $status = $matrix[$pk][$sess['class_date']] ?? null;
                        if ($status !== null && isset($counts[$status])) $counts[$status]++;
                    ?>
                    <td class="text-center">
                        <?php if ($status !== null && isset($badge[$status])): ?>
                        <span class="badge bg-<?= $badge[$status][1] ?>"><?= $badge[$status][0] ?></span>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <?php endforeach; ?>
                    <?php
                        $attended = $counts['present'] + $counts['late'];
                        $pct      = $total_sessions > 0 ? round($attended / $total_sessions * 100) : 0;
                        $pct_cls  = $pct >= 75 ? 'success' : ($pct >= 60 ? 'warning text-dark' : 'danger');
                    ?>
                    <td class="text-center text-success fw-bold"><?= $counts['present'] ?></td>
                    <td class="text-center text-danger fw-bold"><?= $counts['absent'] ?></td>
                    <td class="text-center"><?= $counts['late'] ?></td>
                    <td class="text-center"><?= $counts['excused'] ?></td>
                    <td class="text-center">
                        <?php if ($total_sessions > 0): ?>
                        <span class="badge bg-<?= $pct_cls ?>"><?= $pct ?>%</span>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
