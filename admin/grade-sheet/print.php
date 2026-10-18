<?php
/**
 * Grade Sheet – standalone print page (A4 landscape).
 *
 * Renders the department/batch/semester tabulation grade sheet for the
 * Semester Final Examination. Print or Save as PDF from the browser.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('grade-sheet');
require_once __DIR__ . '/helpers.php';

$f = [
    'exam_id'    => (int)($_GET['exam_id']    ?? 0),
    'dept_id'    => (int)($_GET['dept_id']    ?? 0),
    'program_id' => (int)($_GET['program_id'] ?? 0),
    'batch_id'   => (int)($_GET['batch_id']   ?? 0),
    'semester'   => trim((string)($_GET['semester'] ?? '')),
    'shift'      => trim((string)($_GET['shift']    ?? '')),
    'section'    => trim((string)($_GET['section']  ?? '')),
];

// One self-contained grade sheet per batch (separate sheets when multiple
// batches match the selection).
$groups = gs_build_groups($f);

$has_students = false;
foreach ($groups as $g) {
    if (!empty($g['students'])) { $has_students = true; break; }
}

// Exam label for the title block.
$exam_label = '';
if ($f['exam_id'] > 0) {
    try {
        $st = db()->prepare('SELECT exam_name, exam_year FROM ei_exams WHERE id = ?');
        $st->execute([$f['exam_id']]);
        if ($e = $st->fetch()) {
            $exam_label = trim((string)$e['exam_name'] . ' ' . (string)($e['exam_year'] ?? ''));
        }
    } catch (Throwable $_e) {}
}

$rows_per_page = 20;
$date_today    = date('d F Y');

// Batch caption helper, e.g. "62nd (Group-A) (Evening)".
$gs_batch_caption = static function (array $meta): string {
    $caption = (string)$meta['batch_name'];
    $extras = array_filter([
        $meta['section'] !== '' ? 'Group-' . $meta['section'] : '',
        $meta['shift'] !== ''   ? $meta['shift'] : '',
    ]);
    if ($extras) {
        $caption = trim($caption . ' (' . implode(') (', $extras) . ')');
    }
    return $caption;
};

$page_title = 'Grade Sheet' . ($exam_label !== '' ? ' – ' . $exam_label : '');
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
            background: #fff; width: 100%; max-width: 1160px; min-height: 800px;
            padding: 16px 22px; margin: 0 auto 20px;
            box-shadow: 0 2px 12px rgba(0,0,0,.15);
            position: relative;
        }

        .univ-header { text-align: center; border-bottom: 2px solid #1e3a5f; padding-bottom: 5px; margin-bottom: 6px; }
        .univ-header img.logo { height: 44px; margin-bottom: 2px; }
        .univ-name { font-size: 15px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #1e3a5f; }
        .univ-sub { font-size: 10px; color: #555; margin-top: 1px; }
        .page-indicator { position: absolute; top: 16px; right: 22px; font-size: 10px; color: #444; font-weight: 600; }

        .doc-title {
            text-align: center; font-size: 13px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .06em;
            margin: 8px 0 2px;
        }
        .doc-subtitle { text-align: center; font-size: 11px; font-weight: 600; color: #333; margin-bottom: 8px; }

        .meta-grid {
            display: flex; flex-wrap: wrap; gap: 4px 28px;
            font-size: 11px; margin-bottom: 8px;
            border: 1px solid #ddd; padding: 6px 10px; border-radius: 4px;
        }
        .meta-grid span { color: #555; }
        .meta-grid strong { color: #1e3a5f; }
        .date-issued { text-align: right; font-size: 10px; color: #444; font-weight: 600; margin-bottom: 4px; }

        table.grade-table { width: 100%; border-collapse: collapse; font-size: 10.5px; }
        table.grade-table th {
            background: #1e3a5f; color: #fff; padding: 5px; text-align: center;
            border: 1px solid #1e3a5f; font-size: 9.5px; text-transform: uppercase;
            letter-spacing: .02em; white-space: nowrap;
        }
        table.grade-table th.left { text-align: left; }
        table.grade-table td { border: 1px solid #ccc; padding: 4px 5px; vertical-align: middle; }
        table.grade-table td.center { text-align: center; }
        table.grade-table tbody tr:nth-child(even) td { background: #f5f7fc; }

        .sgpa-cell { font-weight: 700; color: #1e3a5f; }
        .remark-fail { color: #b91c1c; font-weight: 700; }
        .grade-dash { color: #bbb; letter-spacing: 1px; }
        .remark-absent { color: #6b7280; font-style: italic; }

        .sig-section { margin-top: 22px; display: flex; justify-content: space-between; gap: 8px; }
        .sig-block { text-align: center; flex: 1; }
        .sig-line { border-top: 1px solid #555; margin-top: 30px; padding-top: 3px; font-size: 9.5px; color: #374151; font-weight: 600; }
        .sig-subtitle { font-size: 9px; color: #6b7280; margin-top: 2px; }

        @media print {
            @page { size: A4 landscape; margin: 8mm; }
            .screen-controls { display: none !important; }
            body { background: #fff; }
            .print-wrapper { padding: 0; }
            .sheet-page { box-shadow: none; margin: 0; min-height: unset; width: 100%; max-width: none; padding: 0; }
            .sheet-page + .sheet-page { page-break-before: always; }
        }
    </style>
</head>
<body>

<div class="screen-controls">
    <button onclick="window.print()">🖨 Print / Save as PDF</button>
    <a href="<?= APP_URL ?>/grade-sheet/index.php?exam_id=<?= (int)$f['exam_id'] ?>" class="back-btn">← Back</a>
    <span><?= h($exam_label) ?></span>
</div>

<div class="print-wrapper">

<?php if (!$has_students): ?>
<div class="sheet-page">
    <div class="univ-header">
        <img src="<?= LOGO_URL ?>" alt="Prime University" class="logo" onerror="this.style.display='none'">
        <div class="univ-name">Prime University</div>
        <div class="univ-sub">114/116 Mazar Road, Mirpur-1, Dhaka-1216, Bangladesh &nbsp;|&nbsp; www.primeuniversity.ac.bd</div>
    </div>
    <div class="doc-title">Grade Sheet</div>
    <div class="doc-subtitle">Semester Final Examination</div>
    <p style="text-align:center;color:#888;margin-top:40px;">No published grades found for this selection.</p>
</div>
<?php else: ?>

<?php foreach ($groups as $group): ?>
<?php
    $meta     = $group['meta'];
    $courses  = $group['courses'];
    $students = array_values($group['students']);
    if (empty($students)) continue;

    // Paginate this batch's students; each batch starts its own page sequence.
    $pages = array_chunk($students, $rows_per_page);
    if (empty($pages)) $pages = [[]];
    $total_pages   = count($pages);
    $batch_caption = $gs_batch_caption($meta);
?>
<?php foreach ($pages as $pi => $page_students): ?>
<div class="sheet-page">
    <div class="page-indicator">Page <?= $pi + 1 ?> of <?= $total_pages ?></div>

    <div class="univ-header">
        <img src="<?= LOGO_URL ?>" alt="Prime University" class="logo" onerror="this.style.display='none'">
        <div class="univ-name">Prime University</div>
        <div class="univ-sub">114/116 Mazar Road, Mirpur-1, Dhaka-1216, Bangladesh &nbsp;|&nbsp; www.primeuniversity.ac.bd</div>
    </div>

    <div class="doc-title">Grade Sheet</div>
    <div class="doc-subtitle">Semester Final Examination</div>

    <div class="meta-grid">
        <?php if ($batch_caption !== ''): ?><div><span>Batch: </span><strong><?= h($batch_caption) ?></strong></div><?php endif; ?>
        <?php if ($meta['semester'] !== ''): ?><div><span>Semester: </span><strong><?= h($meta['semester']) ?></strong></div><?php endif; ?>
        <?php if ($meta['dept_name'] !== ''): ?><div><span>Department: </span><strong>Dept. of <?= h($meta['dept_name']) ?></strong></div><?php endif; ?>
        <?php if ($meta['program_name'] !== ''): ?><div><span>Program: </span><strong><?= h($meta['program_name']) ?></strong></div><?php endif; ?>
        <?php if ($meta['faculty_label'] !== ''): ?><div><span><?= h($meta['faculty_label']) ?></span></div><?php endif; ?>
    </div>

    <div class="date-issued">Date of Issue: <?= $date_today ?></div>

    <table class="grade-table">
        <thead>
            <tr>
                <th class="left" style="width:34px;">SL. No.</th>
                <th class="left" style="min-width:100px;">Student's ID</th>
                <th class="left" style="min-width:150px;">Name of the Student</th>
                <?php foreach ($courses as $c): ?>
                <th title="<?= h($c['name']) ?>"><?= h($c['code']) ?></th>
                <?php endforeach; ?>
                <th style="min-width:70px;">SGPA / Remarks</th>
            </tr>
        </thead>
        <tbody>
        <?php $sl = $pi * $rows_per_page + 1; foreach ($page_students as $sd): ?>
            <tr>
                <td class="center"><?= $sl++ ?></td>
                <td><strong><?= h($sd['sid']) ?></strong></td>
                <td><?= h($sd['name']) ?></td>
                <?php foreach ($courses as $c): ?>
                <?php $ge = $sd['grades'][$c['code']] ?? null; ?>
                <td class="center">
                    <?php if ($ge === null || $ge['is_absent']): ?>
                        <span class="grade-dash">−−−−−</span>
                    <?php else: ?>
                        <?= h(gs_grade_label($ge['letter'])) ?>
                    <?php endif; ?>
                </td>
                <?php endforeach; ?>
                <td class="center">
                    <?php if ($sd['sgpa'] !== null): ?>
                        <span class="sgpa-cell"><?= h($sd['sgpa']) ?></span>
                        <?php if (($sd['remark'] ?? '') === 'F'): ?>
                            <span class="remark-fail">(F)</span>
                        <?php endif; ?>
                    <?php elseif ($sd['remark'] === 'Absent'): ?>
                        <span class="remark-absent">Absent</span>
                    <?php else: ?>
                        <span class="remark-absent"><?= h((string)$sd['remark']) ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($pi + 1 === $total_pages): ?>
    <div class="sig-section">
        <div class="sig-block">
            <div class="sig-line">Examination Committee</div>
            <div class="sig-subtitle"><?= $meta['dept_name'] !== '' ? 'Dept. of ' . h($meta['dept_name']) : 'Department' ?></div>
        </div>
        <div class="sig-block">
            <div class="sig-line">Dean</div>
            <div class="sig-subtitle"><?= $meta['faculty_label'] !== '' ? h($meta['faculty_label']) : 'Faculty' ?></div>
        </div>
        <div class="sig-block">
            <div class="sig-line">Program Coordinator</div>
            <div class="sig-subtitle"><?= $meta['program_name'] !== '' ? h($meta['program_name']) : 'Program' ?></div>
        </div>
        <div class="sig-block">
            <div class="sig-line">Controller of Examinations</div>
            <div class="sig-subtitle">Office of the Controller</div>
        </div>
    </div>
    <?php endif; ?>

</div>
<?php endforeach; ?>
<?php endforeach; ?>

<?php endif; ?>

</div>

</body>
</html>
