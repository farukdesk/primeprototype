<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('hall-management');
require_once __DIR__ . '/helpers.php';

hm_ensure_schedule_columns();
$hall_id = (int)($_GET['id'] ?? 0);
$hall    = $hall_id > 0 ? hm_get_hall($hall_id) : null;
if (!$hall) {
    flash_set('error', 'Hall not found or you do not have permission to access it.');
    redirect(APP_URL . '/hall-management/index.php');
}

$page_title = 'Hall – ' . $hall['room_number'];
$columns    = hm_hall_columns($hall_id);
$can_edit   = is_super_admin() || can_access('hall-management', 'can_edit');

// ── Assignment filters (students come from generated admit cards) ──────
$f_dept    = (int)($_GET['a_dept'] ?? 0);
$f_program = (int)($_GET['a_program'] ?? 0);
$f_batch   = (int)($_GET['a_batch'] ?? 0);
$f_date    = trim($_GET['a_date'] ?? '');
$f_section = trim($_GET['a_section'] ?? '');
$f_shift   = trim($_GET['a_shift'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_date)) $f_date = '';
if ($f_dept > 0 && !can_access_dept($f_dept)) $f_dept = 0;

// ── POST actions: assign / unassign / clear ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $ret    = APP_URL . '/hall-management/view.php?id=' . $hall_id;
    hm_ensure_assignments_table();

    if ($action === 'assign') {
        $p_dept    = (int)($_POST['dept_id'] ?? 0);
        $p_program = (int)($_POST['program_id'] ?? 0);
        $p_batch   = (int)($_POST['batch_id'] ?? 0);
        $p_date    = trim($_POST['exam_date'] ?? '');
        $p_section = trim($_POST['section'] ?? '');
        $p_shift   = trim($_POST['shift'] ?? '');
        if ($p_dept <= 0 || $p_program <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $p_date)) {
            flash_set('error', 'Department, Program and Exam Date are required.');
        } elseif (!can_access_dept($p_dept)) {
            flash_set('error', 'You do not have permission for that department.');
        } else {
            $students = hm_find_exam_students($p_dept, $p_program, $p_batch, $p_date, $p_section, $p_shift);
            if (!$students) {
                flash_set('error', 'No admit-card students found for the selected Department / Program / Batch / Exam Date / Section / Shift.');
            } else {
                [$assigned, $skipped, $left] = hm_assign_students($hall_id, $p_date, $students, [
                    'dept_id' => $p_dept, 'program_id' => $p_program, 'batch_id' => $p_batch,
                    'section' => $p_section, 'shift' => $p_shift,
                ]);
                $msg = $assigned . ' student(s) assigned to seats (one batch per column, different batches in adjacent columns).';
                if ($skipped > 0) $msg .= ' ' . $skipped . ' already seated elsewhere were skipped.';
                if ($left    > 0) $msg .= ' ' . $left . ' could not be seated — no suitable seats left (each column holds a single batch).';
                flash_set($assigned > 0 ? 'success' : 'error', $msg);
            }
            hm_sync_hall_schedule($hall_id);
            $ret .= '&a_date=' . urlencode($p_date);
        }
        redirect($ret);
    }

    if ($action === 'assign_one') {
        $p_student = (int)($_POST['student_id'] ?? 0);
        $p_seat    = trim((string)($_POST['seat'] ?? ''));
        $p_dept    = (int)($_POST['dept_id'] ?? 0);
        $p_program = (int)($_POST['program_id'] ?? 0);
        $p_batch   = (int)($_POST['batch_id'] ?? 0);
        $p_date    = trim($_POST['exam_date'] ?? '');
        $p_section = trim($_POST['section'] ?? '');
        $p_shift   = trim($_POST['shift'] ?? '');
        $p_force   = (string)($_POST['force'] ?? '') === '1';
        if ($p_student <= 0 || !preg_match('/^\d+:\d+$/', $p_seat) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $p_date)) {
            flash_set('error', 'Pick a student, a seat and an exam date to assign manually.');
        } else {
            [$col_no, $seat_no] = array_map('intval', explode(':', $p_seat));
            [$ok, $msg, $warnings] = hm_assign_single_student($hall_id, $p_date, $p_student, $col_no, $seat_no, [
                'dept_id' => $p_dept, 'program_id' => $p_program, 'batch_id' => $p_batch,
                'section' => $p_section, 'shift' => $p_shift,
            ], $p_force);
            if (!$ok && $warnings) {
                // Rule conflicts — render the page with a confirmation panel
                // instead of blocking; the user may confirm to seat anyway.
                $pending_confirm = [
                    'student_id' => $p_student, 'seat' => $p_seat,
                    'col_no' => $col_no, 'seat_no' => $seat_no,
                    'dept_id' => $p_dept, 'program_id' => $p_program, 'batch_id' => $p_batch,
                    'exam_date' => $p_date, 'section' => $p_section, 'shift' => $p_shift,
                    'warnings' => $warnings,
                ];
                $cs = db()->prepare('SELECT student_id, full_name FROM students WHERE id = ?');
                $cs->execute([$p_student]);
                $pending_confirm['student'] = $cs->fetch() ?: null;
            } else {
                flash_set($ok ? 'success' : 'error', $msg);
            }
        }
        if (!isset($pending_confirm)) {
            hm_sync_hall_schedule($hall_id);
            // Keep the student preview open so more students can be seated
            $ret .= '&preview=1&a_dept=' . $p_dept . '&a_program=' . $p_program . '&a_batch=' . $p_batch
                  . '&a_date=' . urlencode($p_date) . '&a_section=' . urlencode($p_section) . '&a_shift=' . urlencode($p_shift);
            redirect($ret);
        }
    }

    if ($action === 'unassign') {
        $aid = (int)($_POST['assignment_id'] ?? 0);
        $st  = db()->prepare('DELETE FROM hm_hall_assignments WHERE id = ? AND hall_id = ?');
        $st->execute([$aid, $hall_id]);
        hm_sync_hall_schedule($hall_id);
        flash_set('success', $st->rowCount() ? 'Seat assignment removed.' : 'Assignment not found.');
        redirect($ret . ($f_date !== '' ? '&a_date=' . urlencode($f_date) : ''));
    }

    if ($action === 'clear_date') {
        $p_date = trim($_POST['exam_date'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $p_date)) {
            $st = db()->prepare('DELETE FROM hm_hall_assignments WHERE hall_id = ? AND exam_date = ?');
            $st->execute([$hall_id, $p_date]);
            hm_sync_hall_schedule($hall_id);
            flash_set('success', $st->rowCount() . ' assignment(s) cleared for ' . $p_date . '.');
        }
        redirect($ret);
    }
}

