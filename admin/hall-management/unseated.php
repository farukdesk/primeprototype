<?php
/**
 * Hall Management – Unseated students report.
 *
 * Lists students who hold an active admit card for an exam on the selected
 * date (and optional time slot) but have NOT been assigned a seat in any
 * hall for that date yet.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('hall-management');
require_once __DIR__ . '/helpers.php';

$page_title = 'Unseated Students Report';

hm_ensure_schedule_columns();
hm_ensure_assignments_table();

$f_date = trim((string)($_GET['exam_date'] ?? ''));
if ($f_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_date)) $f_date = '';
if ($f_date === '') $f_date = date('Y-m-d');

$f_dept    = (int)($_GET['dept_id'] ?? 0);
$f_program = (int)($_GET['program_id'] ?? 0);
$f_batch   = (int)($_GET['batch_id'] ?? 0);
$f_section = trim((string)($_GET['section'] ?? ''));
$f_shift   = trim((string)($_GET['shift'] ?? ''));
$f_slot    = trim((string)($_GET['time_slot'] ?? ''));

$departments = hm_departments();
$programs    = hm_programs();
$batches     = hm_batches();
$shifts      = hm_shift_options();
$sections    = hm_section_options();
$time_slots  = hm_time_slot_options($f_date);

$students = hm_unseated_students($f_date, [
    'dept_id'    => $f_dept,
    'program_id' => $f_program,
    'batch_id'   => $f_batch,
    'section'    => $f_section,
    'shift'      => $f_shift,
    'time_slot'  => $f_slot,
]);

require_once __DIR__ . '/../includes/header.php';
?>

<style>
@media print {
    .sidebar, .navbar, .breadcrumb, .hm-no-print, footer { display: none !important; }
    .main-content { margin: 0 !important; padding: 0 !important; }
    .card { border: none !important; box-shadow: none !important; }
    .hm-print-head { display: block !important; }
}
.hm-print-head { display: none; text-align: center; margin-bottom: 12px; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4 hm-no-print">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/hall-management/index.php">Hall Management</a></li>
            <li class="breadcrumb-item active">Unseated Students</li>
        </ol>
    </nav>
    <button type="button" class="btn btn-outline-secondary" style="border-radius:10px;" onclick="window.print()">
        <i class="fas fa-print me-1"></i> Print
    </button>
</div>

<?php flash_show(); ?>

<div class="card mb-4 hm-no-print" style="border-radius:12px;">
    <div class="card-body p-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label fw-medium mb-1">Exam Date <span class="text-danger">*</span></label>
                <input type="date" name="exam_date" class="form-control form-control-sm" value="<?= h($f_date) ?>" required>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-medium mb-1">Exam Time</label>
                <select name="time_slot" class="form-select form-select-sm">
                    <option value="">All times</option>
                    <?php foreach ($time_slots as $ts): ?>
                    <option value="<?= h($ts) ?>" <?= $f_slot === $ts ? 'selected' : '' ?>><?= h($ts) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-medium mb-1">Department</label>
                <select name="dept_id" class="form-select form-select-sm">
                    <option value="">All departments</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $f_dept === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-medium mb-1">Program</label>
                <select name="program_id" class="form-select form-select-sm">
                    <option value="">All programs</option>
                    <?php foreach ($programs as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $f_program === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['program_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label fw-medium mb-1">Batch</label>
                <select name="batch_id" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($batches as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $f_batch === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label fw-medium mb-1">Section</label>
                <select name="section" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($sections as $sec): ?>
                    <option value="<?= h($sec) ?>" <?= $f_section === $sec ? 'selected' : '' ?>><?= h($sec) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label fw-medium mb-1">Shift</label>
                <select name="shift" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($shifts as $sh): ?>
                    <option value="<?= h($sh) ?>" <?= $f_shift === $sh ? 'selected' : '' ?>><?= h($sh) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1 d-flex gap-1">
                <button class="btn btn-sm btn-primary" style="border-radius:8px;" title="Filter"><i class="fas fa-filter"></i></button>
                <a href="<?= APP_URL ?>/hall-management/unseated.php" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;" title="Reset"><i class="fas fa-undo"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="hm-print-head">
    <h4 class="mb-1">Unseated Students Report</h4>
    <div>Exam Date: <strong><?= h(date('d M Y', strtotime($f_date))) ?></strong><?= $f_slot !== '' ? ' — ' . h($f_slot) : '' ?></div>
</div>

<div class="card" style="border-radius:12px;">
    <div class="card-header bg-white d-flex justify-content-between align-items-center" style="border-radius:12px 12px 0 0;">
        <div class="fw-semibold">
            <i class="fas fa-user-clock me-2 text-warning"></i>
            Students with an admit card on <?= h(date('d M Y', strtotime($f_date))) ?> but no seat yet
        </div>
        <span class="badge <?= $students ? 'bg-warning text-dark' : 'bg-success' ?>"><?= count($students) ?> unseated</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($students)): ?>
        <div class="text-center text-muted py-5">
            <i class="fas fa-check-circle fa-2x mb-3 d-block text-success"></i>
            Every student with an admit-card exam on this date (matching the filters) already has a seat.
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:50px;">#</th>
                        <th>Student ID</th>
                        <th>Name</th>
                        <th>Department</th>
                        <th>Program</th>
                        <th>Batch</th>
                        <th>Section</th>
                        <th>Shift</th>
                        <th>Exam Date</th>
                        <th>Exam Time</th>
                        <th>Exam</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $i => $stu): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td class="fw-semibold"><?= h($stu['student_id']) ?></td>
                        <td><?= h($stu['full_name']) ?></td>
                        <td><?= h($stu['dept_name']) ?></td>
                        <td><?= h($stu['program_name'] ?? '—') ?></td>
                        <td><?= h($stu['batch_name'] ?? '—') ?></td>
                        <td><?= ($stu['section'] ?? '') !== '' ? h($stu['section']) : '<span class="text-muted">—</span>' ?></td>
                        <td><?= ($stu['shift'] ?? '') !== '' ? h($stu['shift']) : '<span class="text-muted">—</span>' ?></td>
                        <td><?= h(date('d M Y', strtotime($stu['exam_date']))) ?></td>
                        <td><?= ($stu['time_slot'] ?? '') !== '' ? h($stu['time_slot']) : '<span class="text-muted">—</span>' ?></td>
                        <td style="font-size:.85rem;"><?= h($stu['exam_name']) ?> <span class="text-muted">(<?= h($stu['semester']) ?>)</span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
