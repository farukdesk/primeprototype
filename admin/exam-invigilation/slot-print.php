<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/slot-helpers.php';
require_access('exam-invigilation');

$id = (int)($_GET['id'] ?? 0);
$exam_st = db()->prepare('SELECT * FROM ei_exams WHERE id = ?');
$exam_st->execute([$id]);
$exam = $exam_st->fetch();
if (!$exam) {
    flash_set('error', 'Exam not found.');
    redirect(APP_URL . '/exam-invigilation/index.php');
}

// ── Filters (same as view.php) ─────────────────────────────────────────────
$f_date        = trim((string)($_GET['slot_date'] ?? ''));
$f_dept        = (int)($_GET['dept'] ?? 0);
$f_room        = trim((string)($_GET['room'] ?? ''));
$f_time_slot   = trim((string)($_GET['time_slot'] ?? ''));
$f_invigilator = (int)($_GET['invigilator'] ?? 0);

if ($f_date !== '' && !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $f_date)) {
    $f_date = '';
}

$time_order_sql = "COALESCE(
    STR_TO_DATE(TRIM(SUBSTRING_INDEX(REPLACE(s.time_slot, '-', '–'), '–', 1)), '%h:%i %p'),
    STR_TO_DATE(TRIM(SUBSTRING_INDEX(REPLACE(s.time_slot, '-', '–'), '–', 1)), '%H:%i')
)";

$where  = ['s.exam_id = ?'];
$params = [$id];
if ($f_date !== '') {
    $where[]  = 's.slot_date = ?';
    $params[] = $f_date;
}
if ($f_dept > 0) {
    $where[]  = 's.dept_id = ?';
    $params[] = $f_dept;
}
if ($f_room !== '') {
    $where[]  = 's.room_number LIKE ?';
    $params[] = '%' . $f_room . '%';
}
if ($f_time_slot !== '') {
    $where[]  = 's.time_slot = ?';
    $params[] = $f_time_slot;
}
if ($f_invigilator > 0) {
    $where[]  = '(s.faculty1_id = ? OR s.faculty2_id = ?)';
    $params[] = $f_invigilator;
    $params[] = $f_invigilator;
}
$sql_where = 'WHERE ' . implode(' AND ', $where);

$slots_st = db()->prepare(
    "SELECT s.*,
            f1.name AS f1_name, f1.designation AS f1_desig, f1.contact_number AS f1_phone, d1.name AS f1_dept,
            f2.name AS f2_name, f2.designation AS f2_desig, f2.contact_number AS f2_phone, d2.name AS f2_dept,
            dp.name AS pref_dept_name
     FROM ei_slots s
     LEFT JOIN ei_faculty f1 ON f1.id = s.faculty1_id
     LEFT JOIN dept_departments d1 ON d1.id = f1.dept_id
     LEFT JOIN ei_faculty f2 ON f2.id = s.faculty2_id
     LEFT JOIN dept_departments d2 ON d2.id = f2.dept_id
     LEFT JOIN dept_departments dp ON dp.id = s.dept_id
     $sql_where
     ORDER BY s.slot_date ASC, {$time_order_sql} ASC, s.time_slot ASC, s.room_number ASC"
);
$slots_st->execute($params);
$slots = $slots_st->fetchAll();

// Group by date for display
$by_date = [];
foreach ($slots as $s) {
    $by_date[$s['slot_date']][] = $s;
}

// ── Human-readable filter summary for the report header ────────────────────
$filter_parts = [];
if ($f_dept > 0) {
    $dept_st = db()->prepare('SELECT name FROM dept_departments WHERE id = ?');
    $dept_st->execute([$f_dept]);
    $dept_name = (string)$dept_st->fetchColumn();
    if ($dept_name !== '') $filter_parts[] = 'Department: ' . $dept_name;
}
if ($f_date !== '')      $filter_parts[] = 'Date: ' . date('d M Y', strtotime($f_date));
if ($f_room !== '')      $filter_parts[] = 'Room: ' . $f_room;
if ($f_time_slot !== '') $filter_parts[] = 'Time Slot: ' . $f_time_slot;
if ($f_invigilator > 0) {
    $inv_st = db()->prepare('SELECT name FROM ei_faculty WHERE id = ?');
    $inv_st->execute([$f_invigilator]);
    $inv_name = (string)$inv_st->fetchColumn();
    if ($inv_name !== '') $filter_parts[] = 'Invigilator: ' . $inv_name;
}
$filter_summary = implode('  •  ', $filter_parts);

