<?php
/**
 * Result Submission Report – 360° status of result submission for an exam.
 *
 * Universe  : every subject in the course offers (co_offer_subjects), scoped by
 *             department / program / batch / term. This is "what SHOULD have a
 *             result" once the exam is over.
 * Evidence  : result_mark_sheets rows linked to the selected exam (ei_exams)
 *             via exam_id + offer_subject_id, and the students graded inside
 *             those sheets (result_sheet_grades) compared with the students
 *             registered for the offered subject (co_registrations).
 * Statuses  : not_started · draft · returned · pending · partial · published
 *
 * URL: /results/report.php?exam_id=X
 *        [&dept_id=&program_id=&batch_id=&semester=auto|all|<term>
 *         &offer_status=active|all&status=<status>&q=<search>&export=csv]
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('results');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/workflow-helpers.php';

$page_title = 'Result Submission Report';
$today      = date('Y-m-d');

// ── Department scope ──────────────────────────────────────────────────────────
// Explicit scope (get_dept_scope) always wins. When none is configured,
// faculty members are limited to their own department(s) while non-faculty
// staff (e.g. the Controller of Examinations office) remain unrestricted.
$dept_scope = get_dept_scope();
if (!is_super_admin() && $dept_scope === null) {
    $own_uid    = (int)(auth_user()['id'] ?? 0);
    $own_depts  = [];
    $is_faculty = false;
    try {
        $st = db()->prepare('SELECT dept_id FROM faculty_profiles WHERE user_id = ?');
        $st->execute([$own_uid]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) { $is_faculty = true; if ($d) $own_depts[] = (int)$d; }
    } catch (Throwable $_e) {}
    try {
        $st = db()->prepare('SELECT dept_id FROM dept_faculty WHERE user_id = ? AND is_active = 1');
        $st->execute([$own_uid]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) { $is_faculty = true; if ($d) $own_depts[] = (int)$d; }
    } catch (Throwable $_e) {}
    if ($is_faculty) $dept_scope = array_values(array_unique($own_depts));
}

// ── Status metadata ───────────────────────────────────────────────────────────
$STATUS_META = [
    'published'   => ['label' => 'Published',           'short' => 'Published',   'cls' => 'bg-success',              'icon' => 'fa-check-circle',   'color' => '#198754'],
    'partial'     => ['label' => 'Partially Published', 'short' => 'Partial',     'cls' => 'bg-info text-dark',       'icon' => 'fa-adjust',         'color' => '#0dcaf0'],
    'pending'     => ['label' => 'In Approval',         'short' => 'In Approval', 'cls' => 'bg-primary',              'icon' => 'fa-hourglass-half', 'color' => '#0d6efd'],
    'returned'    => ['label' => 'Returned to Teacher', 'short' => 'Returned',    'cls' => 'bg-danger',               'icon' => 'fa-undo',           'color' => '#dc3545'],
    'draft'       => ['label' => 'Draft (not submitted)','short' => 'Draft',      'cls' => 'bg-warning text-dark',    'icon' => 'fa-pen',            'color' => '#ffc107'],
    'not_started' => ['label' => 'Not Started',         'short' => 'Not Started', 'cls' => 'bg-secondary',            'icon' => 'fa-times-circle',   'color' => '#6c757d'],
];
$STATUS_ORDER = array_keys($STATUS_META);

// ── Exams ─────────────────────────────────────────────────────────────────────
$exams = [];
try {
    $exams = db()->query(
        'SELECT * FROM ei_exams ORDER BY exam_year DESC, start_date DESC, exam_name ASC'
    )->fetchAll();
} catch (Throwable $_e) {}

$exam_id = (int)($_GET['exam_id'] ?? 0);
$exam    = null;
foreach ($exams as $e) { if ((int)$e['id'] === $exam_id) { $exam = $e; break; } }
if (!$exam && !empty($exams)) {
    // Default: the most recently COMPLETED exam (results are expected after it),
    // falling back to the newest exam on record.
    foreach ($exams as $e) {
        if (!empty($e['end_date']) && $e['end_date'] < $today) { $exam = $e; break; }
    }
    if (!$exam) $exam = $exams[0];
    $exam_id = (int)$exam['id'];
}

function rpt_exam_phase(array $e, string $today): array
{
    if (!empty($e['end_date']) && $e['end_date'] < $today)    return ['Completed', 'success'];
    if (!empty($e['start_date']) && $e['start_date'] > $today) return ['Upcoming',  'secondary'];
    return ['Ongoing', 'warning'];
}
function rpt_exam_label(array $e): string
{
    $label = trim((string)$e['exam_name'] . ' ' . (string)($e['exam_year'] ?? ''));
    $dates = [];
    if (!empty($e['start_date'])) $dates[] = date('d M Y', strtotime($e['start_date']));
    if (!empty($e['end_date']))   $dates[] = date('d M Y', strtotime($e['end_date']));
    if ($dates) $label .= ' (' . implode(' – ', $dates) . ')';
    return $label;
}

$days_since_end = null;
if ($exam && !empty($exam['end_date']) && $exam['end_date'] < $today) {
    $days_since_end = (int)floor((strtotime($today) - strtotime($exam['end_date'])) / 86400);
}

// ── Filters ───────────────────────────────────────────────────────────────────
$f_dept     = (int)($_GET['dept_id']    ?? 0);
$f_program  = (int)($_GET['program_id'] ?? 0);
$f_batch    = (int)($_GET['batch_id']   ?? 0);
$f_semester = trim((string)($_GET['semester'] ?? 'auto'));
$f_offer_st = (($_GET['offer_status'] ?? 'active') === 'all') ? 'all' : 'active';
$f_status   = trim((string)($_GET['status'] ?? ''));
if ($f_status !== '' && !isset($STATUS_META[$f_status])) $f_status = '';
$q          = trim((string)($_GET['q'] ?? ''));
$export     = (($_GET['export'] ?? '') === 'csv');

// Dropdown data
$departments = [];
$programs    = [];
$batches     = [];
$semesters   = [];
try {
    $departments = db()->query('SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC')->fetchAll();
    if ($dept_scope !== null) {
        $departments = array_values(array_filter($departments, fn($d) => in_array((int)$d['id'], $dept_scope, true)));
    }
    $programs  = db()->query('SELECT id, dept_id, program_name FROM dept_academic_programs WHERE is_active = 1 ORDER BY program_name ASC')->fetchAll();
    $batches   = db()->query('SELECT id, name FROM student_batches WHERE is_active = 1 ORDER BY sort_order ASC, name ASC')->fetchAll();
    $semesters = db()->query("SELECT DISTINCT semester FROM co_offers WHERE semester IS NOT NULL AND semester <> ''")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $_e) {}

// Sort terms newest first: year desc, then Fall > Summer > Spring.
usort($semesters, static function ($a, $b) {
    $rank = static function (string $s): array {
        $year   = preg_match('/(\d{4})/', $s, $m) ? (int)$m[1] : 0;
        $season = 0;
        if (stripos($s, 'fall')   !== false) $season = 3;
        elseif (stripos($s, 'summer') !== false) $season = 2;
        elseif (stripos($s, 'spring') !== false) $season = 1;
        return [$year, $season];
    };
    return $rank($b) <=> $rank($a);
});

// Auto-detect the term(s) the exam belongs to from sheets already submitted
// for it (ei_exams has no direct link to course offers).
$auto_semesters = [];
if ($exam) {
    try {
        $st = db()->prepare(
            'SELECT DISTINCT o.semester
               FROM result_mark_sheets ms
               JOIN co_offer_subjects cos ON cos.id = ms.offer_subject_id
               JOIN co_offers o          ON o.id  = cos.offer_id
              WHERE ms.exam_id = ? AND o.semester IS NOT NULL AND o.semester <> \'\''
        );
        $st->execute([$exam_id]);
        $auto_semesters = array_values(array_filter(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN))));
    } catch (Throwable $_e) {}
}
$semester_filter = [];                       // [] = all terms
if ($f_semester === 'auto')                 $semester_filter = $auto_semesters;
elseif ($f_semester !== 'all' && $f_semester !== '') $semester_filter = [$f_semester];

// ── Universe: offered subjects ────────────────────────────────────────────────
$rows            = [];
$universe_error  = null;
if ($exam) {
    $where  = ['1=1'];
    $params = [];

    if ($dept_scope !== null) {
        if (empty($dept_scope)) {
            $where[] = '0=1';
        } else {
            $where[] = 'o.dept_id IN (' . implode(',', array_fill(0, count($dept_scope), '?')) . ')';
            array_push($params, ...array_map('intval', $dept_scope));
        }
    }
    if ($f_dept > 0)    { $where[] = 'o.dept_id = ?';    $params[] = $f_dept; }
    if ($f_program > 0) { $where[] = 'o.program_id = ?'; $params[] = $f_program; }
    if ($f_batch > 0)   { $where[] = 'o.batch_id = ?';   $params[] = $f_batch; }
    if (!empty($semester_filter)) {
        $where[] = 'o.semester IN (' . implode(',', array_fill(0, count($semester_filter), '?')) . ')';
        array_push($params, ...$semester_filter);
    }
    if ($f_offer_st === 'active') { $where[] = "o.status = 'active'"; }
    if ($q !== '') {
        $where[]  = '(c.course_code LIKE ? OR c.course_name LIKE ?)';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
    }

    try {
        $st = db()->prepare(
            "SELECT cos.id AS offer_subject_id, cos.curriculum_id,
                    o.id AS offer_id, o.dept_id, o.program_id, o.batch_id,
                    o.semester, o.academic_intake, o.status AS offer_status, o.shift, o.section,
                    d.name AS dept_name, p.program_name, b.name AS batch_name,
                    c.course_code, c.course_name, c.credit,
                    (SELECT COUNT(*)
                       FROM co_registrations r
                       JOIN students s ON s.id = r.student_id
                      WHERE r.offer_subject_id = cos.id AND s.status = 'Active') AS registered_count
               FROM co_offer_subjects cos
               JOIN co_offers               o ON o.id = cos.offer_id
               JOIN dept_departments        d ON d.id = o.dept_id
               JOIN dept_academic_programs  p ON p.id = o.program_id
               LEFT JOIN student_batches    b ON b.id = o.batch_id
               JOIN course_curriculum       c ON c.id = cos.curriculum_id
              WHERE " . implode(' AND ', $where) . "
              ORDER BY d.name ASC, p.program_name ASC, b.sort_order ASC, b.name ASC, c.course_code ASC, c.course_name ASC"
        );
        $st->execute($params);
        $rows = $st->fetchAll();
    } catch (Throwable $e) {
        $universe_error = $e->getMessage();
    }
}

// ── Evidence: mark sheets for this exam ───────────────────────────────────────
$sheets_by_subject = [];   // offer_subject_id => [sheet rows]
$graded            = [];   // offer_subject_id => [workflow_status => distinct graded students]
$unlinked_sheets   = 0;    // sheets for this exam with no offer_subject_id (legacy)
if ($exam) {
    try {
        $st = db()->prepare(
            "SELECT ms.id, ms.offer_subject_id, ms.workflow_status, ms.current_step_order,
                    ms.created_at, ms.updated_at, ms.created_by,
                    s.step_label, g.name AS group_name, u.full_name AS creator_name,
                    (SELECT COUNT(*) FROM result_sheet_grades sg WHERE sg.sheet_id = ms.id) AS student_count,
                    (SELECT MAX(h.acted_at) FROM wf_sheet_history h WHERE h.sheet_id = ms.id AND h.action = 'published') AS published_at,
                    (SELECT MAX(h.acted_at) FROM wf_sheet_history h WHERE h.sheet_id = ms.id AND h.action = 'submitted') AS submitted_at
               FROM result_mark_sheets ms
               LEFT JOIN wf_chain_steps s ON s.chain_id = ms.chain_id AND s.step_order = ms.current_step_order
               LEFT JOIN user_groups    g ON g.id = s.group_id
               LEFT JOIN users          u ON u.id = ms.created_by
              WHERE ms.exam_id = ?
              ORDER BY ms.updated_at DESC"
        );
        $st->execute([$exam_id]);
        foreach ($st->fetchAll() as $sh) {
            if (empty($sh['offer_subject_id'])) { $unlinked_sheets++; continue; }
            $sheets_by_subject[(int)$sh['offer_subject_id']][] = $sh;
        }

        $st = db()->prepare(
            "SELECT ms.offer_subject_id, ms.workflow_status, COUNT(DISTINCT sg.student_sid) AS cnt
               FROM result_mark_sheets ms
               JOIN result_sheet_grades sg ON sg.sheet_id = ms.id
              WHERE ms.exam_id = ? AND ms.offer_subject_id IS NOT NULL
                AND (sg.marks_json IS NOT NULL OR sg.is_absent = 1)
              GROUP BY ms.offer_subject_id, ms.workflow_status"
        );
        $st->execute([$exam_id]);
        foreach ($st->fetchAll() as $r) {
            $graded[(int)$r['offer_subject_id']][(string)$r['workflow_status']] = (int)$r['cnt'];
        }
    } catch (Throwable $_e) { /* workflow tables not migrated yet */ }
}

