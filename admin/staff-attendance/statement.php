<?php
/**
 * Staff Attendance – Attendance Statement export (PDF or DOC).
 *
 * A department-wise statement for a chosen date range:
 *   - A4 LANDSCAPE, base font size 14.
 *   - Letterhead on every department page: university logo on the left and the
 *     centred university name/address, then "Attendance Statement" with the
 *     selected date range and the department heading.
 *   - Administrative staff are grouped under a single "Administrative Employee"
 *     heading; everyone else is grouped under their assigned department name.
 *     Each department starts on a NEW page.
 *   - Columns: Sl. | Employee ID | Name | Designation | Dept./Section |
 *     Type of Appointment | CL | ML | PA | Absent | Remarks.
 *     CL/ML are the approved Casual / Medical(Sick) leave days inside the
 *     range, shown like Absent as a count plus the dates, e.g. "02 (5,12)".
 *     PA (Penalty Absent) is the attendance-policy penalty: every 4 Late In /
 *     Early Out days inside the range = 1 absent day.
 *     Absent shows the day count plus the dates, e.g. "05 (1,5,7,8,9)".
 *
 * Query string: from=Y-m-d, to=Y-m-d, format=pdf|doc, dept (optional), q (optional).
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('staff-attendance');
require_once __DIR__ . '/helpers.php';

if (!att_can_view()) {
    http_response_code(403);
    die('Access denied.');
}

// ── Filters ──────────────────────────────────────────────────────────────────
$format = strtolower(trim($_GET['format'] ?? 'pdf'));
if (!in_array($format, ['pdf', 'doc'], true)) $format = 'pdf';

$from = att_normalize_date($_GET['from'] ?? date('Y-m-01'));
$to   = att_normalize_date($_GET['to']   ?? date('Y-m-d'));
if ($from > $to) { [$from, $to] = [$to, $from]; }
if (strtotime($to) - strtotime($from) > 366 * 86400) {
    $to = date('Y-m-d', strtotime($from . ' +366 days'));
}
$dept_id = (int)($_GET['dept'] ?? 0);
$search  = trim($_GET['q'] ?? '');

$range_label = date('d M Y', strtotime($from)) . ' to ' . date('d M Y', strtotime($to));

// ── Data ─────────────────────────────────────────────────────────────────────
$staff    = att_staff_list($dept_id, $search);
$user_ids = array_map(fn($s) => (int)$s['id'], $staff);
$records  = att_records_map($user_ids, $from, $to);
$holidays = att_holidays_in_range($from, $to);

$dates = [];
for ($d = strtotime($from); $d <= strtotime($to); $d = strtotime('+1 day', $d)) {
    $dates[] = date('Y-m-d', $d);
}
$leave_by_date = [];
foreach ($dates as $d) $leave_by_date[$d] = att_on_leave_user_ids($d);

// Profile extras: designation, appointment type (job_type) and employee type.
$profiles = [];
try {
    foreach (db()->query('SELECT user_id, designation, job_type, department_type FROM staff_profiles')->fetchAll() as $r) {
        $profiles[(int)$r['user_id']] = $r;
    }
} catch (Throwable $e) {
    // staff_profiles missing / older schema – columns show "—".
}

// Approved Casual (CL) / Sick a.k.a. Medical (ML) / Paternity (PL) leave days
// inside the range.
$cl_days = [];
$ml_days = [];
$pl_days = [];
try {
    $stmt = db()->prepare(
        "SELECT user_id, category, start_date, end_date FROM leave_requests
          WHERE status = 'approved' AND category IN ('casual','sick','paternity')
            AND start_date <= ? AND end_date >= ?"
    );
    $stmt->execute([$to, $from]);
    foreach ($stmt->fetchAll() as $r) {
        $uid  = (int)$r['user_id'];
        $s    = max(strtotime((string)$r['start_date']), strtotime($from));
        $e    = min(strtotime((string)$r['end_date']),   strtotime($to));
        if ($e < $s) continue;
        $days = (int)floor(($e - $s) / 86400) + 1;
        if ($r['category'] === 'casual')         $cl_days[$uid] = ($cl_days[$uid] ?? 0) + $days;
        elseif ($r['category'] === 'paternity')  $pl_days[$uid] = ($pl_days[$uid] ?? 0) + $days;
        else                                     $ml_days[$uid] = ($ml_days[$uid] ?? 0) + $days;
    }
} catch (Throwable $e) {
    // Leave Management not installed – CL/ML/PL show "—".
}

// When the range spans more than one calendar month, absent dates are shown as
// "5 Jul" instead of a bare day number so they stay unambiguous.
$multi_month = date('Y-m', strtotime($from)) !== date('Y-m', strtotime($to));

// ── Group staff by department ────────────────────────────────────────────────
// Administrative staff → "Administrative Employee"; others → their department.
$groups = [];
foreach ($staff as $s) {
    $uid   = (int)$s['id'];
    $p     = $profiles[$uid] ?? [];
    $sched = att_effective_schedule($uid);

    $absent     = [];
    $late_early = 0; // policy-active Late In / Early Out days → PA (Penalty Absent)
    foreach ($dates as $d) {
        $rec    = $records[$uid . '|' . $d] ?? null;
        $status = att_compute_status($rec, $uid, $d, $sched, $holidays, $leave_by_date[$d] ?? []);
        if ($status === 'absent') {
            $absent[] = $multi_month
                ? date('j M', strtotime($d))
                : (string)(int)date('j', strtotime($d));
        } elseif (att_policy_active($d) && in_array($status, ['late_in', 'early_out', 'late_and_early'], true)) {
            $late_early++;
        }
    }

    $etype = (string)($p['department_type'] ?? '');
    $group = $etype === 'administrative'
        ? 'Administrative Employee'
        : ((string)($s['dept_name'] ?? '') !== '' ? (string)$s['dept_name'] : 'Unassigned Department');

    $groups[$group][] = [
        'employee_id' => (string)($s['employee_id'] ?? ''),
        'name'        => (string)$s['full_name'],
        'designation' => (string)($p['designation'] ?? ''),
        'dept'        => (string)($s['dept_name'] ?? ''),
        'appointment' => (string)($p['job_type'] ?? ''),
        'cl'          => (int)($cl_days[$uid] ?? 0),
        'ml'          => (int)($ml_days[$uid] ?? 0),
        'pl'          => (int)($pl_days[$uid] ?? 0),
        'pa'          => att_late_penalty_days($late_early),
        'absent'      => $absent,
    ];
}

// Administrative Employee first, then the other departments alphabetically.
uksort($groups, static function (string $a, string $b): int {
    if ($a === $b) return 0;
    if ($a === 'Administrative Employee') return -1;
    if ($b === 'Administrative Employee') return 1;
    return strcasecmp($a, $b);
});

// ── HTML helpers ─────────────────────────────────────────────────────────────
// Logo: embedded data URI for the PDF; an absolute URL for the Word document
// (Word does not reliably render data URIs in HTML .doc files).
if ($format === 'pdf') {
    $logo_src = att_logo_data_uri();
} else {
    $site_url = defined('APP_URL') ? preg_replace('#/admin/?$#', '', APP_URL) : '';
    $logo_src = $site_url !== '' ? $site_url . '/assets/img/logo/logo-black.png' : '';
}
$logo_html = $logo_src !== ''
    ? '<img src="' . h($logo_src) . '" style="height:64px;width:auto;" alt="Prime University">'
    : '';
$generated = date('d M Y, g:i A');

/** Letterhead + statement title + department heading (repeated on each page). */
$letterhead = static function (string $dept_heading) use ($logo_html, $range_label, $generated): string {
    return '
    <table class="lh">
        <tr>
            <td class="lh-logo">' . $logo_html . '</td>
            <td class="lh-mid">
                <div class="uni">Prime University</div>
                <div class="addr">114/116, Mazar Road, Mirpur-1, Dhaka-1216</div>
                <div class="stmt">Attendance Statement</div>
                <div class="rng">' . h($range_label) . '</div>
            </td>
            <td class="lh-meta">Generated<br>' . h($generated) . '</td>
        </tr>
    </table>
    <div class="dept">Department: ' . h($dept_heading) . '</div>';
};

