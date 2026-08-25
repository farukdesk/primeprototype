<?php
/**
 * Staff Attendance – Overtime report as a downloadable PDF.
 *
 * A4 portrait with the university logo, a title, the From–To period and a
 * table of overtime amounts (one row per eligible staff member), followed by
 * a totals row, the overtime rules and signature blocks.
 *
 * Query string: from=Y-m-d, to=Y-m-d, designation (optional).
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('staff-attendance', 'can_edit');
require_once __DIR__ . '/overtime-helpers.php';
require_once dirname(__DIR__) . '/../vendor/autoload.php';

// ── Filters ──────────────────────────────────────────────────────────────────
$from = att_normalize_date($_GET['from'] ?? date('Y-m-01'));
$to   = att_normalize_date($_GET['to']   ?? date('Y-m-d'));
if ($from > $to) { [$from, $to] = [$to, $from]; }
if (strtotime($to) - strtotime($from) > 366 * 86400) {
    $to = date('Y-m-d', strtotime($from . ' +366 days'));
}
$desig = trim($_GET['designation'] ?? '');

$cfg    = att_ot_config();
$report = att_ot_report($from, $to, $cfg, $desig);

$sum_minutes = array_sum(array_column($report, 'ot_minutes'));
$sum_amount  = array_sum(array_column($report, 'amount'));
$range_label = date('d M Y', strtotime($from)) . ' to ' . date('d M Y', strtotime($to));
$generated   = date('d M Y, g:i A');
$ot_start    = date('g:i A', strtotime($cfg['start_time']));
$grace_end   = date('g:i A', strtotime($cfg['start_time']) + (int)$cfg['threshold_minutes'] * 60);

$logo_uri  = att_logo_data_uri();
$logo_html = $logo_uri
    ? '<img src="' . $logo_uri . '" style="height:46px;width:auto;" alt="Prime University">'
    : '<span style="font-weight:bold;font-size:16px;">Prime University</span>';

// ── Body rows ────────────────────────────────────────────────────────────────
$body = '';
$sn   = 0;
foreach ($report as $r) {
    $sn++;
    $body .= '<tr>'
        . '<td class="c">' . $sn . '</td>'
        . '<td class="c">' . h($r['employee_id'] !== '' ? $r['employee_id'] : '—') . '</td>'
        . '<td class="l">' . h($r['full_name']) . '</td>'
        . '<td class="l">' . h($r['designation']) . ($r['uncapped'] ? ' <span class="note">(actual hours)</span>' : '') . '</td>'
        . '<td class="c">' . (int)$r['ot_days'] . '</td>'
        . '<td class="c">' . h(att_format_hours((int)$r['ot_minutes'])) . '</td>'
        . '<td class="r">' . h(att_ot_money((float)$r['rate'])) . '</td>'
        . '<td class="r amt">' . h(att_ot_money((float)$r['amount'])) . '</td>'
        . '</tr>';
}
if ($body === '') {
    $body = '<tr><td colspan="8" style="text-align:center;padding:18px;color:#777;">'
          . 'No overtime found for the selected period.</td></tr>';
} else {
    $body .= '<tr class="total">'
        . '<td colspan="5" class="r"><strong>Total</strong></td>'
        . '<td class="c"><strong>' . h(att_format_hours((int)$sum_minutes)) . '</strong></td>'
        . '<td></td>'
        . '<td class="r amt"><strong>' . h(att_ot_money((float)$sum_amount)) . '</strong></td>'
        . '</tr>';
}

$desig_label = $desig !== '' ? $desig : 'All Eligible Designations';

ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 34px 34px; }
    body { font-family: DejaVu Sans, sans-serif; color:#222; font-size:10px; }
    .head { width:100%; border-bottom:2px solid #333; padding-bottom:8px; margin-bottom:4px; }
    .head td { vertical-align:middle; border:none; }
    .uni { font-size:16px; font-weight:bold; text-align:center; }
    .title { font-size:13px; font-weight:bold; text-align:center; margin-top:2px; text-decoration:underline; }
    .sub { text-align:center; font-size:10px; color:#444; margin-top:3px; }
    .meta { text-align:right; font-size:8px; color:#555; }
    table.grid { width:100%; border-collapse:collapse; margin-top:10px; }
    table.grid th, table.grid td { border:0.6px solid #999; padding:4px 5px; }
    thead th { background:#f0f0f0; font-weight:bold; text-align:center; }
    td.c { text-align:center; }
    td.l { text-align:left; }
    td.r { text-align:right; }
    td.amt { font-weight:bold; }
    tr.total td { background:#f6f6f6; }
    .note { color:#0a58ca; font-size:8px; }
    .rules { margin-top:12px; font-size:8.5px; color:#444; border:0.6px solid #ccc; padding:6px 8px; }
    .rules strong { color:#222; }
    table.sign { width:100%; margin-top:56px; border:none; }
    table.sign td { border:none; text-align:center; font-size:9px; width:33%; }
    .line { border-top:1px solid #333; margin:0 22px; padding-top:4px; }
</style>
</head>
<body>
    <table class="head">
        <tr>
            <td style="width:130px;text-align:left;"><?= $logo_html ?></td>
            <td>
                <div class="uni">Prime University</div>
                <div class="title">Staff Overtime Report</div>
                <div class="sub">Period: <strong><?= h($range_label) ?></strong> &nbsp;|&nbsp; <?= h($desig_label) ?></div>
            </td>
            <td style="width:130px;" class="meta">Generated<br><?= h($generated) ?></td>
        </tr>
    </table>

    <table class="grid">
        <thead>
            <tr>
                <th style="width:28px;">Sl.</th>
                <th style="width:58px;">Emp ID</th>
                <th>Name</th>
                <th style="width:110px;">Designation</th>
                <th style="width:44px;">OT Days</th>
                <th style="width:70px;">Total OT Hours</th>
                <th style="width:64px;">Rate (Tk/hr)</th>
                <th style="width:84px;">Overtime Amount (Tk)</th>
            </tr>
        </thead>
        <tbody><?= $body ?></tbody>
    </table>

    <div class="rules">
        <strong>Overtime rules:</strong>
        counted only after <strong><?= h($ot_start) ?></strong>;
        leaving within the first <?= (int)$cfg['threshold_minutes'] ?> minutes (by <?= h($grace_end) ?>) earns no overtime;
        leaving later counts overtime from <?= h($ot_start) ?>;
        daily maximum <?= h(att_format_hours((int)$cfg['cap_minutes'])) ?> — designations marked <em>(actual hours)</em>, e.g. Driver, are paid for the real time worked without the cap;
        weekend / holiday work is not overtime by itself — only time after <?= h($ot_start) ?> counts under the same rules.
    </div>

    <table class="sign">
        <tr>
            <td><div class="line">Prepared by</div></td>
            <td><div class="line">Checked by</div></td>
            <td><div class="line">Approved by</div></td>
        </tr>
    </table>
</body>
</html>
<?php
$html = ob_get_clean();

$options = new \Dompdf\Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new \Dompdf\Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('a4', 'portrait');
$dompdf->render();

$filename = 'staff-overtime-' . $from . '-to-' . $to . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);
exit;