// ── Teachers per offered subject ──────────────────────────────────────────────
$teachers_by_subject = [];
if (!empty($rows)) {
    $ids = array_map(static fn($r) => (int)$r['offer_subject_id'], $rows);
    try {
        $st = db()->prepare(
            'SELECT t.offer_subject_id, f.id AS faculty_id, f.name, f.designation, fd.name AS dept_name
               FROM co_offer_subject_teachers t
               JOIN dept_faculty     f  ON f.id  = t.faculty_id
               LEFT JOIN dept_departments fd ON fd.id = f.dept_id
              WHERE t.offer_subject_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
              ORDER BY t.sort_order ASC, f.name ASC'
        );
        $st->execute($ids);
        foreach ($st->fetchAll() as $t) {
            $teachers_by_subject[(int)$t['offer_subject_id']][] = $t;
        }
    } catch (Throwable $_e) {}
}

// ── Classify every offered subject ────────────────────────────────────────────
foreach ($rows as &$r) {
    $osid   = (int)$r['offer_subject_id'];
    $sheets = $sheets_by_subject[$osid] ?? [];
    $g      = $graded[$osid] ?? [];

    $cnt = ['published' => 0, 'pending' => 0, 'returned' => 0, 'draft' => 0];
    foreach ($sheets as $sh) {
        $ws = (string)$sh['workflow_status'];
        if (isset($cnt[$ws])) $cnt[$ws]++;
    }

    $registered = (int)$r['registered_count'];
    $pub_std    = (int)($g['published'] ?? 0);
    $pend_std   = (int)($g['pending']   ?? 0);

    if (empty($sheets)) {
        $status = 'not_started';
    } elseif ($cnt['published'] > 0 && ($registered === 0 || $pub_std >= $registered)) {
        $status = 'published';
    } elseif ($cnt['published'] > 0) {
        $status = 'partial';
    } elseif ($cnt['pending'] > 0) {
        $status = 'pending';
    } elseif ($cnt['returned'] > 0) {
        $status = 'returned';
    } else {
        $status = 'draft';
    }

    // Current step / people for the "where is it" column.
    $step_label = null; $step_group = null; $submitters = [];
    $last_activity = null; $published_at = null;
    foreach ($sheets as $sh) {
        if ($sh['workflow_status'] === 'pending' && $step_label === null) {
            $step_label = $sh['step_label'];
            $step_group = $sh['group_name'];
        }
        if (!empty($sh['creator_name'])) $submitters[(string)$sh['creator_name']] = true;
        if ($sh['updated_at'] && ($last_activity === null || $sh['updated_at'] > $last_activity)) $last_activity = $sh['updated_at'];
        if ($sh['published_at'] && ($published_at === null || $sh['published_at'] > $published_at)) $published_at = $sh['published_at'];
    }

    $r['status']         = $status;
    $r['sheets']         = $sheets;
    $r['sheet_counts']   = $cnt;
    $r['published_std']  = $pub_std;
    $r['pending_std']    = $pend_std;
    $r['coverage']       = $registered > 0 ? min(100, (int)round($pub_std * 100 / $registered)) : ($cnt['published'] > 0 ? 100 : 0);
    $r['step_label']     = $step_label;
    $r['step_group']     = $step_group;
    $r['submitters']     = array_keys($submitters);
    $r['last_activity']  = $last_activity;
    $r['published_at']   = $published_at;
    $r['teachers']       = $teachers_by_subject[$osid] ?? [];
}
unset($r);

