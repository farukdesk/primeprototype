<?php
/**
 * Printable A4 landscape seat layout of a hall for one exam date.
 * Header: university logo + name, Room Number, exam date; FRONT / BOARD
 * banner above the seat grid, batch+section coloured seats (two sections of
 * the same batch get different colours), legend and the exam schedule
 * (course / teacher / time slot per batch+section) below, mirroring the
 * on-screen Seat Layout.
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

$columns = hm_hall_columns($hall_id);

$f_date = trim($_GET['date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_date)) $f_date = '';
$asg_dates = hm_hall_assignment_dates($hall_id);
if ($f_date === '' && $asg_dates) $f_date = (string)$asg_dates[0];

$assignments   = $f_date !== '' ? hm_assignments($hall_id, $f_date) : [];
$group_courses = ($f_date !== '' && $assignments) ? hm_exam_courses_by_group($hall_id, $f_date) : [];

// Batch+section colours, matching the on-screen Seat Layout: two sections of
// the same batch get different colours so sections are identified separately.
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seat Layout – Room <?= h($hall['room_number']) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; font-size: 12px; color: #000; background: #fff; }
        .page { max-width: 1140px; margin: 0 auto; padding: 16px; }

        .header { position: relative; display: flex; align-items: center; justify-content: center; border-bottom: 2px solid #002147; padding-bottom: 8px; margin-bottom: 10px; min-height: 64px; }
        .header-logo { position: absolute; left: 0; top: 50%; transform: translateY(-50%); width: 140px; height: auto; object-fit: contain; }
        .header-text { text-align: center; line-height: 1.5; }
        .header-text h2 { font-size: 22px; color: #002147; margin-bottom: 2px; font-weight: bold; }
        .header-text p  { font-size: 13px; color: #333; margin: 1px 0; }
        .header-text .sheet-title { font-size: 14px; font-weight: bold; margin-top: 4px; letter-spacing: 1px; color: #002147; }
        .room-no { font-size: 16px; font-weight: bold; color: #002147; }

        .front-board { text-align: center; margin: 10px 0 14px; }
        .front-board span { display: inline-block; background: #002147; color: #fff; font-weight: bold; letter-spacing: 2px; font-size: 13px; padding: 6px 60px; border-radius: 4px; }

        .schedule { margin-top: 16px; }
        .schedule h3 { font-size: 14px; color: #002147; text-align: center; margin-bottom: 6px; letter-spacing: 1px; }
        .schedule-table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .schedule-table th, .schedule-table td { border: 1px solid #999; padding: 5px 8px; text-align: left; vertical-align: middle; }
        .schedule-table th { background: #002147; color: #fff; text-align: center; }
        .schedule-table td.c { text-align: center; }

        .layout { display: flex; gap: 14px; justify-content: center; flex-wrap: wrap; }
        .col { text-align: center; }
        .col-title { font-weight: bold; font-size: 12px; color: #002147; margin-bottom: 4px; }
        .col-title small { display: block; font-weight: normal; color: #555; font-size: 12px; }
        .col-batch { font-size: 12px; margin: 2px 0 4px; }
        .col-batch span { display: inline-block; padding: 1px 8px; border-radius: 10px; border: 1px solid; }
        .seats { display: flex; flex-direction: column; gap: 3px; align-items: center; }
        .seat { min-width: 100px; height: 28px; border-radius: 4px; border: 1px solid #999;
                display: flex; align-items: center; justify-content: center; font-size: 12px; padding: 0 4px; white-space: nowrap; }
        .seat.free { width: 40px; min-width: 40px; background: #f3f4f6; border: 1px dashed #bbb; color: #777; }

        .legend { text-align: center; margin-top: 14px; font-size: 12px; color: #333; }
        .legend .sw { display: inline-block; width: 12px; height: 12px; border: 1px solid; border-radius: 3px; vertical-align: -2px; margin-right: 3px; }

        .footer { margin-top: 14px; border-top: 1px solid #ccc; padding-top: 4px; font-size: 10px; color: #666; display: flex; justify-content: space-between; }

        @media print {
            @page { size: A4 landscape; margin: 10mm; }
            .no-print { display: none !important; }
            .page { max-width: 100%; padding: 0; }
            body, .seat, .front-board span, .col-batch span, .legend .sw, .schedule-table th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .layout { page-break-inside: avoid; }
            .schedule-table tr { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
<div class="page">

    <div class="no-print" style="text-align:right; margin-bottom:12px;">
        <button onclick="window.print()" style="padding:6px 18px; background:#002147; color:#fff; border:none; border-radius:6px; cursor:pointer; font-size:13px;">🖨 Print</button>
        <button onclick="window.close()" style="padding:6px 14px; margin-left:6px; border:1px solid #ccc; border-radius:6px; cursor:pointer; font-size:13px;">Close</button>
    </div>

    <!-- University header with logo + room number -->
    <div class="header">
        <img src="<?= APP_URL ?>/../assets/img/logo/logo-black.png" alt="Prime University Logo" class="header-logo" onerror="this.style.display='none'">
        <div class="header-text">
            <h2>Prime University</h2>
            <p><?= h($hall['dept_name']) ?></p>
            <p class="room-no">Room No: <?= h($hall['room_number']) ?></p>
            <div class="sheet-title">EXAM SEAT PLAN<?= $f_date !== '' ? ' — ' . h(date('d M Y', strtotime($f_date))) : '' ?></div>
        </div>
    </div>

    <?php if (empty($columns)): ?>
    <p style="text-align:center;color:#777;padding:30px 0;">No seat columns defined for this hall.</p>
    <?php else: ?>

    <!-- FRONT / BOARD banner -->
    <div class="front-board"><span>FRONT / BOARD</span></div>

    <div class="layout">
        <?php foreach ($columns as $col):
            $col_group = null;
            for ($s = 1; $s <= (int)$col['seat_capacity']; $s++) {
                $o = $assignments[(int)$col['col_no'] . ':' . $s] ?? null;
                if ($o) { $col_group = (int)($o['student_batch_id'] ?? 0) . '|' . trim((string)($o['student_section'] ?? '')); break; }
            }
            $col_clr = $col_group !== null ? ($group_colors[$col_group] ?? null) : null;
        ?>
        <div class="col">
            <div class="col-title">Column <?= (int)$col['col_no'] ?><small><?= (int)$col['seat_capacity'] ?> seats</small></div>
            <?php if ($col_clr !== null): ?>
            <div class="col-batch">
                <span style="background:<?= h($col_clr['bg']) ?>;border-color:<?= h($col_clr['border']) ?>;color:<?= h($col_clr['text']) ?>;">
                    <?= h($group_names[$col_group] ?? 'No batch') ?>
                </span>
            </div>
            <?php endif; ?>
            <div class="seats">
                <?php for ($s = 1; $s <= (int)$col['seat_capacity']; $s++):
                    $occ = $assignments[(int)$col['col_no'] . ':' . $s] ?? null; ?>
                <?php if ($occ):
                    $occ_gk  = (int)($occ['student_batch_id'] ?? 0) . '|' . trim((string)($occ['student_section'] ?? ''));
                    $occ_sec = trim((string)($occ['student_section'] ?? ''));
                    $clr     = $group_colors[$occ_gk] ?? $batch_palette[0]; ?>
                <div class="seat" style="background:<?= h($clr['bg']) ?>;border-color:<?= h($clr['border']) ?>;color:<?= h($clr['text']) ?>;">
                    <?= h($occ['student_code']) ?><?= $occ_sec !== '' ? ' · ' . h($occ_sec) : '' ?>
                </div>
                <?php else: ?>
                <div class="seat free"><?= $s ?></div>
                <?php endif; ?>
                <?php endfor; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="legend">
        <?php foreach ($group_colors as $gk => $clr): ?>
        <span style="margin-right:14px;"><span class="sw" style="background:<?= h($clr['bg']) ?>;border-color:<?= h($clr['border']) ?>;"></span><?= h($group_names[$gk]) ?></span>
        <?php endforeach; ?>
        <span style="margin-right:14px;"><span class="sw" style="background:#f3f4f6;border-color:#bbb;"></span>Free (<?= max(0, (int)$hall['total_capacity'] - count($assignments)) ?>)</span>
        <span>Assigned: <?= count($assignments) ?> / <?= (int)$hall['total_capacity'] ?></span>
    </div>

    <?php
    // Exam schedule of the seated batch+section groups (course, teacher of
    // that section, time slot, students), with a fallback to every section
    // of the same batch when no courses resolved for the exact section key.
    $group_counts = [];
    foreach ($assignments as $a) {
        $gk = (int)($a['student_batch_id'] ?? 0) . '|' . trim((string)($a['student_section'] ?? ''));
        $group_counts[$gk] = ($group_counts[$gk] ?? 0) + 1;
    }
    $schedule_rows = [];
    foreach ($group_colors as $gk => $clr) {
        $courses = $group_courses[$gk] ?? [];
        if (!$courses) {
            $gbk = (int)strtok((string)$gk, '|');
            foreach ($group_courses as $ogk => $rows) {
                if ((int)strtok((string)$ogk, '|') === $gbk) $courses = array_merge($courses, $rows);
            }
        }
        foreach ($courses as $crs) {
            $schedule_rows[] = ['group' => $group_names[$gk] ?? 'No batch', 'students' => $group_counts[$gk] ?? 0, 'crs' => $crs];
        }
    }
    ?>
    <?php if ($schedule_rows): ?>
    <div class="schedule">
        <h3>EXAM SCHEDULE<?= $f_date !== '' ? ' — ' . h(date('d M Y', strtotime($f_date))) : '' ?></h3>
        <table class="schedule-table">
            <thead>
                <tr>
                    <th style="width:140px;">Batch / Section</th>
                    <th style="width:110px;">Course Code</th>
                    <th>Course Title</th>
                    <th>Course Teacher(s)</th>
                    <th style="width:80px;">Students</th>
                    <th style="width:150px;">Time</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($schedule_rows as $row): $crs = $row['crs']; ?>
                <tr>
                    <td class="c"><?= h($row['group']) ?></td>
                    <td class="c"><?= $crs['course_code'] !== '' ? h($crs['course_code']) : '—' ?></td>
                    <td><?= $crs['course_title'] !== '' ? h($crs['course_title']) : '—' ?></td>
                    <td><?= $crs['teachers'] !== '' ? h($crs['teachers']) : '—' ?></td>
                    <td class="c"><?= (int)$row['students'] ?></td>
                    <td class="c"><?= $crs['time_slot'] !== '' ? h($crs['time_slot']) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <div class="footer">
        <span>Room <?= h($hall['room_number']) ?> — <?= h($hall['dept_name']) ?></span>
        <span>Printed on <?= h(date('d M Y, h:i A')) ?></span>
    </div>
</div>
</body>
</html>
