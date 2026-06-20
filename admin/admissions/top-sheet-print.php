<?php
/**
 * Admissions Top Sheet – Printable / PDF View
 *
 * Standalone page (no admin layout).
 * Formats the enrollment summary for print or browser-based PDF download.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

auth_check();
require_access('admissions');

// ── Date filter ────────────────────────────────────────────────────────────────
$today     = date('Y-m-d');
$date_from = trim($_GET['date_from'] ?? $today);
$date_to   = trim($_GET['date_to']   ?? $today);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) { $date_from = $today; }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   { $date_to   = $today; }
if ($date_to < $date_from) { $date_to = $date_from; }

$is_single_day = ($date_from === $date_to);

// ── Settings ───────────────────────────────────────────────────────────────────
$semester_label  = adm_get_setting('top_sheet_semester_label',  'Summer Semester 2026');
$admission_label = adm_get_setting('top_sheet_admission_label', 'Admission in Summer 2026');

// ── Load program mappings ──────────────────────────────────────────────────────
$map_rows = [];
try {
    $map_rows = db()->query(
        'SELECT program_id, short_label, full_name, sort_order, is_visible
         FROM admissions_top_sheet_programs
         ORDER BY sort_order ASC, id ASC'
    )->fetchAll();
} catch (\Throwable $e) { /* migration not yet run */ }

// ── Query admission counts ─────────────────────────────────────────────────────
try {
    $stmt = db()->prepare(
        "SELECT
             a.program_id,
             p.program_name,
             SUM(CASE WHEN DATE(a.updated_at) < ?            THEN 1 ELSE 0 END) AS prev_count,
             SUM(CASE WHEN DATE(a.updated_at) BETWEEN ? AND ? THEN 1 ELSE 0 END) AS range_count,
             SUM(CASE WHEN DATE(a.updated_at) <= ?           THEN 1 ELSE 0 END) AS total_count
         FROM admissions_applications a
         LEFT JOIN dept_academic_programs p ON p.id = a.program_id
         WHERE a.status = 'admission_complete'
           AND DATE(a.updated_at) <= ?
         GROUP BY a.program_id, p.program_name"
    );
    $stmt->execute([$date_from, $date_from, $date_to, $date_to, $date_to]);
    $raw_rows = $stmt->fetchAll();
} catch (\Throwable $e) {
    $raw_rows = [];
}

$data_by_program = [];
foreach ($raw_rows as $r) {
    $data_by_program[(int)$r['program_id']] = $r;
}

// Merge mapped + unmapped
$report_rows = [];
foreach ($map_rows as $m) {
    if (!$m['is_visible']) { continue; }
    $pid = (int)$m['program_id'];
    $r   = $data_by_program[$pid] ?? null;
    $report_rows[] = [
        'short_label' => $m['short_label'],
        'full_name'   => $m['full_name'] ?? '',
        'sort_order'  => (int)$m['sort_order'],
        'prev_count'  => $r ? (int)$r['prev_count']  : 0,
        'range_count' => $r ? (int)$r['range_count'] : 0,
        'total_count' => $r ? (int)$r['total_count'] : 0,
    ];
    unset($data_by_program[$pid]);
}
foreach ($data_by_program as $pid => $r) {
    if ((int)$r['total_count'] === 0) { continue; }
    $report_rows[] = [
        'short_label' => $r['program_name'] ?: '(Program #' . $pid . ')',
        'full_name'   => '',
        'sort_order'  => 9999,
        'prev_count'  => (int)$r['prev_count'],
        'range_count' => (int)$r['range_count'],
        'total_count' => (int)$r['total_count'],
    ];
}
usort($report_rows, fn($a, $b) => $a['sort_order'] <=> $b['sort_order']);

$grand_prev  = array_sum(array_column($report_rows, 'prev_count'));
$grand_range = array_sum(array_column($report_rows, 'range_count'));
$grand_total = array_sum(array_column($report_rows, 'total_count'));

// Legend rows
$legend = array_filter($report_rows, fn($r) => $r['full_name'] !== '');

// ── Logo ───────────────────────────────────────────────────────────────────────
$logo_path = dirname(dirname(__DIR__)) . '/assets/img/logo/logo-black-sm.png';
$logo_uri  = '';
if (is_file($logo_path) && is_readable($logo_path)) {
    $bytes    = file_get_contents($logo_path);
    if ($bytes !== false) {
        $logo_uri = 'data:image/png;base64,' . base64_encode($bytes);
    }
}

// ── Formatted date label ──────────────────────────────────────────────────────
function ordinal(int $n): string {
    $s = ['th','st','nd','rd'];
    $v = $n % 100;
    return $n . ($s[($v - 20) % 10] ?? $s[$v] ?? $s[0]);
}
$date_label = $is_single_day
    ? ordinal((int)date('j', strtotime($date_from))) . ' ' . date('F Y', strtotime($date_from))
    : date('d M Y', strtotime($date_from)) . ' – ' . date('d M Y', strtotime($date_to));