// ── Summaries (always on the full, status-unfiltered set) ─────────────────────
$totals = array_fill_keys($STATUS_ORDER, 0);
$by_dept = []; $by_program = []; $pending_steps = []; $followup = [];
$total_registered = 0; $total_published_std = 0;

foreach ($rows as $r) {
    $s = $r['status'];
    $totals[$s]++;
    $total_registered    += (int)$r['registered_count'];
    $total_published_std += (int)$r['published_std'];

    $dk = (int)$r['dept_id'];
    if (!isset($by_dept[$dk])) {
        $by_dept[$dk] = ['name' => $r['dept_name'], 'programs' => [], 'total' => 0] + array_fill_keys($STATUS_ORDER, 0);
    }
    $by_dept[$dk]['total']++;
    $by_dept[$dk][$s]++;
    $by_dept[$dk]['programs'][(int)$r['program_id']] = true;

    $pk = $dk . '|' . (int)$r['program_id'];
    if (!isset($by_program[$pk])) {
        $by_program[$pk] = ['dept_id' => $dk, 'dept_name' => $r['dept_name'], 'program_id' => (int)$r['program_id'],
                            'program_name' => $r['program_name'], 'total' => 0] + array_fill_keys($STATUS_ORDER, 0);
    }
    $by_program[$pk]['total']++;
    $by_program[$pk][$s]++;

    foreach ($r['sheets'] as $sh) {
        if ($sh['workflow_status'] !== 'pending') continue;
        $key = trim((string)($sh['step_label'] ?: 'Pending')) . ($sh['group_name'] ? ' (' . $sh['group_name'] . ')' : '');
        if (!isset($pending_steps[$key])) $pending_steps[$key] = ['sheets' => 0, 'subjects' => [], 'depts' => []];
        $pending_steps[$key]['sheets']++;
        $pending_steps[$key]['subjects'][(int)$r['offer_subject_id']] = true;
        $pending_steps[$key]['depts'][$r['dept_name']] = true;
    }

    if (in_array($s, ['not_started', 'draft', 'returned'], true)) {
        if (empty($r['teachers'])) {
            $tk = '#none|' . $dk;
            if (!isset($followup[$tk])) $followup[$tk] = ['name' => 'No teacher assigned', 'designation' => '', 'dept' => $r['dept_name'], 'items' => []];
            $followup[$tk]['items'][] = $r;
        } else {
            foreach ($r['teachers'] as $t) {
                $tk = (int)$t['faculty_id'];
                if (!isset($followup[$tk])) $followup[$tk] = ['name' => $t['name'], 'designation' => $t['designation'], 'dept' => $t['dept_name'], 'items' => []];
                $followup[$tk]['items'][] = $r;
            }
        }
    }
}
uasort($followup, static fn($a, $b) => count($b['items']) <=> count($a['items']) ?: strcmp($a['name'], $b['name']));
uasort($pending_steps, static fn($a, $b) => $b['sheets'] <=> $a['sheets']);

