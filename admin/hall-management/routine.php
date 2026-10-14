<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('hall-management');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/routine-helpers.php';
require_once __DIR__ . '/../id-card/helpers.php';

$page_title = 'Exam Routine';
$date_from = trim((string)($_GET['date_from'] ?? ''));
$date_to = trim((string)($_GET['date_to'] ?? ''));
$exam_filter = trim((string)($_GET['exam'] ?? ''));
$program_filter = (int)($_GET['program_id'] ?? 0);
$error = '';
$groups = $exam_options = $program_options = $short_labels = [];
if (($date_from !== '' && !hm_is_valid_exam_date($date_from))
    || ($date_to !== '' && !hm_is_valid_exam_date($date_to))
    || ($date_from !== '' && $date_to !== '' && $date_from > $date_to)) {
    $error = 'Please select a valid date range (From must not be after To).';
}

try {
    // Optional label settings; their absence must not hide the routine.
    foreach (db()->query('SELECT program_id, short_label FROM admissions_top_sheet_programs')->fetchAll() as $row) {
        if (trim((string)$row['short_label']) !== '') {
            $short_labels[(int)$row['program_id']] = trim((string)$row['short_label']);
        }
    }
} catch (Throwable $e) {}

if ($error === '') {
    try {
        $columns = db()->query('SHOW COLUMNS FROM ac_admit_card_courses')->fetchAll(PDO::FETCH_COLUMN);
        $has_subject = in_array('offer_subject_id', $columns, true);
        $select = "SELECT DISTINCT cc.exam_date, cc.time_slot, cc.course_code,
                          ac.program_id, ac.exam_name, ac.semester, p.program_name,
                          s.id AS student_id
                     FROM ac_admit_cards ac
                     JOIN ac_admit_card_courses cc ON cc.admit_card_id = ac.id
                LEFT JOIN dept_academic_programs p ON p.id = ac.program_id ";
        $where = " WHERE ac.is_active = 1 AND cc.exam_date IS NOT NULL";
        $legacy_membership = "s.dept_id = ac.dept_id
                            AND s.program_id = ac.program_id
                            AND (ac.batch_id IS NULL OR ac.batch_id = 0 OR s.batch_id = ac.batch_id)
                            AND EXISTS (
                                SELECT 1 FROM co_registrations cr WHERE cr.student_id = s.id)";
        $legacy_join = " LEFT JOIN students s ON s.status = 'Active' AND " . $legacy_membership;
        if ($has_subject) {
            $subject_sql = $select . "
                LEFT JOIN co_registrations r ON r.offer_subject_id = cc.offer_subject_id
                LEFT JOIN students s ON s.id = r.student_id AND s.status = 'Active'"
                . $where . ' AND cc.offer_subject_id IS NOT NULL';
            // Unlinked courses cover the card's students, not the whole program.
            $unlinked_join = " LEFT JOIN students s ON s.status = 'Active' AND (
                EXISTS (
                    SELECT 1 FROM ac_admit_card_courses cx
                    JOIN co_registrations cr ON cr.offer_subject_id = cx.offer_subject_id
                     WHERE cx.admit_card_id = ac.id AND cr.student_id = s.id)
                OR (" . $legacy_membership . " AND NOT EXISTS (
                    SELECT 1 FROM ac_admit_card_courses cx
                     WHERE cx.admit_card_id = ac.id AND cx.offer_subject_id IS NOT NULL)))";
            $sql = $subject_sql . ' UNION ALL ' . $select . $unlinked_join
                . $where . ' AND cc.offer_subject_id IS NULL';
        } else {
            $sql = $select . $legacy_join . $where;
        }
        $schedules = db()->query($sql)->fetchAll();

        $assignments = [];
        $tables = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('hm_hall_assignments', $tables, true)) {
            $assignment_columns = db()->query('SHOW COLUMNS FROM hm_hall_assignments')->fetchAll(PDO::FETCH_COLUMN);
            $course_sql = in_array('course_code', $assignment_columns, true) ? 'a.course_code' : 'NULL AS course_code';
            $slot_sql = in_array('time_slot', $assignment_columns, true) ? 'a.time_slot' : 'NULL AS time_slot';
            $assignments = db()->query(
                "SELECT a.student_id, a.exam_date, $course_sql, $slot_sql,
                        h.room_number, h.exam_date AS hall_exam_date, h.exam_time, h.is_active
                   FROM hm_hall_assignments a
                   JOIN hm_halls h ON h.id = a.hall_id AND h.is_active = 1"
            )->fetchAll();
        }
        // Aggregate before filtering, so hidden sessions cannot make a
        // start-only legacy assignment appear unambiguous.
        $groups = hm_routine_groups($schedules, $assignments);
        foreach ($groups as $group) {
            $exam_key = json_encode([$group['exam_name'], $group['semester']]);
            $exam_options[$exam_key] = $group['exam_name'] . ($group['semester'] !== '' ? ' — ' . $group['semester'] : '');
            $program_options[$group['program_id']] = $group['program_name'];
        }
        asort($exam_options);
        asort($program_options);
        $groups = array_values(array_filter($groups, static function (array $group) use ($date_from, $date_to, $exam_filter, $program_filter): bool {
            return ($date_from === '' || $group['exam_date'] >= $date_from)
                && ($date_to === '' || $group['exam_date'] <= $date_to)
                && ($program_filter <= 0 || $group['program_id'] === $program_filter)
                && ($exam_filter === '' || json_encode([$group['exam_name'], $group['semester']]) === $exam_filter);
        }));
    } catch (Throwable $e) {
        $error = 'The exam routine could not be loaded. Please check the admit-card and hall-management tables.';
        $groups = [];
    }
}
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.hm-routine-print { display: none; }
@media print {
    body.hm-routine-page #sidebar, body.hm-routine-page #topbar,
    body.hm-routine-page footer, body.hm-routine-page .hm-routine-no-print { display: none !important; }
    body.hm-routine-page #main-wrapper, body.hm-routine-page #content { margin: 0 !important; padding: 0 !important; }
    #hm-routine-report { border: none !important; box-shadow: none !important; }
    #hm-routine-report .table-responsive { overflow: visible !important; }
    #hm-routine-report .hm-routine-screen { display: none !important; }
    #hm-routine-report .hm-routine-print { display: block !important; }
    .hm-routine-print-header {
        display: grid;
        grid-template-columns: 100px 1fr 100px;
        align-items: center;
        margin-bottom: 18px;
        text-align: center;
    }
    .hm-routine-print-header img { max-width: 90px; max-height: 70px; }
    .hm-routine-print-header h1 { margin: 0; font-size: 20px; font-weight: 700; }
    .hm-routine-print-header p { margin: 4px 0 0; font-size: 13px; }
    .hm-routine-print-exam { margin: 14px 0 6px; font-size: 15px; font-weight: 700; }
    .hm-routine-print table { width: 100%; border-collapse: collapse; }
    .hm-routine-print th, .hm-routine-print td {
        border: 1px solid #333;
        padding: 6px 8px;
        text-align: left;
        vertical-align: middle;
    }
    .hm-routine-print th { background: #eee !important; }
    .hm-routine-print tr { break-inside: avoid; }
    .hm-routine-print thead { display: table-header-group; }
}
</style>
<script>document.body.classList.add('hm-routine-page');</script>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4 hm-routine-no-print">
    <a href="<?= APP_URL ?>/hall-management/index.php" class="btn btn-outline-secondary">Back to Hall Management</a>
    <button type="button" class="btn btn-outline-secondary" onclick="window.print()">Print Routine</button>
</div>
<form method="get" class="row g-2 align-items-end mb-4 hm-routine-no-print">
    <div class="col-md-2">
        <label for="routine-from" class="form-label">From</label>
        <input id="routine-from" type="date" name="date_from" class="form-control" value="<?= h($date_from) ?>">
    </div>
    <div class="col-md-2">
        <label for="routine-to" class="form-label">To</label>
        <input id="routine-to" type="date" name="date_to" class="form-control" value="<?= h($date_to) ?>">
    </div>
    <div class="col-md-3">
        <label for="routine-exam" class="form-label">Exam / Semester</label>
        <select id="routine-exam" name="exam" class="form-select">
            <option value="">All exams / semesters</option>
            <?php foreach ($exam_options as $key => $label): ?>
            <option value="<?= h($key) ?>" <?= $exam_filter === $key ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <label for="routine-program" class="form-label">Program</label>
        <select id="routine-program" name="program_id" class="form-select">
            <option value="">All programs</option>
            <?php foreach ($program_options as $id => $label): ?>
            <option value="<?= h((string)$id) ?>" <?= $program_filter === $id ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2 d-flex gap-2">
        <button class="btn btn-primary">Filter</button>
        <a href="<?= APP_URL ?>/hall-management/routine.php" class="btn btn-outline-secondary">Reset</a>
    </div>
</form>
<div id="hm-routine-report" class="card">
    <div class="card-body">
        <?php if ($error === '' && $groups):
            $print_exam_groups = [];
            foreach ($groups as $group) {
                $print_exam_key = json_encode([$group['exam_name'], $group['semester']]);
                $print_exam_groups[$print_exam_key][] = $group;
            }
        ?>
        <div class="hm-routine-print">
            <?php foreach ($print_exam_groups as $print_exam_key => $exam_groups):
                $first_exam_group = $exam_groups[0];
                $exam_title = $first_exam_group['exam_name']
                    . ($first_exam_group['semester'] !== '' ? ' — ' . $first_exam_group['semester'] : '');
            ?>
            <div class="hm-routine-print-header">
                <img src="<?= APP_URL ?>/../assets/img/logo/logo-black.png" alt="Prime University Logo">
                <div>
                    <h1>At-a-glance Exam Routine</h1>
                    <p><?= h($exam_title) ?></p>
                </div>
                <span></span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>EXAM DATE</th>
                        <th>START TIME</th>
                        <th>END TIME</th>
                        <th>PROGRAM</th>
                        <th>ROOM NUMBERS</th>
                        <th>TOTAL STUDENTS (SCHEDULED)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($exam_groups as $index => $group):
                        $short_name = $short_labels[$group['program_id']] ?? idc_short_program_name($group['program_name']);
                        if ($short_name === '') $short_name = 'Program #' . $group['program_id'];
                        $show_date = $index === 0 || $exam_groups[$index - 1]['exam_date'] !== $group['exam_date'];
                        $date_rowspan = 1;
                        if ($show_date) {
                            for ($next = $index + 1; $next < count($exam_groups)
                                && $exam_groups[$next]['exam_date'] === $group['exam_date']; $next++) {
                                $date_rowspan++;
                            }
                        }
                    ?>
                    <tr>
                        <?php if ($show_date): ?>
                        <td rowspan="<?= $date_rowspan ?>"><?= h(date('d M Y', strtotime($group['exam_date']))) ?></td>
                        <?php endif; ?>
                        <td><?= $group['start'] !== '' ? h(date('g:i A', strtotime($group['start']))) : 'Not set' ?></td>
                        <td><?= $group['end'] !== '' ? h(date('g:i A', strtotime($group['end']))) : 'Not set' ?></td>
                        <td><?= h($short_name) ?></td>
                        <td><?= $group['rooms'] ? h(implode(', ', $group['rooms'])) : 'Not assigned' ?></td>
                        <td><?= $group['total_students'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="hm-routine-screen">
        <h4>At-a-glance Exam Routine</h4>
        <p class="text-muted">
            <?= $date_from === '' && $date_to === '' ? 'All exam dates' : h(($date_from ?: 'All dates') . ' to ' . ($date_to ?: 'All dates')) ?>.
            Active admit cards across all departments. Total students are scheduled; seated counts include active room bookings only.
            Counts are unique within each row, not across sessions.
        </p>
        <?php if ($exam_filter !== '' && isset($exam_options[$exam_filter])): ?>
        <p>Exam / Semester: <?= h($exam_options[$exam_filter]) ?></p>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
        <div class="alert alert-danger" role="alert"><?= h($error) ?></div>
        <?php elseif (!$groups): ?>
        <p class="text-muted">No scheduled exams match these filters.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-bordered table-sm align-middle">
                <thead class="table-light">
                    <tr><th>Exam Date</th><th>Start Time</th><th>End Time</th><th>Exam / Semester</th><th>Program</th><th>Room Numbers</th><th>Total Students (Scheduled)</th><th>Seated</th><th>Unseated</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($groups as $group):
                        $short_name = $short_labels[$group['program_id']] ?? idc_short_program_name($group['program_name']);
                        if ($short_name === '') $short_name = 'Program #' . $group['program_id'];
                    ?>
                    <tr>
                        <td class="text-nowrap"><?= h($group['exam_date']) ?></td>
                        <td class="text-nowrap"><?= $group['start'] !== '' ? h(date('g:i A', strtotime($group['start']))) : 'Not set' ?></td>
                        <td>
                            <?= $group['end'] !== '' ? h(date('g:i A', strtotime($group['end']))) : 'Not set' ?>
                            <?php if ($group['time_warning'] !== ''): ?>
                            <small class="d-block text-warning"><?= h($group['time_warning']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= h($group['exam_name']) ?><?= $group['semester'] !== '' ? ' — ' . h($group['semester']) : '' ?></td>
                        <td title="<?= h($group['program_name']) ?>"><?= h($short_name) ?></td>
                        <td><?= $group['rooms'] ? h(implode(', ', $group['rooms'])) : 'Not assigned' ?></td>
                        <td><?= $group['total_students'] ?></td>
                        <td><?= $group['seated_students'] ?></td>
                        <td><?= $group['total_students'] - $group['seated_students'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