// ── Build the department sections ────────────────────────────────────────────
$sections = '';
$first    = true;
foreach ($groups as $dept_heading => $rows) {
    $body = '';
    $sl   = 0;
    foreach ($rows as $r) {
        $sl++;
        $abs_cnt  = count($r['absent']);
        $abs_text = $abs_cnt > 0
            ? sprintf('%02d', $abs_cnt) . ' (' . implode(',', $r['absent']) . ')'
            : '—';
        $body .= '<tr>'
            . '<td class="c">' . $sl . '</td>'
            . '<td class="c">' . h($r['employee_id'] !== '' ? $r['employee_id'] : '—') . '</td>'
            . '<td class="l nm">' . h($r['name']) . '</td>'
            . '<td class="l">' . h($r['designation'] !== '' ? $r['designation'] : '—') . '</td>'
            . '<td class="l">' . h($r['dept'] !== '' ? $r['dept'] : '—') . '</td>'
            . '<td class="c">' . h($r['appointment'] !== '' ? $r['appointment'] : '—') . '</td>'
            . '<td class="c">' . ($r['cl'] > 0 ? sprintf('%02d', $r['cl']) : '—') . '</td>'
            . '<td class="c">' . ($r['ml'] > 0 ? sprintf('%02d', $r['ml']) : '—') . '</td>'
            . '<td class="c">' . ($r['pl'] > 0 ? sprintf('%02d', $r['pl']) : '—') . '</td>'
            . '<td class="c">' . ($r['pa'] > 0 ? sprintf('%02d', $r['pa']) : '—') . '</td>'
            . '<td class="l">' . h($abs_text) . '</td>'
            . '<td class="l">&nbsp;</td>'
            . '</tr>';
    }

    $sections .= '<div class="sec' . ($first ? ' first' : '') . '">'
        . $letterhead((string)$dept_heading)
        . '<table class="grid">'
        . '<thead><tr>'
        . '<th style="width:4%;">Sl.</th>'
        . '<th style="width:9%;">Employee ID</th>'
        . '<th style="width:15%;">Name</th>'
        . '<th style="width:12%;">Designation</th>'
        . '<th style="width:12%;">Dept./Section</th>'
        . '<th style="width:10%;">Type of Appointment</th>'
        . '<th style="width:5%;">CL</th>'
        . '<th style="width:5%;">ML</th>'
        . '<th style="width:5%;">PL</th>'
        . '<th style="width:5%;">PA</th>'
        . '<th style="width:12%;">Absent</th>'
        . '<th style="width:6%;">Remarks</th>'
        . '</tr></thead>'
        . '<tbody>' . $body . '</tbody>'
        . '</table>'
        . '</div>';
    $first = false;
}

