<?php
/**
 * Spring Result – Grade Sheet (Tabulation Sheet)
 * Standalone print page (no admin layout). Print or Save as PDF via browser.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('spring-result');
require_once __DIR__ . '/helpers.php';

$id     = (int)($_GET['result_id'] ?? 0);
$result = sr_get_result($id);

$entries = sr_get_entries($id);

// ── Build per-student data and collect all course codes (ordered) ────────────
$by_student   = []; // [student_id => ['name'=>..., 'courses'=>[course_code=>entry]]]
$course_codes = []; // ordered list of all unique course codes

foreach ($entries as $e) {
    $sid  = $e['student_id'];
    $code = trim((string)($e['course_code'] ?? ''));

    if (!isset($by_student[$sid])) {
        $by_student[$sid] = [
            'name'    => $e['student_name'] ?? '',
            'courses' => [],
        ];
    }
    if ($code !== '') {
        $by_student[$sid]['courses'][$code] = $e;
        if (!in_array($code, $course_codes, true)) {
            $course_codes[] = $code;
        }
    } else {
        // Entry without a course code – still store under blank key
        $by_student[$sid]['courses'][''] = $e;
        if (!in_array('', $course_codes, true)) {
            $course_codes[] = '';
        }
    }
}

// ── GPA calculator for a single student ──────────────────────────────────────
function sr_calc_gpa(array $courses): ?string
{
    $total_points  = 0.0;
    $total_credits = 0.0;
    foreach ($courses as $e) {
        if ($e['grade_point'] !== null && $e['credit'] !== null && (float)$e['credit'] > 0) {
            $total_credits += (float)$e['credit'];
            $total_points  += (float)$e['grade_point'] * (float)$e['credit'];
        }
    }
    if ($total_credits <= 0) {
        return null;
    }
    return number_format($total_points / $total_credits, 2);
}

$date_today = date('d F Y');
$page_title = 'Grade Sheet – ' . ($result['title'] ?? 'Result');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($page_title) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; font-size: 11px; line-height: 1.3; background: #f0f2f5; color: #222; }

        .screen-controls {
            position: fixed; top: 0; left: 0; right: 0; z-index: 999;
            background: #1e3a5f; color: #fff; padding: 8px 20px;
            display: flex; align-items: center; gap: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,.25);
        }
        .screen-controls button, .screen-controls a {
            background: #2563eb; color: #fff; border: none;
            padding: 5px 14px; border-radius: 5px; cursor: pointer;
            font-size: 12px; text-decoration: none;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .screen-controls a.back-btn { background: #64748b; }
        .screen-controls span { font-size: 12px; opacity: 0.85; }

        .print-wrapper { padding: 52px 16px 20px; }

        .sheet-page {
            background: #fff;
            width: 100%;
            max-width: 1060px;
            min-height: 1123px;
            padding: 14px 20px 14px;
            margin: 0 auto 20px;
            box-shadow: 0 2px 12px rgba(0,0,0,.15);
        }

        .univ-header {
            text-align: center;
            border-bottom: 2px solid #1e3a5f;
            padding-bottom: 5px;
            margin-bottom: 6px;
        }
        .univ-header img.logo { height: 40px; margin-bottom: 2px; }
        .univ-name {
            font-size: 14px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .05em; color: #1e3a5f;
        }
        .univ-sub { font-size: 10px; color: #555; margin-top: 1px; }

        .doc-title {
            text-align: center; font-size: 12px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .08em;
            background: #1e3a5f; color: #fff;
            padding: 4px 0; margin-bottom: 6px;
        }

        .meta-row {
            display: flex; gap: 24px; flex-wrap: wrap;
            font-size: 11px; margin-bottom: 8px;
        }
        .meta-row span { color: #555; }
        .meta-row strong { color: #1e3a5f; }

        .date-issued { text-align: right; font-size: 10px; color: #444; font-weight: 600; margin-bottom: 4px; }

        table.grade-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10.5px;
        }
        table.grade-table th {
            background: #1e3a5f;
            color: #fff;
            padding: 4px 5px;
            text-align: center;
            border: 1px solid #1e3a5f;
            font-size: 9.5px;
            text-transform: uppercase;
            letter-spacing: .03em;
            white-space: nowrap;
        }
        table.grade-table th.left { text-align: left; }
        table.grade-table td {
            border: 1px solid #ccc;
            padding: 3px 5px;
            vertical-align: middle;
        }
        table.grade-table td.center { text-align: center; }
        table.grade-table td.right  { text-align: right; font-weight: 600; }
        table.grade-table tbody tr:nth-child(even) td { background: #f5f7fc; }
        table.grade-table tbody tr:hover td { background: #eef2f8; }

        .gpa-cell { font-weight: 700; color: #1e3a5f; }
        .grade-absent { color: #bbb; }

        .sig-section {
            margin-top: 16px;
            display: flex; justify-content: space-between; gap: 8px;
        }
        .sig-block { text-align: center; flex: 1; }
        .sig-line {
            border-top: 1px solid #555;
            margin-top: 28px; padding-top: 3px;
            font-size: 9.5px; color: #374151; font-weight: 600;
        }
        .sig-subtitle { font-size: 9px; color: #6b7280; margin-top: 2px; }

        @media print {
            @page { size: A3 landscape; margin: 8mm; }
            .screen-controls { display: none !important; }
            body { background: #fff; }
            .print-wrapper { padding: 0; }
            .sheet-page {
                box-shadow: none; margin: 0; min-height: unset;
                width: 100%; max-width: none;
                padding: 0;
            }
        }
    </style>
</head>
<body>

<div class="screen-controls">
    <button onclick="window.print()">🖨 Print / Save as PDF</button>
    <a href="<?= APP_URL ?>/spring-result/view.php?id=<?= $id ?>" class="back-btn">← Back to Result</a>
    <a href="<?= APP_URL ?>/spring-result/index.php" class="back-btn">← All Results</a>
    <span><?= h($result['title']) ?><?= $result['semester'] ? ' — ' . h($result['semester']) : '' ?></span>
</div>

<div class="print-wrapper">
<div class="sheet-page">

    <div class="univ-header">
        <img src="<?= LOGO_URL ?>" alt="Prime University" class="logo" onerror="this.style.display='none'">
        <div class="univ-name">Prime University</div>
        <div class="univ-sub">114/116 Mazar Road, Mirpur-1, Dhaka 1216, Bangladesh &nbsp;|&nbsp; www.primeuniversity.ac.bd</div>
    </div>

    <div class="doc-title">Grade Sheet (Tabulation Sheet)</div>
    <div class="date-issued">Date of Issue: <?= $date_today ?></div>

    <div class="meta-row">
        <div><span>Result Title: </span><strong><?= h($result['title']) ?></strong></div>
        <?php if ($result['semester']): ?>
        <div><span>Semester: </span><strong><?= h($result['semester']) ?></strong></div>
        <?php endif; ?>
        <div><span>Total Students: </span><strong><?= count($by_student) ?></strong></div>
        <div><span>Total Entries: </span><strong><?= count($entries) ?></strong></div>
    </div>

    <?php if (empty($by_student)): ?>
    <p style="text-align:center;color:#888;margin-top:40px;">No entries found for this result.</p>
    <?php else: ?>

    <table class="grade-table">
        <thead>
            <tr>
                <th class="left" style="width:28px;">Sl</th>
                <th class="left" style="min-width:100px;">Student ID</th>
                <th class="left" style="min-width:140px;">Student Name</th>
                <?php foreach ($course_codes as $code): ?>
                <th><?= $code !== '' ? h($code) : '—' ?></th>
                <?php endforeach; ?>
                <th style="min-width:50px;">GPA</th>
            </tr>
        </thead>
        <tbody>
        <?php $sl = 1; foreach ($by_student as $sid => $sdata): ?>
            <?php $gpa = sr_calc_gpa($sdata['courses']); ?>
            <tr>
                <td class="center"><?= $sl++ ?></td>
                <td><strong><?= h($sid) ?></strong></td>
                <td><?= h($sdata['name']) ?></td>
                <?php foreach ($course_codes as $code): ?>
                <td class="center">
                    <?php if (isset($sdata['courses'][$code])): ?>
                        <?php $ce = $sdata['courses'][$code]; ?>
                        <?php $lg = strtoupper(trim($ce['letter_grade'] ?? '')); ?>
                        <?php if ($lg === 'INCOM'): ?>
                            <span style="color:#888;font-style:italic;">Incomplete</span>
                        <?php else: ?>
                            <?= h($ce['letter_grade']) ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="grade-absent">—</span>
                    <?php endif; ?>
                </td>
                <?php endforeach; ?>
                <td class="center gpa-cell"><?= $gpa !== null ? $gpa : '<span style="color:#888;font-style:italic;">N/A</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="sig-section">
        <div class="sig-block">
            <div class="sig-line">Prepared By</div>
            <div class="sig-subtitle">Examination Office</div>
        </div>
        <div class="sig-block">
            <div class="sig-line">Checked By</div>
            <div class="sig-subtitle">Examination Office</div>
        </div>
        <div class="sig-block">
            <div class="sig-line">Controller of Examinations</div>
            <div class="sig-subtitle">Office of the Controller</div>
        </div>
        <div class="sig-block">
            <div class="sig-line">Registrar</div>
            <div class="sig-subtitle">Office of the Registrar</div>
        </div>
    </div>

    <?php endif; ?>

</div>
</div>

</body>
</html>
