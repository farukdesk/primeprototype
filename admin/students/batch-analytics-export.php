<?php
/**
 * Batch Analytics – Student Dropout Analysis Report (PDF)
 *
 * Streams a department → program wise dropout analysis as a single PDF.
 *
 * Layout:
 *   ┌────────────────────────────────────────┐
 *   │                 [University Logo]              │
 *   │        Student Dropout Analysis Report         │
 *   │              Department: <name/s/All>          │
 *   │                 Batch: <name/All>              │
 *   ├────────────────────────────────────────┤
 *   │ Department / Program table with Total Admitted,│
 *   │ Exam Attended, %, Dropout (Not Attended), %    │
 *   └────────────────────────────────────────┘
 *
 * Filters mirror admin/students/batch-analytics.php (dept, program, batch,
 * admitted semester, exam). Additionally accepts depts[] – an array of
 * department ids (from the checkbox picker) – so several departments can be
 * combined in one PDF.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('students');
require_once __DIR__ . '/helpers.php';
require_once dirname(__DIR__) . '/../vendor/autoload.php';

$dept_scope = get_dept_scope();

// ── Filters (identical to batch-analytics.php, plus depts[] checkboxes) ──────
$f_dept    = (int)($_GET['dept']    ?? 0);
$f_program = (int)($_GET['program'] ?? 0);
$f_batch   = (int)($_GET['batch']   ?? 0);
$f_sem     = trim($_GET['semester'] ?? '');
$f_exam    = trim($_GET['exam']     ?? '');
$f_depts   = array_values(array_filter(
    array_map('intval', (array)($_GET['depts'] ?? [])),
    static fn($v) => $v > 0
));

$where  = [];
$params = [];

if ($f_depts) {
    $phs      = implode(',', array_fill(0, count($f_depts), '?'));
    $where[]  = "s.dept_id IN ($phs)";
    array_push($params, ...$f_depts);
} elseif ($f_dept > 0) {
    $where[]  = 's.dept_id = ?';
    $params[] = $f_dept;
}
if ($f_program > 0) {
    $where[]  = 's.program_id = ?';
    $params[] = $f_program;
}
if ($f_batch > 0) {
    $where[]  = '(s.batch_id = ? OR s.id IN (SELECT sbt.student_id FROM student_batch_transfers sbt WHERE sbt.to_batch_id = ? AND sbt.is_active = 1))';
    $params[] = $f_batch;
    $params[] = $f_batch;
}
if ($f_sem !== '') {
    $where[]  = 's.admitted_semester = ?';
    $params[] = $f_sem;
}
if ($dept_scope !== null) {
    if (empty($dept_scope)) {
        $where[] = '0 = 1';
    } else {
        $phs     = implode(',', array_fill(0, count($dept_scope), '?'));
        $where[] = "s.dept_id IN ($phs)";
        array_push($params, ...$dept_scope);
    }
}
$where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

// ── Exam attendance set (same logic as batch-analytics.php) ─────────────────
$has_cards       = false;
$has_subject_col = false;
$has_overrides   = false;
try { db()->query('SELECT 1 FROM ac_admit_cards LIMIT 1'); $has_cards = true; } catch (Throwable $e) {}
if ($has_cards) {
    try { db()->query('SELECT offer_subject_id FROM ac_admit_card_courses LIMIT 1'); $has_subject_col = true; } catch (Throwable $e) {}
    try { db()->query('SELECT 1 FROM ac_student_overrides LIMIT 1'); $has_overrides = true; } catch (Throwable $e) {}
}

$card_union  = [];
$card_params = [];
if ($has_cards) {
    $exam_cond1 = $f_exam !== '' ? ' AND ac.exam_name = ?'  : '';
    $exam_cond2 = $f_exam !== '' ? ' AND ac2.exam_name = ?' : '';
    $exam_cond3 = $f_exam !== '' ? ' AND ac3.exam_name = ?' : '';

    if ($has_subject_col) {
        $card_union[] = 'SELECT r.student_id AS student_id
                           FROM ac_admit_card_courses cc
                           JOIN co_registrations r ON r.offer_subject_id = cc.offer_subject_id
                           JOIN ac_admit_cards ac ON ac.id = cc.admit_card_id
                          WHERE ac.is_active = 1' . $exam_cond1;
        if ($f_exam !== '') $card_params[] = $f_exam;

        $card_union[] = 'SELECT s2.id AS student_id
                           FROM students s2
                           JOIN ac_admit_cards ac2
                             ON ac2.dept_id = s2.dept_id
                            AND ac2.program_id = s2.program_id
                            AND (ac2.batch_id IS NULL OR ac2.batch_id = s2.batch_id)
                          WHERE ac2.is_active = 1' . $exam_cond2 . '
                            AND NOT EXISTS (SELECT 1 FROM ac_admit_card_courses cx
                                             WHERE cx.admit_card_id = ac2.id
                                               AND cx.offer_subject_id IS NOT NULL)';
        if ($f_exam !== '') $card_params[] = $f_exam;
    } else {
        $card_union[] = 'SELECT s2.id AS student_id
                           FROM students s2
                           JOIN ac_admit_cards ac2
                             ON ac2.dept_id = s2.dept_id
                            AND ac2.program_id = s2.program_id
                            AND (ac2.batch_id IS NULL OR ac2.batch_id = s2.batch_id)
                          WHERE ac2.is_active = 1' . $exam_cond2;
        if ($f_exam !== '') $card_params[] = $f_exam;
    }
    if ($has_overrides) {
        $card_union[] = 'SELECT o.student_id AS student_id
                           FROM ac_student_overrides o
                           JOIN ac_admit_cards ac3 ON ac3.id = o.admit_card_id
                          WHERE ac3.is_active = 1' . $exam_cond3;
        if ($f_exam !== '') $card_params[] = $f_exam;
    }
}

$card_join = '';
$card_expr = '0';
if ($card_union) {
    $card_join = ' LEFT JOIN (' . implode(' UNION ', $card_union) . ') acs ON acs.student_id = s.id';
    $card_expr = 'SUM(acs.student_id IS NOT NULL)';
}

// ── Department → Program wise aggregate ─────────────────────────────────
$sql = "SELECT d.id AS dept_id,
               d.name AS dept_name,
               COALESCE(p.program_name, '(No program)') AS program_name,
               COUNT(*)    AS total_cnt,
               $card_expr  AS card_cnt
          FROM students s
          JOIN dept_departments d ON d.id = s.dept_id
          LEFT JOIN dept_academic_programs p ON p.id = s.program_id"
     . $card_join . $where_sql . '
         GROUP BY d.id, d.name, p.id, program_name
         ORDER BY d.name ASC, program_name ASC';

$stmt = db()->prepare($sql);
$stmt->execute(array_merge($card_params, $params));
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group rows per department for section headers + subtotals
$by_dept = [];
foreach ($rows as $r) {
    $by_dept[$r['dept_name']][] = $r;
}

if (!function_exists('ba_pct')) {
    function ba_pct(int $n, int $d): string
    {
        return $d > 0 ? number_format($n / $d * 100, 1) . '%' : '—';
    }
}

// ── Header labels ─────────────────────────────────────────────────────
$university = 'Prime University';

$dept_label = 'All Departments';
if ($f_depts) {
    $phs = implode(',', array_fill(0, count($f_depts), '?'));
    $st  = db()->prepare("SELECT name FROM dept_departments WHERE id IN ($phs) ORDER BY name ASC");
    $st->execute($f_depts);
    $names        = $st->fetchAll(PDO::FETCH_COLUMN);
    $total_active = (int)db()->query('SELECT COUNT(*) FROM dept_departments WHERE is_active = 1')->fetchColumn();
    if ($names && count($names) < $total_active) {
        $dept_label = implode(', ', $names);
    }
} elseif ($f_dept > 0) {
    $st = db()->prepare('SELECT name FROM dept_departments WHERE id = ?');
    $st->execute([$f_dept]);
    $dept_label = (string)($st->fetchColumn() ?: 'All Departments');
}

$batch_label = 'All Batches';
if ($f_batch > 0) {
    $st = db()->prepare('SELECT name FROM student_batches WHERE id = ?');
    $st->execute([$f_batch]);
    $batch_label = (string)($st->fetchColumn() ?: 'All Batches');
}

$exam_label   = $f_exam !== '' ? $f_exam : 'All Exams';
$generated_at = date('d M Y, h:i A');

// Logo (base64-embedded, same asset as the student list export)
$logo_path = dirname(__DIR__, 2) . '/assets/img/logo/logo-black.png';
$logo_html = '';
if (is_file($logo_path)) {
    $logo_html = '<img src="data:image/png;base64,'
        . base64_encode((string)file_get_contents($logo_path))
        . '" alt="Logo" style="height:64px;">';
}

// ── Table body ────────────────────────────────────────────────────────
$tot_total = 0;
$tot_card  = 0;
$body      = '';

if ($by_dept) {
    foreach ($by_dept as $dept_name => $dept_rows) {
        $body .= '<tr class="dept-row"><td colspan="6">' . h($dept_name) . '</td></tr>';
        $d_total = 0;
        $d_card  = 0;
        foreach ($dept_rows as $r) {
            $t   = (int)$r['total_cnt'];
            $att = (int)$r['card_cnt'];
            $d_total += $t;
            $d_card  += $att;
            $body .= '<tr>'
                . '<td class="pl">' . h($r['program_name']) . '</td>'
                . '<td class="num">' . number_format($t) . '</td>'
                . '<td class="num good">' . number_format($att) . '</td>'
                . '<td class="num good">' . ba_pct($att, $t) . '</td>'
                . '<td class="num bad">' . number_format($t - $att) . '</td>'
                . '<td class="num bad">' . ba_pct($t - $att, $t) . '</td>'
                . '</tr>';
        }
        $body .= '<tr class="subtotal">'
            . '<td>' . h($dept_name) . ' — Subtotal</td>'
            . '<td class="num">' . number_format($d_total) . '</td>'
            . '<td class="num good">' . number_format($d_card) . '</td>'
            . '<td class="num good">' . ba_pct($d_card, $d_total) . '</td>'
            . '<td class="num bad">' . number_format($d_total - $d_card) . '</td>'
            . '<td class="num bad">' . ba_pct($d_total - $d_card, $d_total) . '</td>'
            . '</tr>';
        $tot_total += $d_total;
        $tot_card  += $d_card;
    }
} else {
    $body = '<tr><td colspan="6" style="text-align:center;color:#777;">No students match the selected filters.</td></tr>';
}

$html = '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Student Dropout Analysis Report</title>
<style>
    body    { font-family: Arial, sans-serif; font-size: 11px; color: #000; }
    .rpthead { text-align: center; margin-bottom: 14px; }
    .rpthead .title { font-size: 17px; font-weight: bold; margin: 8px 0 4px; }
    .rpthead .line  { font-size: 12px; margin: 2px 0; }
    .rpthead .meta  { font-size: 9px; color: #888; margin-top: 4px; }
    table.data { width: 100%; border-collapse: collapse; }
    table.data th, table.data td {
        border: 1px solid #888; padding: 5px 7px; font-size: 11px; text-align: left;
    }
    table.data th { background: #3A6FD8; color: #fff; }
    table.data td.num  { text-align: right; }
    table.data td.pl   { padding-left: 18px; }
    table.data td.good { color: #1d7a34; }
    table.data td.bad  { color: #a71d2a; }
    tr.dept-row td { background: #e9eef9; font-weight: bold; font-size: 12px; }
    tr.subtotal td { background: #f5f5f5; font-weight: bold; }
    tr.grand td    { background: #3A6FD8; color: #fff; font-weight: bold; }
    tr.grand td.good, tr.grand td.bad { color: #fff; }
</style>
</head>
<body>
    <div class="rpthead">
        ' . $logo_html . '
        <div class="title">Student Dropout Analysis Report</div>
        <div class="line">Department: <strong>' . h($dept_label) . '</strong></div>
        <div class="line">Batch: <strong>' . h($batch_label) . '</strong></div>
        <div class="line">Exam: <strong>' . h($exam_label) . '</strong>'
            . ($f_sem !== '' ? ' &nbsp;•&nbsp; Admitted Semester: <strong>' . h($f_sem) . '</strong>' : '') . '</div>
        <div class="meta">' . h($university) . ' — Generated: ' . h($generated_at) . '</div>
    </div>

    <table class="data">
        <thead>
            <tr>
                <th style="width:34%;">Department / Program</th>
                <th style="width:13%;">Total Admitted</th>
                <th style="width:13%;">Exam Attended</th>
                <th style="width:13%;">Attended %</th>
                <th style="width:13%;">Dropout</th>
                <th style="width:14%;">Dropout %</th>
            </tr>
        </thead>
        <tbody>' . $body . '
            <tr class="grand">
                <td>Grand Total</td>
                <td class="num">' . number_format($tot_total) . '</td>
                <td class="num good">' . number_format($tot_card) . '</td>
                <td class="num good">' . ba_pct($tot_card, $tot_total) . '</td>'
                . '<td class="num bad">' . number_format($tot_total - $tot_card) . '</td>'
                . '<td class="num bad">' . ba_pct($tot_total - $tot_card, $tot_total) . '</td>
            </tr>
        </tbody>
    </table>
</body>
</html>';

$dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('dropout-analysis-' . date('Ymd-His') . '.pdf', ['Attachment' => true]);
exit;
