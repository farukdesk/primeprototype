<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('hall-management');
require_once __DIR__ . '/helpers.php';

hm_ensure_schedule_columns();
$hall_id = (int)($_GET['id'] ?? 0);
// Every faculty may VIEW any department's room (seat layout, empty seats).
// Dept scope is enforced per action: seating students (own dept only) and
// removing students (own-dept students only) below.
$hall    = $hall_id > 0 ? hm_get_hall($hall_id, false) : null;
if (!$hall) {
    flash_set('error', 'Hall not found or you do not have permission to access it.');
    redirect(APP_URL . '/hall-management/index.php');
}

$page_title = 'Hall – ' . $hall['room_number'];
$columns    = hm_hall_columns($hall_id);
$can_edit   = is_super_admin() || can_access('hall-management', 'can_edit');
$own_hall_dept = can_access_dept((int)$hall['dept_id']);

// ── Assignment filters (students come from generated admit cards) ──────
$f_dept    = (int)($_GET['a_dept'] ?? 0);
$f_program = (int)($_GET['a_program'] ?? 0);
$f_batch   = (int)($_GET['a_batch'] ?? 0);
$f_date    = trim($_GET['a_date'] ?? '');
$f_section = trim($_GET['a_section'] ?? '');
$f_shift   = trim($_GET['a_shift'] ?? '');
$f_course  = trim($_GET['a_course'] ?? '');
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
        $p_course  = trim($_POST['course_code'] ?? '');
        if ($p_dept <= 0 || $p_program <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $p_date)) {
            flash_set('error', 'Department, Program and Exam Date are required.');
        } elseif (!can_access_dept($p_dept)) {
            flash_set('error', 'You do not have permission for that department.');
        } else {
            $students = hm_find_exam_students($p_dept, $p_program, $p_batch, $p_date, $p_section, $p_shift, $p_course);
            if (!$students) {
                flash_set('error', 'No admit-card students found for the selected Department / Program / Batch / Exam Date / Section / Shift / Course.');
            } else {
                [$assigned, $skipped, $left] = hm_assign_students($hall_id, $p_date, $students, [
                    'dept_id' => $p_dept, 'program_id' => $p_program, 'batch_id' => $p_batch,
                    'section' => $p_section, 'shift' => $p_shift,
                ]);
                $msg = $assigned . ' student(s) assigned to seats (one course per column, different courses in adjacent columns).';
                if ($skipped > 0) $msg .= ' ' . $skipped . ' already seated elsewhere were skipped.';
                if ($left    > 0) $msg .= ' ' . $left . ' could not be seated — no suitable seats left (each column holds a single course).';
                flash_set($assigned > 0 ? 'success' : 'error', $msg);
            }
            hm_sync_hall_schedule($hall_id);
            $ret .= '&a_date=' . urlencode($p_date);
        }
        redirect($ret);
    }

    if ($action === 'assign_many') {
        $p_dept    = (int)($_POST['dept_id'] ?? 0);
        $p_program = (int)($_POST['program_id'] ?? 0);
        $p_batch   = (int)($_POST['batch_id'] ?? 0);
        $p_date    = trim($_POST['exam_date'] ?? '');
        $p_section = trim($_POST['section'] ?? '');
        $p_shift   = trim($_POST['shift'] ?? '');
        $p_course  = trim($_POST['course_code'] ?? '');
        $p_force   = (string)($_POST['force'] ?? '') === '1';
        // seat_map[student_id] = "col:seat" — rows with no seat picked are skipped
        $picks = [];
        $used  = [];
        $dupes = 0;
        foreach ((array)($_POST['seat_map'] ?? []) as $sid => $seat) {
            $sid  = (int)$sid;
            $seat = trim((string)$seat);
            if ($sid <= 0 || $seat === '' || !preg_match('/^\d+:\d+$/', $seat)) continue;
            if (isset($used[$seat])) { $dupes++; continue; }
            $used[$seat] = true;
            $picks[$sid] = $seat;
        }
        if (!$picks || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $p_date)) {
            flash_set('error', 'Pick a seat for at least one student (and a valid exam date) to assign manually.');
        } else {
            $ctx = [
                'dept_id' => $p_dept, 'program_id' => $p_program, 'batch_id' => $p_batch,
                'section' => $p_section, 'shift' => $p_shift,
            ];
            $ok_cnt    = 0;
            $fail_msgs = [];
            $conflicts = [];
            foreach ($picks as $sid => $seat) {
                [$col_no, $seat_no] = array_map('intval', explode(':', $seat));
                [$ok, $msg, $warnings] = hm_assign_single_student($hall_id, $p_date, $sid, $col_no, $seat_no, $ctx, $p_force);
                if ($ok) {
                    $ok_cnt++;
                } elseif ($warnings) {
                    $conflicts[] = [
                        'student_id' => $sid, 'seat' => $seat,
                        'col_no' => $col_no, 'seat_no' => $seat_no, 'warnings' => $warnings,
                    ];
                } else {
                    $fail_msgs[] = $msg;
                }
            }
            if ($ok_cnt > 0) hm_sync_hall_schedule($hall_id);
            if ($conflicts) {
                // Rule conflicts — render the page with a confirmation panel
                // instead of blocking; the user may confirm to seat the rest anyway.
                foreach ($conflicts as &$cf) {
                    $cs = db()->prepare('SELECT student_id, full_name FROM students WHERE id = ?');
                    $cs->execute([$cf['student_id']]);
                    $cf['student'] = $cs->fetch() ?: null;
                }
                unset($cf);
                $pending_confirm = [
                    'dept_id' => $p_dept, 'program_id' => $p_program, 'batch_id' => $p_batch,
                    'exam_date' => $p_date, 'section' => $p_section, 'shift' => $p_shift, 'course_code' => $p_course,
                    'items' => $conflicts, 'seated' => $ok_cnt, 'failed' => $fail_msgs, 'dupes' => $dupes,
                ];
            } else {
                $msg = $ok_cnt . ' student(s) seated manually.';
                if ($fail_msgs) $msg .= ' ' . count($fail_msgs) . ' failed: ' . implode(' ', $fail_msgs);
                if ($dupes > 0) $msg .= ' ' . $dupes . ' skipped — the same seat was picked more than once.';
                flash_set($ok_cnt > 0 ? 'success' : 'error', $msg);
            }
        }
        if (!isset($pending_confirm)) {
            // Keep the student preview open so more students can be seated
            $ret .= '&preview=1&a_dept=' . $p_dept . '&a_program=' . $p_program . '&a_batch=' . $p_batch
                  . '&a_date=' . urlencode($p_date) . '&a_section=' . urlencode($p_section) . '&a_shift=' . urlencode($p_shift)
                  . '&a_course=' . urlencode($p_course);
            redirect($ret);
        }
    }

    if ($action === 'unassign') {
        $aid = (int)($_POST['assignment_id'] ?? 0);
        // A faculty may remove ONLY students of their own department(s),
        // no matter whose department the room belongs to.
        $chk = db()->prepare(
            'SELECT s.dept_id FROM hm_hall_assignments a JOIN students s ON s.id = a.student_id
              WHERE a.id = ? AND a.hall_id = ?'
        );
        $chk->execute([$aid, $hall_id]);
        $stu_dept = $chk->fetchColumn();
        if ($stu_dept === false) {
            flash_set('error', 'Assignment not found.');
        } elseif (!can_access_dept((int)$stu_dept)) {
            flash_set('error', 'You can only remove students of your own department.');
        } else {
            $st = db()->prepare('DELETE FROM hm_hall_assignments WHERE id = ? AND hall_id = ?');
            $st->execute([$aid, $hall_id]);
            hm_sync_hall_schedule($hall_id);
            flash_set('success', $st->rowCount() ? 'Seat assignment removed.' : 'Assignment not found.');
        }
        redirect($ret . ($f_date !== '' ? '&a_date=' . urlencode($f_date) : ''));
    }

    if ($action === 'clear_date') {
        $p_date = trim($_POST['exam_date'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $p_date)) {
            // Dept-scoped users only clear seats of their own department's
            // students; other departments' assignments are left untouched.
            $scope = get_dept_scope();
            if ($scope === null) {
                $st = db()->prepare('DELETE FROM hm_hall_assignments WHERE hall_id = ? AND exam_date = ?');
                $st->execute([$hall_id, $p_date]);
            } elseif (empty($scope)) {
                $st = null;
            } else {
                $ph = implode(',', array_fill(0, count($scope), '?'));
                $st = db()->prepare(
                    "DELETE a FROM hm_hall_assignments a JOIN students s ON s.id = a.student_id
                      WHERE a.hall_id = ? AND a.exam_date = ? AND s.dept_id IN ($ph)"
                );
                $st->execute(array_merge([$hall_id, $p_date], $scope));
            }
            if ($st !== null) {
                hm_sync_hall_schedule($hall_id);
                flash_set('success', $st->rowCount() . ' assignment(s) cleared for ' . $p_date
                    . ($scope !== null ? ' (your department\'s students only).' : '.'));
            } else {
                flash_set('error', 'You do not have permission to clear assignments.');
            }
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
// Groups are keyed by department AND batch AND section, so students of
// different departments (e.g. same-named batches of two departments) are
// always highlighted with different colours in the layout.
$dept_names = [];
foreach (hm_departments(true) as $dn) $dept_names[(int)$dn['id']] = (string)$dn['name'];
$hall_dept_id = (int)$hall['dept_id'];

$batch_palette = hm_batch_palette();
$group_colors  = [];   // "dept_id|batch_id|section" => palette entry
$group_names   = [];   // "dept_id|batch_id|section" => display label
$group_bs      = [];   // colour key => "batch_id|section" (exam-course lookup key)
$seat_depts    = [];
foreach ($assignments as $a) $seat_depts[(int)($a['student_dept_id'] ?? 0)] = true;
$multi_dept = count($seat_depts) > 1;
foreach ($assignments as $a) {
    $dk  = (int)($a['student_dept_id'] ?? 0);
    $bk  = (int)($a['student_batch_id'] ?? 0);
    $sec = trim((string)($a['student_section'] ?? ''));
    $gk  = $dk . '|' . $bk . '|' . $sec;
    if (!isset($group_colors[$gk])) {
        $group_colors[$gk] = $batch_palette[count($group_colors) % count($batch_palette)];
        $bn = $a['batch_name'] !== null && $a['batch_name'] !== '' ? (string)$a['batch_name'] : 'No batch';
        $label = $bn . ($sec !== '' ? ' — Sec ' . $sec : '');
        // Name the department when the hall mixes departments or the group
        // belongs to another department than the hall's own.
        if ($multi_dept || $dk !== $hall_dept_id) {
            $label .= ' · ' . ($dept_names[$dk] ?? 'Unknown dept');
        }
        $group_names[$gk] = $label;
        $group_bs[$gk]    = $bk . '|' . $sec;
    }
}
ksort($group_colors);
$group_courses = ($f_date !== '' && $assignments) ? hm_exam_courses_by_group($hall_id, $f_date) : [];

// Course codes per seated student — used to label each column of the seat
// layout with the course(s) actually seated in it.
$student_courses = []; // student_id => [course_code => true]
foreach ($group_courses as $rows) {
    foreach ($rows as $crs) {
        $cc = trim((string)($crs['course_code'] ?? ''));
        if ($cc === '' || $cc === '—') continue;
        foreach ((array)($crs['student_ids'] ?? []) as $sid) {
            $student_courses[(int)$sid][$cc] = true;
        }
    }
}

// Students seated here whose department differs from the hall's own
// department are marked distinctly in the layout and the assigned list.
$other_dept_counts = []; // dept_id => number of seated students from that (non-hall) department
foreach ($assignments as $a) {
    $a_dept_id = (int)($a['student_dept_id'] ?? 0);
    if ($a_dept_id !== $hall_dept_id) {
        $other_dept_counts[$a_dept_id] = ($other_dept_counts[$a_dept_id] ?? 0) + 1;
    }
}

// Fit suggestions: when the room still has free seats on the selected date,
// show which leftover (unseated) admit-card groups could fit here — and
// whether a group fits FULLY into the remaining free seats.
$free_seats = max(0, (int)$hall['total_capacity'] - count($assignments));
$empty_cols = 0;
foreach ($columns as $col) {
    $cno = (int)$col['col_no'];
    $col_free = true;
    for ($s = 1; $s <= (int)$col['seat_capacity']; $s++) {
        if (isset($assignments[$cno . ':' . $s])) { $col_free = false; break; }
    }
    if ($col_free) $empty_cols++;
}
$fit_hints = [];
$fit_col_state = [];  // col_no => ['free' => n, 'course' => course|null] for rule-aware fits
$hall_slot_time = null; // the room's exam time ("HH:MM:SS") on the selected date
if ($can_edit && $f_date !== '' && $free_seats > 0) {
    // Same room + same date + same time: prefer the time of students already
    // seated here on the selected date; otherwise the room's booked slot time.
    $hall_slot_time = hm_derive_exam_time($hall_id, $f_date);
    if ($hall_slot_time === null && (string)($hall['exam_date'] ?? '') === $f_date) {
        $t = trim((string)($hall['exam_time'] ?? ''));
        if ($t !== '') $hall_slot_time = $t;
    }
    $fit_hints = hm_unseated_group_counts($f_date, $hall_slot_time);
    if ($fit_hints) {
        // Per-column free seats + the course currently seated in each column,
        // so each suggestion is checked against the column rules (one course
        // per column, no same course in adjacent columns) instead of the raw
        // free-seat count.
        $seated_course_map = hm_student_course_map(
            array_map(static fn($r) => (int)$r['student_id'], $assignments), $f_date);
        foreach ($columns as $col) {
            $cno = (int)$col['col_no'];
            $col_free = 0;
            $col_course = null;
            for ($s = 1; $s <= (int)$col['seat_capacity']; $s++) {
                $occ = $assignments[$cno . ':' . $s] ?? null;
                if ($occ === null) {
                    $col_free++;
                } elseif ($col_course === null) {
                    $col_course = $seated_course_map[(int)$occ['student_id']] ?? '';
                }
            }
            $fit_col_state[$cno] = ['free' => $col_free, 'course' => $col_course];
        }
    }
}

// Course options for the filter: exams found on the selected date for the
// chosen dept / program (+ batch / shift). Lets the user narrow students
// down to one of the courses actually examined on that day.
$asg_courses = ($f_dept > 0 && $f_program > 0 && $f_date !== '')
    ? hm_exam_course_options($f_dept, $f_program, $f_batch, $f_date, $f_shift)
    : [];
if ($f_course !== '' && !in_array($f_course, array_column($asg_courses, 'course_code'), true)) $f_course = '';

// Preview of matching students (before assigning)
$preview = null;
if (isset($_GET['preview']) && $f_dept > 0 && $f_program > 0 && $f_date !== '') {
    $preview = hm_find_exam_students($f_dept, $f_program, $f_batch, $f_date, $f_section, $f_shift, $f_course);
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
    <?php if ($can_edit && $own_hall_dept): ?>
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
        <?php if ((int)$pending_confirm['seated'] > 0 || $pending_confirm['failed']): ?>
        <p class="mb-2" style="font-size:.9rem;">
            <?php if ((int)$pending_confirm['seated'] > 0): ?>
            <span class="badge bg-success"><?= (int)$pending_confirm['seated'] ?> student(s) already seated</span>
            <?php endif; ?>
            <?php foreach ($pending_confirm['failed'] as $fm): ?>
            <span class="text-danger d-block"><?= h($fm) ?></span>
            <?php endforeach; ?>
        </p>
        <?php endif; ?>
        <p class="mb-2" style="font-size:.9rem;">
            Seating the following <?= count($pending_confirm['items']) ?> student(s)
            on <strong><?= h(date('d M Y', strtotime($pending_confirm['exam_date']))) ?></strong>
            breaks seating rule(s):
        </p>
        <ul class="mb-3" style="font-size:.88rem;">
            <?php foreach ($pending_confirm['items'] as $it): ?>
            <li class="mb-1">
                <strong><?= h($it['student']['full_name'] ?? 'Student #' . (int)$it['student_id']) ?></strong>
                <?php if (!empty($it['student']['student_id'])): ?>(<?= h($it['student']['student_id']) ?>)<?php endif; ?>
                at <strong>C<?= (int)$it['col_no'] ?>-S<?= (int)$it['seat_no'] ?></strong>:
                <ul class="mb-0">
                    <?php foreach ($it['warnings'] as $w): ?>
                    <li class="text-danger"><?= h($w) ?></li>
                    <?php endforeach; ?>
                </ul>
            </li>
            <?php endforeach; ?>
        </ul>
        <div class="d-flex gap-2">
            <form method="post" class="mb-0">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="assign_many">
                <input type="hidden" name="force" value="1">
                <?php foreach ($pending_confirm['items'] as $it): ?>
                <input type="hidden" name="seat_map[<?= (int)$it['student_id'] ?>]" value="<?= h($it['seat']) ?>">
                <?php endforeach; ?>
                <input type="hidden" name="dept_id" value="<?= (int)$pending_confirm['dept_id'] ?>">
                <input type="hidden" name="program_id" value="<?= (int)$pending_confirm['program_id'] ?>">
                <input type="hidden" name="batch_id" value="<?= (int)$pending_confirm['batch_id'] ?>">
                <input type="hidden" name="exam_date" value="<?= h($pending_confirm['exam_date']) ?>">
                <input type="hidden" name="section" value="<?= h($pending_confirm['section']) ?>">
                <input type="hidden" name="shift" value="<?= h($pending_confirm['shift']) ?>">
                <input type="hidden" name="course_code" value="<?= h($pending_confirm['course_code'] ?? '') ?>">
                <button type="submit" class="btn btn-sm btn-warning fw-semibold" style="border-radius:8px;">
                    <i class="fas fa-chair me-1"></i> Seat Anyway (override rules)
                </button>
            </form>
            <a href="<?= APP_URL ?>/hall-management/view.php?id=<?= $hall_id ?>&preview=1&a_dept=<?= (int)$pending_confirm['dept_id'] ?>&a_program=<?= (int)$pending_confirm['program_id'] ?>&a_batch=<?= (int)$pending_confirm['batch_id'] ?>&a_date=<?= urlencode($pending_confirm['exam_date']) ?>&a_section=<?= urlencode($pending_confirm['section']) ?>&a_shift=<?= urlencode($pending_confirm['shift']) ?>&a_course=<?= urlencode($pending_confirm['course_code'] ?? '') ?>"
               class="btn btn-sm btn-outline-secondary" style="border-radius:8px;">Cancel</a>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card mb-4" style="border-radius:12px;">
    <div class="card-body py-3">
        <div class="d-flex align-items-center flex-wrap gap-4" style="font-size:.85rem;">
            <span class="fw-semibold" style="font-size:1rem;"><i class="fas fa-door-open me-2 text-primary"></i><?= h($hall['room_number']) ?></span>
            <span><span class="text-muted">Department:</span> <span class="fw-semibold"><?= h($hall['dept_name']) ?></span></span>
            <span><span class="text-muted">Exam Date &amp; Time:</span> <span class="fw-semibold"><?= h(hm_slot_label($hall['exam_date'] ?? null, $hall['exam_time'] ?? null)) ?></span></span>
            <span><span class="text-muted">Columns:</span> <span class="fw-semibold"><?= (int)$hall['num_columns'] ?></span></span>
            <span><span class="text-muted">Rows:</span> <span class="fw-semibold"><?= (int)$hall['num_rows'] ?></span></span>
            <span><span class="text-muted">Capacity:</span> <span class="badge bg-primary"><?= (int)$hall['total_capacity'] ?> seats</span></span>
            <span><?= (int)$hall['is_active'] === 1 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></span>
            <?php if (!empty($hall['notes'])): ?>
            <span><span class="text-muted">Notes:</span> <?= h($hall['notes']) ?></span>
            <?php endif; ?>
        </div>
    </div>
</div>

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
                        $col_group   = null;
                        $col_courses = [];
                        for ($s = 1; $s <= (int)$col['seat_capacity']; $s++) {
                            $o = $assignments[(int)$col['col_no'] . ':' . $s] ?? null;
                            if (!$o) continue;
                            if ($col_group === null) {
                                $col_group = (int)($o['student_dept_id'] ?? 0) . '|' . (int)($o['student_batch_id'] ?? 0) . '|' . trim((string)($o['student_section'] ?? ''));
                            }
                            foreach (array_keys($student_courses[(int)$o['student_id']] ?? []) as $ccode) $col_courses[$ccode] = true;
                        }
                        $col_clr = $col_group !== null ? ($group_colors[$col_group] ?? null) : null;
                        $col_label = $col_courses ? implode(', ', array_keys($col_courses)) : ($group_names[$col_group ?? ''] ?? 'No course');
                    ?>
                    <div class="text-center">
                        <div class="fw-semibold mb-2" style="font-size:.8rem;color:#475569;">
                            Column <?= (int)$col['col_no'] ?>
                            <div class="text-muted" style="font-size:.7rem;"><?= (int)$col['seat_capacity'] ?> seats</div>
                            <?php if ($col_clr !== null): ?>
                            <div style="font-size:.65rem;margin-top:2px;">
                                <span title="Course(s) seated in this column" style="display:inline-block;padding:1px 8px;border-radius:10px;background:<?= h($col_clr['bg']) ?>;border:1px solid <?= h($col_clr['border']) ?>;color:<?= h($col_clr['text']) ?>;">
                                    <?= h($col_label) ?>
                                </span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex flex-column gap-1 align-items-center">
                            <?php for ($s = 1; $s <= (int)$col['seat_capacity']; $s++):
                                $occ = $assignments[(int)$col['col_no'] . ':' . $s] ?? null; ?>
                            <?php if ($occ):
                                $occ_gk  = (int)($occ['student_dept_id'] ?? 0) . '|' . (int)($occ['student_batch_id'] ?? 0) . '|' . trim((string)($occ['student_section'] ?? ''));
                                $clr     = $group_colors[$occ_gk] ?? $batch_palette[0];
                                $occ_sec = trim((string)($occ['student_section'] ?? ''));
                                $occ_other = (int)($occ['student_dept_id'] ?? 0) !== $hall_dept_id;
                                $occ_dept  = $dept_names[(int)($occ['student_dept_id'] ?? 0)] ?? 'Unknown dept'; ?>
                            <div class="hm-seat<?= $occ_other ? ' hm-seat-other' : '' ?>" data-sid="<?= (int)$occ['student_id'] ?>"
                                 title="<?= h($occ['full_name'] . ' (' . $occ['student_code'] . ')' . (!empty($occ['batch_name']) ? ' — ' . $occ['batch_name'] : '') . ($occ_sec !== '' ? ' — Sec ' . $occ_sec : '') . ($occ_other ? ' — OTHER DEPARTMENT: ' . $occ_dept : '')) ?>"
                                 style="min-width:92px;height:26px;border-radius:6px;background:<?= h($clr['bg']) ?>;border:<?= $occ_other ? '2px dashed #dc2626' : '1px solid ' . h($clr['border']) ?>;
                                        display:flex;align-items:center;justify-content:center;font-size:.6rem;color:<?= h($clr['text']) ?>;padding:0 4px;white-space:nowrap;<?= $occ_other ? 'box-shadow:0 0 0 1px #fecaca;' : '' ?>">
                                <?php if ($occ_other): ?><i class="fas fa-exclamation-circle me-1" style="color:#dc2626;font-size:.6rem;"></i><?php endif; ?><?= h($occ['student_code']) ?><?= $occ_sec !== '' ? ' · ' . h($occ_sec) : '' ?>
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
                    <?php foreach ($other_dept_counts as $od_id => $od_cnt): ?>
                    <span class="me-3"><span style="display:inline-block;width:12px;height:12px;background:#fff;border:2px dashed #dc2626;border-radius:3px;"></span> <span class="text-danger fw-semibold"><?= h($dept_names[$od_id] ?? 'Unknown dept') ?> (<?= (int)$od_cnt ?>)</span></span>
                    <?php endforeach; ?>
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
                                $bs      = $group_bs[$gk] ?? '';
                                $courses = $group_courses[$bs] ?? [];
                                if (!$courses) {
                                    // Fall back to every section of the same batch when
                                    // no courses resolved for this exact section key.
                                    $gbk = (int)strtok((string)$bs, '|');
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

        <?php if ($fit_hints): ?>
        <div class="card mt-4" style="border-radius:12px;border-left:4px solid #0ea5e9;">
            <div class="card-header bg-white fw-semibold" style="border-radius:12px 12px 0 0;">
                <i class="fas fa-lightbulb me-2 text-warning"></i>Seating Suggestions — <?= h(date('d M Y', strtotime($f_date))) ?><?= $hall_slot_time !== null ? ', ' . h(date('g:i A', strtotime($hall_slot_time))) : '' ?>
                <span class="text-muted fw-normal" style="font-size:.8rem;">
                    — <?= $free_seats ?> free seat(s)<?= $empty_cols > 0 ? ' (' . $empty_cols . ' fully empty column' . ($empty_cols > 1 ? 's' : '') . ')' : '' ?> ·
                    leftover (unseated) admit-card students who could sit here ·
                    usable seats follow the column rules (one course per column, no same course side by side)
                </span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height:280px;overflow-y:auto;">
                    <table class="table table-sm table-hover mb-0" style="font-size:.85rem;">
                        <thead class="table-light" style="position:sticky;top:0;">
                            <tr><th class="ps-3">Department</th><th>Program</th><th>Batch</th><th>Section</th><th>Shift</th><th>Course</th><th class="text-center">Unseated</th><th class="text-center">Fit Here?</th><th class="text-end pe-3">Action</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($fit_hints as $fh):
                                $usable_seats = hm_course_fit_seats($fit_col_state, (string)($fh['course_code'] ?? ''));
                                $fits_fully = $usable_seats > 0 && (int)$fh['student_count'] <= $usable_seats;
                                $find_url = APP_URL . '/hall-management/view.php?id=' . $hall_id
                                    . '&preview=1&a_dept=' . (int)$fh['dept_id']
                                    . '&a_program=' . (int)$fh['program_id']
                                    . '&a_batch=' . (int)$fh['batch_id']
                                    . '&a_date=' . urlencode($f_date)
                                    . '&a_section=' . urlencode($fh['section'])
                                    . '&a_shift=' . urlencode($fh['shift'])
                                    . '&a_course=' . urlencode((string)($fh['course_code'] ?? '')); ?>
                            <tr<?= $fits_fully ? '' : ' class="text-muted"' ?>>
                                <td class="ps-3"><?= h($fh['dept_name']) ?></td>
                                <td><?= h($fh['program_name']) ?></td>
                                <td class="fw-semibold"><?= $fh['batch_name'] !== '' ? h($fh['batch_name']) : '<span class="text-muted">No batch</span>' ?></td>
                                <td><?= $fh['section'] !== '' ? h($fh['section']) : '—' ?></td>
                                <td><?= $fh['shift'] !== '' ? h($fh['shift']) : '—' ?></td>
                                <td class="fw-semibold"><?= ($fh['course_code'] ?? '') !== '' ? h($fh['course_code']) : '—' ?></td>
                                <td class="text-center"><span class="badge bg-secondary"><?= (int)$fh['student_count'] ?></span></td>
                                <td class="text-center">
                                    <?php if ($fits_fully): ?>
                                    <span class="badge bg-success" title="All <?= (int)$fh['student_count'] ?> leftover student(s) of this course fit into the <?= $usable_seats ?> seat(s) this course may use under the column rules (one course per column, no same course in adjacent columns)">
                                        <i class="fas fa-check me-1"></i>Fits fully
                                    </span>
                                    <?php elseif ($usable_seats > 0): ?>
                                    <span class="badge bg-warning text-dark" title="Under the column rules (one course per column, no same course in adjacent columns) only <?= $usable_seats ?> of the <?= $free_seats ?> free seat(s) can take this course — <?= $usable_seats ?> of <?= (int)$fh['student_count'] ?> student(s) could be seated here">
                                        Partial (<?= $usable_seats ?> of <?= (int)$fh['student_count'] ?>)
                                    </span>
                                    <?php else: ?>
                                    <span class="badge bg-danger" title="No free seat can take this course under the column rules — every usable column already holds another course or sits next to a column of this course">
                                        No fit
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-3">
                                    <a href="<?= h($find_url) ?>#hmAssignFilter" class="btn btn-sm btn-outline-primary py-0" style="border-radius:6px;font-size:.75rem;"
                                       title="Open these students in the Assign Students panel below">
                                        <i class="fas fa-search me-1"></i>Find Students
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php elseif ($can_edit && $f_date !== '' && $free_seats > 0): ?>
        <div class="alert alert-light border mt-4 mb-0" style="font-size:.85rem;border-radius:12px;">
            <i class="fas fa-lightbulb me-2 text-warning"></i>
            <strong><?= $free_seats ?> free seat(s)</strong> in this room — every admit-card student of
            <?= h(date('d M Y', strtotime($f_date))) ?><?= $hall_slot_time !== null ? ', ' . h(date('g:i A', strtotime($hall_slot_time))) : '' ?> is already seated (no leftover students to suggest<?= $hall_slot_time !== null ? ' for this room\'s exam slot' : '' ?>).
        </div>
        <?php endif; ?>

<?php if ($can_edit): ?>
<div class="card mt-4" style="border-radius:12px;">
    <div class="card-header bg-white fw-semibold" style="border-radius:12px 12px 0 0;">
        <i class="fas fa-user-plus me-2 text-primary"></i>Assign Students
        <span class="text-muted fw-normal" style="font-size:.8rem;">— students come from generated admit cards</span>
    </div>
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end" id="hmAssignFilter">
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
            <div class="col-md-3">
                <label class="form-label" style="font-size:.8rem;">Course <span class="text-muted fw-normal">(exams on that day)</span></label>
                <?php if ($asg_courses): ?>
                <select name="a_course" class="form-select form-select-sm">
                    <option value="">All Courses</option>
                    <?php foreach ($asg_courses as $crs): ?>
                    <option value="<?= h($crs['course_code']) ?>" <?= $f_course === $crs['course_code'] ? 'selected' : '' ?>>
                        <?= h($crs['course_code']) ?> — <?= h($crs['course_title']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php else: ?>
                <select class="form-select form-select-sm" disabled>
                    <option><?= ($f_dept > 0 && $f_program > 0 && $f_date !== '') ? 'No exams found on that date' : 'Pick Department, Program & Exam Date first' ?></option>
                </select>
                <?php endif; ?>
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
                <input type="hidden" name="course_code" value="<?= h($f_course) ?>">
                <button type="submit" class="btn btn-sm btn-success" style="border-radius:8px;"
                        onclick="return confirm('Assign <?= $new_cnt ?> student(s) to the free seats of this hall?');">
                    <i class="fas fa-chair me-1"></i> Auto Assign to Seats
                </button>
            </form>
        </div>
        <div class="text-muted mb-2" style="font-size:.8rem;">
            <i class="fas fa-hand-pointer me-1"></i>Or seat students manually: pick a seat (e.g. C1, C2…) for each student below, then press <strong>Save Manual Seats</strong> to assign them all at once. Manual seating is free-form: any seat can be chosen and rule conflicts (mixed/adjacent courses, double seating) only ask for confirmation.
        </div>
        <form method="post" class="mb-0" id="hmManualForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="assign_many">
            <input type="hidden" name="dept_id" value="<?= $f_dept ?>">
            <input type="hidden" name="program_id" value="<?= $f_program ?>">
            <input type="hidden" name="batch_id" value="<?= $f_batch ?>">
            <input type="hidden" name="exam_date" value="<?= h($f_date) ?>">
            <input type="hidden" name="section" value="<?= h($f_section) ?>">
            <input type="hidden" name="shift" value="<?= h($f_shift) ?>">
            <input type="hidden" name="course_code" value="<?= h($f_course) ?>">
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
                            <select name="seat_map[<?= (int)$stu['id'] ?>]" class="form-select form-select-sm hm-seat-pick" style="width:auto;font-size:.75rem;">
                                <option value="">Seat…</option>
                                <?= $seat_opts ?>
                            </select>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($seat_opts !== ''): ?>
        <div class="d-flex align-items-center gap-2 mt-2">
            <button type="submit" class="btn btn-sm btn-success" style="border-radius:8px;" id="hmManualSave">
                <i class="fas fa-chair me-1"></i> Save Manual Seats
            </button>
            <span class="text-muted" style="font-size:.8rem;" id="hmManualCount">No seats picked yet.</span>
        </div>
        <?php endif; ?>
        </form>
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
                    <tr><th class="ps-3">Seat</th><th>Student ID</th><th>Name</th><th>Department</th><th>Batch</th><th>Section</th><th>Shift</th><th class="text-end pe-3">Action</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($assignments as $a):
                        $a_other = (int)($a['student_dept_id'] ?? 0) !== $hall_dept_id;
                        $a_dept  = $dept_names[(int)($a['student_dept_id'] ?? 0)] ?? 'Unknown dept'; ?>
                    <tr<?= $a_other ? ' class="table-danger"' : '' ?>>
                        <td class="ps-3 fw-semibold">C<?= (int)$a['col_no'] ?>-S<?= (int)$a['seat_no'] ?></td>
                        <td><?= h($a['student_code']) ?></td>
                        <td><?= h($a['full_name']) ?></td>
                        <td>
                            <?php if ($a_other): ?>
                            <span class="badge bg-danger" title="Different department from this hall (<?= h($hall['dept_name']) ?>)">
                                <i class="fas fa-exclamation-circle me-1"></i><?= h($a_dept) ?>
                            </span>
                            <?php else: ?>
                            <?= h($a_dept) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= h($a['batch_name'] ?? '') ?></td>
                        <td><?= h($a['section'] ?? '') ?></td>
                        <td><?= h($a['shift'] ?? '') ?></td>
                        <td class="text-end pe-3">
                            <?php if (can_access_dept((int)($a['student_dept_id'] ?? 0))): ?>
                            <form method="post" class="d-inline mb-0">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="unassign">
                                <input type="hidden" name="assignment_id" value="<?= (int)$a['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger py-0" style="border-radius:6px;font-size:.75rem;"
                                        onclick="return confirm('Remove this seat assignment?');">
                                    <i class="fas fa-times"></i>
                                </button>
                            </form>
                            <?php else: ?>
                            <span class="text-muted" style="font-size:.72rem;" title="Only this student's own department can remove them">—</span>
                            <?php endif; ?>
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
// Assign Students filter: reload course options (and the student preview)
// when Department / Program / Batch / Exam Date / Shift change, so the
// Course dropdown always lists the exams of the selected day.
(function () {
    var form = document.getElementById('hmAssignFilter');
    if (!form) return;
    ['a_dept', 'a_program', 'a_batch', 'a_date', 'a_shift'].forEach(function (name) {
        var el = form.querySelector('[name="' + name + '"]');
        if (!el) return;
        el.addEventListener('change', function () {
            var dept = form.querySelector('[name="a_dept"]'),
                prog = form.querySelector('[name="a_program"]'),
                date = form.querySelector('[name="a_date"]');
            if (dept && dept.value !== '' && prog && prog.value !== '' && date && date.value !== '') {
                var course = form.querySelector('[name="a_course"]');
                if (course) course.value = '';
                form.submit();
            }
        });
    });
})();
// Manual seat pickers — block picking the same seat twice and show how many
// seats are picked before saving the whole batch together.
(function () {
    var form = document.getElementById('hmManualForm');
    if (!form) return;
    var picks = form.querySelectorAll('.hm-seat-pick');
    var count = document.getElementById('hmManualCount');
    function refresh() {
        var usedBy = {};
        picks.forEach(function (sel) { if (sel.value !== '') usedBy[sel.value] = sel; });
        picks.forEach(function (sel) {
            Array.prototype.forEach.call(sel.options, function (opt) {
                if (opt.value === '') return;
                opt.disabled = !!(usedBy[opt.value] && usedBy[opt.value] !== sel);
            });
        });
        var n = Object.keys(usedBy).length;
        if (count) count.textContent = n > 0 ? n + ' seat(s) picked — press Save Manual Seats to assign them together.' : 'No seats picked yet.';
    }
    picks.forEach(function (sel) { sel.addEventListener('change', refresh); });
    form.addEventListener('submit', function (e) {
        var n = 0;
        picks.forEach(function (sel) { if (sel.value !== '') n++; });
        if (n === 0) {
            e.preventDefault();
            alert('Pick a seat for at least one student first.');
            return;
        }
        if (!confirm('Assign ' + n + ' student(s) to the picked seats?')) e.preventDefault();
    });
    refresh();
})();
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
