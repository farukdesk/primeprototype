<?php
/**
 * Staff Attendance – Monthly report as a downloadable PDF.
 *
 * Follows the Prime University attendance cycle: a "month" runs from the 26th of
 * the previous calendar month to the 25th of the selected month (att_prime_month_range).
 *
 * Layout: one row per staff member, one column per date in the cycle. Each date
 * cell shows in-time, out-time and total working hours, with late days and absent
 * days highlighted. The final two columns show the Total Late and Total Absent
 * counts for each staff member.
 *
 * Filters (via query string, mirroring index.php): month=YYYY-MM, dept, q.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('staff-attendance');
require_once __DIR__ . '/helpers.php';
require_once dirname(__DIR__) . '/../vendor/autoload.php';

if (!att_can_view()) {
    http_response_code(403);
    die('Access denied.');
}

// ── Resolve the reporting cycle & filters ────────────────────────────────────
$month   = $_GET['month'] ?? date('Y-m');
$range   = att_prime_month_range($month);
$from    = $range['from'];
$to      = $range['to'];
$dept_id = (int)($_GET['dept'] ?? 0);
$search  = trim($_GET['q'] ?? '');

// ── Data ─────────────────────────────────────────────────────────────────────
$staff    = att_staff_list($dept_id, $search);
$user_ids = array_map(fn($s) => (int)$s['id'], $staff);
$records  = att_records_map($user_ids, $from, $to);
$holidays = att_holidays_in_range($from, $to);

// Enumerate the dates in the cycle.
$dates = [];
for ($d = strtotime($from); $d <= strtotime($to); $d = strtotime('+1 day', $d)) {
    $dates[] = date('Y-m-d', $d);
}

// Pre-load approved-leave user ids per date.
$leave_by_date = [];
foreach ($dates as $d) {
    $leave_by_date[$d] = att_on_leave_user_ids($d);
}

// Department label (for the header) when a single department is filtered.
$dept_label = 'All Departments';
if ($dept_id > 0) {
    foreach (att_departments() as $d) {
        if ((int)$d['id'] === $dept_id) { $dept_label = $d['name']; break; }
    }
}

$weekday_short = ['1' => 'Mon', '2' => 'Tue', '3' => 'Wed', '4' => 'Thu', '5' => 'Fri', '6' => 'Sat', '7' => 'Sun'];

// ── Build the HTML ───────────────────────────────────────────────────────────
$logo_uri  = att_logo_data_uri();
$logo_html = $logo_uri
    ? '<img src="' . $logo_uri . '" style="height:38px;width:auto;" alt="Prime University">'
    : '<span style="font-weight:bold;font-size:15px;">Prime University</span>';
$generated = date('d M Y, g:i A');

// The report is laid out on a single A4 sheet in landscape (horizontal) so it
// always fits one standard paper width. The grid table below uses width:100%
// with a fixed layout, so the column widths act as proportional hints and are
// scaled by dompdf to fill exactly the A4 landscape content width — nothing is
// clipped off the page, however wide the cycle. Rows still paginate vertically.
$page_w = 842; // A4 landscape width  (pt)
$page_h = 595; // A4 landscape height (pt)

// Relative column-width hints. Their sum is irrelevant (the table is scaled to
// 100% of the page width); only their ratio to one another matters.
$w_serial = 18;
$w_name   = 96;
$w_id     = 46;
$w_date   = 26;
$w_total  = 32;

// Header row of dates.
$date_head      = '';
$holiday_groups = att_holiday_group_map();
foreach ($dates as $d) {
    // Only holidays that apply to ALL staff shade the whole column; a
    // group-restricted holiday still shows per staff member in the cells.
    $off = (isset($holidays[$d]) && empty($holiday_groups[$d])) || att_is_weekly_off($d);
    $cls = $off ? 'dh off' : 'dh';
    $date_head .= '<th class="' . $cls . '">'
        . '<span class="dnum">' . (int)date('j', strtotime($d)) . '</span><br>'
        . '<span class="dwk">' . $weekday_short[date('N', strtotime($d))] . '</span>'
        . '</th>';
}

// Body rows.
$body = '';
$sn   = 0;
foreach ($staff as $s) {
    $uid   = (int)$s['id'];
    $sched = att_effective_schedule($uid);
    $sn++;

    $cells        = '';
    $total_late   = 0;
    $total_absent = 0;
    $pen_days     = 0; // late-in/early-out days on/after 01 Jun 2026

    foreach ($dates as $d) {
        $rec      = $records[$uid . '|' . $d] ?? null;
        $on_leave = $leave_by_date[$d] ?? [];
        $status   = att_compute_status($rec, $uid, $d, $sched, $holidays, $on_leave);

        $cls  = 'c';
        $html = '';
        switch ($status) {
            case 'holiday':
            case 'weekly_off':
                $cls .= ' off';
                $html = '<span class="tag">' . ($status === 'holiday' ? 'HOL' : 'WE') . '</span>';
                break;
            case 'leave':
                $cls .= ' leave';
                $html = '<span class="tag">Leave</span>';
                break;
            case 'absent':
                $cls .= ' absent';
                $html = '<span class="tag">A</span>';
                $total_absent++;
                break;
            default: // present / late_in / early_out / late_and_early / short_hours / incomplete
                $mins  = $rec ? att_worked_minutes($rec['in_time'] ?? null, $rec['out_time'] ?? null) : 0;
                $in    = att_display_time($rec['in_time'] ?? null);
                $out   = att_display_time($rec['out_time'] ?? null);
                $hours = att_format_hours($mins);
                $is_late = in_array($status, ['late_in', 'late_and_early'], true);
                if ($is_late) { $cls .= ' late'; $total_late++; }
                // Policy (from 01 Jun 2026): each late-in/early-out day feeds the penalty.
                if (att_policy_active($d) && in_array($status, ['late_in', 'early_out', 'late_and_early'], true)) {
                    $pen_days++;
                }
                $html = '<span class="t">' . h($in) . '</span>'
                      . '<span class="t">' . h($out) . '</span>'
                      . '<span class="hh">' . h($hours) . '</span>';
                break;
        }
        $cells .= '<td class="' . $cls . '">' . $html . '</td>';
    }

    $pen_abs = att_late_penalty_days($pen_days);
    $body .= '<tr>'
        . '<td class="sn">' . $sn . '</td>'
        . '<td class="nm">' . h($s['full_name']) . '</td>'
        . '<td class="id">' . h($s['employee_id'] ?? '—') . '</td>'
        . $cells
        . '<td class="tot ' . ($total_late ? 'has' : '') . '">' . $total_late . '</td>'
        . '<td class="tot ' . ($total_absent ? 'hasa' : '') . '">' . $total_absent . '</td>'
        . '<td class="tot ' . ($pen_abs ? 'hasa' : '') . '">' . $pen_abs . '</td>'
        . '</tr>';
}

if ($body === '') {
    $colspan = 6 + count($dates);
    $body = '<tr><td colspan="' . $colspan . '" style="text-align:center;padding:20px;color:#777;">'
          . 'No staff found for the selected filters.</td></tr>';
}

ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 14px 14px; }
    body { font-family: DejaVu Sans, sans-serif; color:#222; font-size:8px; }
    .head { width:100%; border-bottom:2px solid #333; padding-bottom:6px; margin-bottom:8px; }
    .head td { vertical-align:middle; border:none; }
    .title { font-size:15px; font-weight:bold; text-align:center; }
    .sub { text-align:center; font-size:9px; color:#444; margin-top:2px; }
    .meta { text-align:right; font-size:8px; color:#555; }
    table.grid { width:100%; border-collapse:collapse; table-layout:fixed; }
    table.grid th, table.grid td { border:0.5px solid #bbb; text-align:center; padding:1px; overflow:hidden; }
    thead th { background:#f0f0f0; font-weight:bold; }
    th.sn, td.sn { width:<?= $w_serial ?>px; }
    th.nm, td.nm { width:<?= $w_name ?>px; text-align:left; padding-left:4px; }
    td.nm { font-weight:bold; }
    th.id, td.id { width:<?= $w_id ?>px; }
    th.dh, td.c { width:<?= $w_date ?>px; }
    th.tt, td.tot { width:<?= $w_total ?>px; font-weight:bold; }
    .dnum { font-weight:bold; }
    .dwk { font-size:6px; color:#666; }
    th.off, td.c.off { background:#eee; color:#888; }
    td.c .t { display:block; line-height:1.15; }
    td.c .hh { display:block; line-height:1.15; color:#0a58ca; font-weight:bold; }
    td.c .tag { display:block; font-weight:bold; }
    td.c.late { background:#fff3cd; }        /* late in – amber   */
    td.c.absent { background:#f8d7da; color:#842029; }  /* absent – red */
    td.c.leave { background:#cfe2ff; color:#084298; }   /* on leave – blue */
    td.tot.has { background:#fff3cd; color:#664d03; }
    td.tot.hasa { background:#f8d7da; color:#842029; }
    .legend { margin-top:8px; font-size:8px; color:#444; }
    .legend span { display:inline-block; padding:1px 6px; margin-right:6px; border:0.5px solid #bbb; }
    .lg-late { background:#fff3cd; }
    .lg-absent { background:#f8d7da; }
    .lg-leave { background:#cfe2ff; }
    .lg-off { background:#eee; }
</style>
</head>
<body>
    <table class="head">
        <tr>
            <td style="width:120px;text-align:left;"><?= $logo_html ?></td>
            <td>
                <div class="title">Staff Attendance Report</div>
                <div class="sub">Attendance Month: <?= h($range['label']) ?>
                    &nbsp;|&nbsp; <?= h($dept_label) ?></div>
            </td>
            <td style="width:120px;" class="meta">Generated<br><?= h($generated) ?></td>
        </tr>
    </table>

    <table class="grid">
        <thead>
            <tr>
                <th class="sn">#</th>
                <th class="nm">Staff Name</th>
                <th class="id">Emp ID</th>
                <?= $date_head ?>
                <th class="tt">Total<br>Late</th>
                <th class="tt">Total<br>Absent</th>
                <th class="tt">Penalty<br>Absent</th>
            </tr>
        </thead>
        <tbody>
            <?= $body ?>
        </tbody>
    </table>

    <div class="legend">
        Each cell shows <strong>in&nbsp;/&nbsp;out&nbsp;/&nbsp;working hours</strong>.
        <span class="lg-late">Late</span>
        <span class="lg-absent">Absent (A)</span>
        <span class="lg-leave">On Leave</span>
        <span class="lg-off">HOL = Holiday &middot; WE = Weekend</span>
        <br>Policy effective 01 Jun 2026: Administrative clock-in grace 15 min, Faculty 20 min (no clock-out grace);
        faculty Fridays are flexible (minimum 7 hours); clock-in by 8:30 AM allows leaving from 4:30 PM;
        every 4 Late In / Early Out days count as 1 Absent day (Penalty Absent column).
    </div>
</body>
</html>
<?php
$html = ob_get_clean();

$options = new \Dompdf\Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new \Dompdf\Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('a4', 'landscape');
$dompdf->render();

$filename = 'staff-attendance-' . $range['month'] . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);
exit;
