<?php
/**
 * Staff Attendance – Attendance Statement export (PDF or DOC).
 *
 * A section-wise statement for a chosen date range:
 *   - A4 LANDSCAPE, base font size 14, letterhead at the top of the document.
 *   - Fixed section flow. TABLE 1 (no heading above it) holds the non-academic
 *     employees: Group A – Officers directly under the header row, then a bold
 *     divider row "Non Academic Officer and Stuff" followed by Group B, then a
 *     bold divider row "Non Academic Stuff" followed by Group C. After that
 *     come SEPARATE tables (bold heading above each, each with its own header
 *     row): Security Section and the academic departments in a fixed order
 *     (English, Education, Bangla, Business Administration, CSE, EEE, Civil
 *     Engineering, Law, Fashion Design and Apparel Engineering, Prime
 *     University Language School).
 *   - Inside each group, rows follow the fixed designation sequence, then the
 *     appointment type sequence (Regular, Contractual, Probation, Ad-hoc).
 *     In departments the Head comes first within their own rank group.
 *   - Columns: SL | ID | Name | Designation | Dept./Section |
 *     Type of Appointment | CL | ML | PA | Absent | Remarks.
 *     All cells are centre-aligned; SL stays blank; a missing ID stays blank.
 *     Empty count cells show an em dash "—". Counts are two digits.
 *     CL/ML are the approved Casual / Medical(Sick) leave days inside the
 *     range, shown as a count plus the dates, e.g. "02 (24,25)".
 *     PA (Penalty Absent) is the attendance-policy penalty (every 4 Late In /
 *     Early Out days inside the range = 1 absent day) as a count only.
 *     Absent shows the day count plus the dates, e.g. "02 (4,11)".
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

// Approved Casual (CL) / Sick a.k.a. Medical (ML) leave DATES inside the range,
// keyed uid => [Y-m-d => true] so overlapping requests never double-count a day.
$cl_dates = [];
$ml_dates = [];
try {
    $stmt = db()->prepare(
        "SELECT user_id, category, start_date, end_date FROM leave_requests
          WHERE status = 'approved' AND category IN ('casual','sick')
            AND start_date <= ? AND end_date >= ?"
    );
    $stmt->execute([$to, $from]);
    foreach ($stmt->fetchAll() as $r) {
        $uid = (int)$r['user_id'];
        $s   = max(strtotime((string)$r['start_date']), strtotime($from));
        $e   = min(strtotime((string)$r['end_date']),   strtotime($to));
        if ($e < $s) continue;
        for ($d = $s; $d <= $e; $d = strtotime('+1 day', $d)) {
            $day = date('Y-m-d', $d);
            if ($r['category'] === 'casual') $cl_dates[$uid][$day] = true;
            else                             $ml_dates[$uid][$day] = true;
        }
    }
} catch (Throwable $e) {
    // Leave Management not installed – CL/ML show "—".
}

// When the range spans more than one calendar month, absent dates are shown as
// "5 Jul" instead of a bare day number so they stay unambiguous.
$multi_month = date('Y-m', strtotime($from)) !== date('Y-m', strtotime($to));

// Format a keyed [Y-m-d => true] date set like the Absent column: sorted day
// numbers, or "j M" when the range spans multiple months.
$fmt_dates = static function (array $keyed) use ($multi_month): array {
    $days = array_keys($keyed);
    sort($days);
    return array_map(
        static fn(string $day): string => $multi_month
            ? date('j M', strtotime($day))
            : (string)(int)date('j', strtotime($day)),
        $days
    );
};

// ── Section flow / designation sequence configuration ───────────────────────
// Fixed section order. 'main' is TABLE 1 (Groups A, B and C with divider rows).
$ACADEMIC_DEPTS = [
    'english'   => 'Department of English',
    'education' => 'Department of Education',
    'bangla'    => 'Department of Bangla',
    'business'  => 'Department of Business Administration',
    'cse'       => 'Department of CSE',
    'eee'       => 'Department of EEE',
    'civil'     => 'Department of Civil Engineering',
    'law'       => 'Department of Law',
    'fashion'   => 'Department of Fashion Design and Apparel Engineering',
    'puls'      => 'Prime University Language School',
];
$SECTION_ORDER = array_merge(['main', 'security'], array_keys($ACADEMIC_DEPTS));

/** Lowercase, punctuation-free, single-spaced designation/department text. */
$norm = static function (string $s): string {
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
};