$col_prev  = $is_single_day ? 'Previous Day' : 'Before Period';
$col_range = $is_single_day ? 'Today'        : 'This Period';

// ── Back URL ──────────────────────────────────────────────────────────────────
$back_qs = http_build_query(['date_from' => $date_from, 'date_to' => $date_to]);
$back_url = APP_URL . '/admissions/top-sheet.php?' . $back_qs;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admission Top Sheet – <?= h($semester_label) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Times New Roman', Times, serif;
            font-size: 12px;
            background: #f0f2f5;
            color: #000;
        }

        /* ── Screen controls bar ── */
        .screen-controls {
            position: fixed; top: 0; left: 0; right: 0; z-index: 999;
            background: #1e3a5f; color: #fff;
            padding: 8px 20px;
            display: flex; align-items: center; gap: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,.3);
        }
        .screen-controls .ctrl-title {
            font-family: Arial, sans-serif;
            font-size: 13px; font-weight: 600;
            margin-right: auto;
        }
        .screen-controls button,
        .screen-controls a {
            font-family: Arial, sans-serif;
            font-size: 12px;
            background: #2563eb; color: #fff;
            border: none; padding: 5px 14px;
            border-radius: 5px; cursor: pointer;
            text-decoration: none;
            display: inline-flex; align-items: center; gap: 5px;
        }
        .screen-controls a.back-btn { background: #64748b; }
        .screen-controls .hint {
            font-family: Arial, sans-serif;
            font-size: 11px; opacity: .75;
        }

        /* ── Page wrapper ── */
        .print-wrapper {
            padding: 60px 16px 30px;
            display: flex;
            justify-content: center;
        }

        /* ── A4 page ── */
        .report-page {
            background: #fff;
            width: 580px;
            padding: 28px 28px 24px;
            box-shadow: 0 2px 16px rgba(0,0,0,.15);
            border-radius: 2px;
        }

        /* ── University header ── */
        .univ-header {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding-bottom: 8px;
            border-bottom: 2px solid #1e3a5f;
            margin-bottom: 10px;
        }
        .univ-header .logo-wrap {
            flex: 0 0 48px;
        }
        .univ-header .logo-wrap img {
            width: 48px;
            height: auto;
            display: block;
        }
        .univ-header .logo-wrap .placeholder-crest {
            width: 44px; height: 52px;
            border: 1px solid #333;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            font-size: 7px; line-height: 1.3; font-weight: bold;
            letter-spacing: .5px;
            color: #000;
        }
        .univ-header .univ-text {
            flex: 1;
            text-align: center;
        }
        .univ-name {
            font-size: 16px;
            font-weight: bold;
            letter-spacing: .04em;
            color: #1e3a5f;
            text-transform: uppercase;
        }
        .univ-address {
            font-size: 11px;
            color: #444;
            margin-top: 1px;
        }
        .report-title {
            font-size: 13px;
            font-weight: bold;
            margin-top: 5px;
            color: #1e3a5f;
        }
        .report-subtitle {
            font-size: 12px;
            margin-top: 1px;
        }

        /* ── Meta row ── */
        .meta-row {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            font-size: 11px;
            margin-bottom: 6px;
            color: #333;
        }

        /* ── Enrollment table ── */
        .enroll-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11.5px;
        }
        .enroll-table th,
        .enroll-table td {
            border: 1px solid #222;
            padding: 3px 7px;
            vertical-align: middle;
        }
        .enroll-table thead th {
            text-align: center;
            font-size: 11.5px;
            background: #fff;
        }
        .enroll-table thead th.prog-head {
            text-align: left;
            font-weight: normal;
            font-size: 12px;
        }
        .enroll-table thead .span-header {
            font-weight: bold;
            font-size: 12px;
            text-align: center;
        }
        .enroll-table tbody td.prog-cell {
            text-align: left;
        }
        .enroll-table tbody td.num-cell {
            text-align: center;
        }
        .enroll-table tfoot tr td {
            font-weight: bold;
            font-size: 12px;
            text-align: center;
        }
        .enroll-table tfoot tr td:first-child {
            text-align: center;
        }
        .enroll-table tbody tr:hover td { background: transparent; }

        /* ── Zero values ── */
        .empty-cell { color: #bbb; }

        /* ── Legend ── */
        .legend-section {
            margin-top: 12px;
            font-size: 10px;
            color: #333;
            border-top: 1px solid #ccc;
            padding-top: 8px;
        }
        .legend-section .legend-title {
            font-weight: bold;
            font-size: 10.5px;
            margin-bottom: 4px;
            color: #1e3a5f;
        }
        .legend-item {
            margin-bottom: 2px;
        }
        .legend-item strong { color: #000; }

        /* ── Signature section ── */
        .sig-section {
            margin-top: 24px;
            display: flex;
            justify-content: space-between;
        }
        .sig-block {
            text-align: center;
            width: 38%;
        }
        .sig-line {
            border-top: 1px solid #555;
            padding-top: 3px;
            font-size: 9.5px;
            color: #333;
        }

        /* ── Print media ── */
        @media print {
            @page {
                size: A4 portrait;
                margin: 12mm 10mm;
            }
            body { background: #fff; }
            .screen-controls { display: none !important; }
            .print-wrapper { padding: 0; display: block; }
            .report-page {
                box-shadow: none;
                width: 100%;
                padding: 0;
                border-radius: 0;
            }
        }
    </style>
</head>
<body>

<!-- Screen controls (hidden on print) -->
<div class="screen-controls">
    <span class="ctrl-title">Admission Top Sheet</span>
    <span class="hint">Use Ctrl+P / ⌘P → Save as PDF to download</span>
    <button onclick="window.print()">&#128438; Print / Save PDF</button>
    <a class="back-btn" href="<?= h($back_url) ?>">&#8592; Back</a>
</div>

<div class="print-wrapper">
<div class="report-page">

    <!-- University Header -->
    <div class="univ-header">
        <div class="logo-wrap">
            <?php if ($logo_uri !== ''): ?>
                <img src="<?= $logo_uri ?>" alt="Prime University">
            <?php else: ?>
                <div class="placeholder-crest">
                    <span>PRIME</span>
                    <span>CREST</span>
                </div>
            <?php endif; ?>
        </div>
        <div class="univ-text">
            <div class="univ-name">Prime University</div>
            <div class="univ-address">114/116, Mazar Road, Mirpur-1, Dhaka-1216 &nbsp;|&nbsp; Phone: 0241002435</div>
            <div class="report-title">Admission Statement of Enrollment of Students</div>
            <div class="report-subtitle"><?= h($semester_label) ?></div>
        </div>
    </div>

    <!-- Meta row -->
    <div class="meta-row">
        <span><?= h(adm_get_setting('top_sheet_months', '4')) ?> months</span>
        <span>Date: <?= h($date_label) ?></span>
    </div>

    <!-- Enrollment Table -->
    <table class="enroll-table">
        <thead>
            <tr>
                <th class="prog-head" style="width:46%"></th>
                <th colspan="3" class="span-header"><?= h($admission_label) ?></th>
            </tr>
            <tr>
                <th class="prog-head" style="font-weight:normal;">Program</th>
                <th style="width:18%; font-weight:normal;"><?= h($col_prev) ?></th>
                <th style="width:18%; font-weight:normal;"><?= h($col_range) ?></th>
                <th style="width:18%; font-weight:normal;">Total</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($report_rows)): ?>
            <tr>
                <td colspan="4" style="text-align:center; padding:8px; color:#888; font-style:italic;">
                    No completed admissions for this period.
                </td>
            </tr>
        <?php else: ?>
            <?php foreach ($report_rows as $row): ?>
            <tr>
                <td class="prog-cell"><?= h($row['short_label']) ?></td>
                <td class="num-cell <?= $row['prev_count']  === 0 ? 'empty-cell' : '' ?>">
                    <?= $row['prev_count']  > 0 ? str_pad((string)$row['prev_count'],  2, '0', STR_PAD_LEFT) : '' ?>
                </td>
                <td class="num-cell <?= $row['range_count'] === 0 ? 'empty-cell' : '' ?>">
                    <?= $row['range_count'] > 0 ? str_pad((string)$row['range_count'], 2, '0', STR_PAD_LEFT) : '' ?>
                </td>
                <td class="num-cell <?= $row['total_count'] === 0 ? 'empty-cell' : '' ?>">
                    <?= $row['total_count'] > 0 ? str_pad((string)$row['total_count'], 2, '0', STR_PAD_LEFT) : '' ?>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td style="text-align:center;">Grand Total:</td>
                <td><?= $grand_prev  > 0 ? $grand_prev  : '' ?></td>
                <td><?= $grand_range > 0 ? $grand_range : '' ?></td>
                <td><?= $grand_total > 0 ? $grand_total : '' ?></td>
            </tr>
        </tfoot>
    </table>

    <?php if (!empty($legend)): ?>
    <!-- Legend -->
    <div class="legend-section">
        <div class="legend-title">Program Legend</div>
        <?php foreach ($legend as $row): ?>
        <div class="legend-item">
            <strong><?= h($row['short_label']) ?></strong> means:
            <?= h($row['full_name']) ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Signature section -->
    <div class="sig-section">
        <div class="sig-block">
            <div class="sig-line">Prepared by</div>
        </div>
        <div class="sig-block">
            <div class="sig-line">Checked by</div>
        </div>
        <div class="sig-block">
            <div class="sig-line">Authorised by</div>
        </div>
    </div>

</div><!-- .report-page -->
</div><!-- .print-wrapper -->

</body>
</html>
