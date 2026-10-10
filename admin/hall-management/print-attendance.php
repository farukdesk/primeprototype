<?php
/**
 * Printable A4 student attendance sheet for a hall on one exam date.
 * One sheet per exam course (course code) seated in the hall: each sheet
 * lists only the seated students who sit that course, with batch, section
 * and shift derived from those students. Seated students whose course
 * could not be resolved get a final sheet of their own. Layout: logo on
 * the left; university, department, program and sheet title centered;
 * Room Number / Course / Batch / Section / Shift info; No. of Students
 * with hand-written Present / Absent fields; Invigilator 1 & 2 sign-off
 * lines. When a sheet mixes batches, the majority batch is the "common"
 * batch: students from any other batch get a shaded row with a batch tag
 * beside their name, plus a legend note under the table. Sheets with more
 * than 24 students are split across pages, each page repeating the header,
 * info section and invigilator sign-off with "Page X of Y".
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('hall-management');
require_once __DIR__ . '/helpers.php';

$hall_id = (int)($_GET['id'] ?? 0);
$hall    = $hall_id > 0 ? hm_get_hall($hall_id, false) : null;
if (!$hall) {
    flash_set('error', 'Hall not found or you do not have permission to access it.');
    redirect(APP_URL . '/hall-management/index.php');
}

$f_date = trim($_GET['date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_date)) $f_date = '';
$asg_dates = hm_hall_assignment_dates($hall_id);
if ($f_date === '' && $asg_dates) $f_date = (string)$asg_dates[0];

$assignments   = $f_date !== '' ? hm_assignments($hall_id, $f_date) : [];
$group_courses = ($f_date !== '' && $assignments) ? hm_exam_courses_by_group($hall_id, $f_date) : [];

// Seated assignments keyed by student DB id.
$by_sid = [];
foreach ($assignments as $a) $by_sid[(int)$a['student_id']] = $a;

// One sheet per course code: course rows of every batch+section group are
// merged per course, combining titles, time slots, teachers and the seated
// students who sit that course.
$by_course = [];   // lowercase course code => ['code','title','time_slots'=>[],'teachers'=>[],'student_ids'=>[]]
foreach ($group_courses as $rows) {
    foreach ($rows as $crs) {
        $code = trim((string)($crs['course_code'] ?? ''));
        $ck   = mb_strtolower($code);
        if ($ck === '' || $ck === '—') continue;
        if (!isset($by_course[$ck])) {
            $by_course[$ck] = [
                'code'        => $code,
                'title'       => trim((string)($crs['course_title'] ?? '')),
                'time_slots'  => [],
                'teachers'    => [],
                'student_ids' => [],
            ];
        }
        if ($by_course[$ck]['title'] === '') $by_course[$ck]['title'] = trim((string)($crs['course_title'] ?? ''));
        $slot = trim((string)($crs['time_slot'] ?? ''));
        if ($slot !== '') $by_course[$ck]['time_slots'][$slot] = true;
        foreach (array_map('trim', explode(',', (string)($crs['teachers'] ?? ''))) as $tn) {
            if ($tn !== '') $by_course[$ck]['teachers'][$tn] = true;
        }
        foreach (($crs['student_ids'] ?? []) as $sid) $by_course[$ck]['student_ids'][(int)$sid] = true;
    }
}
ksort($by_course);

/** Build one printable sheet: students sorted by student ID, with batch /
 *  section / shift / program info derived from those students. The batch
 *  with the most students becomes the sheet's "common" batch so students
 *  from any other batch can be visually flagged on the printout. */
function hm_att_make_sheet(string $code, string $title, array $time_slots, array $teachers, array $students): array
{
    usort($students, static fn($x, $y) => strcmp((string)$x['student_code'], (string)$y['student_code']));
    $batches = $sections = $shifts = $program_ids = [];
    foreach ($students as $s) {
        $bn = ($s['batch_name'] ?? '') !== '' ? (string)$s['batch_name'] : 'No batch';
        $batches[$bn] = ($batches[$bn] ?? 0) + 1;
        $sec = trim((string)($s['student_section'] ?? ''));
        if ($sec !== '') $sections[$sec] = true;
        $shf = trim((string)($s['shift'] ?? ''));
        if ($shf !== '') $shifts[$shf] = true;
        $pid = (int)($s['program_id'] ?? 0);
        if ($pid > 0) $program_ids[$pid] = true;
    }
    // Common batch = the one seating the most students (first wins on ties).
    $common_batch = '';
    foreach ($batches as $bn => $cnt) {
        if ($common_batch === '' || $cnt > $batches[$common_batch]) $common_batch = (string)$bn;
    }
    return [
        'course_code'  => $code,
        'course_title' => $title,
        'time_slots'   => $time_slots,
        'teachers'     => $teachers,
        'batches'      => array_keys($batches),
        'common_batch' => $common_batch,
        'sections'     => array_keys($sections),
        'shifts'       => array_keys($shifts),
        'program_ids'  => $program_ids,
        'students'     => $students,
    ];
}

