<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('results');
require_once __DIR__ . '/helpers.php';

$id       = (int)($_GET['id'] ?? 0);
$exam     = rm_get_exam($id);
$subjects = rm_get_subjects($id);
$grades   = rm_get_grades($id);
$students = rm_get_exam_students($id);

$page_title = h($exam['exam_title']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($exam['exam_title']) ?> – Result Sheet</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; font-size: 12px; color: #000; background: #fff; }
        .page { max-width: 1100px; margin: 0 auto; padding: 20px; }

        .header { text-align: center; border-bottom: 2px solid #002147; padding-bottom: 10px; margin-bottom: 14px; }
        .header h2 { font-size: 16px; color: #002147; margin-bottom: 4px; }
        .header p  { font-size: 12px; color: #333; margin: 2px 0; }

        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 20px; margin-bottom: 14px; }
        .info-grid .info-row { display: flex; gap: 6px; font-size: 11px; }
        .info-grid .info-row .lbl { font-weight: bold; min-width: 130px; }

        .grading-title { font-weight: bold; font-size: 12px; margin-bottom: 4px; }
        .grading-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; font-size: 11px; }
        .grading-table th, .grading-table td {
            border: 1px solid #ccc; padding: 3px 8px; text-align: center;
        }
        .grading-table th { background: #002147; color: #fff; }

        .result-table { width: 100%; border-collapse: collapse; font-size: 11px; }
        .result-table th, .result-table td {
            border: 1px solid #bbb; padding: 4px 6px; text-align: center; vertical-align: middle;
        }
        .result-table thead tr:first-child th { background: #002147; color: #fff; }
        .result-table thead tr:last-child th  { background: #eef2fa; color: #000; font-size:10px; }
        .result-table tbody tr:nth-child(even) { background: #f9f9f9; }
        .result-table td.student-info { text-align: left; }
        .result-table td.student-id   { font-family: monospace; font-size: 11px; }

        .footer { margin-top: 20px; border-top: 1px solid #ccc; padding-top: 10px; font-size: 11px; color: #555; text-align: right; }

        @media print {
            body { font-size: 11px; }
            .no-print { display: none !important; }
            .page { padding: 0; }
        }
    </style>
</head>
<body>
<div class="page">

    <!-- Print button (hidden when printing) -->
    <div class="no-print" style="text-align:right; margin-bottom:12px;">
        <button onclick="window.print()" style="padding:6px 18px; background:#002147; color:#fff; border:none; border-radius:6px; cursor:pointer; font-size:13px;">
            🖨 Print
        </button>
        <button onclick="window.close()" style="padding:6px 14px; margin-left:6px; border:1px solid #ccc; border-radius:6px; cursor:pointer; font-size:13px;">
            Close
        </button>
    </div>

    <!-- Header -->
    <div class="header">
        <h2>Prime University</h2>
        <p><?= h($exam['faculty_label'] ?? '') ?></p>
        <p><strong>Department:</strong> <?= h($exam['dept_name']) ?></p>
        <?php if ($exam['program_name']): ?>
        <p><strong>Program:</strong> <?= h($exam['program_name']) ?></p>
        <?php endif; ?>
        <p style="font-size:14px; font-weight:bold; margin-top:6px;"><?= h($exam['exam_title']) ?></p>
        <?php if ($exam['exam_level']): ?>
        <p><?= h($exam['exam_level']) ?></p>
        <?php endif; ?>
    </div>

    <!-- Institution / Batch Info -->
    <div class="info-grid">
        <?php if ($exam['batch']): ?>
        <div class="info-row"><span class="lbl">Batch:</span><span><?= h($exam['batch']) ?></span></div>
        <?php endif; ?>
        <?php if ($exam['enrollment_semester']): ?>
        <div class="info-row"><span class="lbl">Enrollment Semester:</span><span><?= h($exam['enrollment_semester']) ?></span></div>
        <?php endif; ?>
        <?php if ($exam['completion_semester']): ?>
        <div class="info-row"><span class="lbl">Completion Semester:</span><span><?= h($exam['completion_semester']) ?></span></div>
        <?php endif; ?>
    </div>

    <!-- Grading Reference -->
    <div class="grading-title">Grading System Reference</div>
    <table class="grading-table">
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

    <!-- Subject codes legend -->
    <?php if (!empty($subjects)): ?>
    <div class="grading-title" style="margin-bottom:4px;">Course Codes &amp; Titles</div>
    <div style="font-size:11px; margin-bottom:12px; columns:2; column-gap:20px;">
        <?php foreach ($subjects as $s): ?>
        <div><?= h(($s['course_code'] ? $s['course_code'] . ': ' : '') . $s['course_title']) ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Grade matrix -->
    <?php if (!empty($students) && !empty($subjects)): ?>
    <div class="grading-title" style="margin-bottom:4px;">Student Performance Records (Grades &amp; Grade Points)</div>
    <table class="result-table">
        <thead>
            <tr>
                <th style="width:35px;">SL</th>
                <th style="min-width:110px;">Student ID</th>
                <th style="min-width:140px;">Name</th>
                <?php foreach ($subjects as $s): ?>
                <th><?= h($s['course_code'] ?? $s['course_title']) ?></th>
                <?php endforeach; ?>
            </tr>
            <!-- Sub-header: full names -->
            <tr>
                <th colspan="3" style="text-align:left;">SLID No. / Name</th>
                <?php foreach ($subjects as $s): ?>
                <th style="font-weight:normal; font-size:9px;"><?= h($s['course_title']) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($students as $idx => $st):
            $sid  = $st['student_sid'];
            $name = $st['s_full_name'] ?: $st['student_name'];
            $s_id_str = $st['s_student_id'] ?: $sid;
        ?>
        <tr>
            <td><?= $idx + 1 ?></td>
            <td class="student-id"><?= h($s_id_str) ?></td>
            <td class="student-info"><?= h($name) ?></td>
            <?php foreach ($subjects as $s):
                $g = $grades[$sid][$s['id']] ?? null;
            ?>
            <td>
                <?php if ($g && $g['letter_grade'] !== null): ?>
                <?= h($g['letter_grade']) ?> (<?= h(number_format((float)$g['grade_point'], 2)) ?>)
                <?php else: ?>—<?php endif; ?>
            </td>
            <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php elseif (empty($subjects)): ?>
    <p style="color:#888;">No subjects added to this result exam yet.</p>
    <?php else: ?>
    <p style="color:#888;">No student grades recorded yet.</p>
    <?php endif; ?>

    <div class="footer">
        Printed on <?= date('d M Y, h:i A') ?> | Prime University Admin
    </div>

</div>
</body>
</html>