// Dropdown data + current view state
$asg_depts    = hm_departments();
$asg_programs = hm_programs();
$asg_batches  = hm_batches();
$asg_shifts   = hm_shift_options();
$asg_sections = hm_section_options();
$asg_dates    = hm_hall_assignment_dates($hall_id);
if ($f_date === '' && $asg_dates) $f_date = (string)$asg_dates[0];
$assignments  = $f_date !== '' ? hm_assignments($hall_id, $f_date) : [];

// Batch+section colours for the seat layout + per-group exam course details.
// Two sections of the same batch get different colours so sections are
// highlighted in the layout; teachers resolve per section.
$batch_palette = hm_batch_palette();
$group_colors  = [];   // "batch_id|section" => palette entry
$group_names   = [];   // "batch_id|section" => display label (batch — Sec X)
foreach ($assignments as $a) {
    $bk  = (int)($a['student_batch_id'] ?? 0);
    $sec = trim((string)($a['student_section'] ?? ''));
    $gk  = $bk . '|' . $sec;
    if (!isset($group_colors[$gk])) {
        $group_colors[$gk] = $batch_palette[count($group_colors) % count($batch_palette)];
        $bn = $a['batch_name'] !== null && $a['batch_name'] !== '' ? (string)$a['batch_name'] : 'No batch';
        $group_names[$gk] = $bn . ($sec !== '' ? ' — Sec ' . $sec : '');
    }
}
ksort($group_colors);
$group_courses = ($f_date !== '' && $assignments) ? hm_exam_courses_by_group($hall_id, $f_date) : [];

