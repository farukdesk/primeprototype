<?php
/**
 * Exam Routine – print-friendly standalone page (no admin layout).
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('exam-routine');
require_once __DIR__ . '/helpers.php';

$routine = er_get_routine((int)($_GET['id'] ?? 0));
if (!$routine) { flash_set('error', 'Routine not found.'); redirect(APP_URL . '/exam-routine/index.php'); }
$items = er_get_items((int)$routine['id']);

$ctx = array_filter([
    'Batch'    => $routine['batch_name'],
    'Semester' => $routine['semester'],
    'Section'  => $routine['section'],
    'Shift'    => $routine['shift'],
    'Intake'   => $routine['academic_intake'],
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Exam Routine – <?= h($routine['exam_name']) ?></title>
<style>
    body  { font-family: 'Segoe UI', Arial, sans-serif; color:#111; margin:32px; }
    .head { text-align:center; margin-bottom:18px; }
    .head h1 { font-size:20px; margin:0 0 4px; }
    .head h2 { font-size:15px; font-weight:600; margin:0 0 4px; }
    .head .sub { font-size:13px; color:#333; }
    table { width:100%; border-collapse:collapse; font-size:12.5px; margin-top:14px; }
    th, td { border:1px solid #444; padding:6px 8px; text-align:left; vertical-align:top; }
    th { background:#f0f0f0; }
    td.c, th.c { text-align:center; }
    .notes { font-size:12px; margin-top:14px; }
    .meta  { font-size:11px; color:#555; margin-top:22px; }
    .toolbar { text-align:right; margin-bottom:14px; }
    .toolbar button { padding:8px 18px; border:1px solid #888; background:#fff; border-radius:6px; cursor:pointer; }
    @media print { .toolbar { display:none; } body { margin:12px; } }
</style>
</head>
<body>

<div class="toolbar"><button onclick="window.print()">Print</button></div>

<div class="head">
    <h1>Exam Routine</h1>
    <h2><?= h($routine['exam_name']) ?><?= $routine['exam_year'] ? ' – ' . h($routine['exam_year']) : '' ?></h2>
    <div class="sub">
        <?= h($routine['dept_name']) ?><?= $routine['program_name'] ? ' — ' . h($routine['program_name']) : '' ?>
    </div>
    <?php if ($ctx): ?>
    <div class="sub">
        <?php $bits = []; foreach ($ctx as $k => $v) $bits[] = $k . ': ' . $v; echo h(implode('  |  ', $bits)); ?>
    </div>
    <?php endif; ?>
</div>

<table>
    <thead>
        <tr>
            <th class="c" style="width:34px;">SL</th>
            <th style="width:110px;">Course Code</th>
            <th>Course Title</th>
            <th class="c" style="width:70px;">Students</th>
            <th style="width:120px;">Date</th>
            <th style="width:130px;">Time</th>
            <th style="width:80px;">Room</th>
            <th style="width:140px;">Remarks</th>
        </tr>
    </thead>
    <tbody>
    <?php $total = 0; foreach ($items as $n => $it): $total += (int)$it['student_count']; ?>
        <tr>
            <td class="c"><?= $n + 1 ?></td>
            <td><?= h($it['course_code'] ?? '') ?></td>
            <td><?= h($it['course_title']) ?></td>
            <td class="c"><?= (int)$it['student_count'] ?></td>
            <td><?= h(date('d M Y (D)', strtotime($it['exam_date']))) ?></td>
            <td><?= h(er_fmt_time($it['start_time'])) ?><?= $it['end_time'] ? ' – ' . h(er_fmt_time($it['end_time'])) : '' ?></td>
            <td><?= h($it['room_number'] ?? '') ?></td>
            <td><?= h($it['remarks'] ?? '') ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($items): ?>
        <tr>
            <th colspan="3" style="text-align:right;">Total students</th>
            <th class="c"><?= $total ?></th>
            <th colspan="4"></th>
        </tr>
    <?php endif; ?>
    </tbody>
</table>

<?php if (!empty($routine['notes'])): ?>
<div class="notes"><strong>Notes:</strong> <?= nl2br(h($routine['notes'])) ?></div>
<?php endif; ?>

<div class="meta">Generated on <?= h(date('d M Y, g:i A')) ?> · <?= h(APP_NAME) ?></div>

</body>
</html>