// Course-wise sheets; seated students whose exam course could not be
// resolved are collected on a final sheet so nobody is missed.
$sheets  = [];
$covered = [];   // student DB id => true, once placed on a course sheet
foreach ($by_course as $c) {
    $students = [];
    foreach (array_keys($c['student_ids']) as $sid) {
        if (!isset($by_sid[$sid])) continue;
        $students[]    = $by_sid[$sid];
        $covered[$sid] = true;
    }
    if (!$students) continue;
    $sheets[] = hm_att_make_sheet($c['code'], $c['title'], array_keys($c['time_slots']), array_keys($c['teachers']), $students);
}
$leftover = [];
foreach ($by_sid as $sid => $a) {
    if (!isset($covered[$sid])) $leftover[] = $a;
}
if ($leftover) $sheets[] = hm_att_make_sheet('', '', [], [], $leftover);

// Program names used by the seated students.
$program_names = [];
foreach (hm_programs() as $p) $program_names[(int)$p['id']] = (string)$p['program_name'];

// Max students per printed page; extra students flow to additional pages.
const HM_ATT_STUDENTS_PER_PAGE = 24;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Sheet – Room <?= h($hall['room_number']) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; font-size: 12px; color: #000; background: #fff; }
        .page { max-width: 1140px; margin: 0 auto; padding: 16px; }
        .sheet { page-break-after: always; }
        .sheet:last-child { page-break-after: auto; }

        .header { position: relative; display: flex; align-items: center; justify-content: center; border-bottom: 2px solid #002147; padding-bottom: 8px; margin-bottom: 10px; min-height: 64px; }
        .header-logo { position: absolute; left: 0; top: 50%; transform: translateY(-50%); width: 140px; height: auto; object-fit: contain; }
        .header-text { text-align: center; line-height: 1.5; }
        .header-text h2 { font-size: 22px; color: #002147; margin-bottom: 2px; font-weight: bold; }
        .header-text p  { font-size: 13px; color: #333; margin: 1px 0; }
        .header-text .sheet-title { font-size: 14px; font-weight: bold; margin-top: 4px; letter-spacing: 1px; color: #002147; }

        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 3px 24px; margin-bottom: 10px; font-size: 12px; line-height: 1.6; }
        .info-row { display: flex; gap: 5px; }
        .info-row .lbl { font-weight: bold; min-width: 120px; color: #002147; flex-shrink: 0; }
        .blank-field { display: inline-block; min-width: 90px; border-bottom: 1px solid #666; height: 15px; }

        .att-table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .att-table th, .att-table td { border: 1px solid #999; padding: 5px 8px; text-align: left; vertical-align: middle; }
        .att-table th { background: #002147; color: #fff; text-align: center; }
        .att-table td.c { text-align: center; }
        .att-table .sig { min-width: 160px; height: 28px; }
        .att-table tr.other-batch td { background: #f3f3f3; }
        .batch-flag { display: inline-block; margin-left: 6px; padding: 0 5px; border: 1px solid #555; border-radius: 3px; font-size: 10px; font-weight: bold; white-space: nowrap; }
        .batch-note { margin-top: 5px; font-size: 11px; color: #333; }

        .signoff { margin-top: 42px; display: grid; grid-template-columns: 1fr 1fr; column-gap: 160px; font-size: 12px; }
        .signoff-box { text-align: center; }
        .sig-line { border-top: 1px solid #999; margin-bottom: 5px; margin-top: 36px; }

        .page-no { text-align: right; font-size: 11px; color: #333; margin-bottom: 2px; }

        @media print {
            @page { size: A4 portrait; margin: 8mm 10mm; }
            .no-print { display: none !important; }
            .page { max-width: 100%; padding: 0; }
            .header, .att-table th, .att-table tr.other-batch td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .att-table tr, .signoff { page-break-inside: avoid; }
            /* Compress vertical space so 24 students + the invigilator
               sign-off always fit on a single printed A4 page at 12px. */
            body { font-size: 12px; }
            .header { min-height: 52px; padding-bottom: 5px; margin-bottom: 6px; }
            .header-text { line-height: 1.35; }
            .header-text h2 { font-size: 19px; }
            .header-text p  { font-size: 12px; }
            .header-text .sheet-title { font-size: 12px; margin-top: 2px; }
            .header-logo { width: 110px; }
            .info-grid { gap: 1px 24px; margin-bottom: 6px; font-size: 12px; line-height: 1.45; }
            .blank-field { height: 14px; }
            .att-table { font-size: 12px; }
            .att-table th, .att-table td { padding: 2px 6px; }
            .att-table .sig { height: 21px; }
            .signoff { margin-top: 14px; font-size: 12px; }
            .sig-line { margin-top: 20px; margin-bottom: 3px; }
            .page-no { font-size: 10px; }
        }
    </style>
</head>
<body>
<div class="page">

    <div class="no-print" style="text-align:right; margin-bottom:12px;">
        <button onclick="window.print()" style="padding:6px 18px; background:#002147; color:#fff; border:none; border-radius:6px; cursor:pointer; font-size:13px;">🖨 Print</button>
        <button onclick="window.close()" style="padding:6px 14px; margin-left:6px; border:1px solid #ccc; border-radius:6px; cursor:pointer; font-size:13px;">Close</button>
    </div>

    <?php if (empty($sheets)): ?>
    <p style="text-align:center;color:#777;padding:30px 0;">
        No students are assigned to this hall<?= $f_date !== '' ? ' for ' . h(date('d M Y', strtotime($f_date))) : '' ?>.
    </p>
    <?php else: ?>
    <?php foreach ($sheets as $grp):
        $programs     = array_values(array_intersect_key($program_names, $grp['program_ids']));
        $course_name  = trim($grp['course_code'] . ' — ' . $grp['course_title'], ' —');
        $pages        = array_chunk($grp['students'], HM_ATT_STUDENTS_PER_PAGE, true);
        $total_pages  = count($pages);
        $multi_batch  = count($grp['batches']) > 1;
        $common_batch = $grp['common_batch'];
        $batch_labels = [];
        foreach ($grp['batches'] as $bn) {
            $bn = (string)$bn; // numeric batch names become int array keys
            $batch_labels[] = ($multi_batch && $bn === $common_batch) ? $bn . ' (common)' : $bn;
        }
    ?>
    <?php foreach ($pages as $page_idx => $page_students): ?>
    <div class="sheet">
        <div class="page-no">Page <?= $page_idx + 1 ?> of <?= $total_pages ?></div>
        <div class="header">
            <img src="<?= APP_URL ?>/../assets/img/logo/logo-black.png" alt="Prime University Logo" class="header-logo" onerror="this.style.display='none'">
            <div class="header-text">
                <h2>Prime University</h2>
                <p><?= h($hall['dept_name']) ?></p>
                <p><?= $programs ? h(implode(', ', $programs)) : '—' ?></p>
                <div class="sheet-title">STUDENT ATTENDANCE SHEET</div>
            </div>
        </div>

        <div class="info-grid">
            <div class="info-row"><span class="lbl">Room Number:</span><span><?= h($hall['room_number']) ?></span></div>
            <div class="info-row"><span class="lbl">Course:</span><span><?= $course_name !== '' ? h($course_name) : '—' ?></span></div>
            <div class="info-row"><span class="lbl">Batch:</span><span><?= $batch_labels ? h(implode(', ', $batch_labels)) : '—' ?></span></div>
            <div class="info-row"><span class="lbl">Section:</span><span><?= $grp['sections'] ? h(implode(', ', $grp['sections'])) : '—' ?></span></div>
            <div class="info-row"><span class="lbl">Shift:</span><span><?= $grp['shifts'] ? h(implode(', ', $grp['shifts'])) : '—' ?></span></div>
            <div class="info-row"><span class="lbl">Exam Date:</span><span><?= $f_date !== '' ? h(date('d M Y', strtotime($f_date))) : '—' ?></span></div>
            <div class="info-row"><span class="lbl">Time Slot:</span><span><?= $grp['time_slots'] ? h(implode('; ', $grp['time_slots'])) : '—' ?></span></div>
            <div class="info-row"><span class="lbl">Course Teacher(s):</span><span><?= $grp['teachers'] ? h(implode(', ', $grp['teachers'])) : '—' ?></span></div>
            <div class="info-row"><span class="lbl">No. of Students:</span><span><?= count($grp['students']) ?></span></div>
            <div class="info-row">
                <span class="lbl">Present:</span><span class="blank-field"></span>
                <span class="lbl" style="min-width:auto; margin-left:24px;">Absent:</span><span class="blank-field"></span>
            </div>
        </div>

        <table class="att-table">
            <thead>
                <tr>
                    <th style="width:36px;">#</th>
                    <th style="width:110px;">Student ID</th>
                    <th>Name</th>
                    <th style="width:60px;">Seat</th>
                    <th style="width:90px;">Script No.</th>
                    <th style="width:140px;">Signature</th>
                    <th style="width:110px;">Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($page_students as $i => $stu):
                    $stu_batch = ($stu['batch_name'] ?? '') !== '' ? (string)$stu['batch_name'] : 'No batch';
                    $is_other  = $multi_batch && $stu_batch !== $common_batch;
                ?>
                <tr<?= $is_other ? ' class="other-batch"' : '' ?>>
                    <td class="c"><?= $i + 1 ?></td>
                    <td class="c"><?= h($stu['student_code']) ?></td>
                    <td><?= h($stu['full_name']) ?><?php if ($is_other): ?><span class="batch-flag">Batch: <?= h($stu_batch) ?></span><?php endif; ?></td>
                    <td class="c">C<?= (int)$stu['col_no'] ?>-S<?= (int)$stu['seat_no'] ?></td>
                    <td></td>
                    <td class="sig"></td>
                    <td></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($multi_batch): ?>
        <div class="batch-note">* Shaded rows with a batch tag mark students from a batch other than the common batch (<?= h($common_batch) ?>).</div>
        <?php endif; ?>
        <div class="signoff">
            <div class="signoff-box">
                <div class="sig-line"></div>
                Invigilator 1
            </div>
            <div class="signoff-box">
                <div class="sig-line"></div>
                Invigilator 2
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endforeach; ?>
    <?php endif; ?>
</div>
</body>
</html>
