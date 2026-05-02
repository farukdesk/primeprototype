<?php
/**
 * Print view for a workflow mark sheet.
 * Admin-only; no published-status restriction (staff can print any stage).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/workflow-helpers.php';

$id       = (int)($_GET['id'] ?? 0);
$sheet    = wf_get_sheet($id);
$grades   = wf_get_grades($id);
$signoffs = wf_get_sheet_signoffs($id);

// Mark distribution: prefer curriculum config, fall back to legacy defaults
$mark_distribution = [];
if (!empty($sheet['curriculum_id'])) {
    try {
        $md_stmt = db()->prepare(
            'SELECT distribution_name, max_marks
               FROM cc_mark_distributions
              WHERE curriculum_id = ?
              ORDER BY sort_order ASC, id ASC'
        );
        $md_stmt->execute([$sheet['curriculum_id']]);
        $mark_distribution = $md_stmt->fetchAll();
    } catch (Throwable $_e) {}
}
if (empty($mark_distribution)) {
    $mark_distribution = [
        ['distribution_name' => 'Attendance', 'max_marks' => 10],
        ['distribution_name' => 'Class Test',  'max_marks' => 10],
        ['distribution_name' => 'Mid Term',    'max_marks' => 30],
        ['distribution_name' => 'Final Exam',  'max_marks' => 50],
    ];
}
$dist_total = array_sum(array_column($mark_distribution, 'max_marks'));

$page_title = h($sheet['subject_title']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> – Mark Sheet</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; font-size: 12px; color: #000; background: #fff; }
        .page { max-width: 1100px; margin: 0 auto; padding: 20px; }

        .header { text-align: center; border-bottom: 2px solid #002147; padding-bottom: 10px; margin-bottom: 14px; }
        .header h2 { font-size: 16px; color: #002147; margin-bottom: 4px; }
        .header p  { font-size: 12px; color: #333; margin: 2px 0; }

        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 20px; margin-bottom: 14px; font-size: 11px; }
        .info-row  { display: flex; gap: 6px; }
        .info-row .lbl { font-weight: bold; min-width: 120px; }

        .grading-title { font-weight: bold; font-size: 12px; margin-bottom: 4px; }
        .scale-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; font-size: 11px; }
        .scale-table th, .scale-table td { border: 1px solid #ccc; padding: 3px 8px; text-align: center; }
        .scale-table th { background: #002147; color: #fff; }

        .marks-table { width: 100%; border-collapse: collapse; font-size: 11px; }
        .marks-table th, .marks-table td { border: 1px solid #bbb; padding: 4px 6px; text-align: center; vertical-align: middle; }
        .marks-table thead tr:first-child th { background: #002147; color: #fff; }
        .marks-table thead tr:last-child th  { background: #eef2fa; color: #000; font-size: 10px; }
        .marks-table tbody tr:nth-child(even) { background: #f9f9f9; }
        .marks-table td.left { text-align: left; }
        .marks-table td.mono { font-family: monospace; }
        .absent-row td { background: #fff3cd !important; }

        .signoff { margin-top: 30px; display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 20px; font-size: 11px; }
        .signoff-box { border-top: 1px solid #999; padding-top: 6px; text-align: center; }
        .signoff-box .lbl { font-weight: bold; }

        .footer { margin-top: 14px; border-top: 1px solid #ccc; padding-top: 8px; font-size: 10px; color: #666; text-align: right; }

        @media print {
            body { font-size: 11px; }
            .no-print { display: none !important; }
            .page { padding: 0; }
        }
    </style>
</head>
<body>
<div class="page">

    <div class="no-print" style="text-align:right; margin-bottom:12px;">
        <button onclick="window.print()" style="padding:6px 18px; background:#002147; color:#fff; border:none; border-radius:6px; cursor:pointer; font-size:13px;">
            🖨 Print
        </button>
        <button onclick="window.close()" style="padding:6px 14px; margin-left:6px; border:1px solid #ccc; border-radius:6px; cursor:pointer; font-size:13px;">
            Close
        </button>
    </div>

    <!-- University Header -->
    <div class="header">
        <h2>Prime University</h2>
        <p><?= h($sheet['faculty_label'] ?? '') ?></p>
        <p><strong>Department:</strong> <?= h($sheet['dept_name']) ?></p>
        <?php if ($sheet['program_name']): ?>
        <p><strong>Program:</strong> <?= h($sheet['program_name']) ?></p>
        <?php endif; ?>
        <p style="font-size:14px; font-weight:bold; margin-top:6px;">MARK SHEET</p>
    </div>

    <!-- Sheet Info -->
    <div class="info-grid">
        <div class="info-row"><span class="lbl">Subject:</span>
            <span><?= $sheet['subject_code'] ? h($sheet['subject_code']) . ' – ' : '' ?><?= h($sheet['subject_title']) ?></span>
        </div>
        <div class="info-row"><span class="lbl">Semester:</span><span><?= h($sheet['semester']) ?></span></div>
        <?php if ($sheet['academic_year']): ?>
        <div class="info-row"><span class="lbl">Academic Year:</span><span><?= h($sheet['academic_year']) ?></span></div>
        <?php endif; ?>
        <?php if ($sheet['credits']): ?>
        <div class="info-row"><span class="lbl">Credits:</span><span><?= h($sheet['credits']) ?></span></div>
        <?php endif; ?>
        <div class="info-row"><span class="lbl">Status:</span><span><?= ucfirst(str_replace('_', ' ', $sheet['workflow_status'])) ?></span></div>
    </div>

    <!-- Grading Scale -->
    <div class="grading-title">Grading Scale</div>
    <table class="scale-table">
        <thead><tr><th>Marks</th><th>Grade</th><th>Point</th></tr></thead>
        <tbody>
        <?php foreach (wf_grading_scale() as [$min, $max, $letter, $point]):
            $range = ($max === PHP_INT_MAX) ? $min . '% and above' : $min . '% to less than ' . $max . '%';
        ?>
        <tr><td><?= $range ?></td><td><strong><?= h($letter) ?></strong></td><td><?= number_format($point, 2) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Mark Distribution reference -->
    <div class="grading-title">Mark Distribution</div>
    <table class="scale-table" style="width:auto; margin-bottom:14px;">
        <thead><tr><th>Component</th><th>Max Marks</th></tr></thead>
        <tbody>
            <?php foreach ($mark_distribution as $dist): ?>
            <tr><td><?= h($dist['distribution_name']) ?></td><td><?= h($dist['max_marks']) ?></td></tr>
            <?php endforeach; ?>
            <tr><td><strong>Total</strong></td><td><strong><?= $dist_total ?></strong></td></tr>
        </tbody>
    </table>

    <!-- Student Marks -->
    <div class="grading-title">Student Performance Records</div>
    <?php if (empty($grades)): ?>
    <p style="color:#888; font-style:italic;">No grades recorded.</p>
    <?php else: ?>
    <table class="marks-table">
        <thead>
            <tr>
                <th style="width:35px;">SL</th>
                <th style="min-width:110px;">Student ID</th>
                <th style="min-width:150px;">Name</th>
                <th>Abs.</th>
                <?php foreach ($mark_distribution as $_pd): ?>
                <th><?= h($_pd['distribution_name']) ?><br>/<?= h($_pd['max_marks']) ?></th>
                <?php endforeach; ?>
                <th>Total</th>
                <th>Grade</th>
                <th>Point</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($grades as $idx => $g):
            // Prefer marks_json; fall back to legacy columns
            $_marks = null;
            if (!empty($g['marks_json'])) {
                $_marks = json_decode($g['marks_json'], true);
            }
            if (!is_array($_marks)) {
                $_marks = [$g['attendance'] ?? null, $g['class_test'] ?? null, $g['mid_term'] ?? null, $g['final_exam'] ?? null];
            }
        ?>
        <tr class="<?= $g['is_absent'] ? 'absent-row' : '' ?>">
            <td><?= $idx + 1 ?></td>
            <td class="mono"><?= h($g['s_student_id'] ?? $g['student_sid']) ?></td>
            <td class="left"><?= h($g['s_full_name'] ?? $g['student_name']) ?></td>
            <td><?= $g['is_absent'] ? '✓' : '' ?></td>
            <?php foreach ($mark_distribution as $di => $_pd): ?>
            <td><?= $g['is_absent'] ? '—' : h($_marks[$di] ?? '—') ?></td>
            <?php endforeach; ?>
            <td><?= $g['is_absent'] ? '—' : h($g['total_marks'] ?? '—') ?></td>
            <td><strong><?= h($g['letter_grade'] ?? '—') ?></strong></td>
            <td><?= $g['grade_point'] !== null ? number_format((float)$g['grade_point'], 2) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- Signoff trail -->
    <div class="signoff">
        <div class="signoff-box">
            <div style="height:35px;"></div>
            <div class="lbl">Teacher / Marks Entered By</div>
            <div><?= h($sheet['creator_name'] ?? '') ?></div>
        </div>
        <div class="signoff-box">
            <div style="height:35px;"></div>
            <div class="lbl">Reviewer</div>
            <div><?= h($signoffs['reviewer_name'] ?? '') ?></div>
            <?php if ($signoffs['reviewed_at'] ?? null): ?>
            <div style="color:#555;"><?= date('d M Y', strtotime($signoffs['reviewed_at'])) ?></div>
            <?php endif; ?>
        </div>
        <div class="signoff-box">
            <div style="height:35px;"></div>
            <div class="lbl">Head of Department</div>
            <div><?= h($signoffs['hod_name'] ?? '') ?></div>
            <?php if ($signoffs['hod_approved_at'] ?? null): ?>
            <div style="color:#555;"><?= date('d M Y', strtotime($signoffs['hod_approved_at'])) ?></div>
            <?php endif; ?>
        </div>
        <div class="signoff-box">
            <div style="height:35px;"></div>
            <div class="lbl">Controller of Examinations</div>
            <div><?= h($signoffs['publisher_name'] ?? '') ?></div>
            <?php if ($signoffs['published_at'] ?? null): ?>
            <div style="color:#555;"><?= date('d M Y', strtotime($signoffs['published_at'])) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="footer">
        Printed on <?= date('d M Y, h:i A') ?> &nbsp;|&nbsp; Prime University – Controller of Examinations
    </div>

</div>
</body>
</html>