/** Map a department/section name to its fixed academic section key, or ''. */
$acad_key = static function (string $dept) use ($norm): string {
    $d = ' ' . $norm($dept) . ' ';
    if (strpos($d, 'english') !== false)                                     return 'english';
    if (strpos($d, 'education') !== false)                                   return 'education';
    if (strpos($d, 'bangla') !== false)                                      return 'bangla';
    if (strpos($d, 'business') !== false || strpos($d, ' bba ') !== false)   return 'business';
    if (strpos($d, ' cse ') !== false || strpos($d, 'computer') !== false)   return 'cse';
    if (strpos($d, ' eee ') !== false || strpos($d, 'electrical') !== false) return 'eee';
    if (strpos($d, 'civil') !== false)                                       return 'civil';
    if (strpos($d, ' law ') !== false)                                       return 'law';
    if (strpos($d, 'fashion') !== false)                                     return 'fashion';
    if (strpos($d, 'language school') !== false || strpos($d, ' puls ') !== false) return 'puls';
    return '';
};

/**
 * Ordered designation matchers (most specific first; first match wins).
 * Each rule: [regex on the normalised designation, group, rank].
 * Groups: A/B/C (Table 1), sec (Security Section), acad (department tables).
 * 'B|A' = Section Officer: Group B in a department office, else Group A.
 */
$DESIG_RULES = [
    ['/\bsecurity guard\b/',                                   'sec', 1],
    ['/\b(oa cum co|pa to treasurer|pa to registrar)\b/',      'C',   3],
    ['/\b(pro vice chancellor|pro vc)\b/',                     'A',   2],
    ['/\bvice chancellor\b/',                                  'A',   1],
    ['/\btreasurer\b/',                                        'A',   2],
    ['/\b(assistant registrar|ps to vc)\b/',                   'A',   9],
    ['/\bregistrar\b/',                                        'A',   3],
    ['/\bassistant advisor\b/',                                'A',  10],
    ['/\badvisor\b/',                                          'A',   4],
    ['/\bdeputy director\b/',                                  'A',   6],
    ['/\bmedical officer\b/',                                  'A',   6],
    ['/\bassistant director\b/',                               'A',   7],
    ['/\bhod digital marketing\b/',                            'A',  12],
    ['/\bdirector\b/',                                         'A',   5],
    ['/\bassistant (controller|coe)\b/',                       'A',   8],
    ['/\baccounts officer\b/',                                 'A',  11],
    ['/\b(admission officer|store officer|admin officer)\b/',  'A',  13],
    ['/\bsection officer\b/',                                  'B|A', 0],
    ['/\bdeputy librarian\b/',                                 'B',   1],
    ['/\bassistant librarian\b/',                              'B',   2],
    ['/\blab assistant\b/',                                    'B',   4],
    ['/\bcataloguer\b/',                                       'B',   5],
    ['/\bbook sorter\b/',                                      'B',   6],
    ['/\bsub assistant engineer\b/',                           'C',   1],
    ['/\baccounts assistant\b/',                               'C',   2],
    ['/\belectrician\b/',                                      'C',   4],
    ['/\bac technician\b/',                                    'C',   5],
    ['/\bdriver\b/',                                           'C',   6],
    ['/\bplumber\b/',                                          'C',   7],
    ['/\blift operator\b/',                                    'C',   8],
    ['/\bmlss\b/',                                             'C',   9],
    ['/\b(cleaner|cook)\b/',                                   'C',  10],
    ['/\bdean\b/',                                             'acad', 1],
    ['/\bassistant professor\b/',                              'acad', 5],
    ['/\bassociate professor\b/',                              'acad', 4],
    ['/\bprofessor\b/',                                        'acad', 3],
    ['/\blecturer\b/',                                         'acad', 6],
    ['/\bhead\b/',                                             'acad', 2],
];

// Appointment type sequence inside the same designation.
$appt_rank = static function (string $type) use ($norm): int {
    $t = $norm($type);
    if ($t === '' )                                   return 5;
    if (strpos($t, 'regular') !== false)              return 1;
    if (strpos($t, 'contract') !== false)             return 2;
    if (strpos($t, 'probation') !== false)            return 3;
    if (strpos($t, 'ad hoc') !== false || strpos($t, 'adhoc') !== false) return 4;
    return 5;
};

