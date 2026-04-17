<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('results');
require_once __DIR__ . '/helpers.php';

$exam_id     = (int)($_GET['exam_id']     ?? 0);
$student_sid = trim($_GET['student_sid']  ?? '');

if (!$exam_id || $student_sid === '') {
    flash_set('error', 'Invalid parameters.');
    redirect(APP_URL . '/results/index.php');
}

$exam     = rm_get_exam($exam_id);
$subjects = rm_get_subjects($exam_id);
$all_grades = rm_get_grades($exam_id);

// Get the specific student's row
$stmt = db()->prepare(
    'SELECT DISTINCT g.student_sid, g.student_name,
            s.full_name AS s_full_name, s.student_id AS s_student_id,
            s.email AS s_email
     FROM result_grades g
     LEFT JOIN students s ON s.id = g.student_id
     WHERE g.exam_id = ? AND g.student_sid = ?
     LIMIT 1'
);
$stmt->execute([$exam_id, $student_sid]);
$student = $stmt->fetch();

if (!$student) {
    flash_set('error', 'Student not found in this result exam.');
    redirect(APP_URL . '/results/view.php?id=' . $exam_id);
}

$name      = $student['s_full_name'] ?: $student['student_name'];
$s_id_str  = $student['s_student_id'] ?: $student['student_sid'];
$grades    = $all_grades[$student_sid] ?? [];