// Preview of matching students (before assigning)
$preview = null;
if (isset($_GET['preview']) && $f_dept > 0 && $f_program > 0 && $f_date !== '') {
    $preview = hm_find_exam_students($f_dept, $f_program, $f_batch, $f_date, $f_section, $f_shift);
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/hall-management/index.php">Hall Management</a></li>
            <li class="breadcrumb-item active"><?= h($hall['room_number']) ?></li>
        </ol>
    </nav>
    <?php if ($can_edit): ?>
    <a href="<?= APP_URL ?>/hall-management/edit.php?id=<?= $hall_id ?>" class="btn btn-sm btn-primary" style="border-radius:8px;">
        <i class="fas fa-edit me-1"></i> Edit Hall
    </a>
    <?php endif; ?>
</div>

<?php flash_show(); ?>

<?php if (isset($pending_confirm)): ?>
<div class="card mb-4 border-warning" style="border-radius:12px;border-width:2px;">
    <div class="card-header fw-semibold text-dark" style="border-radius:10px 10px 0 0;background:#fff7e6;">
        <i class="fas fa-triangle-exclamation me-2 text-warning"></i>Seating Rule Conflicts — confirm to seat anyway
    </div>
    <div class="card-body">
        <p class="mb-2" style="font-size:.9rem;">
            Seating
            <strong><?= h($pending_confirm['student']['full_name'] ?? 'this student') ?></strong>
            <?php if (!empty($pending_confirm['student']['student_id'])): ?>(<?= h($pending_confirm['student']['student_id']) ?>)<?php endif; ?>
            at <strong>C<?= (int)$pending_confirm['col_no'] ?>-S<?= (int)$pending_confirm['seat_no'] ?></strong>
            on <strong><?= h(date('d M Y', strtotime($pending_confirm['exam_date']))) ?></strong>
            breaks the following rule(s):
        </p>
        <ul class="mb-3" style="font-size:.88rem;">
            <?php foreach ($pending_confirm['warnings'] as $w): ?>
            <li class="text-danger"><?= h($w) ?></li>
            <?php endforeach; ?>
        </ul>
        <div class="d-flex gap-2">
            <form method="post" class="mb-0">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="assign_one">
                <input type="hidden" name="force" value="1">
                <input type="hidden" name="student_id" value="<?= (int)$pending_confirm['student_id'] ?>">
                <input type="hidden" name="seat" value="<?= h($pending_confirm['seat']) ?>">
                <input type="hidden" name="dept_id" value="<?= (int)$pending_confirm['dept_id'] ?>">
                <input type="hidden" name="program_id" value="<?= (int)$pending_confirm['program_id'] ?>">
                <input type="hidden" name="batch_id" value="<?= (int)$pending_confirm['batch_id'] ?>">
                <input type="hidden" name="exam_date" value="<?= h($pending_confirm['exam_date']) ?>">
                <input type="hidden" name="section" value="<?= h($pending_confirm['section']) ?>">
                <input type="hidden" name="shift" value="<?= h($pending_confirm['shift']) ?>">
                <button type="submit" class="btn btn-sm btn-warning fw-semibold" style="border-radius:8px;">
                    <i class="fas fa-chair me-1"></i> Seat Anyway (override rules)
                </button>
            </form>
            <a href="<?= APP_URL ?>/hall-management/view.php?id=<?= $hall_id ?>&preview=1&a_dept=<?= (int)$pending_confirm['dept_id'] ?>&a_program=<?= (int)$pending_confirm['program_id'] ?>&a_batch=<?= (int)$pending_confirm['batch_id'] ?>&a_date=<?= urlencode($pending_confirm['exam_date']) ?>&a_section=<?= urlencode($pending_confirm['section']) ?>&a_shift=<?= urlencode($pending_confirm['shift']) ?>"
               class="btn btn-sm btn-outline-secondary" style="border-radius:8px;">Cancel</a>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card" style="border-radius:12px;">
            <div class="card-header bg-white fw-semibold" style="border-radius:12px 12px 0 0;">
                <i class="fas fa-door-open me-2 text-primary"></i>Hall Details
            </div>
            <div class="card-body">
                <table class="table table-sm mb-0" style="font-size:.9rem;">
                    <tr><th class="text-muted" style="width:45%;">Room Number</th><td class="fw-semibold"><?= h($hall['room_number']) ?></td></tr>
                    <tr><th class="text-muted">Department</th><td><?= h($hall['dept_name']) ?></td></tr>
                    <tr><th class="text-muted">Exam Date &amp; Time</th><td><?= h(hm_slot_label($hall['exam_date'] ?? null, $hall['exam_time'] ?? null)) ?></td></tr>
                    <tr><th class="text-muted">Columns</th><td><?= (int)$hall['num_columns'] ?></td></tr>
                    <tr><th class="text-muted">Rows</th><td><?= (int)$hall['num_rows'] ?></td></tr>
                    <tr><th class="text-muted">Total Seat Capacity</th><td><span class="badge bg-primary"><?= (int)$hall['total_capacity'] ?> seats</span></td></tr>
                    <tr><th class="text-muted">Status</th>
                        <td><?= (int)$hall['is_active'] === 1 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td></tr>
                    <?php if (!empty($hall['notes'])): ?>
                    <tr><th class="text-muted">Notes</th><td><?= h($hall['notes']) ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card" style="border-radius:12px;">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2" style="border-radius:12px 12px 0 0;">
                <span><i class="fas fa-th me-2 text-primary"></i>Seat Layout<?= $f_date !== '' ? ' — ' . h(date('d M Y', strtotime($f_date))) : '' ?></span>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <form method="get" class="d-flex align-items-center gap-2 mb-0">
                        <input type="hidden" name="id" value="<?= $hall_id ?>">
                        <?php if ($asg_dates): ?>
                        <select name="a_date" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
                            <?php foreach ($asg_dates as $d): ?>
                            <option value="<?= h($d) ?>" <?= $d === $f_date ? 'selected' : '' ?>><?= h(date('d M Y', strtotime($d))) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                    </form>
                    <a href="<?= APP_URL ?>/hall-management/print-layout.php?id=<?= $hall_id ?><?= $f_date !== '' ? '&date=' . urlencode($f_date) : '' ?>"
                       target="_blank" class="btn btn-sm btn-outline-primary" style="border-radius:8px;" title="Printable A4 seat plan">
                        <i class="fas fa-print me-1"></i> Print Seat Plan
                    </a>
                    <?php if ($assignments): ?>
                    <a href="<?= APP_URL ?>/hall-management/print-attendance.php?id=<?= $hall_id ?><?= $f_date !== '' ? '&date=' . urlencode($f_date) : '' ?>"
                       target="_blank" class="btn btn-sm btn-outline-success" style="border-radius:8px;" title="Printable student attendance sheet">
                        <i class="fas fa-clipboard-list me-1"></i> Attendance Sheet
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($columns)): ?>
                <div class="text-center text-muted py-4">No seat columns defined for this hall.</div>
                <?php else: ?>
                <div class="text-center mb-3">
                    <span class="badge bg-dark px-4 py-2" style="font-size:.8rem;"><i class="fas fa-chalkboard me-1"></i> FRONT / BOARD</span>
                </div>
                <div class="d-flex gap-3 justify-content-center flex-wrap" style="overflow-x:auto;">
                    <?php foreach ($columns as $col):
                        $col_group = null;
                        for ($s = 1; $s <= (int)$col['seat_capacity']; $s++) {
                            $o = $assignments[(int)$col['col_no'] . ':' . $s] ?? null;
                            if ($o) { $col_group = (int)($o['student_batch_id'] ?? 0) . '|' . trim((string)($o['student_section'] ?? '')); break; }
                        }
                        $col_clr = $col_group !== null ? ($group_colors[$col_group] ?? null) : null;
                    ?>
                    <div class="text-center">
                        <div class="fw-semibold mb-2" style="font-size:.8rem;color:#475569;">
                            Column <?= (int)$col['col_no'] ?>
                            <div class="text-muted" style="font-size:.7rem;"><?= (int)$col['seat_capacity'] ?> seats</div>
                            <?php if ($col_clr !== null): ?>
                            <div style="font-size:.65rem;margin-top:2px;">
                                <span style="display:inline-block;padding:1px 8px;border-radius:10px;background:<?= h($col_clr['bg']) ?>;border:1px solid <?= h($col_clr['border']) ?>;color:<?= h($col_clr['text']) ?>;">
                                    <?= h($group_names[$col_group] ?? 'No batch') ?>
                                </span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex flex-column gap-1 align-items-center">
                            <?php for ($s = 1; $s <= (int)$col['seat_capacity']; $s++):
                                $occ = $assignments[(int)$col['col_no'] . ':' . $s] ?? null; ?>
                            <?php if ($occ):
                                $occ_gk  = (int)($occ['student_batch_id'] ?? 0) . '|' . trim((string)($occ['student_section'] ?? ''));
                                $clr     = $group_colors[$occ_gk] ?? $batch_palette[0];
                                $occ_sec = trim((string)($occ['student_section'] ?? '')); ?>
                            <div class="hm-seat" data-sid="<?= (int)$occ['student_id'] ?>"
                                 title="<?= h($occ['full_name'] . ' (' . $occ['student_code'] . ')' . (!empty($occ['batch_name']) ? ' — ' . $occ['batch_name'] : '') . ($occ_sec !== '' ? ' — Sec ' . $occ_sec : '')) ?>"
                                 style="min-width:92px;height:26px;border-radius:6px;background:<?= h($clr['bg']) ?>;border:1px solid <?= h($clr['border']) ?>;
                                        display:flex;align-items:center;justify-content:center;font-size:.6rem;color:<?= h($clr['text']) ?>;padding:0 4px;white-space:nowrap;">
                                <?= h($occ['student_code']) ?><?= $occ_sec !== '' ? ' · ' . h($occ_sec) : '' ?>
                            </div>
                            <?php else: ?>
                            <div title="Column <?= (int)$col['col_no'] ?>, Seat <?= $s ?>"
                                 style="width:34px;height:26px;border-radius:6px;background:#eef2ff;border:1px solid #c7d2fe;
                                        display:flex;align-items:center;justify-content:center;font-size:.65rem;color:#4338ca;">
                                <?= $s ?>
                            </div>
                            <?php endif; ?>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($assignments): ?>
                <div class="text-center mt-3 text-muted" style="font-size:.8rem;">
                    <?php foreach ($group_colors as $gk => $clr): ?>
                    <span class="me-3"><span style="display:inline-block;width:12px;height:12px;background:<?= h($clr['bg']) ?>;border:1px solid <?= h($clr['border']) ?>;border-radius:3px;"></span> <?= h($group_names[$gk]) ?></span>
                    <?php endforeach; ?>
                    <span class="me-3"><span style="display:inline-block;width:12px;height:12px;background:#eef2ff;border:1px solid #c7d2fe;border-radius:3px;"></span> Free (<?= max(0, (int)$hall['total_capacity'] - count($assignments)) ?>)</span>
                    <span>Assigned: <?= count($assignments) ?></span>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($assignments && $group_courses): ?>
        <div class="card mt-4" style="border-radius:12px;">
            <div class="card-header bg-white fw-semibold" style="border-radius:12px 12px 0 0;">
                <i class="fas fa-book-open me-2 text-primary"></i>Exam Schedule — <?= h(date('d M Y', strtotime($f_date))) ?>
                <span class="text-muted fw-normal" style="font-size:.8rem;">— which exam each batch / section sits in this hall</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0" style="font-size:.85rem;">
                        <thead class="table-light">
                            <tr><th class="ps-3">Batch / Section</th><th>Course Code</th><th>Course Title</th><th>Course Teacher</th><th>Time Slot</th><th class="pe-3 text-center">Students Here</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($group_colors as $gk => $clr):
                                $courses = $group_courses[$gk] ?? [];
                                if (!$courses) {
                                    // Fall back to every section of the same batch when
                                    // no courses resolved for this exact section key.
                                    $gbk = (int)strtok((string)$gk, '|');
                                    foreach ($group_courses as $ogk => $rows) {
                                        if ((int)strtok((string)$ogk, '|') === $gbk) $courses = array_merge($courses, $rows);
                                    }
                                }
                                if (!$courses) $courses = [['course_code' => '—', 'course_title' => 'No admit-card exam found for this date', 'teachers' => '', 'time_slot' => '', 'student_count' => null]];
                                foreach ($courses as $ci => $crs): ?>
                            <tr>
                                <?php if ($ci === 0): ?>
                                <td class="ps-3" rowspan="<?= count($courses) ?>">
                                    <span style="display:inline-block;padding:2px 10px;border-radius:10px;font-size:.75rem;background:<?= h($clr['bg']) ?>;border:1px solid <?= h($clr['border']) ?>;color:<?= h($clr['text']) ?>;">
                                        <?= h($group_names[$gk]) ?>
                                    </span>
                                </td>
                                <?php endif; ?>
                                <td class="fw-semibold"><?= h($crs['course_code']) ?></td>
                                <td><?= h($crs['course_title']) ?></td>
                                <td><?= $crs['teachers'] !== '' ? h($crs['teachers']) : '<span class="text-muted">—</span>' ?></td>
                                <td><?= $crs['time_slot'] !== '' ? h($crs['time_slot']) : '<span class="text-muted">—</span>' ?></td>
                                <td class="pe-3 text-center"><?php if (isset($crs['student_count']) && $crs['student_count'] !== null): ?><?php $sids = array_values(array_unique(array_map('intval', (array)($crs['student_ids'] ?? [])))); ?><?php if ($sids): ?><button type="button" class="badge bg-primary-subtle text-primary border hm-count-btn" style="font-size:.75rem;cursor:pointer;" data-students="<?= h(implode(',', $sids)) ?>" title="Click to highlight these students in the seat layout"><?= count($sids) ?></button><?php else: ?><span class="badge bg-primary-subtle text-primary border" style="font-size:.75rem;"><?= (int)$crs['student_count'] ?></span><?php endif; ?><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                            </tr>
                            <?php endforeach; endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($can_edit): ?>