// ── Classify + collect every roster row into its section ─────────────────────
// $sections_data[section][] = row; the 'main' section rows also carry 'tgroup'
// (A/B/C). Departments not in the fixed list are appended after PULS.
$sections_data = [];
$extra_depts   = [];
foreach ($staff as $s) {
    $uid   = (int)$s['id'];
    $p     = $profiles[$uid] ?? [];
    $sched = att_effective_schedule($uid);

    $absent     = [];
    $late_early = 0; // policy-active Late In / Early Out days → PA (Penalty Absent)
    $has_data   = false;
    foreach ($dates as $d) {
        $rec = $records[$uid . '|' . $d] ?? null;
        if ($rec !== null) $has_data = true;
        $status = att_compute_status($rec, $uid, $d, $sched, $holidays, $leave_by_date[$d] ?? []);
        if ($status === 'absent') {
            $absent[] = $multi_month
                ? date('j M', strtotime($d))
                : (string)(int)date('j', strtotime($d));
        } elseif (att_policy_active($d) && in_array($status, ['late_in', 'early_out', 'late_and_early'], true)) {
            $late_early++;
        }
    }

    $designation = (string)($p['designation'] ?? '');
    $dept_name   = (string)($s['dept_name'] ?? '');
    $nd          = $norm($designation);
    $dept_acad   = $acad_key($dept_name);
    $is_security = $dept_acad === '' && strpos($norm($dept_name), 'security') !== false;

    // Match the designation against the ordered rules.
    $grp = ''; $rank = 99;
    foreach ($DESIG_RULES as [$re, $g, $rk]) {
        if ($nd !== '' && preg_match($re, $nd)) {
            if ($g === 'B|A') { // Section Officer: department office → Group B
                $grp  = ($dept_acad !== '' || (string)($p['department_type'] ?? '') === 'educational') ? 'B' : 'A';
                $rank = $grp === 'B' ? 3 : 14;
            } else {
                $grp  = $g;
                $rank = $rk;
            }
            break;
        }
    }
    // Head rule: heads keep their own rank group but come first inside it.
    $is_head = $grp === 'acad' && preg_match('/\bhead\b/', $nd) === 1;

    // Fallbacks for unmatched designations.
    if ($grp === '') {
        if ($is_security)          { $grp = 'sec';  $rank = 99; }
        elseif ($dept_acad !== '') { $grp = 'acad'; $rank = 99; }
        else                       { $grp = 'C';    $rank = 99; }
    }

    // Resolve the section this row belongs to.
    if ($grp === 'sec')      { $section = 'security'; }
    elseif ($grp === 'acad') {
        if ($dept_acad !== '') { $section = $dept_acad; }
        else {
            $section = 'dept:' . ($dept_name !== '' ? $dept_name : 'Unassigned Department');
            $extra_depts[$section] = $dept_name !== '' ? $dept_name : 'Unassigned Department';
        }
    } else { $section = 'main'; }

    $appointment = (string)($p['job_type'] ?? '');
    $sections_data[$section][] = [
        'tgroup'      => $grp,                       // A/B/C inside TABLE 1
        'rank'        => $rank,
        'head'        => $is_head ? 0 : 1,           // heads first in their rank
        'appt'        => $appt_rank($appointment),
        'employee_id' => (string)($s['employee_id'] ?? ''),
        'name'        => (string)$s['full_name'],
        'designation' => $designation,
        'dept'        => $dept_name,
        'appointment' => $appointment,
        'cl'          => $fmt_dates($cl_dates[$uid] ?? []),
        'ml'          => $fmt_dates($ml_dates[$uid] ?? []),
        'pa'          => att_late_penalty_days($late_early),
        'absent'      => $absent,
        'has_data'    => $has_data,
    ];
}

// Sort rows inside every section: Table-1 group, designation rank, Head first,
// appointment type sequence, then name.
$tg_order = ['A' => 0, 'B' => 1, 'C' => 2];
foreach ($sections_data as &$rows) {
    usort($rows, static function (array $a, array $b) use ($tg_order): int {
        return [$tg_order[$a['tgroup']] ?? 3, $a['rank'], $a['head'], $a['appt'], strtolower($a['name'])]
           <=> [$tg_order[$b['tgroup']] ?? 3, $b['rank'], $b['head'], $b['appt'], strtolower($b['name'])];
    });
}
unset($rows);

// Final section order: TABLE 1, Security Section, the fixed academic
// departments, then any extra departments alphabetically.
ksort($extra_depts, SORT_NATURAL | SORT_FLAG_CASE);
$section_flow = [];
foreach ($SECTION_ORDER as $key) {
    if (!empty($sections_data[$key])) $section_flow[] = $key;
}
foreach (array_keys($extra_depts) as $key) {
    if (!empty($sections_data[$key])) $section_flow[] = $key;
}
$section_headings = array_merge(
    ['main' => '', 'security' => 'Security Section'],
    $ACADEMIC_DEPTS,
    $extra_depts
);

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

/** Letterhead + statement title (shown once at the top of the document). */
$letterhead = static function () use ($logo_html, $range_label, $generated): string {
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
    </table>';
};

// ── Build the sections ───────────────────────────────────────────────────────
$COLS = 11;

