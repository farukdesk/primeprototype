<?php
/**
 * Printable A4 student attendance sheet for a hall on one exam date.
 * One sheet per batch seated in the hall: logo on the left; university,
 * department, program and sheet title centered; Room Number / Batch /
 * Section / Shift info; No. of Students with hand-written Present /
 * Absent fields; Invigilator 1 & 2 sign-off lines. Sheets with more
 * than 24 students are split across pages, each page repeating the
 * header, info section and invigilator sign-off with "Page X of Y".
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('hall-management');
require_once __DIR__ . '/helpers.php';

$hall_id = (int)($_GET['id'] ?? 0);
$hall    = $hall_id > 0 ? hm_get_hall($hall_id) : null;
if (!$hall) {
    flash_set('error', 'Hall not found or you do not have permission to access it.');
    redirect(APP_URL . '/hall-management/index.php');
}

$f_date = trim($_GET['date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_date)) $f_date = '';
$asg_dates = hm_hall_assignment_dates($hall_id);
if ($f_date === '' && $asg_dates) $f_date = (string)$asg_dates[0];

$assignments   = $f_date !== '' ? hm_assignments($hall_id, $f_date) : [];
$batch_courses = ($f_date !== '' && $assignments) ? hm_exam_courses_by_batch($hall_id, $f_date) : [];

// Group seated students per batch (sheet per batch), keeping seat order.
$by_batch = [];   // batch_id => ['name' =>, 'program_ids' => [], 'sections' => [], 'shifts' => [], 'students' => []]
foreach ($assignments as $a) {
    $bk = (int)($a['student_batch_id'] ?? 0);
    if (!isset($by_batch[$bk])) {
        $by_batch[$bk] = [
            'name'        => $a['batch_name'] !== null && $a['batch_name'] !== '' ? (string)$a['batch_name'] : 'No batch',
            'program_ids' => [],
            'sections'    => [],
            'shifts'      => [],
            'students'    => [],
        ];
    }
    $pid = (int)($a['program_id'] ?? 0);
    if ($pid > 0) $by_batch[$bk]['program_ids'][$pid] = true;
    $sec = trim((string)($a['section'] ?? ''));
    if ($sec !== '') $by_batch[$bk]['sections'][$sec] = true;
    $shf = trim((string)($a['shift'] ?? ''));
    if ($shf !== '') $by_batch[$bk]['shifts'][$shf] = true;
    $by_batch[$bk]['students'][] = $a;
}
foreach ($by_batch as &$g) {
    usort($g['students'], static fn($x, $y) => strcmp((string)$x['student_code'], (string)$y['student_code']));
}
unset($g);

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

        .signoff { margin-top: 42px; display: grid; grid-template-columns: 1fr 1fr; column-gap: 160px; font-size: 12px; }
        .signoff-box { text-align: center; }
        .sig-line { border-top: 1px solid #999; margin-bottom: 5px; margin-top: 36px; }

        .page-no { text-align: right; font-size: 11px; color: #333; margin-bottom: 2px; }

        @media print {
            @page { size: A4 portrait; margin: 10mm; }
            .no-print { display: none !important; }
            .page { max-width: 100%; padding: 0; }
            .header, .att-table th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .att-table tr, .signoff { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
<div class="page">

    <div class="no-print" style="text-align:right; margin-bottom:12px;">
        <button onclick="window.print()" style="padding:6px 18px; background:#002147; color:#fff; border:none; border-radius:6px; cursor:pointer; font-size:13px;">🖨 Print</button>
        <button onclick="window.close()" style="padding:6px 14px; margin-left:6px; border:1px solid #ccc; border-radius:6px; cursor:pointer; font-size:13px;">Close</button>
    </div>

    <?php if (empty($by_batch)): ?>
    <p style="text-align:center;color:#777;padding:30px 0;">
        No students are assigned to this hall<?= $f_date !== '' ? ' for ' . h(date('d M Y', strtotime($f_date))) : '' ?>.
    </p>
    <?php else: ?>
    <?php foreach ($by_batch as $bk => $grp):
        $courses  = $batch_courses[$bk] ?? [];
        $programs = array_values(array_intersect_key($program_names, $grp['program_ids']));
        $course_names  = [];
        $teacher_names = [];
        $time_slots    = [];
        foreach ($courses as $crs) {
            $cn = trim($crs['course_code'] . ' — ' . $crs['course_title'], ' —');
            if ($cn !== '' && $crs['course_code'] !== '—') $course_names[] = $cn;
            if ($crs['teachers']  !== '') $teacher_names[] = $crs['teachers'];
            if ($crs['time_slot'] !== '') $time_slots[]    = $crs['time_slot'];
        }
        $course_names  = array_values(array_unique($course_names));
        $teacher_names = array_values(array_unique($teacher_names));
        $time_slots    = array_values(array_unique($time_slots));
        $pages       = array_chunk($grp['students'], HM_ATT_STUDENTS_PER_PAGE, true);
        $total_pages = count($pages);
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
            <div class="info-row"><span class="lbl">Batch:</span><span><?= h($grp['name']) ?></span></div>
            <div class="info-row"><span class="lbl">Section:</span><span><?= $grp['sections'] ? h(implode(', ', array_keys($grp['sections']))) : '—' ?></span></div>
            <div class="info-row"><span class="lbl">Shift:</span><span><?= $grp['shifts'] ? h(implode(', ', array_keys($grp['shifts']))) : '—' ?></span></div>
            <div class="info-row"><span class="lbl">Exam Date:</span><span><?= $f_date !== '' ? h(date('d M Y', strtotime($f_date))) : '—' ?></span></div>
            <div class="info-row"><span class="lbl">Time Slot:</span><span><?= $time_slots ? h(implode('; ', $time_slots)) : '—' ?></span></div>
            <div class="info-row"><span class="lbl">Course Name:</span><span><?= $course_names ? h(implode('; ', $course_names)) : '—' ?></span></div>
            <div class="info-row"><span class="lbl">Course Teacher(s):</span><span><?= $teacher_names ? h(implode('; ', $teacher_names)) : '—' ?></span></div>
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
                <?php foreach ($page_students as $i => $stu): ?>
                <tr>
                    <td class="c"><?= $i + 1 ?></td>
                    <td class="c"><?= h($stu['student_code']) ?></td>
                    <td><?= h($stu['full_name']) ?></td>
                    <td class="c">C<?= (int)$stu['col_no'] ?>-S<?= (int)$stu['seat_no'] ?></td>
                    <td></td>
                    <td class="sig"></td>
                    <td></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

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