$total_subjects  = count($rows);
$done_pct        = $total_subjects ? (int)round($totals['published'] * 100 / $total_subjects) : 0;
$submitted_cnt   = $totals['published'] + $totals['partial'] + $totals['pending'];
$submitted_pct   = $total_subjects ? (int)round($submitted_cnt * 100 / $total_subjects) : 0;
$outstanding_cnt = $totals['not_started'] + $totals['draft'] + $totals['returned'];

// ── Detail rows (status filter applies here only) ─────────────────────────────
$detail = $f_status !== '' ? array_values(array_filter($rows, fn($r) => $r['status'] === $f_status)) : $rows;

// ── Helpers for links ─────────────────────────────────────────────────────────
$base_query = array_filter([
    'exam_id'      => $exam_id,
    'dept_id'      => $f_dept ?: null,
    'program_id'   => $f_program ?: null,
    'batch_id'     => $f_batch ?: null,
    'semester'     => $f_semester !== 'auto' ? $f_semester : null,
    'offer_status' => $f_offer_st === 'all' ? 'all' : null,
    'q'            => $q !== '' ? $q : null,
], static fn($v) => $v !== null && $v !== '');
function rpt_url(array $base, array $extra = []): string
{
    return APP_URL . '/results/report.php?' . http_build_query(array_filter(array_merge($base, $extra), static fn($v) => $v !== null && $v !== ''));
}
function rpt_teacher_names(array $r): string
{
    return implode(', ', array_map(static fn($t) => (string)$t['name'], $r['teachers']));
}