/** The 11-column bold header row used by every table. */
$header_row = '<thead><tr>'
    . '<th style="width:4%;">SL</th>'
    . '<th style="width:8%;">ID</th>'
    . '<th style="width:14%;">Name</th>'
    . '<th style="width:12%;">Designation</th>'
    . '<th style="width:11%;">Dept./Section</th>'
    . '<th style="width:9%;">Type of Appointment</th>'
    . '<th style="width:8%;">CL</th>'
    . '<th style="width:8%;">ML</th>'
    . '<th style="width:4%;">PA</th>'
    . '<th style="width:12%;">Absent</th>'
    . '<th style="width:10%;">Remarks</th>'
    . '</tr></thead>';

/** One data row. SL stays blank; a missing ID stays blank; all cells centred. */
$data_row = static function (array $r): string {
    $count_cell = static function (array $days): string {
        return count($days) > 0
            ? sprintf('%02d', count($days)) . ' (' . implode(',', $days) . ')'
            : '—';
    };
    $emp_id  = trim($r['employee_id']);
    if ($emp_id === '(no ID)') $emp_id = '';
    $remarks = $r['has_data'] ? '—' : 'Not found in attendance data';
    return '<tr>'
        . '<td class="c">&nbsp;</td>'
        . '<td class="c">' . ($emp_id !== '' ? h($emp_id) : '&nbsp;') . '</td>'
        . '<td class="c">' . h($r['name']) . '</td>'
        . '<td class="c">' . h($r['designation']) . '</td>'
        . '<td class="c">' . h($r['dept']) . '</td>'
        . '<td class="c">' . h($r['appointment']) . '</td>'
        . '<td class="c">' . h($count_cell($r['cl'])) . '</td>'
        . '<td class="c">' . h($count_cell($r['ml'])) . '</td>'
        . '<td class="c">' . ($r['pa'] > 0 ? sprintf('%02d', $r['pa']) : '—') . '</td>'
        . '<td class="c">' . h($count_cell($r['absent'])) . '</td>'
        . '<td class="c">' . h($remarks) . '</td>'
        . '</tr>';
};

$sections = $letterhead();
$first    = true;
foreach ($section_flow as $key) {
    $rows = $sections_data[$key];
    $body = '';
    if ($key === 'main') {
        // TABLE 1: Group A directly under the header row, then the bold
        // divider rows introducing Groups B and C.
        $dividers = ['B' => 'Non Academic Officer and Stuff', 'C' => 'Non Academic Stuff'];
        $shown    = [];
        foreach ($rows as $r) {
            $tg = $r['tgroup'];
            if (isset($dividers[$tg]) && !isset($shown[$tg])) {
                $body .= '<tr><td colspan="' . $COLS . '" class="divider">' . h($dividers[$tg]) . '</td></tr>';
                $shown[$tg] = true;
            }
            $body .= $data_row($r);
        }
    } else {
        foreach ($rows as $r) $body .= $data_row($r);
    }

    $heading = (string)($section_headings[$key] ?? '');
    $sections .= '<div class="sec' . ($first ? ' first' : '') . '">'
        . ($heading !== '' ? '<div class="dept">' . h($heading) . '</div>' : '')
        . '<table class="grid">'
        . $header_row
        . '<tbody>' . $body . '</tbody>'
        . '</table>'
        . '</div>';
    $first = false;
}

if ($section_flow === []) {
    $sections .= '<p style="text-align:center;color:#777;padding:24px 0;">No staff found for the selected filters.</p>';
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
    .sec { margin-bottom: 18px; }
    table.lh { width:100%; border-collapse:collapse; border-bottom:2px solid #333; margin-bottom:10px; }
    table.lh td { border:none; vertical-align:middle; padding:0 0 6px 0; }
    .lh-logo { width:130px; text-align:left; }
    .lh-mid  { text-align:center; }
    .lh-meta { width:130px; text-align:right; font-size:10px; color:#555; }
    .uni  { font-size:22px; font-weight:bold; }
    .addr { font-size:13px; color:#333; }
    .stmt { font-size:16px; font-weight:bold; text-decoration:underline; margin-top:6px; }
    .rng  { font-size:13px; color:#333; }
    .dept { font-size:15px; font-weight:bold; margin:10px 0 6px 0; }
    table.grid { width:100%; border-collapse:collapse; table-layout:fixed; }
    table.grid th, table.grid td { border:1px solid #444; padding:4px 5px; font-size:14px; overflow:hidden; text-align:center; }
    table.grid thead th { background:#f0f0f0; font-weight:bold; }
    td.c { text-align:center; }
    td.divider { font-weight:bold; text-align:center; background:#f7f7f7; }
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
