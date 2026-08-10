<?php
/**
 * Print view for a workflow mark sheet.
 * Admin-only; no published-status restriction (staff can print any stage).
 *
 * Layout: compact single-A4-page design, 12px Arial.
 * - Header: Prime University > Faculty > Department > Program > Batch(es).
 * - Mid Term exams print only the Mid Term mark columns and hide letter
 *   grades / grade points (the result is not final until the final exam).
 * - Final (or unspecified) exams print the full distribution with grades.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/workflow-helpers.php';

$id     = (int)($_GET['id'] ?? 0);
$sheet  = wf_get_sheet($id);
$grades = wf_get_grades($id);

// ── Exam name + exam mode (mid / final / all) ───────────────────────────
$exam_label = '';
if (!empty($sheet['exam_id'])) {
    try {
        $eq = db()->prepare('SELECT exam_name, exam_year FROM ei_exams WHERE id = ? LIMIT 1');
        $eq->execute([(int)$sheet['exam_id']]);
        if ($erow = $eq->fetch()) {
            $exam_label = trim($erow['exam_name'] . ' ' . $erow['exam_year']);
        }
    } catch (Throwable $_e) {}
}
$_exam_lc  = strtolower($exam_label);
$_has_mid  = (bool)preg_match('/mid\s*-?\s*term|midterm/', $_exam_lc);
$_has_fin  = (strpos($_exam_lc, 'final') !== false);
$exam_mode = ($_has_mid && !$_has_fin) ? 'mid' : (($_has_fin && !$_has_mid) ? 'final' : 'all');
// Mid Term only → no letter grade / grade point yet (result not final)
$show_grades = ($exam_mode !== 'mid');

// ── Batches of the marked students (comma separated) ───────────────────────
$batches = [];
try {
    $bq = db()->prepare(
        "SELECT DISTINCT s.batch
           FROM result_sheet_grades g
           JOIN students s ON s.id = g.student_id
          WHERE g.sheet_id = ? AND s.batch IS NOT NULL AND s.batch <> ''
          ORDER BY s.batch ASC"
    );
    $bq->execute([$id]);
    $batches = array_column($bq->fetchAll(PDO::FETCH_ASSOC), 'batch');
} catch (Throwable $_e) {}
$batch_label = implode(', ', $batches);

// ── Per-grade batch map + main (predominant) batch of the sheet ────────────
$grade_batches = [];
try {
    $sbq = db()->prepare(
        'SELECT g.id AS grade_id, s.batch
           FROM result_sheet_grades g
           LEFT JOIN students s ON s.id = g.student_id
          WHERE g.sheet_id = ?'
    );
    $sbq->execute([$id]);
    foreach ($sbq->fetchAll(PDO::FETCH_ASSOC) as $_row) {
        $grade_batches[(int)$_row['grade_id']] = (string)($_row['batch'] ?? '');
    }
} catch (Throwable $_e) {}
// The sheet's own batch = the batch with the most students on the sheet.
$_batch_counts = array_count_values(array_filter($grade_batches, fn($b) => $b !== ''));
arsort($_batch_counts);
$main_batch = $_batch_counts ? (string)array_key_first($_batch_counts) : '';

// ── Optional batch filter (?batch=) ────────────────────────────────────────
// When students from a different batch were registered in this course offer,
// each batch is printed on its own separate sheet.
$batch_filter = trim((string)($_GET['batch'] ?? ''));
if ($batch_filter !== '') {
    $grades = array_values(array_filter(
        $grades,
        function ($g) use ($grade_batches, $batch_filter, $main_batch) {
            $b = $grade_batches[(int)$g['id']] ?? '';
            // Students without a batch on record (or manually added rows with
            // no linked student profile) print with the sheet's main batch, so
            // their marks are never dropped from every printout.
            if ($b === '') $b = $main_batch;
            return $b === $batch_filter;
        }
    ));
    $batch_label = $batch_filter;
}

// ── Shift / Section of the marked students (printed only when available) ────
$shift_label = '';
$section_label = '';
foreach (['shift' => 'shift_label', 'section' => 'section_label'] as $_col => $_var) {
    try {
        $_sql    = "SELECT DISTINCT s.`$_col` AS v
                      FROM result_sheet_grades g
                      JOIN students s ON s.id = g.student_id
                     WHERE g.sheet_id = ? AND s.`$_col` IS NOT NULL AND s.`$_col` <> ''";
        $_params = [$id];
        if ($batch_filter !== '') { $_sql .= ' AND s.batch = ?'; $_params[] = $batch_filter; }
        $_q = db()->prepare($_sql . ' ORDER BY v ASC');
        $_q->execute($_params);
        $$_var = implode(', ', array_column($_q->fetchAll(PDO::FETCH_ASSOC), 'v'));
    } catch (Throwable $_e) {}
}

// ── Mark distribution: prefer curriculum config, fall back to legacy defaults ─
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

// Guard against distribution drift: if any grade stored more mark components than
// the resolved distribution defines (e.g. the curriculum's mark distribution was
// edited after marks were entered), extend the column list so no entered mark is
// silently hidden from the printout.
$max_marks_len = 0;
foreach ($grades as $_g) {
    if (!empty($_g['marks_json'])) {
        $_decoded = json_decode($_g['marks_json'], true);
        if (is_array($_decoded)) {
            $max_marks_len = max($max_marks_len, count($_decoded));
        }
    }
}
while (count($mark_distribution) < $max_marks_len) {
    $mark_distribution[] = [
        'distribution_name' => 'Part ' . (count($mark_distribution) + 1),
        'max_marks'         => '',
    ];
}

// ── Visible columns ────────────────────────────────────────────────────
// Mid Term exam → only Mid Term components; Final / other → full distribution.
// Keys preserve the original distribution index so marks_json lines up.
$visible_dist = [];
foreach ($mark_distribution as $di => $d) {
    if ($exam_mode === 'mid' && !preg_match('/mid/i', (string)$d['distribution_name'])) continue;
    $visible_dist[$di] = $d;
}
if (empty($visible_dist)) $visible_dist = $mark_distribution; // safety net

// Exam title + "Mark Sheet", e.g. "Mid Term Examination Mark Sheet".
// The trailing semester/year (e.g. "Summer 2026") is stripped — the Semester
// is already shown in the info section below.
$_exam_title = preg_replace('/\s*\b(spring|summer|fall|autumn|winter)\b\s*-?\s*(\d{4})?\s*$/i', '', $exam_label);
$_exam_title = trim((string)preg_replace('/\s*\b\d{4}\b\s*$/', '', (string)$_exam_title));
$sheet_title_txt = ($_exam_title !== '') ? $_exam_title . ' Mark Sheet' : 'Mark Sheet';
$page_title      = h($sheet['subject_title']);
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
        .page { max-width: 1140px; margin: 0 auto; padding: 16px; }

        /* ── Header ── */
        .header { position: relative; display: flex; align-items: center; justify-content: center; border-bottom: 2px solid #002147; padding-bottom: 8px; margin-bottom: 8px; min-height: 64px; }
        .header-logo { position: absolute; left: 0; top: 50%; transform: translateY(-50%); width: 140px; height: auto; object-fit: contain; }
        .header-text { text-align: center; line-height: 1.5; }
        .header-text h2 { font-size: 22px; color: #002147; margin-bottom: 3px; font-weight: bold; }
        .header-text p  { font-size: 14px; color: #333; margin: 2px 0; }
        .header-text .sheet-title { font-size: 14px; font-weight: bold; margin-top: 6px; letter-spacing: 1px; color: #002147; }

        /* ── Info grid: 3 columns ── */
        .info-grid { display: grid; grid-template-columns: 1.4fr 1fr 1fr; gap: 0 24px; margin-bottom: 10px; font-size: 12px; line-height: 1.6; align-items: start; }
        .info-col  { display: flex; flex-direction: column; gap: 5px; }
        .info-row  { display: flex; gap: 5px; }
        .info-row .lbl { font-weight: bold; min-width: 100px; color: #002147; flex-shrink: 0; }
        .blank-line { display: inline-block; min-width: 130px; border-bottom: 1px dotted #555; }

        /* ── Grading scale strip (horizontal, only when grades are printed) ── */
        .scale-strip { width: 100%; border-collapse: collapse; font-size: 11px; margin-bottom: 8px; }
        .scale-strip th, .scale-strip td { border: 1px solid #ccc; padding: 1px 3px; text-align: center; }
        .scale-strip th { background: #002147; color: #fff; font-weight: bold; }

        /* ── Marks table ── */
        .marks-table { width: 100%; border-collapse: collapse; font-size: 12px; line-height: 1.6; }
        .marks-table th, .marks-table td { border: 1px solid #bbb; padding: 4px 6px; text-align: center; vertical-align: middle; }
        .marks-table thead th { background: #002147; color: #fff; }
        .marks-table thead th small { font-weight: normal; font-size: 10px; }
        .marks-table tbody tr:nth-child(even) { background: #f9f9f9; }
        .marks-table td.left { text-align: left; }
        .marks-table td.mono { font-family: monospace; }
        .absent-row td { background: #fff3cd !important; }
        .abs-cell { color: #c0392b; font-weight: bold; font-size: 11px; }

        /* ── Signatures ── */
        .signoff { margin-top: 48px; display: grid; grid-template-columns: 1fr 1fr; column-gap: 160px; font-size: 12px; }
        .signoff-box { border-top: 1px solid #999; padding-top: 5px; text-align: center; }
        .signoff-box .lbl { font-weight: bold; color: #002147; }

        .footer { margin-top: 10px; border-top: 1px solid #ccc; padding-top: 4px; font-size: 10px; color: #666; text-align: right; }

        @media print {
            @page { size: A4 portrait; margin: 10mm; }
            body { font-size: 12px; }
            .no-print { display: none !important; }
            .page { max-width: 100%; padding: 0; }
            .header, .marks-table thead th, .scale-strip th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .marks-table tr, .signoff { page-break-inside: avoid; }
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
        <img src="<?= APP_URL ?>/../assets/img/logo/logo-black.png" alt="Prime University Logo" class="header-logo">
        <div class="header-text">
            <h2>Prime University</h2>
            <?php if ($sheet['faculty_label'] ?? ''): ?>
            <p><?= h($sheet['faculty_label']) ?></p>
            <?php endif; ?>
            <?php // Avoid "Department of Department of ..." when dept name already has the prefix
            $dept_line = preg_match('/^\s*(department|dept\.?)\b/i', (string)$sheet['dept_name'])
                ? $sheet['dept_name']
                : 'Department of ' . $sheet['dept_name']; ?>
            <p><strong><?= h($dept_line) ?></strong></p>
            <?php if ($sheet['program_name']): ?>
            <p><?= h($sheet['program_name']) ?></p>
            <?php endif; ?>
            <div class="sheet-title"><?= h($sheet_title_txt) ?></div>
        </div>
    </div>

    <!-- Sheet Info: 3 columns -->
    <div class="info-grid">
        <div class="info-col">
            <div class="info-row"><span class="lbl">Batch:</span><span><?= h($batch_label !== '' ? $batch_label : '—') ?></span></div>
            <div class="info-row"><span class="lbl">Course Title:</span><span><?= h($sheet['subject_title']) ?></span></div>
            <div class="info-row"><span class="lbl">Course Code:</span><span><?= h($sheet['subject_code'] ?: '—') ?></span></div>
        </div>
        <div class="info-col">
            <div class="info-row"><span class="lbl">Credit:</span><span><?= h($sheet['credits'] ?: '—') ?></span></div>
            <div class="info-row"><span class="lbl">Course Teacher:</span><span><?= h($sheet['creator_name'] ?? '—') ?></span></div>
            <?php if ($shift_label !== ''): ?>
            <div class="info-row"><span class="lbl">Shift:</span><span><?= h($shift_label) ?></span></div>
            <?php endif; ?>
        </div>
        <div class="info-col">
            <div class="info-row"><span class="lbl">Semester:</span><span><?= h($sheet['semester']) ?></span></div>
            <div class="info-row"><span class="lbl">Exam Date:</span><span class="blank-line">&nbsp;</span></div>
            <?php if ($section_label !== ''): ?>
            <div class="info-row"><span class="lbl">Section:</span><span><?= h($section_label) ?></span></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($show_grades): ?>
    <!-- Grading scale (compact horizontal strip) -->
    <table class="scale-strip">
        <tr>
            <th style="text-align:left;padding-left:5px;">Grade</th>
            <?php foreach (wf_grading_scale() as [$min, $max, $letter, $point]): ?>
            <td><strong><?= h($letter) ?></strong> (<?= number_format($point, 2) ?>)</td>
            <?php endforeach; ?>
        </tr>
        <tr>
            <th style="text-align:left;padding-left:5px;">Marks %</th>
            <?php foreach (wf_grading_scale() as [$min, $max, $letter, $point]): ?>
            <td><?= ($max === PHP_INT_MAX) ? '&ge;' . $min : $min . ' &ndash; &lt;' . $max ?></td>
            <?php endforeach; ?>
        </tr>
    </table>
    <?php else: ?>
    <p style="font-size:12px;color:#555;margin-bottom:8px;line-height:1.6;font-style:italic;">
        Mid Term result — letter grades and grade points will be issued after the final examination.
    </p>
    <?php endif; ?>

    <!-- Student Marks -->
    <?php if (empty($grades)): ?>
    <p style="color:#888; font-style:italic;">No grades recorded.</p>
    <?php else: ?>
    <table class="marks-table">
        <thead>
            <tr>
                <th style="width:28px;">SL</th>
                <th style="min-width:95px;">Student ID</th>
                <th style="min-width:135px;">Name</th>
                <?php foreach ($visible_dist as $_pd): ?>
                <th><?= h($_pd['distribution_name']) ?><?php if ($_pd['max_marks'] !== '' && $_pd['max_marks'] !== null): ?><br><small>/<?= h($_pd['max_marks']) ?></small><?php endif; ?></th>
                <?php endforeach; ?>
                <?php if ($exam_mode !== 'mid'): ?>
                <th style="width:46px;">Total</th>
                <?php endif; ?>
                <?php if ($show_grades): ?>
                <th style="width:46px;">Grade</th>
                <th style="width:40px;">Point</th>
                <?php endif; ?>
                <th style="min-width:110px;">Remarks</th>
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
                    $_remarks = $absent_names ? 'Absent from ' . implode(', ', $absent_names) . ' examination' : 'Absent from examination';
                } else {
                    $_remarks = 'Absent from examination';
                }
            }
        ?>
        <tr class="<?= $g['is_absent'] ? 'absent-row' : '' ?>">
            <td><?= $idx + 1 ?></td>
            <td class="mono"><?= h($g['s_student_id'] ?? $g['student_sid']) ?></td>
            <td class="left"><?= h($g['s_full_name'] ?? $g['student_name']) ?></td>
            <?php foreach ($visible_dist as $di => $_pd): ?>
            <td><?php
                if (!empty($_abs_flags[$di])) {
                    echo '<span class="abs-cell">Abs</span>';
                } elseif (isset($_marks[$di]) && $_marks[$di] !== null && $_marks[$di] !== '') {
                    echo h($_marks[$di]);
                } else {
                    echo '—';
                }
            ?></td>
            <?php endforeach; ?>
            <?php if ($exam_mode !== 'mid'): ?>
            <td><?php
                $is_fully_absent = $g['is_absent'] && empty($_abs_flags);
                echo $is_fully_absent ? '&mdash;' : h($g['total_marks'] ?? '—');
            ?></td>
            <?php endif; ?>
            <?php if ($show_grades): ?>
            <td><strong><?= h($g['letter_grade'] ?? '—') ?></strong></td>
            <td><?= $g['grade_point'] !== null ? number_format((float)$g['grade_point'], 2) : '—' ?></td>
            <?php endif; ?>
            <td style="text-align:left;font-size:11px;color:#c0392b;"><?= $_remarks ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- Signatures (manual signing – no digital names) -->
    <div class="signoff">
        <div class="signoff-box">
            <div style="height:34px;"></div>
            <div class="lbl">Course Teacher</div>
        </div>
        <div class="signoff-box">
            <div style="height:34px;"></div>
            <div class="lbl">Head of the Department</div>
        </div>
    </div>

    <div class="footer">
        Printed on <?= date('d M Y') ?>
    </div>

</div>
</body>
</html>