// Compute CGPA (average of grade points for subjects that have a grade)
$gp_sum   = 0.0;
$gp_count = 0;
foreach ($subjects as $s) {
    $g = $grades[$s['id']] ?? null;
    if ($g && $g['grade_point'] !== null) {
        $gp_sum   += (float)$g['grade_point'];
        $gp_count++;
    }
}
$cgpa = $gp_count > 0 ? $gp_sum / $gp_count : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Result – <?= h($name) ?></title>
    <style>
        /* ── Reset ───────────────────────────────────────────────────── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        /* ── Screen: centred A4 preview ─────────────────────────────── */
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12px;
            color: #000;
            background: #e0e0e0;
        }

        .no-print {
            background: #fff;
            border-bottom: 1px solid #ccc;
            padding: 10px 20px;
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .no-print button {
            padding: 6px 18px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
        }
        .no-print .btn-print  { background: #002147; color: #fff; }
        .no-print .btn-close2 { background: #f0f0f0; border: 1px solid #ccc; }

        /* A4 page wrapper (screen) */
        .a4-page {
            width: 210mm;
            min-height: 297mm;
            margin: 20px auto;
            background: #fff;
            padding: 18mm 16mm;
            box-shadow: 0 2px 12px rgba(0,0,0,.25);
        }

        /* ── Header ─────────────────────────────────────────────────── */
        .header {
            text-align: center;
            border-bottom: 3px double #002147;
            padding-bottom: 10px;
            margin-bottom: 14px;
        }
        .header .uni-name  { font-size: 18px; font-weight: bold; color: #002147; letter-spacing: .5px; }
        .header .sub-line  { font-size: 11px; color: #333; margin-top: 3px; }
        .header .doc-title {
            font-size: 14px; font-weight: bold; color: #002147;
            margin-top: 8px; border: 1px solid #002147;
            display: inline-block; padding: 3px 20px; border-radius: 4px;
        }

        /* ── Student info box ────────────────────────────────────────── */
        .info-box {
            border: 1px solid #002147;
            border-radius: 6px;
            padding: 10px 14px;
            margin-bottom: 14px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 5px 20px;
            font-size: 11.5px;
        }
        .info-box .row { display: flex; gap: 6px; }
        .info-box .lbl { font-weight: bold; min-width: 140px; }

        /* ── Grades table ────────────────────────────────────────────── */
        .grades-title { font-weight: bold; font-size: 12px; margin-bottom: 6px; }
        .grades-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
            font-size: 11.5px;
        }
        .grades-table th, .grades-table td {
            border: 1px solid #999;
            padding: 5px 8px;
            vertical-align: middle;
        }
        .grades-table thead th {
            background: #002147;
            color: #fff;
            text-align: center;
        }
        .grades-table td:nth-child(1) { width: 32px; text-align: center; }
        .grades-table td:nth-child(3) { text-align: center; }
        .grades-table td:nth-child(4) { text-align: center; font-weight: bold; font-size: 13px; }
        .grades-table td:nth-child(5) { text-align: center; }
        .grades-table tbody tr:nth-child(even) { background: #f7f8fa; }

        /* ── Summary box ─────────────────────────────────────────────── */
        .summary-box {
            border: 2px solid #002147;
            border-radius: 6px;
            padding: 10px 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14px;
            font-size: 12px;
        }
        .summary-box .item { text-align: center; }
        .summary-box .item .val { font-size: 18px; font-weight: bold; color: #002147; }
        .summary-box .item .lbl { font-size: 10px; color: #555; margin-top: 2px; }

        /* ── Grading reference ───────────────────────────────────────── */
        .ref-title { font-weight: bold; font-size: 11px; margin-bottom: 4px; }
        .ref-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10.5px;
        }
        .ref-table th, .ref-table td {
            border: 1px solid #ccc;
            padding: 3px 8px;
            text-align: center;
        }
        .ref-table th { background: #eef2fa; color: #000; }

        /* ── Footer ──────────────────────────────────────────────────── */
        .footer {
            margin-top: 20px;
            border-top: 1px solid #ccc;
            padding-top: 10px;
            font-size: 10px;
            color: #666;
            display: flex;
            justify-content: space-between;
        }

        /* ── Print overrides ─────────────────────────────────────────── */
        @media print {
            body      { background: #fff; }
            .no-print { display: none !important; }
            .a4-page  {
                margin: 0;
                padding: 12mm 12mm;
                box-shadow: none;
                width: 100%;
                min-height: auto;
            }
            @page {
                size: A4 portrait;
                margin: 0;
            }
        }
    </style>
</head>
<body>

<!-- Screen-only toolbar -->
<div class="no-print">
    <button class="btn-print" onclick="window.print()">🖨 Print / Save as PDF</button>
    <button class="btn-close2" onclick="window.close()">✕ Close</button>
    <span style="margin-left:10px; font-size:12px; color:#555;">
        Individual result for: <strong><?= h($name) ?></strong>
    </span>
</div>

<div class="a4-page">

    <!-- Header -->
    <div class="header">
        <div class="uni-name">Prime University</div>
        <?php if (!empty($exam['faculty_label'])): ?>
        <div class="sub-line"><?= h($exam['faculty_label']) ?></div>
        <?php endif; ?>
        <div class="sub-line"><strong>Department:</strong> <?= h($exam['dept_name']) ?></div>
        <?php if (!empty($exam['program_name'])): ?>
        <div class="sub-line"><strong>Program:</strong> <?= h($exam['program_name']) ?></div>
        <?php endif; ?>
        <div class="doc-title"><?= h($exam['exam_title']) ?></div>
        <?php if (!empty($exam['exam_level'])): ?>
        <div class="sub-line" style="margin-top:4px;"><?= h($exam['exam_level']) ?></div>
        <?php endif; ?>
    </div>

    <!-- Student info -->
    <div class="info-box">
        <div class="row"><span class="lbl">Student Name:</span><span><?= h($name) ?></span></div>
        <div class="row"><span class="lbl">Student ID:</span><span><?= h($s_id_str) ?></span></div>
        <?php if (!empty($exam['batch'])): ?>
        <div class="row"><span class="lbl">Batch:</span><span><?= h($exam['batch']) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($exam['enrollment_semester'])): ?>
        <div class="row"><span class="lbl">Enrollment Semester:</span><span><?= h($exam['enrollment_semester']) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($exam['completion_semester'])): ?>
        <div class="row"><span class="lbl">Completion Semester:</span><span><?= h($exam['completion_semester']) ?></span></div>
        <?php endif; ?>
    </div>

    <!-- Grade summary -->
    <div class="summary-box">
        <?php
        $pass_count = 0;
        $fail_count = 0;
        foreach ($subjects as $s) {
            $g = $grades[$s['id']] ?? null;
            if ($g && $g['letter_grade'] !== null) {
                if ($g['letter_grade'] === 'F') $fail_count++;
                else $pass_count++;
            }
        }
        $total_subjects = count($subjects);
        $graded_count   = $pass_count + $fail_count;
        ?>
        <div class="item">
            <div class="val"><?= $total_subjects ?></div>
            <div class="lbl">Total Courses</div>
        </div>
        <div class="item">
            <div class="val"><?= $graded_count ?></div>
            <div class="lbl">Graded</div>
        </div>
        <div class="item">
            <div class="val" style="color:#1a7f40;"><?= $pass_count ?></div>
            <div class="lbl">Passed</div>
        </div>
        <div class="item">
            <div class="val" style="color:#c0392b;"><?= $fail_count ?></div>
            <div class="lbl">Failed</div>
        </div>
        <div class="item">
            <div class="val"><?= $cgpa !== null ? number_format($cgpa, 2) : '—' ?></div>
            <div class="lbl">CGPA</div>
        </div>
    </div>

    <!-- Grades detail -->
    <div class="grades-title">Course Grades</div>
    <table class="grades-table">
        <thead>
            <tr>
                <th style="width:32px;">#</th>
                <th style="text-align:left;">Course</th>
                <th style="width:60px;">Credits</th>
                <th style="width:70px;">Grade</th>
                <th style="width:70px;">Grade Point</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($subjects as $i => $s):
            $g = $grades[$s['id']] ?? null;
            $has_grade = $g && $g['letter_grade'] !== null;
            $is_fail   = $has_grade && $g['letter_grade'] === 'F';
        ?>
        <tr>
            <td><?= $i + 1 ?></td>
            <td>
                <?php if (!empty($s['course_code'])): ?>
                <span style="font-weight:bold; color:#002147;"><?= h($s['course_code']) ?></span>
                <span style="color:#555;"> – </span>
                <?php endif; ?>
                <?= h($s['course_title']) ?>
            </td>
            <td style="text-align:center;"><?= $s['credits'] !== null ? h($s['credits']) : '—' ?></td>
            <td style="text-align:center; <?= $is_fail ? 'color:#c0392b;' : '' ?>">
                <?= $has_grade ? '<strong>' . h($g['letter_grade']) . '</strong>' : '—' ?>
            </td>
            <td style="text-align:center;">
                <?= $has_grade ? number_format((float)$g['grade_point'], 2) : '—' ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Grading reference -->
    <div class="ref-title">Grading Scale Reference</div>
    <table class="ref-table">
        <thead>
            <tr>
                <th>Numerical Grade</th>
                <th>Letter Grade</th>
                <th>Grade Point</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach (rm_grading_scale() as [$min, $max, $letter, $point]):
            if ($max === PHP_INT_MAX) {
                $range = $min . '% and above';
            } elseif ($min === 0) {
                $range = 'Less than ' . $max . '%';
            } else {
                $range = $min . '% to less than ' . $max . '%';
            }
        ?>
        <tr>
            <td><?= $range ?></td>
            <td><strong><?= h($letter) ?></strong></td>
            <td><?= number_format($point, 2) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="footer">
        <span>Printed on <?= date('d M Y, h:i A') ?> | Prime University Admin</span>
        <span>This is a computer-generated document.</span>
    </div>

</div><!-- /.a4-page -->
</body>
</html>