// ── CSV export ────────────────────────────────────────────────────────────────
if ($export && $exam) {
    $fname = 'result-submission-report-' . preg_replace('/[^A-Za-z0-9]+/', '-', trim($exam['exam_name'] . '-' . $exam['exam_year'])) . '-' . date('Ymd') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
    fputcsv($out, ['Exam', rpt_exam_label($exam), 'Generated', date('d M Y H:i')]);
    fputcsv($out, []);
    fputcsv($out, ['Department', 'Program', 'Batch', 'Term', 'Academic Intake', 'Course Code', 'Course Name', 'Credit',
                   'Teacher(s)', 'Registered Students', 'Published Students', 'Coverage %', 'Status', 'Current Step',
                   'Published Sheets', 'Pending Sheets', 'Returned Sheets', 'Draft Sheets', 'Submitted By', 'Last Activity', 'Published At']);
    foreach ($detail as $r) {
        fputcsv($out, [
            $r['dept_name'], $r['program_name'], $r['batch_name'], $r['semester'], $r['academic_intake'],
            $r['course_code'], $r['course_name'], $r['credit'],
            rpt_teacher_names($r), $r['registered_count'], $r['published_std'], $r['coverage'],
            $STATUS_META[$r['status']]['label'],
            $r['step_label'] ? $r['step_label'] . ($r['step_group'] ? ' (' . $r['step_group'] . ')' : '') : '',
            $r['sheet_counts']['published'], $r['sheet_counts']['pending'], $r['sheet_counts']['returned'], $r['sheet_counts']['draft'],
            implode(', ', $r['submitters']),
            $r['last_activity'] ? date('Y-m-d H:i', strtotime($r['last_activity'])) : '',
            $r['published_at']  ? date('Y-m-d H:i', strtotime($r['published_at']))  : '',
        ]);
    }
    fclose($out);
    exit;
}

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.rpt-kpi { border-radius:12px; border:1px solid #e9ecef; transition:transform .12s ease; text-decoration:none; display:block; color:inherit; }
.rpt-kpi:hover { transform:translateY(-2px); color:inherit; }
.rpt-kpi.active { box-shadow:0 0 0 2px rgba(13,110,253,.35); }
.rpt-kpi .num { font-size:1.75rem; font-weight:700; line-height:1.1; }
.rpt-kpi .lbl { font-size:.78rem; color:#6c757d; }
.rpt-bar { height:10px; border-radius:6px; overflow:hidden; background:#f1f3f5; display:flex; }
.rpt-bar span { display:block; height:100%; }
.rpt-table td, .rpt-table th { vertical-align:middle; font-size:.85rem; }
.rpt-sheet-link { font-size:.72rem; }
@media print {
  .no-print, .sidebar, nav.navbar, footer, .breadcrumb { display:none !important; }
  .card { border:1px solid #ccc !important; box-shadow:none !important; break-inside:avoid; }
  body { font-size:11px; }
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/results/index.php">Results</a></li>
            <li class="breadcrumb-item active">Submission Report</li>
        </ol>
    </nav>
    <div class="d-flex gap-2 flex-wrap no-print">
        <?php if ($exam): ?>
        <a href="<?= h(rpt_url($base_query, ['status' => $f_status, 'export' => 'csv'])) ?>" class="btn btn-outline-success btn-sm" style="border-radius:10px;">
            <i class="fas fa-file-csv me-1"></i> Export CSV
        </a>
        <?php endif; ?>
        <button type="button" class="btn btn-outline-secondary btn-sm" style="border-radius:10px;" onclick="window.print()">
            <i class="fas fa-print me-1"></i> Print
        </button>
    </div>
</div>

<?php flash_show(); ?>

<?php if (empty($exams)): ?>
<div class="alert alert-warning">
    No exams found. Create an exam in <a href="<?= APP_URL ?>/exam-invigilation/index.php">Exam Invigilation</a> first.
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; exit; endif; ?>

<!-- ── Filters ─────────────────────────────────────────────────────────────── -->
<div class="card mb-4 no-print" style="border-radius:12px;">
    <div class="card-body py-3 px-4">
        <form method="GET" class="row g-2 align-items-end" id="rptFilter">
            <div class="col-12 col-lg-4">
                <label class="form-label fw-medium mb-1" style="font-size:.8rem;">Exam <span class="text-danger">*</span></label>
                <select name="exam_id" class="form-select form-select-sm" style="border-radius:8px;" onchange="this.form.submit()">
                    <?php foreach ($exams as $e): [$ph, $phc] = rpt_exam_phase($e, $today); ?>
                    <option value="<?= (int)$e['id'] ?>" <?= (int)$e['id'] === $exam_id ? 'selected' : '' ?>>
                        <?= h(rpt_exam_label($e)) ?> • <?= $ph ?><?= (int)($e['is_active'] ?? 0) ? '' : ' • Inactive' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label fw-medium mb-1" style="font-size:.8rem;">Department</label>
                <select name="dept_id" id="f_dept" class="form-select form-select-sm" style="border-radius:8px;">
                    <option value="">All departments</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= (int)$d['id'] ?>" <?= (int)$d['id'] === $f_dept ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label fw-medium mb-1" style="font-size:.8rem;">Program</label>
                <select name="program_id" id="f_program" class="form-select form-select-sm" style="border-radius:8px;">
                    <option value="">All programs</option>
                    <?php foreach ($programs as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" data-dept="<?= (int)$p['dept_id'] ?>" <?= (int)$p['id'] === $f_program ? 'selected' : '' ?>><?= h($p['program_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label fw-medium mb-1" style="font-size:.8rem;">Batch</label>
                <select name="batch_id" class="form-select form-select-sm" style="border-radius:8px;">
                    <option value="">All batches</option>
                    <?php foreach ($batches as $b): ?>
                    <option value="<?= (int)$b['id'] ?>" <?= (int)$b['id'] === $f_batch ? 'selected' : '' ?>><?= h($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label fw-medium mb-1" style="font-size:.8rem;">Term (offer semester)</label>
                <select name="semester" class="form-select form-select-sm" style="border-radius:8px;">
                    <option value="auto" <?= $f_semester === 'auto' ? 'selected' : '' ?>>
                        Auto<?= $auto_semesters ? ': ' . h(implode(', ', $auto_semesters)) : ' (no sheets yet → all terms)' ?>
                    </option>
                    <option value="all" <?= $f_semester === 'all' ? 'selected' : '' ?>>All terms</option>
                    <?php foreach ($semesters as $s): ?>
                    <option value="<?= h($s) ?>" <?= $f_semester === $s ? 'selected' : '' ?>><?= h($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label fw-medium mb-1" style="font-size:.8rem;">Course offers</label>
                <select name="offer_status" class="form-select form-select-sm" style="border-radius:8px;">
                    <option value="active" <?= $f_offer_st === 'active' ? 'selected' : '' ?>>Active offers only</option>
                    <option value="all" <?= $f_offer_st === 'all' ? 'selected' : '' ?>>All offers (incl. closed)</option>
                </select>
            </div>
            <div class="col-6 col-lg-3">
                <label class="form-label fw-medium mb-1" style="font-size:.8rem;">Search subject</label>
                <input type="text" name="q" value="<?= h($q) ?>" class="form-control form-control-sm" style="border-radius:8px;" placeholder="Course code or name…">
            </div>
            <?php if ($f_status !== ''): ?><input type="hidden" name="status" value="<?= h($f_status) ?>"><?php endif; ?>
            <div class="col-12 col-lg-7 d-flex gap-2 justify-content-lg-end">
                <button class="btn btn-sm btn-primary" style="border-radius:8px;"><i class="fas fa-filter me-1"></i> Apply</button>
                <a href="<?= APP_URL ?>/results/report.php?exam_id=<?= $exam_id ?>" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;">Reset</a>
            </div>
        </form>
    </div>
</div>

<?php if ($universe_error): ?>
<div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-1"></i> Could not load course offers: <?= h($universe_error) ?></div>
<?php endif; ?>

<?php [$phase, $phase_cls] = rpt_exam_phase($exam, $today); ?>

<!-- ── Exam banner ─────────────────────────────────────────────────────────── -->
<div class="card mb-4" style="border-radius:12px;border-left:5px solid #0d6efd;">
    <div class="card-body py-3 px-4 d-flex flex-wrap align-items-center gap-3">
        <div class="flex-grow-1">
            <div class="fw-semibold" style="font-size:1.05rem;">
                <i class="fas fa-graduation-cap me-2 text-primary"></i><?= h(trim($exam['exam_name'] . ' ' . $exam['exam_year'])) ?>
                <span class="badge bg-<?= $phase_cls ?> ms-2"><?= $phase ?></span>
                <?php if (array_key_exists('marks_entry_open', $exam)): ?>
                <span class="badge <?= (int)$exam['marks_entry_open'] ? 'bg-success bg-opacity-75' : 'bg-dark' ?> ms-1">
                    Marks entry <?= (int)$exam['marks_entry_open'] ? 'open' : 'closed' ?>
                </span>
                <?php endif; ?>
            </div>
            <div class="text-muted" style="font-size:.82rem;">
                <?php if (!empty($exam['start_date']) || !empty($exam['end_date'])): ?>
                <i class="far fa-calendar me-1"></i>
                <?= !empty($exam['start_date']) ? date('d M Y', strtotime($exam['start_date'])) : '—' ?>
                &rarr; <?= !empty($exam['end_date']) ? date('d M Y', strtotime($exam['end_date'])) : '—' ?>
                <?php endif; ?>
                <?php if ($days_since_end !== null): ?>
                &nbsp;•&nbsp; <strong><?= $days_since_end ?></strong> day<?= $days_since_end === 1 ? '' : 's' ?> since the exam ended
                <?php endif; ?>
                <?php if ($semester_filter): ?>
                &nbsp;•&nbsp; Term<?= count($semester_filter) > 1 ? 's' : '' ?>: <strong><?= h(implode(', ', $semester_filter)) ?></strong>
                <?php else: ?>
                &nbsp;•&nbsp; Term: <strong>all</strong>
                <?php endif; ?>
            </div>
        </div>
        <div style="min-width:240px;">
            <div class="d-flex justify-content-between" style="font-size:.78rem;">
                <span class="text-muted">Fully published</span>
                <span class="fw-semibold"><?= $done_pct ?>%</span>
            </div>
            <div class="rpt-bar mb-1">
                <?php foreach ($STATUS_ORDER as $s): if ($totals[$s] > 0 && $total_subjects > 0): ?>
                <span style="width:<?= $totals[$s] * 100 / $total_subjects ?>%;background:<?= $STATUS_META[$s]['color'] ?>;" title="<?= h($STATUS_META[$s]['label']) ?>: <?= $totals[$s] ?>"></span>
                <?php endif; endforeach; ?>
            </div>
            <div class="d-flex justify-content-between text-muted" style="font-size:.72rem;">
                <span>Submitted (any stage): <?= $submitted_pct ?>%</span>
                <span>Students published: <?= $total_published_std ?>/<?= $total_registered ?></span>
            </div>
        </div>
    </div>
</div>

<?php if ($unlinked_sheets > 0): ?>
<div class="alert alert-info py-2 px-3 mb-4" style="font-size:.85rem;">
    <i class="fas fa-info-circle me-1"></i>
    <strong><?= $unlinked_sheets ?></strong> mark sheet<?= $unlinked_sheets === 1 ? '' : 's' ?> for this exam
    <?= $unlinked_sheets === 1 ? 'is' : 'are' ?> not linked to a course offer subject and cannot be matched here.
    See the <a href="<?= APP_URL ?>/results/index.php?tab=published">Published</a> tab for those.
</div>
<?php endif; ?>

<!-- ── KPI cards ───────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xl">
        <a href="<?= h(rpt_url($base_query)) ?>" class="card rpt-kpi text-center py-3 <?= $f_status === '' ? 'active' : '' ?>" style="border-top:4px solid #212529;">
            <div class="num"><?= $total_subjects ?></div>
            <div class="lbl">Offered Subjects</div>
        </a>
    </div>
    <?php foreach ($STATUS_ORDER as $s): $m = $STATUS_META[$s]; ?>
    <div class="col-6 col-md-4 col-xl">
        <a href="<?= h(rpt_url($base_query, ['status' => $s])) ?>" class="card rpt-kpi text-center py-3 <?= $f_status === $s ? 'active' : '' ?>" style="border-top:4px solid <?= $m['color'] ?>;">
            <div class="num" style="color:<?= $m['color'] ?>;"><?= $totals[$s] ?></div>
            <div class="lbl"><i class="fas <?= $m['icon'] ?> me-1"></i><?= h($m['short']) ?></div>
        </a>
    </div>
    <?php endforeach; ?>
</div>

<?php if ($outstanding_cnt > 0 && $phase === 'Completed'): ?>
<div class="alert alert-warning py-2 px-3 mb-4 d-flex align-items-center gap-2" style="font-size:.875rem;">
    <i class="fas fa-exclamation-triangle"></i>
    <span><strong><?= $outstanding_cnt ?></strong> subject<?= $outstanding_cnt === 1 ? '' : 's' ?> still
          <?= $outstanding_cnt === 1 ? 'has' : 'have' ?> no submitted result
          (<?= $totals['not_started'] ?> not started, <?= $totals['draft'] ?> draft, <?= $totals['returned'] ?> returned)
          although the exam ended <?= $days_since_end ?> day<?= $days_since_end === 1 ? '' : 's' ?> ago.</span>
    <a href="#followup" class="ms-auto btn btn-sm btn-outline-warning no-print" style="border-radius:8px;white-space:nowrap;">
        <i class="fas fa-user-clock me-1"></i> Teacher follow-up list
    </a>
</div>
<?php endif; ?>

<!-- ── Department summary ──────────────────────────────────────────────────── -->
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-building me-2 text-muted"></i>Department Summary</h6>
        <span class="badge bg-secondary"><?= count($by_dept) ?> dept<?= count($by_dept) === 1 ? '' : 's' ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover rpt-table mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4">Department</th>
                        <th class="text-center">Programs</th>
                        <th class="text-center">Subjects</th>
                        <?php foreach ($STATUS_ORDER as $s): ?>
                        <th class="text-center" title="<?= h($STATUS_META[$s]['label']) ?>"><?= h($STATUS_META[$s]['short']) ?></th>
                        <?php endforeach; ?>
                        <th style="min-width:180px;">Completion</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($by_dept)): ?>
                    <tr><td colspan="<?= 4 + count($STATUS_ORDER) ?>" class="text-center text-muted py-4">No offered subjects match the current filters.</td></tr>
                <?php else: foreach ($by_dept as $dk => $d): $pct = $d['total'] ? (int)round($d['published'] * 100 / $d['total']) : 0; ?>
                    <tr>
                        <td class="px-4 fw-medium">
                            <a href="<?= h(rpt_url($base_query, ['dept_id' => $dk, 'program_id' => null])) ?>" class="text-decoration-none"><?= h($d['name']) ?></a>
                        </td>
                        <td class="text-center"><?= count($d['programs']) ?></td>
                        <td class="text-center fw-semibold"><?= $d['total'] ?></td>
                        <?php foreach ($STATUS_ORDER as $s): ?>
                        <td class="text-center">
                            <?php if ($d[$s] > 0): ?>
                            <a href="<?= h(rpt_url($base_query, ['dept_id' => $dk, 'program_id' => null, 'status' => $s])) ?>" class="badge <?= $STATUS_META[$s]['cls'] ?> text-decoration-none"><?= $d[$s] ?></a>
                            <?php else: ?><span class="text-muted">–</span><?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="rpt-bar flex-grow-1">
                                    <?php foreach ($STATUS_ORDER as $s): if ($d[$s] > 0): ?>
                                    <span style="width:<?= $d[$s] * 100 / $d['total'] ?>%;background:<?= $STATUS_META[$s]['color'] ?>;" title="<?= h($STATUS_META[$s]['label']) ?>: <?= $d[$s] ?>"></span>
                                    <?php endif; endforeach; ?>
                                </div>
                                <span class="fw-semibold" style="font-size:.8rem;min-width:38px;text-align:right;"><?= $pct ?>%</span>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ── Program summary ─────────────────────────────────────────────────────── -->
<?php if (count($by_program) > 1 || $f_dept > 0): ?>
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-layer-group me-2 text-muted"></i>Program Summary</h6>
        <span class="badge bg-secondary"><?= count($by_program) ?> program<?= count($by_program) === 1 ? '' : 's' ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover rpt-table mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4">Department</th>
                        <th>Program</th>
                        <th class="text-center">Subjects</th>
                        <?php foreach ($STATUS_ORDER as $s): ?>
                        <th class="text-center" title="<?= h($STATUS_META[$s]['label']) ?>"><?= h($STATUS_META[$s]['short']) ?></th>
                        <?php endforeach; ?>
                        <th style="min-width:180px;">Completion</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($by_program as $p): $pct = $p['total'] ? (int)round($p['published'] * 100 / $p['total']) : 0; ?>
                    <tr>
                        <td class="px-4 text-muted"><?= h($p['dept_name']) ?></td>
                        <td class="fw-medium">
                            <a href="<?= h(rpt_url($base_query, ['dept_id' => $p['dept_id'], 'program_id' => $p['program_id']])) ?>" class="text-decoration-none"><?= h($p['program_name']) ?></a>
                        </td>
                        <td class="text-center fw-semibold"><?= $p['total'] ?></td>
                        <?php foreach ($STATUS_ORDER as $s): ?>
                        <td class="text-center">
                            <?php if ($p[$s] > 0): ?>
                            <a href="<?= h(rpt_url($base_query, ['dept_id' => $p['dept_id'], 'program_id' => $p['program_id'], 'status' => $s])) ?>" class="badge <?= $STATUS_META[$s]['cls'] ?> text-decoration-none"><?= $p[$s] ?></a>
                            <?php else: ?><span class="text-muted">–</span><?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="rpt-bar flex-grow-1">
                                    <?php foreach ($STATUS_ORDER as $s): if ($p[$s] > 0): ?>
                                    <span style="width:<?= $p[$s] * 100 / $p['total'] ?>%;background:<?= $STATUS_META[$s]['color'] ?>;"></span>
                                    <?php endif; endforeach; ?>
                                </div>
                                <span class="fw-semibold" style="font-size:.8rem;min-width:38px;text-align:right;"><?= $pct ?>%</span>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Where is it stuck? + Teacher follow-up ─────────────────────────────── -->
<div class="row g-4 mb-4">
    <div class="col-lg-5">
        <div class="card h-100" style="border-radius:12px;">
            <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-route me-2 text-muted"></i>Where Are Pending Sheets Waiting?</h6>
                <span class="badge bg-primary"><?= array_sum(array_column($pending_steps, 'sheets')) ?></span>
            </div>
            <div class="card-body p-0">
                <table class="table rpt-table mb-0">
                    <thead class="table-light">
                        <tr><th class="px-4">Approval Step (Group)</th><th class="text-center">Sheets</th><th class="text-center">Subjects</th><th>Departments</th></tr>
                    </thead>
                    <tbody>
                    <?php if (empty($pending_steps)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4"><i class="fas fa-check-circle text-success me-1"></i>Nothing is waiting in the approval chain.</td></tr>
                    <?php else: foreach ($pending_steps as $label => $ps): ?>
                        <tr>
                            <td class="px-4 fw-medium"><?= h($label) ?></td>
                            <td class="text-center"><span class="badge bg-primary"><?= $ps['sheets'] ?></span></td>
                            <td class="text-center"><?= count($ps['subjects']) ?></td>
                            <td class="text-muted" style="font-size:.78rem;"><?= h(implode(', ', array_keys($ps['depts']))) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if (!empty($pending_steps) && wf_has_approver_role()): ?>
            <div class="card-footer bg-transparent py-2 px-4 no-print">
                <a href="<?= APP_URL ?>/results/workflow-queue.php" class="btn btn-sm btn-outline-primary" style="border-radius:8px;"><i class="fas fa-tasks me-1"></i> Open my approval queue</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-7" id="followup">
        <div class="card h-100" style="border-radius:12px;">
            <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-user-clock me-2 text-muted"></i>Teacher Follow-up (no submitted result yet)</h6>
                <span class="badge bg-danger"><?= count($followup) ?> teacher<?= count($followup) === 1 ? '' : 's' ?></span>
            </div>
            <div class="card-body p-0" style="max-height:420px;overflow:auto;">
                <table class="table table-hover rpt-table mb-0">
                    <thead class="table-light" style="position:sticky;top:0;z-index:1;">
                        <tr><th class="px-4">Teacher</th><th>Department</th><th class="text-center">Outstanding</th><th>Subjects</th></tr>
                    </thead>
                    <tbody>
                    <?php if (empty($followup)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4"><i class="fas fa-check-circle text-success me-1"></i>Every offered subject has at least been submitted.</td></tr>
                    <?php else: foreach ($followup as $t): ?>
                        <tr>
                            <td class="px-4">
                                <div class="fw-medium"><?= h($t['name']) ?></div>
                                <?php if ($t['designation']): ?><small class="text-muted"><?= h($t['designation']) ?></small><?php endif; ?>
                            </td>
                            <td class="text-muted"><?= h($t['dept'] ?? '') ?></td>
                            <td class="text-center"><span class="badge bg-danger"><?= count($t['items']) ?></span></td>
                            <td style="font-size:.78rem;">
                                <?php foreach ($t['items'] as $it): ?>
                                <div>
                                    <span class="badge <?= $STATUS_META[$it['status']]['cls'] ?>" style="font-size:.65rem;"><?= h($STATUS_META[$it['status']]['short']) ?></span>
                                    <?= h(trim(($it['course_code'] ? $it['course_code'] . ' – ' : '') . $it['course_name'])) ?>
                                    <span class="text-muted">(<?= h($it['program_name']) ?><?= $it['batch_name'] ? ', ' . h($it['batch_name']) : '' ?>)</span>
                                </div>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ── Subject-level detail ─────────────────────────────────────────────────── -->
<div class="card" style="border-radius:12px;">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="mb-0 fw-semibold">
            <i class="fas fa-list-check me-2 text-muted"></i>Subject Detail
            <?php if ($f_status !== ''): ?>
            <span class="badge <?= $STATUS_META[$f_status]['cls'] ?> ms-2"><?= h($STATUS_META[$f_status]['label']) ?></span>
            <a href="<?= h(rpt_url($base_query)) ?>" class="ms-1 text-muted no-print" title="Clear status filter" style="font-size:.8rem;"><i class="fas fa-times"></i></a>
            <?php endif; ?>
        </h6>
        <div class="d-flex align-items-center gap-2 no-print">
            <input type="text" id="rptQuick" class="form-control form-control-sm" style="border-radius:8px;width:220px;" placeholder="Quick filter this table…">
            <span class="badge bg-secondary"><?= count($detail) ?></span>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover rpt-table mb-0" id="rptDetail">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Department / Program</th>
                        <th>Batch / Term</th>
                        <th>Subject</th>
                        <th>Teacher(s)</th>
                        <th class="text-center">Students<br><small class="text-muted fw-normal">published / registered</small></th>
                        <th>Status</th>
                        <th>Where / Who</th>
                        <th>Sheets</th>
                        <th>Last Activity</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($detail)): ?>
                    <tr><td colspan="10" class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No subjects match the current filters.</td></tr>
                <?php else: foreach ($detail as $i => $r): $m = $STATUS_META[$r['status']]; ?>
                    <tr data-status="<?= h($r['status']) ?>">
                        <td class="px-4 text-muted"><?= $i + 1 ?></td>
                        <td>
                            <div class="fw-medium"><?= h($r['dept_name']) ?></div>
                            <small class="text-muted"><?= h($r['program_name']) ?></small>
                        </td>
                        <td>
                            <div><?= h($r['batch_name'] ?? '—') ?></div>
                            <small class="text-muted"><?= h(trim((string)$r['semester'])) ?><?= $r['academic_intake'] ? ' • ' . h($r['academic_intake']) : '' ?></small>
                            <?php if ($r['offer_status'] !== 'active'): ?><br><span class="badge bg-light text-dark border" style="font-size:.65rem;">offer <?= h($r['offer_status']) ?></span><?php endif; ?>
                        </td>
                        <td>
                            <div class="fw-medium"><?= h($r['course_name']) ?></div>
                            <small class="text-muted"><?= h($r['course_code'] ?? '') ?><?= $r['credit'] !== null && $r['credit'] !== '' ? ' • ' . h($r['credit']) . ' cr' : '' ?></small>
                        </td>
                        <td style="font-size:.8rem;">
                            <?php if (empty($r['teachers'])): ?>
                                <span class="text-danger"><i class="fas fa-user-slash me-1"></i>Not assigned</span>
                            <?php else: foreach ($r['teachers'] as $t): ?>
                                <div><?= h($t['name']) ?><?php if ($t['designation']): ?> <span class="text-muted">(<?= h($t['designation']) ?>)</span><?php endif; ?></div>
                            <?php endforeach; endif; ?>
                        </td>
                        <td class="text-center">
                            <div class="fw-semibold"><?= (int)$r['published_std'] ?> / <?= (int)$r['registered_count'] ?></div>
                            <?php if ((int)$r['registered_count'] > 0): ?>
                            <div class="rpt-bar mx-auto" style="height:6px;width:70px;">
                                <span style="width:<?= $r['coverage'] ?>%;background:<?= $r['coverage'] >= 100 ? '#198754' : ($r['coverage'] > 0 ? '#0dcaf0' : '#dee2e6') ?>;"></span>
                            </div>
                            <?php if ($r['pending_std'] > 0): ?><small class="text-primary" style="font-size:.7rem;">+<?= (int)$r['pending_std'] ?> in approval</small><?php endif; ?>
                            <?php else: ?><small class="text-danger" style="font-size:.7rem;">no registrations</small><?php endif; ?>
                        </td>
                        <td><span class="badge <?= $m['cls'] ?>"><i class="fas <?= $m['icon'] ?> me-1"></i><?= h($m['label']) ?></span></td>
                        <td style="font-size:.78rem;">
                            <?php if ($r['status'] === 'pending'): ?>
                                <div class="text-primary"><i class="fas fa-hourglass-half me-1"></i>Waiting at <strong><?= h($r['step_label'] ?: 'approval') ?></strong><?= $r['step_group'] ? ' (' . h($r['step_group']) . ')' : '' ?></div>
                            <?php elseif ($r['status'] === 'partial'): ?>
                                <div class="text-info"><i class="fas fa-adjust me-1"></i><?= (int)$r['registered_count'] - (int)$r['published_std'] ?> student<?= ((int)$r['registered_count'] - (int)$r['published_std']) === 1 ? '' : 's' ?> still unpublished<?= $r['step_label'] ? ' • rest at ' . h($r['step_label']) : '' ?></div>
                            <?php elseif ($r['status'] === 'returned'): ?>
                                <div class="text-danger"><i class="fas fa-undo me-1"></i>Back with teacher for correction</div>
                            <?php elseif ($r['status'] === 'draft'): ?>
                                <div class="text-warning"><i class="fas fa-pen me-1"></i>Draft saved, not submitted</div>
                            <?php elseif ($r['status'] === 'not_started'): ?>
                                <div class="text-muted"><i class="fas fa-minus-circle me-1"></i>No mark sheet created<?= $days_since_end !== null ? ' • <span class="text-danger">' . $days_since_end . 'd overdue</span>' : '' ?></div>
                            <?php else: ?>
                                <div class="text-success"><i class="fas fa-check me-1"></i>Published<?= $r['published_at'] ? ' on ' . date('d M Y', strtotime($r['published_at'])) : '' ?></div>
                            <?php endif; ?>
                            <?php if ($r['submitters']): ?><div class="text-muted">By: <?= h(implode(', ', $r['submitters'])) ?></div><?php endif; ?>
                        </td>
                        <td class="rpt-sheet-link">
                            <?php if (empty($r['sheets'])): ?><span class="text-muted">—</span>
                            <?php else: foreach ($r['sheets'] as $sh): ?>
                                <div class="text-nowrap">
                                    <a href="<?= APP_URL ?>/results/view.php?id=<?= (int)$sh['id'] ?>" class="text-decoration-none">#<?= (int)$sh['id'] ?></a>
                                    <?= wf_status_badge((string)$sh['workflow_status']) ?>
                                    <span class="text-muted"><?= (int)$sh['student_count'] ?> std</span>
                                </div>
                            <?php endforeach; endif; ?>
                        </td>
                        <td style="white-space:nowrap;font-size:.78rem;">
                            <?php if ($r['last_activity']): ?>
                                <i class="far fa-clock me-1 text-muted"></i><?= date('d M Y, h:i A', strtotime($r['last_activity'])) ?>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer bg-transparent py-2 px-4 text-muted" style="font-size:.75rem;">
        <i class="fas fa-info-circle me-1"></i>
        <strong>How status is decided:</strong> a subject is <em>Published</em> when every active registered student has a grade in a published sheet for this exam;
        <em>Partially Published</em> when some but not all do; <em>In Approval</em> when a sheet is submitted and waiting in the workflow chain;
        <em>Returned</em> / <em>Draft</em> when the teacher still holds it; <em>Not Started</em> when no mark sheet exists for this exam and offered subject.
        The universe of subjects comes from Course Offers; the term is auto-detected from sheets already linked to the exam and can be overridden above.
    </div>
</div>

<script>
(function () {
    // Program dropdown follows the selected department.
    var deptSel = document.getElementById('f_dept');
    var progSel = document.getElementById('f_program');
    function syncPrograms() {
        var dept = deptSel.value;
        Array.prototype.forEach.call(progSel.options, function (opt) {
            if (!opt.value) return;
            var show = !dept || opt.getAttribute('data-dept') === dept;
            opt.hidden = !show;
            if (!show && opt.selected) progSel.value = '';
        });
    }
    if (deptSel && progSel) { deptSel.addEventListener('change', syncPrograms); syncPrograms(); }

    // Client-side quick filter for the detail table.
    var quick = document.getElementById('rptQuick');
    var tbody = document.querySelector('#rptDetail tbody');
    if (quick && tbody) {
        quick.addEventListener('input', function () {
            var term = quick.value.trim().toLowerCase();
            Array.prototype.forEach.call(tbody.rows, function (tr) {
                if (!tr.hasAttribute('data-status')) return;
                tr.style.display = (!term || tr.textContent.toLowerCase().indexOf(term) !== -1) ? '' : 'none';
            });
        });
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
