<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('hall-management', 'can_create');
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/hall-management/index.php');
}
csrf_check();
hm_ensure_schedule_columns();
hm_ensure_assignments_table();

$id       = (int)($_POST['id'] ?? 0);
$new_date = trim((string)($_POST['exam_date'] ?? ''));
$new_time = trim((string)($_POST['exam_time'] ?? ''));

$hall = $id > 0 ? hm_get_hall($id) : null;
if (!$hall) {
    flash_set('error', 'Hall not found or you do not have permission to copy it.');
    redirect(APP_URL . '/hall-management/index.php');
}
if (!hm_is_valid_exam_date($new_date)) {
    flash_set('error', 'Please pick a valid exam date for the copy.');
    redirect(APP_URL . '/hall-management/index.php');
}
if ($new_time !== '') {
    $parsed_new_time = hm_parse_clock_time($new_time);
    if ($parsed_new_time === null) {
        flash_set('error', 'Please provide a valid exam time for the copy.');
        redirect(APP_URL . '/hall-management/index.php');
    }
    $new_time = (string)$parsed_new_time[1];
}

// Source date for the seat assignments to copy: the hall's scheduled exam
// date, falling back to its assignment date nearest to today.
$src_date = (string)($hall['exam_date'] ?? '');
if ($src_date === '') {
    $dates = hm_hall_assignment_dates($id);
    if ($dates) {
        usort($dates, static fn($a, $b) =>
            abs(strtotime($a) - time()) <=> abs(strtotime($b) - time()));
        $src_date = (string)$dates[0];
    }
}

$src_assignments = $src_date !== '' ? hm_assignments($id, $src_date) : [];

if ($src_date !== '' && $src_date === $new_date) {
    flash_set('error', 'The copy must use a different exam date — room ' . $hall['room_number']
            . ' already holds these students on ' . date('d M Y', strtotime($src_date)) . '.');
    redirect(APP_URL . '/hall-management/index.php');
}

if ($new_time !== '' && hm_room_slot_taken($hall['room_number'], $new_date, $new_time)) {
    flash_set('error', 'Room ' . $hall['room_number'] . ' is already booked on ' . $new_date
            . ' at ' . date('g:i A', strtotime($new_time))
            . '. Pick a different time for the copy.');
    redirect(APP_URL . '/hall-management/index.php');
}

// Students already seated in ANY hall on the target date. We compare time
// ranges and skip overlaps so a student is never double-booked in the same
// exam slot.
$busy = [];
try {
    $st = db()->prepare(
        "SELECT student_id, COALESCE(shift, '') AS shift, time_slot FROM hm_hall_assignments WHERE exam_date = ?"
    );
    $st->execute([$new_date]);
    foreach ($st->fetchAll() as $r) {
        $slot = hm_parse_time_slot_interval((string)($r['time_slot'] ?? ''));
        if (!($slot['ok'] ?? false)) continue;
        $busy[(int)$r['student_id']][] = [
            'shift' => (string)$r['shift'],
            'slot'  => $slot,
        ];
    }
} catch (Throwable $e) {
    $busy = [];
}

$columns = hm_hall_columns($id);

try {
    db()->beginTransaction();

    // 1) Duplicate the hall row (same room + layout) for the new slot.
    $st = db()->prepare(
        'INSERT INTO hm_halls
                (dept_id, room_number, exam_date, exam_time, num_columns, num_rows, total_capacity, notes, is_active, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $st->execute([
        (int)$hall['dept_id'],
        $hall['room_number'],
        $new_date,
        $new_time !== '' ? $new_time : null,
        (int)$hall['num_columns'],
        (int)$hall['num_rows'],
        (int)$hall['total_capacity'],
        $hall['notes'] !== null && $hall['notes'] !== '' ? $hall['notes'] : null,
        (int)$hall['is_active'],
        (int)(auth_user()['id'] ?? 0) ?: null,
    ]);
    $new_id = (int)db()->lastInsertId();

    // 2) Duplicate the per-column seat capacities.
    $ins = db()->prepare('INSERT INTO hm_hall_columns (hall_id, col_no, seat_capacity) VALUES (?, ?, ?)');
    foreach ($columns as $c) {
        $ins->execute([$new_id, (int)$c['col_no'], (int)$c['seat_capacity']]);
    }

    // 3) Copy the seated students onto the same seats for the new date.
    $copied  = 0;
    $skipped = 0;
    $ins = db()->prepare(
        'INSERT INTO hm_hall_assignments
               (hall_id, exam_date, student_id, col_no, seat_no, dept_id, program_id, batch_id, section, shift, course_code, time_slot, assigned_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($src_assignments as $a) {
        $sid   = (int)$a['student_id'];
        $shift = (string)($a['shift'] ?? '');
        $slot  = hm_parse_time_slot_interval((string)($a['time_slot'] ?? ''));
        if (!($slot['ok'] ?? false)) { $skipped++; continue; }
        if (isset($busy[$sid])) {
            $conflict = false;
            foreach ($busy[$sid] as $b) {
                $bshift = (string)($b['shift'] ?? '');
                $bslot  = (array)($b['slot'] ?? []);
                if ($shift !== '' && $bshift !== '' && $shift !== $bshift) continue;
                if (hm_intervals_overlap($slot['start_sec'], $slot['end_sec'], (int)$bslot['start_sec'], (int)$bslot['end_sec'])) {
                    $conflict = true;
                    break;
                }
            }
            if ($conflict) { $skipped++; continue; }
        }
        $ins->execute([
            $new_id, $new_date, $sid,
            (int)$a['col_no'], (int)$a['seat_no'],
            $a['dept_id'] !== null ? (int)$a['dept_id'] : null,
            $a['program_id'] !== null ? (int)$a['program_id'] : null,
            $a['batch_id'] !== null ? (int)$a['batch_id'] : null,
            $a['section'] !== null && $a['section'] !== '' ? $a['section'] : null,
            $shift !== '' ? $shift : null,
            $a['course_code'] !== null && $a['course_code'] !== '' ? $a['course_code'] : null,
            $a['time_slot'] !== null && $a['time_slot'] !== '' ? $a['time_slot'] : null,
            (int)(auth_user()['id'] ?? 0) ?: null,
        ]);
        $copied++;
    }

    db()->commit();
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    flash_set('error', 'Could not copy the hall. Make sure admin/hall-management-schema.sql has been run.');
    redirect(APP_URL . '/hall-management/index.php');
}

// No time given — derive it from the copied students' admit-card time slots.
if ($new_time === '' && $copied > 0) {
    try {
        $t = hm_derive_exam_time($new_id, $new_date);
        if ($t !== null) {
            db()->prepare('UPDATE hm_halls SET exam_time = ? WHERE id = ?')->execute([$t, $new_id]);
        }
    } catch (Throwable $e) {}
}

$msg = 'Room ' . $hall['room_number'] . ' copied to ' . date('d M Y', strtotime($new_date)) . '.';
if ($copied > 0) {
    $msg .= ' ' . $copied . ' student(s) seated on the same seats.';
} elseif (!$src_assignments) {
    $msg .= ' The source room had no seated students'
          . ($src_date !== '' ? ' on ' . date('d M Y', strtotime($src_date)) : '') . '.';
}
if ($skipped > 0) {
    $msg .= ' ' . $skipped . ' student(s) skipped — already seated in another room on that date.';
}
flash_set($copied > 0 || !$src_assignments ? 'success' : 'error', $msg);
redirect(APP_URL . '/hall-management/view.php?id=' . $new_id . '&a_date=' . urlencode($new_date));
