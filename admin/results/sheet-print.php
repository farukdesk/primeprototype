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
        .page { max-width: 1140px; margin: 0 auto; padding: 20px; }

        /* ── Header ── */
        .header { display: flex; align-items: center; gap: 16px; border-bottom: 2px solid #002147; padding-bottom: 10px; margin-bottom: 12px; }
        .header-logo { width: 64px; height: 64px; object-fit: contain; flex-shrink: 0; }
        .header-text { flex: 1; text-align: center; }
        .header-text h2 { font-size: 17px; color: #002147; margin-bottom: 2px; }
        .header-text p  { font-size: 11px; color: #333; margin: 1px 0; }
        .header-text .sheet-title { font-size: 13px; font-weight: bold; margin-top: 5px; letter-spacing: .5px; color: #002147; }

        /* ── Info grid ── */
        .info-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 4px 16px; margin-bottom: 10px; font-size: 11px; }
        .info-row  { display: flex; gap: 5px; }
        .info-row .lbl { font-weight: bold; min-width: 100px; color: #002147; }

        /* ── Reference tables (grading + distribution side-by-side) ── */
        .ref-row { display: flex; gap: 14px; margin-bottom: 12px; align-items: flex-start; }
        .ref-block { flex: 1; }
        .ref-block-title { font-weight: bold; font-size: 11px; margin-bottom: 3px; color: #002147; border-left: 3px solid #002147; padding-left: 5px; }
        .ref-table { width: 100%; border-collapse: collapse; font-size: 10px; }
        .ref-table th, .ref-table td { border: 1px solid #ccc; padding: 2px 6px; text-align: center; }
        .ref-table th { background: #002147; color: #fff; }
        .ref-table td.left { text-align: left; }
        .dist-total-row td { background: #e8f0ff; font-weight: bold; }

        /* ── Teacher info bar ── */
        .teacher-bar { background: #f0f4ff; border: 1px solid #c9d9f8; border-radius: 4px; padding: 5px 10px; margin-bottom: 10px; font-size: 11px; }
        .teacher-bar strong { color: #002147; }

        /* ── Marks table ── */
        .marks-table { width: 100%; border-collapse: collapse; font-size: 11px; }
        .marks-table th, .marks-table td { border: 1px solid #bbb; padding: 3px 5px; text-align: center; vertical-align: middle; }
        .marks-table thead tr:first-child th { background: #002147; color: #fff; }
        .marks-table thead tr:last-child th  { background: #eef2fa; color: #000; font-size: 10px; }
        .marks-table tbody tr:nth-child(even) { background: #f9f9f9; }
        .marks-table td.left { text-align: left; }
        .marks-table td.mono { font-family: monospace; }
        .absent-row td { background: #fff3cd !important; }
        .abs-cell { color: #c0392b; font-weight: bold; font-size: 10px; }

        /* ── Signoff ── */
        .signoff { margin-top: 28px; display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 16px; font-size: 11px; }
        .signoff-box { border-top: 1px solid #999; padding-top: 6px; text-align: center; }
        .signoff-box .lbl { font-weight: bold; color: #002147; }

        .footer { margin-top: 12px; border-top: 1px solid #ccc; padding-top: 6px; font-size: 10px; color: #666; text-align: right; }

        @media print {
            body { font-size: 10.5px; }
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

    <!-- University Header with Logo -->
    <div class="header">
        <img src="<?= APP_URL ?>/assets/img/logo/logo-black.png" alt="Prime University Logo" class="header-logo">
        <div class="header-text">
            <h2>Prime University</h2>
            <?php if ($sheet['faculty_label'] ?? ''): ?>
            <p><?= h($sheet['faculty_label']) ?></p>
            <?php endif; ?>
            <p><strong>Department of <?= h($sheet['dept_name']) ?></strong></p>
            <?php if ($sheet['program_name']): ?>
            <p><?= h($sheet['program_name']) ?></p>
            <?php endif; ?>
            <div class="sheet-title">MARK SHEET</div>
        </div>
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
        <div class="info-row"><span class="lbl">Date:</span><span><?= date('d M Y') ?></span></div>
    </div>

    <!-- Teacher / Course Instructor bar -->
    <div class="teacher-bar">
        <strong>Course Teacher / Marks Entered By:</strong> <?= h($sheet['creator_name'] ?? '—') ?>
    </div>

    <!-- Grading Scale + Mark Distribution side-by-side -->
    <div class="ref-row">
        <div class="ref-block">
            <div class="ref-block-title">Grading Scale</div>
            <table class="ref-table">
                <thead><tr><th>Marks Range</th><th>Grade</th><th>Point</th></tr></thead>
                <tbody>
                <?php foreach (wf_grading_scale() as [$min, $max, $letter, $point]):
                    $range = ($max === PHP_INT_MAX) ? '&ge;' . $min . '%' : $min . '% &ndash; &lt;' . $max . '%';
                ?>
                <tr><td><?= $range ?></td><td><strong><?= h($letter) ?></strong></td><td><?= number_format($point, 2) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="ref-block">
            <div class="ref-block-title">Mark Distribution</div>
            <table class="ref-table">
                <thead><tr><th class="left">Component</th><th>Max Marks</th></tr></thead>
                <tbody>
                    <?php foreach ($mark_distribution as $dist): ?>
                    <tr><td class="left"><?= h($dist['distribution_name']) ?></td><td><?= h($dist['max_marks']) ?></td></tr>
                    <?php endforeach; ?>
                    <tr class="dist-total-row"><td class="left">Total</td><td><?= $dist_total ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Student Marks -->
    <div style="font-weight:bold;font-size:11px;margin-bottom:4px;color:#002147;border-left:3px solid #002147;padding-left:5px;">Student Performance Records</div>
    <?php if (empty($grades)): ?>
    <p style="color:#888; font-style:italic;">No grades recorded.</p>
    <?php else: ?>
    <table class="marks-table">
        <thead>
            <tr>
                <th rowspan="2" style="width:30px;">SL</th>
                <th rowspan="2" style="min-width:100px;">Student ID</th>
                <th rowspan="2" style="min-width:140px;">Name</th>
                <?php foreach ($mark_distribution as $_pd): ?>
                <th><?= h($_pd['distribution_name']) ?><br><small>/<?= h($_pd['max_marks']) ?></small></th>
                <?php endforeach; ?>
                <th rowspan="2" style="width:48px;">Total</th>
                <th rowspan="2" style="width:48px;">Grade</th>
                <th rowspan="2" style="width:40px;">Point</th>
                <th rowspan="2" style="min-width:120px;">Remarks</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($grades as $idx => $g):
            $_marks = null;
            if (!empty($g['marks_json'])) {
                $_marks = json_decode($g['marks_json'], true);
            }
            if (!is_array($_marks)) {
                $_marks = [$g['attendance'] ?? null, $g['class_test'] ?? null, $g['mid_term'] ?? null, $g['final_exam'] ?? null];
            }
            // Per-segment absent flags
            $_abs_flags = [];
            if (!empty($g['absent_json'])) {
                $decoded = json_decode($g['absent_json'], true);
                if (is_array($decoded)) $_abs_flags = $decoded;
            }
            // Build remarks for Incom rows
            $_remarks = '';
            if ($g['is_absent']) {
                if (!empty($_abs_flags)) {
                    $absent_names = [];
                    foreach ($mark_distribution as $di => $_pd) {
                        if (!empty($_abs_flags[$di])) {
                            $absent_names[] = h($_pd['distribution_name']);
                        }
                    }
                    $_remarks = $absent_names ? implode(', ', $absent_names) . ' – Absent' : 'Absent';
                } else {
                    $_remarks = 'Absent from exam';
                }
            }
        ?>
        <tr class="<?= $g['is_absent'] ? 'absent-row' : '' ?>">
            <td><?= $idx + 1 ?></td>
            <td class="mono"><?= h($g['s_student_id'] ?? $g['student_sid']) ?></td>
            <td class="left"><?= h($g['s_full_name'] ?? $g['student_name']) ?></td>
            <?php foreach ($mark_distribution as $di => $_pd): ?>
            <td><?php
                if (!empty($_abs_flags[$di])) {
                    echo '<span class="abs-cell">Abs</span>';
                } elseif ($_marks[$di] !== null && $_marks[$di] !== '') {
                    echo h($_marks[$di]);
                } else {
                    echo '—';
                }
            ?></td>
            <?php endforeach; ?>
            <td><?php
                $is_fully_absent = $g['is_absent'] && empty($_abs_flags);
                echo $is_fully_absent ? '&mdash;' : h($g['total_marks'] ?? '—');
            ?></td>
            <td><strong><?= h($g['letter_grade'] ?? '—') ?></strong></td>
            <td><?= $g['grade_point'] !== null ? number_format((float)$g['grade_point'], 2) : '—' ?></td>
            <td style="text-align:left;font-size:10px;color:#c0392b;"><?= $_remarks ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- Signoff trail -->
    <div class="signoff">
        <div class="signoff-box">
            <div style="height:35px;"></div>
            <div class="lbl">Course Teacher</div>
            <div style="margin-top:3px;"><?= h($sheet['creator_name'] ?? '') ?></div>
        </div>
        <div class="signoff-box">
            <div style="height:35px;"></div>
            <div class="lbl">Reviewer</div>
            <div style="margin-top:3px;"><?= h($signoffs['reviewer_name'] ?? '') ?></div>
            <?php if ($signoffs['reviewed_at'] ?? null): ?>
            <div style="color:#555;font-size:10px;"><?= date('d M Y', strtotime($signoffs['reviewed_at'])) ?></div>
            <?php endif; ?>
        </div>
        <div class="signoff-box">
            <div style="height:35px;"></div>
            <div class="lbl">Head of Department</div>
            <div style="margin-top:3px;"><?= h($signoffs['hod_name'] ?? '') ?></div>
            <?php if ($signoffs['hod_approved_at'] ?? null): ?>
            <div style="color:#555;font-size:10px;"><?= date('d M Y', strtotime($signoffs['hod_approved_at'])) ?></div>
            <?php endif; ?>
        </div>
        <div class="signoff-box">
            <div style="height:35px;"></div>
            <div class="lbl">Controller of Examinations</div>
            <div style="margin-top:3px;"><?= h($signoffs['publisher_name'] ?? '') ?></div>
            <?php if ($signoffs['published_at'] ?? null): ?>
            <div style="color:#555;font-size:10px;"><?= date('d M Y', strtotime($signoffs['published_at'])) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="footer">
        Printed on <?= date('d M Y, h:i A') ?> &nbsp;|&nbsp; Prime University – Controller of Examinations
    </div>

</div>
</body>
</html>