<div class="card mt-4" style="border-radius:12px;">
    <div class="card-header bg-white fw-semibold" style="border-radius:12px 12px 0 0;">
        <i class="fas fa-user-plus me-2 text-primary"></i>Assign Students
        <span class="text-muted fw-normal" style="font-size:.8rem;">— students come from generated admit cards</span>
    </div>
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <input type="hidden" name="id" value="<?= $hall_id ?>">
            <input type="hidden" name="preview" value="1">
            <div class="col-md-3">
                <label class="form-label" style="font-size:.8rem;">Department <span class="text-danger">*</span></label>
                <select name="a_dept" class="form-select form-select-sm" required>
                    <option value="">Select Department</option>
                    <?php foreach ($asg_depts as $d): ?>
                    <option value="<?= (int)$d['id'] ?>" <?= $f_dept === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" style="font-size:.8rem;">Program <span class="text-danger">*</span></label>
                <select name="a_program" class="form-select form-select-sm" required>
                    <option value="">Select Program</option>
                    <?php foreach ($asg_programs as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= $f_program === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['program_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" style="font-size:.8rem;">Batch</label>
                <select name="a_batch" class="form-select form-select-sm">
                    <option value="">All Batches</option>
                    <?php foreach ($asg_batches as $b): ?>
                    <option value="<?= (int)$b['id'] ?>" <?= $f_batch === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" style="font-size:.8rem;">Exam Date <span class="text-danger">*</span></label>
                <input type="date" name="a_date" class="form-control form-control-sm" value="<?= h($f_date) ?>" required>
            </div>
            <div class="col-md-2">
                <label class="form-label" style="font-size:.8rem;">Section</label>
                <?php if ($asg_sections): ?>
                <select name="a_section" class="form-select form-select-sm">
                    <option value="">All Sections</option>
                    <?php foreach ($asg_sections as $sec): ?>
                    <option value="<?= h($sec) ?>" <?= $f_section === $sec ? 'selected' : '' ?>><?= h($sec) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php else: ?>
                <input type="text" name="a_section" class="form-control form-control-sm" value="<?= h($f_section) ?>" placeholder="e.g. A">
                <?php endif; ?>
            </div>
            <div class="col-md-2">
                <label class="form-label" style="font-size:.8rem;">Shift</label>
                <select name="a_shift" class="form-select form-select-sm">
                    <option value="">All Shifts</option>
                    <?php foreach ($asg_shifts as $sh): ?>
                    <option value="<?= h($sh) ?>" <?= $f_shift === $sh ? 'selected' : '' ?>><?= h($sh) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-sm btn-outline-primary w-100" style="border-radius:8px;">
                    <i class="fas fa-search me-1"></i> Find Students
                </button>
            </div>
        </form>

        <?php if ($preview !== null): ?>
        <hr>
        <?php if (empty($preview)): ?>
        <div class="alert alert-warning mb-0" style="font-size:.9rem;">
            No admit-card students found for the selected filters.
        </div>
        <?php else:
            $busy_map  = hm_busy_student_halls($f_date, $f_shift);
            $free_cnt  = max(0, (int)$hall['total_capacity'] - count($assignments));
            $new_cnt   = 0;
            foreach ($preview as $stu) { if (!isset($busy_map[(int)$stu['id']])) $new_cnt++; }
            // Free seat options for manual (single-student) assignment
            $seat_opts = '';
            foreach ($columns as $col) {
                $cno = (int)$col['col_no'];
                for ($s = 1; $s <= (int)$col['seat_capacity']; $s++) {
                    if (!isset($assignments[$cno . ':' . $s])) {
                        $seat_opts .= '<option value="' . $cno . ':' . $s . '">C' . $cno . '-S' . $s . '</option>';
                    }
                }
            }
        ?>
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
            <div style="font-size:.9rem;">
                <span class="badge bg-primary"><?= count($preview) ?> student(s) found</span>
                <span class="badge bg-success"><?= $new_cnt ?> to seat</span>
                <span class="badge bg-secondary"><?= count($preview) - $new_cnt ?> already seated</span>
                <span class="badge <?= $free_cnt >= $new_cnt ? 'bg-info' : 'bg-danger' ?>"><?= $free_cnt ?> free seat(s) in this hall</span>
            </div>
            <form method="post" class="mb-0">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="assign">
                <input type="hidden" name="dept_id" value="<?= $f_dept ?>">
                <input type="hidden" name="program_id" value="<?= $f_program ?>">
                <input type="hidden" name="batch_id" value="<?= $f_batch ?>">
                <input type="hidden" name="exam_date" value="<?= h($f_date) ?>">
                <input type="hidden" name="section" value="<?= h($f_section) ?>">
                <input type="hidden" name="shift" value="<?= h($f_shift) ?>">
                <button type="submit" class="btn btn-sm btn-success" style="border-radius:8px;"
                        onclick="return confirm('Assign <?= $new_cnt ?> student(s) to the free seats of this hall?');">
                    <i class="fas fa-chair me-1"></i> Auto Assign to Seats
                </button>
            </form>
        </div>
        <div class="text-muted mb-2" style="font-size:.8rem;">
            <i class="fas fa-hand-pointer me-1"></i>Or seat a single student manually with the seat picker in each row — manual seating is free-form: any seat can be chosen and rule conflicts (mixed/adjacent batches, double seating) only ask for confirmation.
        </div>
        <div class="table-responsive" style="max-height:320px;overflow-y:auto;">
            <table class="table table-sm table-hover mb-0" style="font-size:.85rem;">
                <thead class="table-light" style="position:sticky;top:0;">
                    <tr><th>#</th><th>Student ID</th><th>Name</th><th>Batch</th><th>Shift</th><th>Section</th><th>Status</th><th style="min-width:170px;">Manual Seat</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($preview as $i => $stu): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td class="fw-semibold"><?= h($stu['student_id']) ?></td>
                        <td><?= h($stu['full_name']) ?></td>
                        <td><?= h($stu['batch_name'] ?? '') ?></td>
                        <td><?= h($stu['shift'] ?? '') ?></td>
                        <td><?= h($stu['section'] ?? '') ?></td>
                        <td>
                            <?php if (isset($busy_map[(int)$stu['id']])):
                                $bm   = $busy_map[(int)$stu['id']];
                                $burl = APP_URL . '/hall-management/view.php?id=' . $bm['hall_id']
                                      . '&a_date=' . urlencode($f_date)
                                      . '&focus_sid=' . (int)$stu['id']; ?>
                            <a href="<?= h($burl) ?>" class="badge bg-secondary text-decoration-none"
                               title="Go to Room <?= h($bm['room_number']) ?> — seat C<?= $bm['col_no'] ?>-S<?= $bm['seat_no'] ?>">
                                <i class="fas fa-location-arrow me-1"></i>Seated — Room <?= h($bm['room_number']) ?> (C<?= $bm['col_no'] ?>-S<?= $bm['seat_no'] ?>)
                            </a>
                            <?php else: ?>
                            <span class="badge bg-success">Will be seated</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($seat_opts !== ''): ?>
                            <form method="post" class="d-flex gap-1 mb-0">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="assign_one">
                                <input type="hidden" name="student_id" value="<?= (int)$stu['id'] ?>">
                                <input type="hidden" name="dept_id" value="<?= $f_dept ?>">
                                <input type="hidden" name="program_id" value="<?= $f_program ?>">
                                <input type="hidden" name="batch_id" value="<?= $f_batch ?>">
                                <input type="hidden" name="exam_date" value="<?= h($f_date) ?>">
                                <input type="hidden" name="section" value="<?= h($f_section) ?>">
                                <input type="hidden" name="shift" value="<?= h($f_shift) ?>">
                                <select name="seat" class="form-select form-select-sm" required style="width:auto;font-size:.75rem;">
                                    <option value="">Seat…</option>
                                    <?= $seat_opts ?>
                                </select>
                                <button type="submit" class="btn btn-sm btn-outline-success py-0" style="border-radius:6px;font-size:.75rem;"
                                        title="Seat this student manually">
                                    <i class="fas fa-chair"></i>
                                </button>
                            </form>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($f_date !== '' && $assignments): ?>
<div class="card mt-4" style="border-radius:12px;">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center" style="border-radius:12px 12px 0 0;">
        <span><i class="fas fa-users me-2 text-primary"></i>Assigned Students — <?= h(date('d M Y', strtotime($f_date))) ?> (<?= count($assignments) ?>)</span>
        <form method="post" class="mb-0">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="clear_date">
            <input type="hidden" name="exam_date" value="<?= h($f_date) ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger" style="border-radius:8px;"
                    onclick="return confirm('Remove ALL seat assignments of this hall for <?= h($f_date) ?>?');">
                <i class="fas fa-trash me-1"></i> Clear All
            </button>
        </form>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0" style="font-size:.85rem;">
                <thead class="table-light">
                    <tr><th class="ps-3">Seat</th><th>Student ID</th><th>Name</th><th>Batch</th><th>Section</th><th>Shift</th><th class="text-end pe-3">Action</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($assignments as $a): ?>
                    <tr>
                        <td class="ps-3 fw-semibold">C<?= (int)$a['col_no'] ?>-S<?= (int)$a['seat_no'] ?></td>
                        <td><?= h($a['student_code']) ?></td>
                        <td><?= h($a['full_name']) ?></td>
                        <td><?= h($a['batch_name'] ?? '') ?></td>
                        <td><?= h($a['section'] ?? '') ?></td>
                        <td><?= h($a['shift'] ?? '') ?></td>
                        <td class="text-end pe-3">
                            <form method="post" class="d-inline mb-0">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="unassign">
                                <input type="hidden" name="assignment_id" value="<?= (int)$a['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger py-0" style="border-radius:6px;font-size:.75rem;"
                                        onclick="return confirm('Remove this seat assignment?');">
                                    <i class="fas fa-times"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<script>
// "Students Here" badge → highlight those students' seats in the layout.
(function () {
    var active = null;
    function clearHighlight() {
        document.querySelectorAll('.hm-seat.hm-seat-hl').forEach(function (el) {
            el.classList.remove('hm-seat-hl');
            el.style.boxShadow = '';
            el.style.outline = '';
        });
        document.querySelectorAll('.hm-seat.hm-seat-dim').forEach(function (el) {
            el.classList.remove('hm-seat-dim');
            el.style.opacity = '';
        });
        if (active) { active.classList.remove('active'); active.style.boxShadow = ''; }
        active = null;
    }
    document.querySelectorAll('.hm-count-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var same = active === btn;
            clearHighlight();
            if (same) return;
            var ids = (btn.getAttribute('data-students') || '').split(',').filter(Boolean);
            var set = {};
            ids.forEach(function (id) { set[id] = true; });
            var first = null;
            document.querySelectorAll('.hm-seat[data-sid]').forEach(function (el) {
                if (set[el.getAttribute('data-sid')]) {
                    el.classList.add('hm-seat-hl');
                    el.style.boxShadow = '0 0 0 3px #f59e0b';
                    el.style.outline = '1px solid #b45309';
                    if (!first) first = el;
                } else {
                    el.classList.add('hm-seat-dim');
                    el.style.opacity = '0.3';
                }
            });
            active = btn;
            btn.classList.add('active');
            btn.style.boxShadow = '0 0 0 2px #f59e0b';
            if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    });
    // ?focus_sid= → highlight and scroll to that student's seat on page load
    var focusSid = new URLSearchParams(window.location.search).get('focus_sid');
    if (focusSid) {
        var seat = document.querySelector('.hm-seat[data-sid="' + CSS.escape(focusSid) + '"]');
        if (seat) {
            document.querySelectorAll('.hm-seat[data-sid]').forEach(function (el) {
                if (el !== seat) { el.classList.add('hm-seat-dim'); el.style.opacity = '0.3'; }
            });
            seat.classList.add('hm-seat-hl');
            seat.style.boxShadow = '0 0 0 3px #f59e0b';
            seat.style.outline = '1px solid #b45309';
            seat.scrollIntoView({ behavior: 'smooth', block: 'center' });
            seat.addEventListener('click', clearHighlight, { once: true });
            setTimeout(clearHighlight, 8000);
        }
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