/** Render one invigilator cell: name, designation, department, phone. */
function ei_print_invigilator_cell(?string $name, ?string $desig, ?string $dept, ?string $phone): string
{
    if ($name === null || trim($name) === '') {
        return '<span class="not-assigned">Not assigned</span>';
    }
    $html = '<span class="inv-name">' . h($name) . '</span>';
    if ($desig !== null && trim($desig) !== '') {
        $html .= '<br><span class="inv-meta">' . h($desig) . '</span>';
    }
    if ($dept !== null && trim($dept) !== '') {
        $html .= '<br><span class="inv-dept">' . h($dept) . '</span>';
    }
    if ($phone !== null && trim($phone) !== '') {
        $html .= '<br><span class="inv-meta">Phone: ' . h($phone) . '</span>';
    }
    return $html;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($exam['exam_name']) ?> – Invigilation Duty Roster</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    html, body { background: #fff; color: #000; font-family: 'Segoe UI', Arial, sans-serif; font-size: 11px; }
    .page { max-width: 190mm; margin: 0 auto; padding: 10mm 0; }

    .report-header { text-align: center; margin-bottom: 12px; border-bottom: 2px solid #000; padding-bottom: 8px; }
    .report-header .uni { font-size: 16px; font-weight: 700; }
    .report-header .exam { font-size: 13px; font-weight: 600; margin-top: 2px; }
    .report-header .sub { font-size: 11px; margin-top: 2px; }
    .report-header .filters { font-size: 10px; color: #333; margin-top: 4px; }

    .date-heading {
        font-size: 12px; font-weight: 700; margin: 12px 0 4px;
        padding: 4px 8px; background: #efefef; border: 1px solid #999;
    }

    table.roster { width: 100%; border-collapse: collapse; }
    table.roster th, table.roster td {
        border: 1px solid #666; padding: 4px 6px; vertical-align: top; text-align: left;
    }
    table.roster thead th { background: #e8e8e8; font-size: 10.5px; }
    table.roster tbody tr { page-break-inside: avoid; }
    table.roster thead { display: table-header-group; }

    .col-sl   { width: 6%; text-align: center; }
    .col-room { width: 14%; }
    .col-time { width: 16%; }
    .col-inv  { width: 32%; }

    .inv-name { font-weight: 700; }
    .inv-meta { font-size: 10px; color: #222; }
    .inv-dept { font-size: 10px; font-weight: 600; }
    .not-assigned { color: #b00; font-weight: 600; }
    .room-dept { font-size: 9.5px; color: #444; }

    .report-footer { margin-top: 10px; font-size: 9.5px; color: #444; text-align: right; }
    .empty-note { padding: 20px; text-align: center; color: #666; border: 1px dashed #999; margin-top: 10px; }

    .toolbar { text-align: center; margin-bottom: 12px; }
    .toolbar button {
        padding: 8px 18px; margin: 0 4px; font-size: 13px; cursor: pointer;
        border: 1px solid #333; background: #fff; border-radius: 6px;
    }
    .toolbar button.primary { background: #1a56db; border-color: #1a56db; color: #fff; }

    @media print {
        @page { size: A4 portrait; margin: 10mm; }
        .toolbar { display: none !important; }
        .page { max-width: none; padding: 0; }
        .date-heading { background: #efefef !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        table.roster thead th { background: #e8e8e8 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
</style>
</head>
<body>
<div class="page">
    <div class="toolbar">
        <button type="button" class="primary" onclick="window.print()">Print / Save as PDF (A4)</button>
        <button type="button" onclick="window.close()">Close</button>
    </div>

    <div class="report-header">
        <div class="uni">Prime University</div>
        <div class="exam"><?= h($exam['exam_name']) ?><?= !empty($exam['exam_year']) ? ' – ' . h($exam['exam_year']) : '' ?></div>
        <div class="sub">Invigilation Duty Roster</div>
        <?php if ($filter_summary !== ''): ?>
        <div class="filters"><?= h($filter_summary) ?></div>
        <?php endif; ?>
    </div>

    <?php if (empty($slots)): ?>
    <div class="empty-note">No slots found for the selected filters.</div>
    <?php else: ?>
    <?php $sl = 0; foreach ($by_date as $date => $date_slots): ?>
    <div class="date-heading"><?= date('l, d F Y', strtotime($date)) ?> — <?= count($date_slots) ?> room(s)</div>
    <table class="roster">
        <thead>
            <tr>
                <th class="col-sl">SL</th>
                <th class="col-room">Room</th>
                <th class="col-time">Time Slot</th>
                <th class="col-inv">Invigilator 1</th>
                <th class="col-inv">Invigilator 2</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($date_slots as $s): $sl++; ?>
            <tr>
                <td class="col-sl"><?= $sl ?></td>
                <td class="col-room">
                    <strong><?= h($s['room_number']) ?></strong>
                    <?php if (!empty($s['pref_dept_name'])): ?>
                    <br><span class="room-dept"><?= h($s['pref_dept_name']) ?></span>
                    <?php endif; ?>
                </td>
                <td class="col-time"><?= h($s['time_slot']) ?></td>
                <td class="col-inv"><?= ei_print_invigilator_cell($s['f1_name'], $s['f1_desig'], $s['f1_dept'], $s['f1_phone']) ?></td>
                <td class="col-inv"><?= ei_print_invigilator_cell($s['f2_name'], $s['f2_desig'], $s['f2_dept'], $s['f2_phone']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endforeach; ?>
    <?php endif; ?>

    <div class="report-footer">
        Generated on <?= date('d M Y h:i A') ?> — <?= count($slots) ?> slot(s) listed.
    </div>
</div>
<script>
// Auto-open the print dialog so the admin can immediately Save as PDF (A4).
window.addEventListener('load', function () {
    setTimeout(function () { window.print(); }, 300);
});
</script>
</body>
</html>