if ($sections === '') {
    $sections = '<div class="sec first">' . $letterhead($dept_id > 0 ? 'Selected Department' : 'All Departments')
        . '<p style="text-align:center;color:#777;padding:24px 0;">No staff found for the selected filters.</p></div>';
}

// ── Full document ────────────────────────────────────────────────────────────
$is_doc = $format === 'doc';
ob_start();
?>
<!DOCTYPE html>
<html lang="en"<?= $is_doc ? ' xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word"' : '' ?>>
<head>
<meta charset="UTF-8">
<title>Attendance Statement</title>
<style>
    <?php if ($is_doc): ?>
    /* Word: A4 landscape section */
    @page Section1 { size: 29.7cm 21cm; mso-page-orientation: landscape; margin: 1.4cm 1.4cm; }
    div.Section1 { page: Section1; }
    <?php else: ?>
    @page { margin: 24px 24px; }
    <?php endif; ?>
    body { font-family: <?= $is_doc ? '"Times New Roman", serif' : 'DejaVu Sans, sans-serif' ?>; color:#111; font-size:14px; }
    .sec { page-break-before: always; }
    .sec.first { page-break-before: auto; }
    table.lh { width:100%; border-collapse:collapse; border-bottom:2px solid #333; margin-bottom:6px; }
    table.lh td { border:none; vertical-align:middle; padding:0 0 6px 0; }
    .lh-logo { width:130px; text-align:left; }
    .lh-mid  { text-align:center; }
    .lh-meta { width:130px; text-align:right; font-size:10px; color:#555; }
    .uni  { font-size:22px; font-weight:bold; }
    .addr { font-size:13px; color:#333; }
    .stmt { font-size:16px; font-weight:bold; text-decoration:underline; margin-top:6px; }
    .rng  { font-size:13px; color:#333; }
    .dept { font-size:15px; font-weight:bold; margin:8px 0 6px 0; }
    table.grid { width:100%; border-collapse:collapse; table-layout:fixed; }
    table.grid th, table.grid td { border:1px solid #444; padding:4px 5px; font-size:14px; overflow:hidden; }
    table.grid thead th { background:#f0f0f0; font-weight:bold; text-align:center; }
    td.c { text-align:center; }
    td.l { text-align:left; }
    td.nm { font-weight:bold; }
</style>
</head>
<body>
<?= $is_doc ? '<div class="Section1">' : '' ?>
<?= $sections ?>
<?= $is_doc ? '</div>' : '' ?>
</body>
</html>
<?php
$html = ob_get_clean();

$filename = 'attendance-statement-' . $from . '-to-' . $to;

if ($is_doc) {
    header('Content-Type: application/vnd.ms-word; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.doc"');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF" . $html; // UTF-8 BOM so Word decodes correctly
    exit;
}

require_once dirname(__DIR__) . '/../vendor/autoload.php';

$options = new \Dompdf\Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new \Dompdf\Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('a4', 'landscape');
$dompdf->render();
$dompdf->stream($filename . '.pdf', ['Attachment' => true]);
exit;
